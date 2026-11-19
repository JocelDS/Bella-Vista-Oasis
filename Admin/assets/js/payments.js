(function () {
    'use strict';

    var app = document.getElementById('payApp');
    if (!app) { return; }

    var apiBase = document.body.getAttribute('data-api') || '';
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var ready = app.getAttribute('data-ready') === '1';
    var canAuth = app.getAttribute('data-can-auth') === '1';
    var receiptUrl = app.getAttribute('data-receipt');

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }
    function money(v) {
        var n = Number(v) || 0;
        return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function moneyShort(v) {
        var n = Number(v) || 0;
        return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }
    function round2(n) { return Math.round(n * 100) / 100; }
    function newKey() {
        if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
        var s = '';
        for (var i = 0; i < 32; i++) { s += Math.floor(Math.random() * 16).toString(16); }
        return s;
    }

    var toastEl = $('#toast');
    var toastTimer = null;
    function toast(msg) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 4200);
    }

    function api(path, options) {
        return fetch(apiBase + path, Object.assign({ credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, options || {}))
            .then(function (res) {
                if (res.status === 401) { window.location.reload(); throw new Error('unauthorized'); }
                return res.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; });
            });
    }
    function post(data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) { v.forEach(function (x) { fd.append(k + '[]', x); }); } else { fd.append(k, v); }
        });
        fd.append('csrf', csrf);
        return api('/payments.php', { method: 'POST', body: fd });
    }

    var openModals = [];
    function openModal(m) {
        m.hidden = false;
        if (openModals.indexOf(m) === -1) { openModals.push(m); }
    }
    function closeModal(m) {
        m.hidden = true;
        openModals = openModals.filter(function (x) { return x !== m; });
    }
    $$('.modal').forEach(function (m) {
        m.addEventListener('mousedown', function (ev) { if (ev.target === m && m.id !== 'folioModal') { closeModal(m); } });
        $$('[data-close]', m).forEach(function (b) { b.addEventListener('click', function () { closeModal(m); }); });
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && openModals.length) { closeModal(openModals[openModals.length - 1]); }
    });

    var STATUS = { paid: 'Paid', pending: 'Pending', partial: 'Partial', refunded: 'Refunded', voided: 'Voided' };
    var state = { tab: 'all', range: 'today', from: '', to: '', q: '', channel: '', service: '0', sort: 'date', dir: 'desc', page: 1, per: 8 };
    var lastData = null;
    var reqId = 0;

    function query(withPage) {
        var p = new URLSearchParams();
        p.set('tab', state.tab);
        p.set('range', state.range);
        if (state.range === 'custom') {
            if (state.from) { p.set('from', state.from); }
            if (state.to) { p.set('to', state.to); }
        }
        if (state.q) { p.set('q', state.q); }
        if (state.channel) { p.set('channel', state.channel); }
        if (state.service !== '0') { p.set('service', state.service); }
        p.set('sort', state.sort);
        p.set('dir', state.dir);
        if (withPage) {
            p.set('page', String(state.page));
            p.set('per', String(state.per));
        }
        return p.toString();
    }

    function badge(status) {
        return el('span', 'pay-badge st-' + status, STATUS[status] || status);
    }

    function renderStats(s) {
        $('#stGross').textContent = money(s.gross);
        var pace = $('#stPace');
        pace.className = '';
        if (s.pct === null || s.pct === undefined) {
            pace.textContent = 'No sales recorded yesterday';
        } else {
            pace.textContent = (s.pct >= 0 ? '▲ +' : '▼ ') + s.pct + '% vs yesterday';
            pace.className = s.pct >= 0 ? 'is-up' : 'is-down';
        }
        var barWrap = $('#stBarWrap');
        if (s.target && s.target > 0) {
            barWrap.hidden = false;
            $('#stBar').style.width = Math.min(100, (s.gross / s.target) * 100) + '%';
            $('#stTarget').textContent = 'Target ' + moneyShort(s.target);
        } else {
            barWrap.hidden = true;
            $('#stTarget').textContent = 'No daily target set';
        }
        $('#stSettled').textContent = s.settled.count;
        $('#stTotal').textContent = '/ ' + s.settled.total;
        $('#stSplit').textContent = s.settled.digital + ' Digital · ' + s.settled.cash + ' Cash';
        $('#stSettlePct').textContent = s.settled.pct + '% Settled';
        $('#stEscrow').textContent = money(s.escrow.amount);
        $('#stEscrowPct').textContent = s.escrow.pct + '% of upcoming bookings';
        $('#stEscrowCount').textContent = s.escrow.count + ' active deposit' + (s.escrow.count === 1 ? '' : 's');
        $('#stOpen').textContent = money(s.open.amount);
        $('#stActive').textContent = s.open.active + ' active guest folio' + (s.open.active === 1 ? '' : 's');
        $('#stPending').textContent = s.open.pending + ' Pending Checkout';
    }

    function renderEscrow(e) {
        var pol = e.deposit_pct !== null && e.deposit_pct !== undefined
            ? 'Sanctuary bookings require an advance deposit of ' + (Math.round(e.deposit_pct * 100) / 100) + '% to reserve the suite and therapist. Deposits are held in escrow and applied to the final bill.'
            : 'Advance deposits are collected per booking to reserve the suite and therapist. Deposits are held in escrow and applied to the final bill.';
        $('#escPolicy').textContent = pol;
        $('#escGraceTitle').textContent = e.notice + '-Hour Grace Window';
        $('#escGraceText').textContent = 'Cancellations made less than ' + e.notice + ' hours before the scheduled treatment follow the late-cancellation rule of the booking system. This page only reports forfeitures. Refunds need an authorized administrator.';
        $('#escHeld').textContent = money(e.held) + ' · ' + e.held_count + ' booking' + (e.held_count === 1 ? '' : 's');
        $('#escForfeit').textContent = e.forfeit.count + ' case' + (e.forfeit.count === 1 ? '' : 's') + ' (' + moneyShort(e.forfeit.total) + ')';
        $('#escRefund').textContent = moneyShort(e.refunds.total) + ' processed';
    }

    function renderCounts(c) {
        $('#cAll').textContent = c.all;
        $('#cCompleted').textContent = c.completed;
        $('#cPending').textContent = c.pending;
        $('#cRefunds').textContent = c.refunds;
    }

    function renderRows(rows) {
        var tb = $('#payRows');
        tb.innerHTML = '';
        $('#payEmpty').hidden = rows.length > 0;
        rows.forEach(function (r) {
            var tr = el('tr', r.status === 'voided' ? 'is-voided' : '');
            var td = el('td');
            td.appendChild(el('strong', 'pay-inv', r.invoice));
            td.appendChild(el('span', 'pay-sub', r.date + ' · ' + r.time));
            tr.appendChild(td);

            td = el('td');
            td.appendChild(el('strong', null, r.guest));
            td.appendChild(el('span', 'pay-sub', r.service + (r.ref !== '—' ? ' · ' + r.ref : '')));
            tr.appendChild(td);

            td = el('td');
            td.appendChild(el('span', 'pay-chan', r.channel));
            if (r.ext_ref) { td.appendChild(el('span', 'pay-sub', 'Ref ' + r.ext_ref)); }
            tr.appendChild(td);

            td = el('td', 'num', money(r.gross));
            tr.appendChild(td);
            td = el('td', 'num', r.deposit > 0 ? money(r.deposit) : '—');
            tr.appendChild(td);
            td = el('td', 'num');
            td.appendChild(el('strong', null, r.settled > 0 ? money(r.settled) : '—'));
            if (r.balance > 0 && r.status !== 'voided' && r.status !== 'refunded') { td.appendChild(el('span', 'pay-sub pay-due-sub', 'Due ' + money(r.balance))); }
            tr.appendChild(td);

            td = el('td');
            td.appendChild(badge(r.status));
            tr.appendChild(td);

            td = el('td', 'num pay-actions');
            var view = el('button', 'btn btn-soft btn-sm', 'View');
            view.type = 'button';
            view.addEventListener('click', function () { openDetail(r.id); });
            td.appendChild(view);
            var rc = el('a', 'pay-icon-link', 'Receipt');
            rc.href = receiptUrl + '?id=' + r.id;
            rc.target = '_blank';
            rc.rel = 'noopener';
            td.appendChild(rc);
            tr.appendChild(td);
            tb.appendChild(tr);
        });
    }

    function renderPager(d) {
        var host = $('#payPager');
        host.innerHTML = '';
        var from = d.total ? ((d.page - 1) * d.per) + 1 : 0;
        var to = Math.min(d.total, d.page * d.per);
        $('#payShowing').textContent = 'Showing ' + from + '–' + to + ' of ' + d.total + ' transaction' + (d.total === 1 ? '' : 's');
        if (d.pages <= 1) { return; }
        function btn(label, page, active, disabled) {
            var b = el('button', 'pg' + (active ? ' is-active' : ''), label);
            b.type = 'button';
            b.disabled = !!disabled;
            if (!disabled && !active) { b.addEventListener('click', function () { state.page = page; load(); }); }
            host.appendChild(b);
        }
        btn('‹', d.page - 1, false, d.page <= 1);
        var start = Math.max(1, d.page - 2);
        var end = Math.min(d.pages, start + 4);
        start = Math.max(1, end - 4);
        for (var i = start; i <= end; i++) { btn(String(i), i, i === d.page, false); }
        btn('›', d.page + 1, false, d.page >= d.pages);
    }

    function renderSortState() {
        $$('.pay-sort').forEach(function (b) {
            var on = b.getAttribute('data-sort') === state.sort;
            var th = b.parentNode;
            th.setAttribute('aria-sort', on ? (state.dir === 'asc' ? 'ascending' : 'descending') : 'none');
            b.classList.toggle('is-sorted', on);
            b.setAttribute('data-dir', on ? state.dir : '');
        });
    }

    function load() {
        var my = ++reqId;
        $('#tableWrap').classList.add('is-loading');
        return api('/payments.php?action=list&' + query(true)).then(function (d) {
            if (my !== reqId) { return; }
            $('#tableWrap').classList.remove('is-loading');
            if (!d.ok) { toast(d.error || 'Unable to load transactions.'); return; }
            lastData = d;
            state.page = d.page;
            renderStats(d.summary);
            renderEscrow(d.escrow);
            renderCounts(d.counts);
            renderRows(d.rows);
            renderPager(d);
            renderSortState();
            $('#btnExport').href = apiBase + '/payments.php?action=export&' + query(false);
        }).catch(function () {
            $('#tableWrap').classList.remove('is-loading');
            toast('Network error. Please try again.');
        });
    }

    $$('.pay-tab').forEach(function (t) {
        t.addEventListener('click', function () {
            state.tab = t.getAttribute('data-tab');
            state.page = 1;
            $$('.pay-tab').forEach(function (x) { x.classList.toggle('is-active', x === t); });
            load();
        });
    });
    $$('.pay-sort').forEach(function (b) {
        b.addEventListener('click', function () {
            var s = b.getAttribute('data-sort');
            if (state.sort === s) { state.dir = state.dir === 'desc' ? 'asc' : 'desc'; } else { state.sort = s; state.dir = s === 'guest' ? 'asc' : 'desc'; }
            state.page = 1;
            load();
        });
    });

    var fRange = $('#fRange');
    var fFrom = $('#fFrom');
    var fTo = $('#fTo');
    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    fRange.addEventListener('change', function () {
        state.range = fRange.value;
        $('#customRange').hidden = state.range !== 'custom';
        if (state.range === 'custom') {
            if (!fFrom.value) { fFrom.value = todayStr(); }
            if (!fTo.value) { fTo.value = fFrom.value; }
            state.from = fFrom.value;
            state.to = fTo.value;
        }
        state.page = 1;
        load();
    });
    [fFrom, fTo].forEach(function (i) {
        i.addEventListener('change', function () {
            state.from = fFrom.value;
            state.to = fTo.value;
            state.page = 1;
            load();
        });
    });
    var searchTimer = null;
    $('#fSearch').addEventListener('input', function (ev) {
        var v = ev.target.value;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { state.q = v.trim(); state.page = 1; load(); }, 300);
    });
    $('#fChannel').addEventListener('change', function (ev) { state.channel = ev.target.value; state.page = 1; load(); });
    $('#fService').addEventListener('change', function (ev) { state.service = ev.target.value; state.page = 1; load(); });

    var dmBody = $('#dmBody');
    var dmFoot = $('#dmFoot');
    var dmError = $('#dmError');
    var reverseForm = $('#reverseForm');
    var reverseMode = '';
    var reverseId = 0;
    var detailModal = $('#detailModal');

    function kv(host, k, v) {
        var d = el('div', 'pay-kv');
        d.appendChild(el('span', null, k));
        d.appendChild(el('strong', null, v));
        host.appendChild(d);
    }

    function hideReverse() {
        reverseForm.hidden = true;
        reverseMode = '';
        $('#rvReason').value = '';
        dmError.hidden = true;
    }

    function openDetail(id) {
        hideReverse();
        dmBody.innerHTML = '';
        dmFoot.innerHTML = '';
        $('#dmTitle').textContent = 'Loading…';
        openModal(detailModal);
        api('/payments.php?action=detail&id=' + encodeURIComponent(id)).then(function (d) {
            if (!d.ok) { closeModal(detailModal); toast(d.error || 'Unable to load that transaction.'); return; }
            renderDetail(d.transaction);
        }).catch(function () { closeModal(detailModal); toast('Network error. Please try again.'); });
    }

    function renderDetail(t) {
        reverseId = t.id;
        $('#dmKicker').textContent = 'Transaction · ' + t.date + ' ' + t.time;
        $('#dmTitle').textContent = t.invoice;
        dmBody.innerHTML = '';

        var top = el('div', 'pay-d-top');
        top.appendChild(badge(t.status));
        top.appendChild(el('span', 'pay-sub', t.txn_ref || ''));
        dmBody.appendChild(top);

        var grid = el('div', 'pay-d-grid');
        kv(grid, 'Guest', t.guest);
        kv(grid, 'Treatment', t.service);
        kv(grid, 'Reservation', t.ref);
        kv(grid, 'Channel', t.channel);
        kv(grid, 'Reference No.', t.ext_ref || '—');
        kv(grid, 'Processed By', t.processed_by);
        dmBody.appendChild(grid);

        var fig = el('div', 'pay-d-fig');
        kv(fig, 'Gross', money(t.gross));
        kv(fig, 'Deposit', money(t.deposit));
        kv(fig, 'This Payment', t.settled > 0 ? money(t.settled) : '—');
        kv(fig, 'Balance', money(t.balance));
        dmBody.appendChild(fig);

        if (t.notes) { dmBody.appendChild(el('p', 'pay-note', 'Note: ' + t.notes)); }

        if (t.charges.length) {
            dmBody.appendChild(el('p', 'label-small pay-sub-h', 'Add-ons & Discounts'));
            var ul = el('ul', 'pay-d-list');
            t.charges.forEach(function (c) {
                var li = el('li');
                li.appendChild(el('span', null, c.label));
                li.appendChild(el('strong', null, (c.type === 'discount' ? '−' : '') + money(c.amount)));
                ul.appendChild(li);
            });
            dmBody.appendChild(ul);
        }

        dmBody.appendChild(el('p', 'label-small pay-sub-h', 'Payment History'));
        var tbl = el('table', 'pay-table pay-mini');
        var thead = el('thead');
        var hr = el('tr');
        ['Invoice', 'Date', 'Channel', 'Amount', 'Status'].forEach(function (h, i) { hr.appendChild(el('th', i === 3 ? 'num' : '', h)); });
        thead.appendChild(hr);
        tbl.appendChild(thead);
        var tb = el('tbody');
        t.history.forEach(function (h) {
            var tr = el('tr', h.id === t.id ? 'is-current' : '');
            tr.appendChild(el('td', null, h.invoice));
            tr.appendChild(el('td', null, h.at));
            tr.appendChild(el('td', null, h.method));
            tr.appendChild(el('td', 'num', money(h.amount)));
            var s = el('td');
            var key = h.status === 'deposit' ? 'partial' : (h.status === 'settled' ? 'paid' : h.status);
            s.appendChild(badge(key));
            tr.appendChild(s);
            tb.appendChild(tr);
        });
        tbl.appendChild(tb);
        var wrap = el('div', 'pay-ledger-wrap');
        wrap.appendChild(tbl);
        dmBody.appendChild(wrap);

        if (t.audit.length) {
            dmBody.appendChild(el('p', 'label-small pay-sub-h', 'Audit Trail'));
            var al = el('ul', 'pay-d-list');
            t.audit.forEach(function (a) {
                var li = el('li');
                li.appendChild(el('span', null, a.action.toUpperCase() + ' · ' + money(a.amount) + ' · ' + a.reason + ' (' + a.admin + ', ' + a.at + ')'));
                al.appendChild(li);
            });
            dmBody.appendChild(al);
        }

        dmFoot.innerHTML = '';
        var rc = el('a', 'btn btn-outline btn-sm', 'View Receipt');
        rc.href = receiptUrl + '?id=' + t.id;
        rc.target = '_blank';
        rc.rel = 'noopener';
        dmFoot.appendChild(rc);
        if (t.can_pay) {
            var pay = el('button', 'btn btn-grad btn-sm', 'Settle Balance');
            pay.type = 'button';
            pay.addEventListener('click', function () { closeModal(detailModal); selectFolio(t.appointment_id); });
            dmFoot.appendChild(pay);
        }
        if (t.can_void) {
            var vb = el('button', 'btn btn-danger-soft btn-sm', 'Void');
            vb.type = 'button';
            vb.addEventListener('click', function () { showReverse('void'); });
            dmFoot.appendChild(vb);
        }
        if (t.can_refund) {
            var rb = el('button', 'btn btn-danger-soft btn-sm', 'Refund');
            rb.type = 'button';
            rb.addEventListener('click', function () { showReverse('refund'); });
            dmFoot.appendChild(rb);
        }
        if (!canAuth && (t.status === 'paid' || t.status === 'partial' || t.status === 'pending')) {
            dmFoot.appendChild(el('span', 'pay-sub', 'Refunds and voids need an authorized administrator.'));
        }
    }

    function showReverse(mode) {
        reverseMode = mode;
        $('#rvLabel').textContent = mode === 'refund' ? 'Reason for refund' : 'Reason for void';
        $('#rvSubmit').textContent = mode === 'refund' ? 'Confirm Refund' : 'Confirm Void';
        reverseForm.hidden = false;
        dmError.hidden = true;
        $('#rvReason').focus();
    }
    $('#rvCancel').addEventListener('click', hideReverse);
    reverseForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var reason = $('#rvReason').value.trim();
        if (reason.length < 5) {
            dmError.textContent = 'Enter a clear reason (at least 5 characters).';
            dmError.hidden = false;
            return;
        }
        var btn = $('#rvSubmit');
        btn.disabled = true;
        post({ action: reverseMode, id: reverseId, reason: reason }).then(function (d) {
            if (!d.ok) { dmError.textContent = d.error || 'Unable to process that.'; dmError.hidden = false; return; }
            closeModal(detailModal);
            toast(d.message || 'Done.');
            load();
            loadFolios();
        }).catch(function () {
            dmError.textContent = 'Network error. Please try again.';
            dmError.hidden = false;
        }).then(function () { btn.disabled = false; });
    });

    var form = $('#settleForm');
    var folioSelect = $('#folioSelect');
    var folio = null;
    var method = '';
    var amountEdited = false;
    var errBox = $('#sfError');
    var submitBtn = $('#sfSubmit');
    var inFlight = false;
    var folioModal = $('#folioModal');

    function openFolioModal() {
        openModal(folioModal);
        if (!folio) { folioSelect.focus(); }
    }

    function loadFolios(keep) {
        return api('/payments.php?action=folios').then(function (d) {
            if (!d.ok) { return; }
            var current = keep || folioSelect.value;
            folioSelect.innerHTML = '';
            folioSelect.appendChild(new Option('Select a guest and appointment', ''));
            d.folios.forEach(function (f) {
                folioSelect.appendChild(new Option(f.guest + ' · ' + f.service + ' · ' + f.code + ' (' + money(f.balance) + ')', String(f.id)));
            });
            $('#folioEmpty').hidden = d.folios.length > 0 ? true : false;
            if (!d.folios.length) { $('#folioEmpty').textContent = 'No open folios right now. Confirmed or completed appointments with an unpaid balance appear here.'; }
            else { $('#folioEmpty').textContent = d.folios.length + ' open folio' + (d.folios.length === 1 ? '' : 's') + ' ready to settle.'; $('#folioEmpty').hidden = false; }
            if (current) { folioSelect.value = String(current); }
        });
    }

    function pendingAddonIds() {
        return $$('#addonList input:checked').map(function (i) { return Number(i.value); });
    }
    function pendingAddonSum() {
        var sum = 0;
        $$('#addonList input:checked').forEach(function (i) { sum += Number(i.getAttribute('data-price')) || 0; });
        return round2(sum);
    }
    function pendingDisc() {
        var v = parseFloat($('#sfDisc').value);
        return isFinite(v) && v > 0 ? round2(v) : 0;
    }
    function dueNow() {
        if (!folio) { return 0; }
        return Math.max(0, round2(folio.balance + pendingAddonSum() - pendingDisc()));
    }

    function renderLines() {
        var host = $('#fgLines');
        host.innerHTML = '';
        function line(label, amount, cls) {
            var d = el('div', 'pay-line' + (cls ? ' ' + cls : ''));
            d.appendChild(el('span', null, label));
            d.appendChild(el('strong', null, amount));
            host.appendChild(d);
        }
        line(folio.service, money(folio.total));
        folio.charges.forEach(function (c) { line(c.label, (c.type === 'discount' ? '−' : '') + money(c.amount), c.type === 'discount' ? 'is-neg' : ''); });
        $$('#addonList input:checked').forEach(function (i) { line(i.getAttribute('data-name') + ' (new)', money(i.getAttribute('data-price')), 'is-new'); });
        var d = pendingDisc();
        if (d > 0) { line('Discount (new)', '−' + money(d), 'is-neg is-new'); }
        line('Gross Treatment Total', money(round2(folio.gross + pendingAddonSum())), 'is-sum');
        if (folio.deposit > 0) { line('Deposit / Payments Applied', '−' + money(folio.deposit), 'is-neg'); }
    }

    function refreshDue() {
        if (!folio) { return; }
        renderLines();
        var due = dueNow();
        $('#fgDue').textContent = money(due);
        var amt = $('#sfAmount');
        if (!amountEdited) { amt.value = due > 0 ? due.toFixed(2) : ''; }
        amt.max = due > 0 ? due.toFixed(2) : '';
        renderQrAmount();
    }

    var qrCache = {};
    var qrToken = 0;

    function qrChannel() { return $('#sfDigital').value; }

    function renderQrAmount() {
        var amt = parseFloat($('#sfAmount').value);
        $('#qrAmount').textContent = money(isFinite(amt) && amt > 0 ? amt : dueNow());
    }

    function renderQr() {
        var panel = $('#qrPanel');
        var show = method === 'digital';
        panel.hidden = !show;
        if (!show) { return; }
        var channel = qrChannel();
        var url = qrCache[channel];
        $('#qrTitle').textContent = 'Scan to pay with ' + channel;
        var img = $('#qrImg');
        if (url) {
            img.src = url;
            img.alt = channel + ' payment QR code';
            img.hidden = false;
            $('#qrEmpty').hidden = true;
        } else {
            img.hidden = true;
            img.removeAttribute('src');
            $('#qrEmpty').textContent = url === null ? 'No ' + channel + ' QR uploaded yet. Upload the spa ' + channel + ' QR below.' : 'Loading QR…';
            $('#qrEmpty').hidden = false;
        }
        $('#qrUploadLabel').textContent = url ? 'Replace QR' : 'Upload QR';
        $('#qrRemove').hidden = !url;
        renderQrAmount();
    }

    function loadQr() {
        var channel = qrChannel();
        var token = ++qrToken;
        if (qrCache[channel] === undefined) { renderQr(); }
        api('/payment-qr.php?channel=' + encodeURIComponent(channel)).then(function (d) {
            if (d && d.ok) { qrCache[channel] = d.url || null; }
            else { qrCache[channel] = null; }
            if (token === qrToken) { renderQr(); }
        }).catch(function () {
            qrCache[channel] = null;
            if (token === qrToken) { renderQr(); }
        });
    }

    function qrSend(fd) {
        fd.append('csrf', csrf);
        fd.append('channel', qrChannel());
        return api('/payment-qr.php', { method: 'POST', body: fd }).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to update the QR.'); return; }
            qrCache[qrChannel()] = d.url || null;
            renderQr();
            toast(d.message || 'QR updated.');
        }).catch(function () { toast('Network error. Please try again.'); });
    }

    $('#qrFile').addEventListener('change', function (ev) {
        var f = ev.target.files && ev.target.files[0];
        ev.target.value = '';
        if (!f) { return; }
        var fd = new FormData();
        fd.append('action', 'upload');
        fd.append('qr', f);
        qrSend(fd);
    });
    $('#qrRemove').addEventListener('click', function () {
        var fd = new FormData();
        fd.append('action', 'remove');
        qrSend(fd);
    });

    function setMethod(m) {
        method = m;
        $$('.pay-method').forEach(function (b) {
            var on = b.getAttribute('data-method') === m;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-checked', on ? 'true' : 'false');
        });
        var box = $('#methodBox');
        box.hidden = !m;
        var digital = m === 'digital';
        var card = m === 'Card';
        var suite = m === 'Post to Suite';
        $('#digitalRow').hidden = !digital;
        $('#amountRow').hidden = suite;
        $('#refRow').hidden = !(digital || card);
        $('#confirmRow').hidden = !(digital || card);
        $('#suiteNote').hidden = !suite;
        $('#sfRefLabel').textContent = card ? 'Terminal Approval Code' : 'Payment Reference Number';
        $('#confirmText').textContent = card
            ? 'I confirm the card terminal approved this transaction. It is recorded as completed only after this confirmation.'
            : 'I confirm this payment was actually received. Bella Vista does not verify GCash, Maya, or bank payments automatically.';
        $('#sfMethod').value = digital ? $('#sfDigital').value : m;
        errBox.hidden = true;
        if (digital) { loadQr(); } else { renderQr(); }
    }
    $$('.pay-method').forEach(function (b) {
        b.addEventListener('click', function () { if (!b.disabled) { setMethod(b.getAttribute('data-method')); } });
    });
    $('#sfDigital').addEventListener('change', function () { if (method === 'digital') { $('#sfMethod').value = $('#sfDigital').value; loadQr(); } });

    $('#sfAmount').addEventListener('input', function () { amountEdited = true; errBox.hidden = true; renderQrAmount(); });
    $('#sfDisc').addEventListener('input', function () { amountEdited = false; refreshDue(); });
    $('#addonList').addEventListener('change', function () { amountEdited = false; refreshDue(); });

    function resetFolio() {
        folio = null;
        method = '';
        amountEdited = false;
        form.hidden = true;
        form.reset();
        folioSelect.value = '';
        $$('.pay-method').forEach(function (b) { b.classList.remove('is-active'); b.disabled = false; b.setAttribute('aria-checked', 'false'); });
        $('#methodBox').hidden = true;
        errBox.hidden = true;
    }

    function selectFolio(id) {
        var has = $$('option', folioSelect).some(function (o) { return o.value === String(id); });
        var run = function () {
            folioSelect.value = String(id);
            folioSelect.dispatchEvent(new Event('change'));
            openModal(folioModal);
        };
        if (has) { run(); } else { loadFolios(id).then(run); }
    }

    folioSelect.addEventListener('change', function () {
        var id = folioSelect.value;
        if (!id) { resetFolio(); return; }
        api('/payments.php?action=folio&id=' + encodeURIComponent(id)).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load that folio.'); resetFolio(); return; }
            folio = d.folio;
            amountEdited = false;
            form.reset();
            folioSelect.value = String(folio.id);
            $('#sfAppt').value = String(folio.id);
            $('#sfKey').value = newKey();
            $('#fgGuest').textContent = folio.guest;
            $('#fgService').textContent = folio.service + ' · ' + folio.code;
            $('#fgMeta').textContent = folio.date + ' · ' + folio.time + ' · ' + folio.room + (folio.therapist !== '—' ? ' · ' + folio.therapist : '');
            var list = $('#addonList');
            list.innerHTML = '';
            folio.catalog.forEach(function (a) {
                var lab = el('label', 'pay-addon');
                var cb = el('input');
                cb.type = 'checkbox';
                cb.name = 'addon_ids[]';
                cb.value = String(a.id);
                cb.setAttribute('data-price', String(a.price));
                cb.setAttribute('data-name', a.name);
                lab.appendChild(cb);
                lab.appendChild(el('span', null, a.name));
                lab.appendChild(el('strong', null, money(a.price)));
                list.appendChild(lab);
            });
            $('#addonBox').hidden = folio.catalog.length === 0;
            var suiteBtn = $('.pay-method[data-method="Post to Suite"]');
            suiteBtn.disabled = !!folio.posted;
            suiteBtn.title = folio.posted ? 'Already posted to the suite folio' : '';
            form.hidden = false;
            $('#folioEmpty').hidden = true;
            setMethod('');
            refreshDue();
        }).catch(function () { toast('Network error. Please try again.'); });
    });

    function showErr(msg) {
        errBox.textContent = msg;
        errBox.hidden = false;
        errBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function validate() {
        if (!folio) { return 'Select a guest folio to settle.'; }
        if (!method) { return 'Select a settlement method.'; }
        var gross = round2(folio.gross + pendingAddonSum());
        var disc = pendingDisc();
        if (disc > 0) {
            if (folio.discount + disc > gross + 0.004) { return 'The discount cannot be more than the gross amount of ' + money(gross) + '.'; }
            if ($('#sfDiscWhy').value.trim().length < 3) { return 'Enter a reason for the discount.'; }
        }
        var due = dueNow();
        if (due <= 0) { return 'There is no balance to collect.'; }
        if (method === 'Post to Suite') { return ''; }
        var amt = parseFloat($('#sfAmount').value);
        if (!isFinite(amt) || amt <= 0) { return 'Enter a payment amount greater than zero.'; }
        if (amt > due + 0.004) { return 'The amount cannot exceed the remaining balance of ' + money(due) + '.'; }
        if (method === 'digital' || method === 'Card') {
            if ($('#sfRef').value.trim().length < 4) { return 'Enter the ' + (method === 'Card' ? 'terminal approval code' : 'payment reference number') + ' shown on the actual transaction.'; }
            if (!$('#sfConfirm').checked) { return 'Confirm that the payment was actually received before recording it.'; }
        }
        return '';
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (inFlight) { return; }
        var msg = validate();
        if (msg) { showErr(msg); return; }
        errBox.hidden = true;
        var label = method === 'digital' ? $('#sfDigital').value : method;
        var due = dueNow();
        var amt = method === 'Post to Suite' ? due : parseFloat($('#sfAmount').value);
        $('#cfText').textContent = method === 'Post to Suite'
            ? 'Post ' + money(amt) + ' to the suite folio of ' + folio.guest + ' (' + folio.code + ')? The charge stays open until settled.'
            : 'Record ' + money(amt) + ' received via ' + label + ' from ' + folio.guest + ' (' + folio.code + ')?' + (amt + 0.004 < due ? ' A balance of ' + money(round2(due - amt)) + ' will remain.' : ' This settles the folio in full.');
        openModal($('#confirmModal'));
    });

    $('#cfNo').addEventListener('click', function () { closeModal($('#confirmModal')); });
    $('#cfYes').addEventListener('click', function () {
        if (inFlight) { return; }
        closeModal($('#confirmModal'));
        inFlight = true;
        submitBtn.disabled = true;
        var win = null;
        try { win = window.open('', '_blank'); } catch (e) { win = null; }
        if (win) { win.document.write('<p style="font-family:sans-serif;padding:16px">Preparing receipt…</p>'); }
        var fd = new FormData(form);
        fd.set('payment_method', method === 'digital' ? $('#sfDigital').value : method);
        if (method === 'Post to Suite') { fd.delete('amount'); }
        fd.set('csrf', csrf);
        api('/payments.php', { method: 'POST', body: fd }).then(function (d) {
            if (!d.ok) {
                if (win) { win.close(); }
                showErr(d.error || 'Unable to record the payment.');
                return;
            }
            var url = receiptUrl + '?id=' + d.payment_id + '&print=1';
            closeModal(folioModal);
            if (win) { win.location.href = url; }
            $('#dnText').textContent = d.message || 'Payment recorded.';
            var pl = $('#dnPrint');
            pl.href = url;
            pl.hidden = false;
            if (!win) { pl.textContent = 'Print Thermal Slip'; }
            openModal($('#doneModal'));
            toast(d.message || 'Payment recorded.');
            resetFolio();
            load();
            loadFolios();
        }).catch(function () {
            if (win) { win.close(); }
            showErr('Network error. The payment may not have been saved. Check the transaction list before trying again.');
        }).then(function () {
            inFlight = false;
            submitBtn.disabled = false;
        });
    });

    $('#btnNewCharge').addEventListener('click', openFolioModal);

    $('#btnDrawer').addEventListener('click', function () {
        var body = $('#drBody');
        body.innerHTML = '';
        openModal($('#drawerModal'));
        api('/payments.php?action=drawer').then(function (d) {
            if (!d.ok) { body.appendChild(el('p', 'pay-empty', d.error || 'Unable to load the drawer.')); return; }
            var r = d.drawer;
            $('#drDate').textContent = r.date + ' · ' + r.admin;
            var tbl = el('table', 'pay-table pay-mini');
            var th = el('thead');
            var hr = el('tr');
            ['Channel', 'Receipts', 'Total'].forEach(function (h, i) { hr.appendChild(el('th', i ? 'num' : '', h)); });
            th.appendChild(hr);
            tbl.appendChild(th);
            var tb = el('tbody');
            r.channels.forEach(function (c) {
                var tr = el('tr');
                tr.appendChild(el('td', null, c.channel));
                tr.appendChild(el('td', 'num', String(c.count)));
                tr.appendChild(el('td', 'num', money(c.total)));
                tb.appendChild(tr);
            });
            if (!r.channels.length) {
                var e0 = el('tr');
                var c0 = el('td', 'pay-empty-cell', 'No settled payments yet today.');
                c0.colSpan = 3;
                e0.appendChild(c0);
                tb.appendChild(e0);
            }
            tbl.appendChild(tb);
            body.appendChild(tbl);
            var sum = el('div', 'pay-d-fig');
            kv(sum, 'Total Settled', money(r.total));
            kv(sum, 'Cash In Drawer (today)', money(r.cash));
            kv(sum, 'Refunds Today', r.refunds.count + ' · ' + money(r.refunds.total));
            kv(sum, 'Open Suite Charges', r.suite.count + ' · ' + money(r.suite.total));
            body.appendChild(sum);
            body.appendChild(el('p', 'pay-hint', 'Count the physical cash drawer against the cash total above before closing the shift.'));
        }).catch(function () { body.appendChild(el('p', 'pay-empty', 'Network error. Please try again.')); });
    });

    $('#btnLedger').addEventListener('click', function () {
        var rows = $('#ldRows');
        rows.innerHTML = '';
        $('#ldEmpty').hidden = true;
        openModal($('#ledgerModal'));
        api('/payments.php?action=ledger').then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load the ledger.'); return; }
            $('#ldEmpty').hidden = d.ledger.length > 0;
            d.ledger.forEach(function (r) {
                var tr = el('tr');
                tr.appendChild(el('td', null, r.txn));
                tr.appendChild(el('td', null, r.ref));
                tr.appendChild(el('td', null, r.guest));
                tr.appendChild(el('td', 'num', money(r.deposit)));
                tr.appendChild(el('td', 'num', r.refund > 0 ? money(r.refund) : '—'));
                tr.appendChild(el('td', 'num', r.forfeit > 0 ? money(r.forfeit) : '—'));
                tr.appendChild(el('td', null, r.date));
                var s = el('td');
                s.appendChild(el('span', 'pay-badge es-' + r.status.toLowerCase().replace(/[^a-z]+/g, '-'), r.status));
                tr.appendChild(s);
                rows.appendChild(tr);
            });
        }).catch(function () { toast('Network error. Please try again.'); });
    });

    $('#btnTariffs').addEventListener('click', function () {
        var c = lastData ? lastData.commission : null;
        var rate = c && c.rate !== null && c.rate !== undefined ? Number(c.rate) : null;
        $('#tfRate').textContent = rate !== null
            ? 'Therapists earn ' + (Math.round(rate * 100) / 100) + '% of each commissionable add-on. It is credited automatically when the add-on is posted to a folio that has an assigned therapist.'
            : 'No add-on commission rate is configured, so no commission is credited.';
        $$('#tfRows tr').forEach(function (tr) {
            var cell = $('.tf-comm', tr);
            if (!cell) { return; }
            if (tr.getAttribute('data-comm') !== '1') { cell.textContent = 'Not commissionable'; return; }
            cell.textContent = rate === null ? 'Not configured' : money(Number(tr.getAttribute('data-price')) * rate / 100);
        });
        $('#tfMonth').textContent = c ? c.count + ' add-on commission' + (c.count === 1 ? '' : 's') + ' credited this month · ' + money(c.total) : '';
        openModal($('#tariffModal'));
    });

    if (!ready) {
        $$('.pay-app button, .pay-app select, .pay-app input').forEach(function (n) { n.disabled = true; });
        return;
    }

    renderSortState();
    load();

    var incoming = null;
    try { incoming = new URLSearchParams(window.location.search).get('appointment'); } catch (e) { incoming = null; }
    if (incoming && /^\d+$/.test(incoming)) {
        loadFolios(incoming).then(function () {
            var has = $$('option', folioSelect).some(function (o) { return o.value === incoming; });
            if (has) { selectFolio(incoming); } else { toast('This appointment has no open balance to settle.'); }
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname);
            }
        });
    } else {
        loadFolios();
    }
}());