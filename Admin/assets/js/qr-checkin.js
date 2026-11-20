(function () {
    'use strict';

    var page = document.getElementById('qrPage');
    if (!page) { return; }

    var apiBase = document.body.getAttribute('data-api') || '';
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var appointmentsUrl = page.getAttribute('data-appointments-url') || '#';
    var tz = page.getAttribute('data-tz') || undefined;
    var POLL_MS = 15000;

    /* ------------------------------------------------------------------ helpers */
    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }
    function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } return node; }
    function pad(n) { return n < 10 ? '0' + n : String(n); }

    var ICONS = {
        scan: '<path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 12h10"/>',
        keyboard: '<rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/>',
        eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        shield: '<path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.5 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
        arrow: '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/>'
    };
    function icon(name, cls) {
        var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        s.setAttribute('class', cls || 'ico');
        s.setAttribute('viewBox', '0 0 24 24');
        s.setAttribute('fill', 'none');
        s.setAttribute('stroke', 'currentColor');
        s.setAttribute('stroke-width', '1.7');
        s.setAttribute('stroke-linecap', 'round');
        s.setAttribute('stroke-linejoin', 'round');
        s.setAttribute('aria-hidden', 'true');
        s.innerHTML = ICONS[name] || '';   // static constants only, never data
        return s;
    }
    function btn(label, cls, fn, iconName) {
        var b = el('button', cls);
        b.type = 'button';
        if (iconName) { b.appendChild(icon(iconName)); }
        b.appendChild(el('span', null, label));
        if (fn) { b.addEventListener('click', fn); }
        return b;
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
    function get(path, params) {
        var q = params ? '?' + new URLSearchParams(params).toString() : '';
        return api(path + q);
    }
    function post(path, data) {
        var fd = new FormData();
        fd.append('csrf', csrf);
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        return api(path, { method: 'POST', body: fd });
    }

    /* modals */
    var openModals = [];
    function openModal(m) {
        if (openModals.indexOf(m) === -1) { openModals.push(m); }
        m.style.zIndex = String(100 + openModals.length);
        m.hidden = false;
    }
    function closeModal(m) {
        m.hidden = true;
        m.style.zIndex = '';
        openModals = openModals.filter(function (x) { return x !== m; });
    }
    $$('.modal', page).forEach(function (m) {
        m.addEventListener('mousedown', function (ev) { if (ev.target === m) { closeModal(m); } });
        $$('[data-close]', m).forEach(function (b) { b.addEventListener('click', function () { closeModal(m); }); });
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && openModals.length) { closeModal(openModals[openModals.length - 1]); }
    });

    var confirmModal = $('#confirmModal');
    var confirmCb = null;
    function askConfirm(title, text, label, cb, kicker) {
        $('#confirmKicker').textContent = kicker || 'Please confirm';
        $('#confirmTitle').textContent = title;
        $('#confirmText').textContent = text;
        $('#confirmYes').textContent = label;
        confirmCb = cb;
        openModal(confirmModal);
        $('#confirmYes').focus();
    }
    $('#confirmNo').addEventListener('click', function () { confirmCb = null; closeModal(confirmModal); });
    $('#confirmYes').addEventListener('click', function () {
        var cb = confirmCb;
        confirmCb = null;
        closeModal(confirmModal);
        if (cb) { cb(); }
    });

    function setPill(node, text, cls) {
        node.removeAttribute('data-state');
        node.className = 'qr-pill' + (cls ? ' ' + cls : '');
        node.textContent = text;
    }

    /* ------------------------------------------------------------------ clock + service indicator */
    var clockOffset = 0;
    var timeFmt, dateFmt;
    try {
        timeFmt = new Intl.DateTimeFormat('en-US', { timeZone: tz, hour: 'numeric', minute: '2-digit', second: '2-digit' });
        dateFmt = new Intl.DateTimeFormat('en-US', { timeZone: tz, weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    } catch (e) {
        timeFmt = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
        dateFmt = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    }
    function tickClock() {
        var d = new Date(Date.now() + clockOffset);
        $('#qrClock').textContent = timeFmt.format(d);
        $('#qrClockDate').textContent = dateFmt.format(d);
    }
    tickClock();
    setInterval(tickClock, 1000);

    var cameraOk = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) &&
        (window.isSecureContext || ['localhost', '127.0.0.1', '[::1]'].indexOf(location.hostname) !== -1);
    var svcState = 'checking';
    function paintService() {
        var box = $('#qrService');
        box.setAttribute('data-state', svcState);
        $('#qrServiceText').textContent = svcState === 'ok' ? 'QR services operational' : (svcState === 'down' ? 'QR services unavailable' : 'Checking services…');
        $('#qrServiceSub').textContent = svcState === 'down' ? 'Use refresh or check the server' : (cameraOk ? 'Check-in online · camera ready' : 'Check-in online · camera unavailable, manual mode');
    }
    paintService();

    /* ------------------------------------------------------------------ summary metrics */
    function paintStat(idVal, idBar, idNote, s) {
        $(idVal).textContent = s.value;
        $(idBar).style.width = Math.max(0, Math.min(100, s.bar)) + '%';
        $(idNote).textContent = s.note;
    }
    function loadStats() {
        return get('/qr-stats.php').then(function (j) {
            if (!j.ok) { throw new Error(j.error || 'stats'); }
            paintStat('#sQr', '#sQrBar', '#sQrNote', j.stats.qr_checkins);
            paintStat('#sVer', '#sVerBar', '#sVerNote', j.stats.verified);
            paintStat('#sRate', '#sRateBar', '#sRateNote', j.stats.success);
            paintStat('#sMan', '#sManBar', '#sManNote', j.stats.manual);
            clockOffset = Date.parse(j.server.time) - Date.now();
            tickClock();
            applyPolicy(j.settings);
            svcState = 'ok';
        }).catch(function (e) {
            if (e && e.message === 'unauthorized') { return; }
            svcState = 'down';
        }).then(paintService);
    }
    function applyPolicy(s) {
        $('#polEarly').textContent = s.early;
        $('#polLate').textContent = s.late;
        $('#polPending').textContent = s.allow_pending ? 'Pending bookings may be checked in.' : 'Only confirmed bookings can be checked in.';
    }

    /* ------------------------------------------------------------------ recent activity */
    function loadActivity() {
        return get('/qr-activity.php', { limit: 8 }).then(function (j) {
            var ul = clear($('#actList'));
            if (!j.ok || !j.items.length) {
                ul.appendChild(el('li', 'empty', j.ok ? 'No check-in activity recorded yet.' : 'Unable to load activity.'));
                return;
            }
            j.items.forEach(function (a) {
                var li = el('li', 'qr-act-item tone-' + a.tone);
                li.appendChild(el('span', 'qr-act-dot'));
                var body = el('div');
                body.appendChild(el('p', 'qr-act-title', a.label));
                var who = [];
                if (a.code) { who.push(a.code); }
                if (a.guest) { who.push(a.guest); }
                var sub = who.join(' · ');
                if (!sub && a.details) { sub = a.details; }
                if (a.staff) { sub += (sub ? ' · ' : '') + 'by ' + a.staff; }
                if (sub) { body.appendChild(el('p', 'qr-act-sub', sub)); }
                li.appendChild(body);
                var t = el('span', 'qr-act-time', a.time);
                t.title = a.stamp;
                li.appendChild(t);
                ul.appendChild(li);
            });
        }).catch(function () {});
    }

    /* ------------------------------------------------------------------ arrival monitor */
    var arr = { date: '', filter: 'all', q: '', page: 1, per: 6 };
    var selectedApptId = 0;

    function badge(kind, key, label) { return el('span', 'qr-badge k-' + key, label); }

    function renderArrivals(j) {
        $$('[data-count]').forEach(function (n) { n.textContent = j.counts[n.getAttribute('data-count')] || 0; });
        $$('#monFilters .filter').forEach(function (f) { f.classList.toggle('is-active', f.getAttribute('data-filter') === j.filter); });
        $('#monSub').textContent = 'Arrivals for ' + j.date_label + (j.is_today ? ' (today)' : '') + ' · QR verification and check-in method.';
        $('#monDate').value = j.date;

        var tb = clear($('#monBody'));
        if (!j.rows.length) {
            var tr = el('tr', 'empty-row');
            var td = el('td', null, arr.q || j.filter !== 'all' ? 'No arrivals match these filters.' : 'No appointments scheduled for this date.');
            td.colSpan = 9;
            tr.appendChild(td);
            tb.appendChild(tr);
        }
        j.rows.forEach(function (r) {
            var tr = el('tr');
            if (r.id === selectedApptId) { tr.className = 'is-selected'; }

            tr.appendChild(el('td', null)).appendChild(el('span', 'qr-code', r.code));

            var g = el('td'); var gc = el('div', 'guest-cell');
            gc.appendChild(el('span', 'avatar', r.initials));
            gc.appendChild(el('span', 'guest-name', r.guest));
            g.appendChild(gc); tr.appendChild(g);

            var s = el('td'); s.appendChild(el('span', 'cell-main', r.service));
            if (r.room) { s.appendChild(el('span', 'cell-sub', r.room)); }
            tr.appendChild(s);

            tr.appendChild(el('td', null, r.therapist));

            var t = el('td'); t.appendChild(el('span', 'cell-main', r.time)); t.appendChild(el('span', 'cell-sub', 'to ' + r.time_end));
            tr.appendChild(t);

            tr.appendChild(el('td')).appendChild(badge('v', r.verification.key, r.verification.label));

            var a = el('td'); a.appendChild(badge('a', r.arrival.key, r.arrival.label));
            if (r.arrival.sub) { a.appendChild(el('span', 'cell-sub', r.arrival.sub)); }
            tr.appendChild(a);

            var m = el('td');
            if (r.method.key) {
                var ms = el('span', 'qr-method');
                ms.appendChild(icon(r.method.key === 'qr' ? 'scan' : 'keyboard'));
                ms.appendChild(el('span', null, r.method.label));
                m.appendChild(ms);
            } else { m.appendChild(el('span', 'qr-none', '—')); }
            tr.appendChild(m);

            var act = el('td', 'col-actions');
            var v = el('button', 'qr-icon-act');
            v.type = 'button'; v.title = 'View appointment details'; v.setAttribute('aria-label', 'View ' + r.code);
            v.appendChild(icon('eye'));
            v.addEventListener('click', function () { loadDetail(r.id); });
            act.appendChild(v); tr.appendChild(act);
            tb.appendChild(tr);
        });

        $('#monSummary').textContent = j.summary;
        renderPager(j);
    }
    function renderPager(j) {
        var nav = clear($('#monPager'));
        if (j.pages <= 1) { return; }
        function pg(label, page, opts) {
            var b = el('button', 'pg' + (opts && opts.active ? ' is-active' : ''), label);
            b.type = 'button';
            if (opts && opts.disabled) { b.disabled = true; }
            b.addEventListener('click', function () { arr.page = page; loadArrivals(true); });
            nav.appendChild(b);
        }
        pg('Prev', j.page - 1, { disabled: j.page <= 1 });
        var start = Math.max(1, Math.min(j.page - 2, j.pages - 4));
        for (var p = start; p <= Math.min(j.pages, start + 4); p++) { pg(String(p), p, { active: p === j.page }); }
        pg('Next', j.page + 1, { disabled: j.page >= j.pages });
    }
    var arrSeq = 0;
    function loadArrivals(showSpinner) {
        var seq = ++arrSeq;
        var spin = $('#monLoading');
        if (showSpinner) { spin.hidden = false; }
        var params = { filter: arr.filter, q: arr.q, page: arr.page, per: arr.per };
        if (arr.date) { params.date = arr.date; }
        return get('/qr-arrivals.php', params).then(function (j) {
            if (seq !== arrSeq) { return; }
            if (!j.ok) { throw new Error(j.error || 'arrivals'); }
            arr.date = j.date; arr.page = j.page;
            renderArrivals(j);
        }).catch(function (e) {
            if (e && e.message === 'unauthorized') { return; }
            if (showSpinner) { toast('Unable to load arrivals. Please try again.'); }
        }).then(function () { if (seq === arrSeq) { spin.hidden = true; } });
    }

    $('#monFilters').addEventListener('click', function (ev) {
        var b = ev.target.closest('.filter');
        if (!b) { return; }
        arr.filter = b.getAttribute('data-filter'); arr.page = 1; loadArrivals(true);
    });
    var searchTimer = null;
    $('#monSearch').addEventListener('input', function (ev) {
        clearTimeout(searchTimer);
        var v = ev.target.value;
        searchTimer = setTimeout(function () { arr.q = v.trim(); arr.page = 1; loadArrivals(true); }, 300);
    });
    $('#monDate').addEventListener('change', function (ev) {
        if (/^\d{4}-\d{2}-\d{2}$/.test(ev.target.value)) { arr.date = ev.target.value; arr.page = 1; loadArrivals(true); }
    });
    $('#monRefresh').addEventListener('click', function () { refreshAll(true).then(function () { toast('Arrival monitor refreshed.'); }); });

    function refreshAll(spinner) {
        return Promise.all([loadStats(), loadActivity(), loadArrivals(!!spinner)]);
    }

    /* ------------------------------------------------------------------ live verification spotlight */
    var spotBody = $('#spotBody');
    var spotStatus = $('#spotStatus');
    var spot = null;      // { result, code }
    var busy = false;

    function spotEmpty() {
        spot = null;
        selectedApptId = 0;
        $$('#monBody tr.is-selected').forEach(function (r) { r.classList.remove('is-selected'); });
        clear(spotBody);
        var d = el('div', 'qr-spot-empty');
        var ic = el('span', 'qr-vp-icon'); ic.appendChild(icon('scan'));
        d.appendChild(ic);
        d.appendChild(el('h3', null, 'Ready for the next guest'));
        d.appendChild(el('p', null, 'Scan a guest\'s QR pass, enter their booking reference, or open Manual Check-in. Their appointment and verification details appear here.'));
        spotBody.appendChild(d);
        $('#spotKicker').textContent = 'Guest Verification';
        spotStatus.setAttribute('data-state', 'idle');
        spotStatus.className = 'qr-pill';
        spotStatus.textContent = 'Waiting for scan';
    }
    function spotLoading() {
        clear(spotBody);
        var d = el('div', 'qr-spot-loading'); d.appendChild(el('span', 'spinner'));
        spotBody.appendChild(d);
        setPill(spotStatus, 'Verifying…', 'is-warn');
    }
    function toneClass(t) { return 'is-' + (t || 'muted'); }
    function pillFor(key) {
        return { verified: 'is-ok', pending: 'is-warn', invalid: 'is-bad', expired: 'is-bad', not_used: 'is-muted' }[key] || 'is-muted';
    }
    function dlRow(dl, label, value, sub) {
        dl.appendChild(el('dt', null, label));
        var dd = el('dd', null, value || '—');
        if (sub) { dd.appendChild(el('span', 'sub', sub)); }
        dl.appendChild(dd);
    }
    function dlBlock(rows, cls) {
        var dl = el('dl', 'qr-dl' + (cls ? ' ' + cls : ''));
        rows.forEach(function (r) {
            var wrap = el('div');
            wrap.appendChild(el('dt', null, r[0]));
            var dd = el('dd', null, r[1] || '—');
            if (r[2]) { dd.appendChild(el('span', 'sub', r[2])); }
            wrap.appendChild(dd);
            dl.appendChild(wrap);
        });
        return dl;
    }
    function viewLink() {
        var a = el('a', 'btn btn-outline');
        a.href = appointmentsUrl;
        a.appendChild(icon('eye'));
        a.appendChild(el('span', null, 'View Appointment'));
        return a;
    }

    var ARRIVAL_PILL = { verified: 'Awaiting Confirmation', manual: 'Awaiting Confirmation' };
    function arrivalTone(res) { return ARRIVAL_PILL[res.status] ? 'warn' : res.tone; }

    function renderResult(res, code) {
        spot = { result: res, code: code || null };
        clear(spotBody);
        setPill(spotStatus, ARRIVAL_PILL[res.status] || res.label, toneClass(arrivalTone(res)));
        $('#spotKicker').textContent = res.mode === 'qr' ? 'QR Verified Guest' : (res.mode === 'manual' ? 'Manual Check-in' : 'Guest Verification');

        var c = res.card;
        if (!c) {
            selectedApptId = 0;
            var alert = el('div', 'qr-alert tone-' + res.tone);
            alert.appendChild(el('strong', null, res.label));
            alert.appendChild(document.createTextNode(res.message));
            spotBody.appendChild(alert);
            var acts = el('div', 'qr-spot-actions');
            acts.appendChild(btn('Open Manual Check-in', 'btn btn-primary', openManual, 'keyboard'));
            acts.appendChild(btn('Dismiss', 'btn btn-outline', clearSpot));
            spotBody.appendChild(acts);
            return;
        }

        selectedApptId = c.appointment.id;
        $$('#monBody tr').forEach(function (r) { r.classList.remove('is-selected'); });

        var a = c.appointment, u = c.customer, k = c.checkin;

        /* guest header */
        var g = el('div', 'qr-guest');
        var main = el('div', 'qr-guest-main');
        main.appendChild(el('span', 'qr-avatar', u.initials));
        var info = el('div');
        info.appendChild(el('h3', 'qr-guest-name', u.name));
        info.appendChild(el('p', 'qr-guest-meta', 'Customer #' + u.id));
        var contact = [u.phone, u.email].filter(Boolean).join(' · ');
        if (contact) { info.appendChild(el('p', 'qr-guest-meta', contact)); }
        info.appendChild(el('p', 'qr-guest-svc', a.service + ' · ' + a.duration + ' min'));
        var pills = el('div', 'qr-guest-pills');
        if (res.status !== 'invalid' && res.status !== 'expired') {
            pills.appendChild(el('span', 'qr-pill ' + pillFor(k.verification), 'QR · ' + k.verification_label));
        }
        pills.appendChild(el('span', 'qr-pill ' + toneClass(arrivalTone(res)), ARRIVAL_PILL[res.status] || res.label));
        info.appendChild(pills);
        main.appendChild(info);
        g.appendChild(main);
        var when = el('div', 'qr-when');
        when.appendChild(el('strong', null, a.date_label));
        when.appendChild(el('span', null, a.start_label + ' – ' + a.end_label));
        g.appendChild(when);
        spotBody.appendChild(g);

        /* room + therapist */
        var two = el('div', 'qr-two');
        var p1 = el('div', 'qr-panel');
        p1.appendChild(el('h4', null, 'Assigned Room'));
        p1.appendChild(el('strong', null, a.room));
        if (a.room_type) { p1.appendChild(el('span', null, a.room_type)); }
        var p2 = el('div', 'qr-panel');
        p2.appendChild(el('h4', null, 'Assigned Therapist'));
        p2.appendChild(el('strong', null, a.therapist));
        if (a.specialization) { p2.appendChild(el('span', null, a.specialization)); }
        two.appendChild(p1); two.appendChild(p2);
        spotBody.appendChild(two);

        /* appointment details */
        spotBody.appendChild(el('p', 'qr-section-h', 'Appointment Information'));
        spotBody.appendChild(dlBlock([
            ['Appointment ID', '#' + a.id],
            ['Requested Service', a.service],
            ['Date', a.date_label],
            ['Start Time', a.start_label],
            ['End Time', a.end_label],
            ['Appointment Status', a.status_label],
            ['Payment Status', a.payment_label]
        ]));

        /* verification details */
        spotBody.appendChild(el('p', 'qr-section-h', 'Arrival Verification'));
        var verifText = k.verification_label;
        var verifSub = k.verification === 'not_used' ? 'Identity checked by staff' : '';
        var arrivalSub = k.arrival === 'checked_in' ? ((k.method_label || '') + (k.checked_in_by ? ' · ' + k.checked_in_by : '')) : '';
        spotBody.appendChild(dlBlock([
            ['QR Verification', verifText, verifSub],
            ['Booking Reference', a.code],
            ['Verification Time', k.checked_in_at && k.verification === 'not_used' ? k.checked_in_at : (k.verified_at || 'Not verified yet')],
            ['Arrival Status', k.arrival_label, arrivalSub]
        ], 'is-verif'));

        /* message */
        var msgTone = res.tone;
        if (res.needs_ack) { msgTone = 'warn'; }
        var box = el('div', 'qr-alert tone-' + msgTone);
        box.appendChild(el('strong', null, res.needs_ack ? 'Outside the check-in window' : res.label));
        box.appendChild(document.createTextNode(res.message));
        spotBody.appendChild(box);

        var lt = c && c.late;
        if (lt && c.checkin.arrival !== 'checked_in') {
            var lb = el('div', 'qr-alert tone-warn');
            lb.appendChild(el('strong', null, 'Late arrival: ' + lt.late_label + ' late'));
            lb.appendChild(document.createTextNode('The session will be shortened to ' + lt.session_label + ' and still ends at ' + lt.end_label + ', so the next booking is not affected.'));
            spotBody.appendChild(lb);
        }

        /* actions */
        var identity = null;
        if (res.can_manual && !res.can_confirm) {
            var lab = el('label', 'qr-identity');
            identity = el('input'); identity.type = 'checkbox';
            lab.appendChild(identity);
            lab.appendChild(el('span', null, 'I have checked the guest\'s identity and this booking matches the guest in front of me.'));
            spotBody.appendChild(lab);
        }
        var actions = el('div', 'qr-spot-actions');
        if (res.can_confirm) {
            actions.appendChild(btn('Confirm Arrival & Check In', 'btn btn-primary', function () { confirmQr(false, this); }, 'check'));
        } else if (res.can_manual) {
            var mb = btn('Check In Manually', 'btn btn-primary', function () { confirmManual(a.id, identity.checked, false, this); }, 'keyboard');
            mb.disabled = true;
            identity.addEventListener('change', function () { mb.disabled = !identity.checked; });
            actions.appendChild(mb);
        }
        actions.appendChild(viewLink());
        actions.appendChild(btn(res.can_confirm ? 'Cancel Verification' : 'Clear', 'btn btn-outline', cancelSpot));
        spotBody.appendChild(actions);
    }

    function renderDone(card, message) {
        clear(spotBody);
        setPill(spotStatus, 'Checked In', 'is-ok');
        $('#spotKicker').textContent = 'Check-in complete';
        var a = card.appointment, k = card.checkin, u = card.customer;
        selectedApptId = a.id;
        var d = el('div', 'qr-done');
        var ic = el('span', 'qr-done-ico'); ic.appendChild(icon('check'));
        d.appendChild(ic);
        d.appendChild(el('h3', 'qr-guest-name', u.name + ' is checked in'));
        d.appendChild(el('p', 'qr-guest-meta', a.code + ' · ' + a.service));
        spotBody.appendChild(d);
        var two = el('div', 'qr-two');
        var p1 = el('div', 'qr-panel'); p1.appendChild(el('h4', null, 'Proceed to')); p1.appendChild(el('strong', null, a.room)); if (a.room_type) { p1.appendChild(el('span', null, a.room_type)); }
        var p2 = el('div', 'qr-panel'); p2.appendChild(el('h4', null, 'Therapist')); p2.appendChild(el('strong', null, a.therapist)); if (a.specialization) { p2.appendChild(el('span', null, a.specialization)); }
        two.appendChild(p1); two.appendChild(p2);
        spotBody.appendChild(two);
        spotBody.appendChild(dlBlock([
            ['Check-in Method', k.method_label],
            ['Checked In At', k.checked_in_at],
            ['Processed By', k.checked_in_by || '—'],
            ['Session', a.start_label + ' – ' + a.end_label]
        ].concat(card.late ? [
            ['Late Arrival', card.late.late_label + ' late'],
            ['Shortened Session', card.late.session_label, 'Ends at the original ' + card.late.end_label]
        ] : []), 'is-verif'));
        var actions = el('div', 'qr-spot-actions');
        actions.appendChild(btn('Scan Next Guest', 'btn btn-primary', clearSpot, 'scan'));
        actions.appendChild(viewLink());
        spotBody.appendChild(actions);
    }

    function clearSpot() {
        spotEmpty();
        resumeScan();
    }
    function cancelSpot() {
        var s = spot;
        if (s && s.code) { cooldown(s.code); }
        if (s && s.code && s.result && s.result.can_confirm) {
            post('/qr-confirm.php', { action: 'cancel_verification', code: s.code }).then(function () { refreshAll(false); });
        }
        clearSpot();
    }

    function scrollToSpot() {
        var card = $('#spotCard');
        var r = card.getBoundingClientRect();
        if (r.top < 0 || r.top > window.innerHeight * 0.6) { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    }

    /* verify a scanned / typed code */
    function verifyCode(code, source) {
        if (busy) { return Promise.resolve(); }
        busy = true;
        spotLoading();
        scrollToSpot();
        return post('/qr-verify.php', { code: code }).then(function (j) {
            if (!j.ok) { throw new Error(j.error || 'Verification failed.'); }
            var res = j.result;
            renderResult(res, res.mode === 'qr' ? code : null);
            toast(res.status === 'verified' ? 'QR code verified.' : res.label + '.');
            if (source === 'scan') {
                pauseScan(true);
                if (!res.card) { setTimeout(function () { if (spot && spot.result === res) { pauseScan(false); } }, 2500); }
            }
            return refreshAll(false);
        }).catch(function (e) {
            if (e && e.message === 'unauthorized') { return; }
            spotEmpty();
            toast(e && e.message ? e.message : 'Verification failed. Please try again.');
            if (source === 'scan') { pauseScan(false); }
        }).then(function () { busy = false; });
    }

    function lockBtn(b, on) { if (b) { b.disabled = on; } }

    function confirmQr(ack, button) {
        if (!spot || !spot.code) { return; }
        var code = spot.code;
        lockBtn(button, true);
        post('/qr-confirm.php', { action: 'confirm', code: code, acknowledge_window: ack ? '1' : '0' }).then(function (j) {
            if (j.ok) {
                toast(j.message);
                cooldown(code);
                renderDone(j.card, j.message);
                refreshAll(false);
                resumeScan();
                return;
            }
            lockBtn(button, false);
            if (j.code === 'window') {
                askConfirm('Outside the check-in window', j.error + ' Check the guest in anyway?', 'Check in anyway', function () { confirmQr(true, button); }, 'Staff acknowledgement');
                return;
            }
            toast(j.error || 'Unable to complete the check-in.');
            if (spot && spot.result && spot.result.card) { loadDetail(spot.result.card.appointment.id); }
            refreshAll(false);
        }).catch(function () { lockBtn(button, false); toast('Network problem. The guest was not checked in.'); });
    }

    function confirmManual(apptId, identityOk, ack, button) {
        if (!identityOk) { toast('Confirm the guest\'s identity first.'); return; }
        lockBtn(button, true);
        post('/qr-manual.php', { appointment_id: apptId, identity_verified: '1', acknowledge_window: ack ? '1' : '0' }).then(function (j) {
            if (j.ok) {
                toast(j.message + ' (manual)');
                renderDone(j.card, j.message);
                refreshAll(false);
                resumeScan();
                return;
            }
            lockBtn(button, false);
            if (j.code === 'window') {
                askConfirm('Outside the check-in window', j.error + ' Check the guest in anyway?', 'Check in anyway', function () { confirmManual(apptId, true, true, button); }, 'Staff acknowledgement');
                return;
            }
            toast(j.error || 'Unable to complete the check-in.');
            loadDetail(apptId);
            refreshAll(false);
        }).catch(function () { lockBtn(button, false); toast('Network problem. The guest was not checked in.'); });
    }

    /* read-only look-up of an appointment (monitor View action / manual search pick) */
    function loadDetail(id) {
        spotLoading();
        scrollToSpot();
        return get('/qr-detail.php', { id: id }).then(function (j) {
            if (!j.ok) { throw new Error(j.error || 'Not found'); }
            renderResult(j.result, null);
            pauseScan(true);
        }).catch(function (e) {
            if (e && e.message === 'unauthorized') { return; }
            spotEmpty();
            toast(e && e.message ? e.message : 'Unable to load the appointment.');
        });
    }

    /* ------------------------------------------------------------------ scanner */
    var video = $('#qrVideo');
    var frame = $('#qrFrame');
    var fctx = frame.getContext('2d', { willReadFrequently: true });
    var viewport = $('#qrViewport');
    var termStatus = $('#termStatus');
    var vpMsg = $('#vpMsg');
    var scan = { on: false, paused: false, stream: null, timer: 0, lastCode: '', lastAt: 0 };
    var mode = 'scan';

    function showView(v) {
        viewport.setAttribute('data-view', v);
        $('#vpIdle').hidden = v !== 'idle';
        $('#vpQr').hidden = v !== 'qr';
        $('#vpGenEmpty').hidden = v !== 'gen-empty';
    }
    function setTerm(text, cls, msg) {
        setPill(termStatus, text, cls);
        vpMsg.textContent = msg;
    }
    function scanNote(msg) {
        var n = $('#scanNote');
        n.hidden = !msg;
        n.className = 'qr-note' + (msg ? ' is-error' : '');
        n.textContent = msg || '';
    }
    function paintScanUi() {
        $('#btnScanStart').hidden = scan.on;
        $('#btnScanStop').hidden = !scan.on;
        viewport.setAttribute('data-paused', scan.paused ? '1' : '0');
        if (mode !== 'scan') { return; }
        if (scan.on && scan.paused) { setTerm('Paused', 'is-warn', 'Paused · finish the guest on the right'); }
        else if (scan.on) { setTerm('Scanning', 'is-ok', 'Camera live · looking for a QR code'); termStatus.setAttribute('data-state', 'live'); }
        else { setTerm('Scanner idle', '', 'Camera off'); termStatus.setAttribute('data-state', 'idle'); }
    }

    function camError(err) {
        var n = err && err.name;
        if (n === 'NotAllowedError' || n === 'PermissionDeniedError' || n === 'SecurityError') {
            return 'Camera permission was blocked. Allow camera access for this site in your browser, or use the booking reference box below.';
        }
        if (n === 'NotFoundError' || n === 'DevicesNotFoundError' || n === 'OverconstrainedError') {
            return 'No camera was found on this device. Use the booking reference box below or Manual Check-in.';
        }
        if (n === 'NotReadableError' || n === 'TrackStartError') {
            return 'The camera is being used by another app. Close it and try again, or use the booking reference box.';
        }
        return 'The camera could not be started. Use the booking reference box below or Manual Check-in.';
    }

    function startScanner() {
        if (scan.on) { return; }
        scanNote('');
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            var insecure = !window.isSecureContext && ['localhost', '127.0.0.1', '[::1]'].indexOf(location.hostname) === -1;
            scanNote(insecure
                ? 'Camera access needs HTTPS or localhost. Open this page over HTTPS, or use the booking reference box below.'
                : 'This browser does not support camera access. Use the booking reference box below or Manual Check-in.');
            setTerm('Camera unavailable', 'is-bad', 'Camera unavailable');
            return;
        }
        setTerm('Starting…', 'is-warn', 'Requesting camera…');
        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false })
            .then(function (stream) {
                if (mode !== 'scan') { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
                scan.stream = stream;
                video.srcObject = stream;
                return video.play().then(function () {
                    scan.on = true; scan.paused = !!(spot && spot.result && (spot.result.can_confirm || spot.result.can_manual));
                    showView('camera');
                    paintScanUi();
                    loop();
                });
            })
            .catch(function (err) {
                stopScanner(true);
                scanNote(camError(err));
                setTerm('Camera unavailable', 'is-bad', 'Camera unavailable');
                $('#refInput').focus();
            });
    }
    function stopScanner(silent) {
        clearTimeout(scan.timer);
        scan.on = false; scan.paused = false;
        if (scan.stream) { scan.stream.getTracks().forEach(function (t) { t.stop(); }); }
        scan.stream = null;
        try { video.pause(); video.srcObject = null; } catch (e) {}
        if (mode === 'scan') { showView('idle'); }
        if (!silent) { paintScanUi(); }
    }
    function pauseScan(on) {
        if (!scan.on) { return; }
        scan.paused = on;
        paintScanUi();
    }
    function resumeScan() {
        if (scan.on) { scan.paused = false; paintScanUi(); }
    }
    /* Ignore this exact code for a few seconds: the guest is usually still holding it up
       when a check-in completes or is cancelled, and we must not re-read it straight away. */
    function cooldown(code) {
        if (code) { scan.lastCode = code; scan.lastAt = Date.now() + 6000; }
    }
    function loop() {
        if (!scan.on) { return; }
        try {
            if (!scan.paused && !busy && video.readyState >= 2 && video.videoWidth) {
                var w = video.videoWidth, h = video.videoHeight, s = Math.min(1, 640 / Math.max(w, h));
                var cw = Math.round(w * s), ch = Math.round(h * s);
                if (frame.width !== cw || frame.height !== ch) { frame.width = cw; frame.height = ch; }
                fctx.drawImage(video, 0, 0, cw, ch);
                var img = fctx.getImageData(0, 0, cw, ch);
                var hit = window.jsQR ? window.jsQR(img.data, cw, ch, { inversionAttempts: 'dontInvert' }) : null;
                if (hit && hit.data) {
                    var now = Date.now();
                    if (!(hit.data === scan.lastCode && now - scan.lastAt < 3500)) {
                        scan.lastCode = hit.data; scan.lastAt = now;
                        verifyCode(hit.data, 'scan');
                    }
                }
            }
        } catch (e) {
            /* a single unreadable frame must never stop the scanner */
        }
        scan.timer = setTimeout(loop, 140);
    }

    $('#btnScanStart').addEventListener('click', startScanner);
    $('#btnScanStop').addEventListener('click', function () { stopScanner(false); });
    window.addEventListener('pagehide', function () { stopScanner(true); });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { return; }
        refreshAll(false);
    });

    /* ------------------------------------------------------------------ terminal mode (scan / generate) */
    function setMode(m) {
        mode = m;
        $$('.qr-seg-btn').forEach(function (b) {
            var on = b.getAttribute('data-mode') === m;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        $('#paneScan').hidden = m !== 'scan';
        $('#paneGen').hidden = m !== 'generate';
        if (m === 'generate') {
            if (scan.on) { stopScanner(true); }
            showView(gen.payload ? 'qr' : 'gen-empty');
            if (gen.payload) { setTerm('QR ready', 'is-ok', 'Valid until ' + gen.expires); }
            else { setTerm('Generator', '', 'Choose an appointment'); }
            termStatus.setAttribute('data-state', 'idle');
            loadGenList();
        } else {
            showView(scan.on ? 'camera' : 'idle');
            paintScanUi();
        }
    }
    $$('.qr-seg-btn').forEach(function (b) { b.addEventListener('click', function () { setMode(b.getAttribute('data-mode')); }); });

    /* ------------------------------------------------------------------ QR generation */
    var gen = { payload: '', pass: null, expires: '' };
    var genList = [];
    var genSel = $('#genAppt');

    function loadGenList() {
        var keep = genSel.value;
        return get('/qr-generate.php').then(function (j) {
            if (!j.ok) { throw new Error(j.error || 'list'); }
            genList = j.appointments;
            clear(genSel);
            var first = el('option', null, genList.length ? 'Select an appointment…' : 'No upcoming eligible appointments');
            first.value = '';
            genSel.appendChild(first);
            genList.forEach(function (a) {
                var o = el('option', null, a.code + ' · ' + a.guest + ' · ' + a.when + (a.has_active ? ' · QR active' : ''));
                o.value = String(a.id);
                genSel.appendChild(o);
            });
            if (keep && genList.some(function (a) { return String(a.id) === keep; })) { genSel.value = keep; }
        }).catch(function () {
            clear(genSel);
            var o = el('option', null, 'Unable to load appointments'); o.value = '';
            genSel.appendChild(o);
        });
    }

    function drawQr(canvas, text, cell, margin) {
        var q = window.qrcode(0, 'M');
        q.addData(text);
        q.make();
        var n = q.getModuleCount();
        var size = (n + margin * 2) * cell;
        canvas.width = size; canvas.height = size;
        var c = canvas.getContext('2d');
        c.fillStyle = '#ffffff';
        c.fillRect(0, 0, size, size);
        for (var r = 0; r < n; r++) {
            for (var col = 0; col < n; col++) {
                if (q.isDark(r, col)) { c.fillStyle = qrTone(r, col, n); c.fillRect((col + margin) * cell, (r + margin) * cell, cell, cell); }
            }
        }
    }

    /* System palette QR: purple finder squares, purple-to-pink fade across the data modules. */
    function qrTone(r, col, n) {
        if ((r < 7 && col < 7) || (r < 7 && col >= n - 7) || (r >= n - 7 && col < 7)) { return 'rgb(152,107,194)'; }
        var t = (r + col) / (2 * (n - 1));
        return 'rgb(' + Math.round(152 + 73 * t) + ',' + Math.round(107 + 32 * t) + ',' + Math.round(194 - 9 * t) + ')';
    }

    function doGenerate(id) {
        var b = $('#btnGenerate');
        b.disabled = true;
        post('/qr-generate.php', { appointment_id: id }).then(function (j) {
            if (!j.ok) { toast(j.error || 'Unable to generate a QR code.'); return; }
            gen = { payload: j.payload, pass: j.pass, expires: j.expires_label };
            drawQr($('#qrCanvas'), j.payload, 8, 4);
            $('#vpQrCap').textContent = j.pass.code + ' · valid until ' + j.expires_label;
            showView('qr');
            setTerm('QR ready', 'is-ok', 'Valid until ' + j.expires_label);
            $('#btnQrPrint').disabled = false;
            $('#btnQrDownload').disabled = false;
            $('#genNote').textContent = (j.replaced ? 'The previous QR code for this booking no longer works. ' : '') +
                'Print or download this code now: for security it cannot be shown again after you leave this page.';
            toast('QR code generated for ' + j.pass.code + '.');
            loadGenList();
            loadActivity();
        }).catch(function () { toast('Network problem. No QR code was generated.'); })
            .then(function () { b.disabled = false; });
    }
    $('#btnGenerate').addEventListener('click', function () {
        var id = parseInt(genSel.value, 10);
        if (!id) { toast('Select an appointment first.'); genSel.focus(); return; }
        var item = genList.filter(function (a) { return a.id === id; })[0];
        if (item && item.has_active) {
            askConfirm('Replace the existing QR code?', 'This booking already has an active QR code. A new code will make the old one stop working.', 'Generate new code', function () { doGenerate(id); });
        } else { doGenerate(id); }
    });

    /* ---- Reservation pass (same layout as the guest's PDF pass) ---- */
    var logoUrl = page.getAttribute('data-logo') || '';

    function loadLogo(done) {
        if (!logoUrl) { done(null); return; }
        var im = new Image();
        im.onload = function () { done(im); };
        im.onerror = function () { done(null); };
        im.src = logoUrl;
    }

    function passCanvas(g, logo) {
        var S = 2, W = 595, H = 842, p = g.pass || {};
        var SERIF = '"Times New Roman", Times, serif', SANS = 'Arial, Helvetica, sans-serif';
        var cv = document.createElement('canvas');
        cv.width = W * S; cv.height = H * S;
        var c = cv.getContext('2d');
        c.scale(S, S);
        c.textBaseline = 'alphabetic';
        function rect(x, y, w, h, col) { c.fillStyle = col; c.fillRect(x, y, w, h); }
        function text(x, y, font, col, str, maxW) {
            c.font = font; c.fillStyle = col;
            str = String(str === undefined || str === null ? '' : str);
            if (maxW && c.measureText(str).width > maxW) {
                while (str.length > 1 && c.measureText(str + '\u2026').width > maxW) { str = str.slice(0, -1); }
                str += '\u2026';
            }
            c.fillText(str, x, y);
        }
        var INK = '#49364F', PURPLE = '#986BC2', MUTE = '#89788D';

        rect(0, 0, W, H, '#FFF7FC');
        rect(0, 0, W, 12, PURPLE);
        rect(W / 3, 0, W / 2 - W / 3, 12, '#E18BB9');

        var tx = 48;
        if (logo) { c.drawImage(logo, 48, 46, 64, 64); tx = 124; }
        text(tx, 70, 'bold 26px ' + SERIF, INK, 'Bella Vista Oasis');
        text(tx, 90, '9px ' + SANS, PURPLE, 'SPA, BATH & SANCTUARY');

        text(48, 150, 'bold 9px ' + SANS, MUTE, 'EXPEDITED SANCTUARY CHECK-IN');
        text(48, 182, 'bold 30px ' + SERIF, INK, 'Reservation Pass');

        rect(48, 204, 499, 34, '#FFFFFF');
        text(60, 226, 'bold 12px ' + SANS, PURPLE, 'Reference  ' + (p.code || ''));
        var st = String(p.status || 'confirmed');
        text(330, 226, '11px ' + SANS, INK, 'Status: ' + st.charAt(0).toUpperCase() + st.slice(1).replace(/_/g, ' '));

        /* QR */
        var q = window.qrcode(0, 'M');
        q.addData(g.payload); q.make();
        var n = q.getModuleCount(), cell = 6, mg = 3, qs = (n + 2 * mg) * cell, qx = 48, qy = 270;
        rect(qx, qy, qs, qs, '#FFFFFF');
        for (var r = 0; r < n; r++) {
            for (var col = 0; col < n; col++) {
                if (q.isDark(r, col)) { c.fillStyle = qrTone(r, col, n); c.fillRect(qx + (col + mg) * cell, qy + (r + mg) * cell, cell, cell); }
            }
        }
        text(qx, qy + qs + 16, '9px ' + SANS, MUTE, 'Valid until ' + (g.expires || ''));

        /* Details */
        var dx = qx + qs + 34, dy = 290, maxW = W - 48 - dx;
        var total = 'PHP ' + (p.total || '0.00') + (p.payment ? ' (payment ' + p.payment + ')' : '');
        var rows = [
            ['GUEST', p.guest], ['TREATMENT', p.service], ['DATE', p.date], ['TIME', p.time],
            ['SUITE', p.room || 'To be assigned'], ['THERAPIST', p.therapist || 'Any Master Therapist'],
            ['GUESTS', p.guests || 1], ['TOTAL', total]
        ];
        rows.forEach(function (row) {
            text(dx, dy, 'bold 8px ' + SANS, MUTE, row[0]);
            text(dx, dy + 14, '12px ' + SANS, INK, row[1], maxW);
            dy += 38;
        });

        /* Instructions: always below the details column */
        var iy = Math.max(qy + qs + 70, dy + 6);
        rect(48, iy - 14, 499, 84, '#FCE9F2');
        text(64, iy + 8, 'bold 11px ' + SANS, '#B23A63', 'Check-in instructions');
        text(64, iy + 28, '10px ' + SANS, INK, 'Please arrive ' + (p.arrive || 15) + ' minutes before your appointment.');
        text(64, iy + 43, '10px ' + SANS, INK, 'Present this QR code to reception for verification. Each code works once.');
        text(64, iy + 58, '10px ' + SANS, INK, 'Concierge: ' + (p.phone || ''));

        text(48, 788, '9px ' + SANS, MUTE, p.address || '', W - 96);
        text(48, 802, '9px ' + SANS, MUTE, 'Bella Vista Oasis Spa & Wellness Sanctuary');
        return cv;
    }

    /* One-page A4 PDF holding the pass image (no library needed). */
    function passPdf(cv) {
        var b64 = cv.toDataURL('image/jpeg', 0.92).split(',')[1];
        var bin = atob(b64), img = new Uint8Array(bin.length), i;
        for (i = 0; i < bin.length; i++) { img[i] = bin.charCodeAt(i); }
        var enc = function (str) { var u = new Uint8Array(str.length); for (var k = 0; k < str.length; k++) { u[k] = str.charCodeAt(k) & 255; } return u; };
        var chunks = [], offs = [], len = 0;
        function put(x) { var u = typeof x === 'string' ? enc(x) : x; chunks.push(u); len += u.length; }
        function obj(num, head, body) {
            offs[num] = len;
            put(num + ' 0 obj\n' + head);
            if (body) { put('\nstream\n'); put(body); put('\nendstream'); }
            put('\nendobj\n');
        }
        var content = 'q 595 0 0 842 0 0 cm /Im0 Do Q';
        put('%PDF-1.4\n');
        obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
        obj(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
        obj(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>');
        obj(4, '<< /Type /XObject /Subtype /Image /Width ' + cv.width + ' /Height ' + cv.height + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + img.length + ' >>', img);
        obj(5, '<< /Length ' + content.length + ' >>', content);
        var xref = len;
        put('xref\n0 6\n0000000000 65535 f \n');
        for (i = 1; i <= 5; i++) { put(('0000000000' + offs[i]).slice(-10) + ' 00000 n \n'); }
        put('trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF');
        return new Blob(chunks, { type: 'application/pdf' });
    }

    $('#btnQrDownload').addEventListener('click', function () {
        if (!gen.payload) { return; }
        loadLogo(function (logo) {
            var blob = passPdf(passCanvas(gen, logo));
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = gen.pass.code + '-pass.pdf';
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
        });
    });

    $('#btnQrPrint').addEventListener('click', function () {
        if (!gen.payload) { return; }
        loadLogo(function (logo) {
            var url = passCanvas(gen, logo).toDataURL('image/png');
            var f = document.createElement('iframe');
            f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
            document.body.appendChild(f);
            var d = f.contentDocument;
            d.open(); d.write('<!doctype html><html><head><meta charset="utf-8"><title>Reservation Pass</title>' +
                '<style>@page{size:A4;margin:0}html,body{margin:0;padding:0}img{display:block;width:210mm;height:297mm}</style></head><body></body></html>'); d.close();
            var im = d.createElement('img'); im.alt = 'Reservation pass';
            im.onload = function () {
                f.contentWindow.focus();
                f.contentWindow.print();
                setTimeout(function () { f.remove(); }, 2000);
            };
            d.body.appendChild(im);
            im.src = url;
        });
    });

    /* ------------------------------------------------------------------ reference / token input */
    $('#refForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var input = $('#refInput');
        var v = input.value.trim();
        if (!v) { toast('Enter a booking reference or QR token.'); input.focus(); return; }
        if (/^\d{1,6}$/.test(v)) { v = 'BVO-' + ('0000' + v).slice(-4); }
        verifyCode(v, 'ref').then(function () { input.select(); });
    });

    /* ------------------------------------------------------------------ manual check-in search */
    var manualModal = $('#manualModal');
    var manualQ = $('#manualQ');
    var manualTimer = null;
    var manualSeq = 0;
    function openManual() {
        openModal(manualModal);
        manualQ.focus();
    }
    function renderManual(list) {
        var ul = clear($('#manualResults'));
        if (!list.length) { ul.appendChild(el('li', 'empty', 'No appointments match that search.')); return; }
        list.forEach(function (r) {
            var li = el('li');
            var b = el('button', 'qr-result' + (r.selectable ? '' : ' is-blocked'));
            b.type = 'button';
            var left = el('div');
            left.appendChild(el('p', 'qr-result-name', r.guest + ' · ' + r.code));
            left.appendChild(el('p', 'qr-result-sub', r.service + ' · ' + r.date_label + ' · ' + r.time + (r.phone ? ' · ' + r.phone : '')));
            if (!r.selectable && r.reason) { left.appendChild(el('p', 'qr-result-why', r.reason)); }
            b.appendChild(left);
            b.appendChild(el('span', 'qr-badge k-' + r.arrival, r.arrival_label));
            b.addEventListener('click', function () { closeModal(manualModal); loadDetail(r.id); });
            li.appendChild(b);
            ul.appendChild(li);
        });
    }
    manualQ.addEventListener('input', function () {
        clearTimeout(manualTimer);
        var v = manualQ.value.trim();
        var seq = ++manualSeq;
        if (v.length < 2) { renderManual([]); clear($('#manualResults')).appendChild(el('li', 'empty', 'Type at least two characters to search.')); return; }
        manualTimer = setTimeout(function () {
            get('/qr-manual.php', { q: v }).then(function (j) {
                if (seq !== manualSeq) { return; }
                if (!j.ok) { throw new Error(j.error); }
                renderManual(j.results);
            }).catch(function () { clear($('#manualResults')).appendChild(el('li', 'empty', 'Search failed. Please try again.')); });
        }, 250);
    });
    $('#btnManual').addEventListener('click', openManual);

    /* ------------------------------------------------------------------ check-in window settings */
    var settingsModal = $('#settingsModal');
    var settingsForm = $('#settingsForm');
    $('#btnSettings').addEventListener('click', function () {
        $('#settingsError').hidden = true;
        get('/qr-settings.php').then(function (j) {
            if (!j.ok) { throw new Error(j.error); }
            $('#setEarly').value = j.settings.early;
            $('#setLate').value = j.settings.late;
            $('#setPending').checked = !!j.settings.allow_pending;
            openModal(settingsModal);
            $('#setEarly').focus();
        }).catch(function () { toast('Unable to load the check-in window.'); });
    });
    settingsForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var err = $('#settingsError'); err.hidden = true;
        var save = $('#settingsSave'); save.disabled = true;
        post('/qr-settings.php', {
            early_minutes: $('#setEarly').value,
            late_minutes: $('#setLate').value,
            allow_pending: $('#setPending').checked ? '1' : '0'
        }).then(function (j) {
            if (!j.ok) { err.textContent = j.error || 'Unable to save.'; err.hidden = false; return; }
            applyPolicy(j.settings);
            closeModal(settingsModal);
            toast('Check-in window updated.');
            refreshAll(false);
            if (mode === 'generate') { loadGenList(); }
        }).catch(function () { err.textContent = 'Network problem. Please try again.'; err.hidden = false; })
            .then(function () { save.disabled = false; });
    });

    /* ------------------------------------------------------------------ boot + auto refresh */
    spotEmpty();
    showView('idle');
    paintScanUi();
    refreshAll(true);

    setInterval(function () {
        if (document.hidden || busy || openModals.length) { return; }
        refreshAll(false);
    }, POLL_MS);
}());