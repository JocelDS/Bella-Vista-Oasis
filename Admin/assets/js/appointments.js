(function () {
    'use strict';

    var body = document.body;
    var apiBase = body.getAttribute('data-api') || '';
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    var toastEl = $('#toast');
    var toastTimer = null;
    function toast(msg) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3400);
    }

    function api(path, options) {
        return fetch(apiBase + path, Object.assign({ credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, options || {}))
            .then(function (res) {
                if (res.status === 401) { window.location.reload(); throw new Error('unauthorized'); }
                return res.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; });
            });
    }

    function post(path, data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        fd.append('csrf', csrf);
        return api(path, { method: 'POST', body: fd });
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
        m.addEventListener('mousedown', function (ev) { if (ev.target === m) { closeModal(m); } });
        $$('[data-close]', m).forEach(function (b) { b.addEventListener('click', function () { closeModal(m); }); });
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
            hideMenu();
            if (openModals.length) { closeModal(openModals[openModals.length - 1]); }
        }
    });

    var state = { status: 'all', q: '', date: '', therapist: '', service: '', page: 1, per_page: 10, pages: 1 };
    var bodyEl = $('#apptBody');
    var summaryEl = $('#apptSummary');
    var pagerEl = $('#apptPager');
    var loadingEl = $('#apptLoading');
    var controller = null;
    var searchTimer = null;

    function renderPager() {
        pagerEl.innerHTML = '';
        function btn(label, page, opts) {
            var b = el('button', 'pg' + (opts.active ? ' is-active' : ''), label);
            b.type = 'button';
            if (opts.aria) { b.setAttribute('aria-label', opts.aria); }
            if (opts.active) { b.setAttribute('aria-current', 'page'); }
            if (opts.disabled) { b.disabled = true; }
            b.addEventListener('click', function () {
                if (page !== state.page) { state.page = page; load(); }
            });
            return b;
        }
        pagerEl.appendChild(btn('Previous', state.page - 1, { disabled: state.page <= 1, aria: 'Previous page' }));
        var start = Math.max(1, state.page - 2);
        var end = Math.min(state.pages, start + 4);
        start = Math.max(1, end - 4);
        for (var i = start; i <= end; i++) {
            pagerEl.appendChild(btn(String(i), i, { active: i === state.page, aria: 'Page ' + i }));
        }
        pagerEl.appendChild(btn('Next', state.page + 1, { disabled: state.page >= state.pages, aria: 'Next page' }));
    }

    function applyPayload(d) {
        bodyEl.innerHTML = d.html;
        summaryEl.textContent = d.summary;
        state.page = d.page;
        state.pages = d.pages;
        renderPager();
        if (d.counts) {
            $$('[data-count]').forEach(function (n) {
                var k = n.getAttribute('data-count');
                n.textContent = d.counts[k] !== undefined ? d.counts[k] : 0;
            });
        }
        if (d.stats) {
            $$('[data-stat]').forEach(function (n) {
                var k = n.getAttribute('data-stat');
                if (d.stats[k] !== undefined) { n.textContent = d.stats[k]; }
            });
        }
    }

    function load() {
        if (controller) { controller.abort(); }
        controller = new AbortController();
        var params = new URLSearchParams({
            status: state.status, q: state.q, date: state.date, therapist: state.therapist,
            service: state.service, page: String(state.page), per_page: String(state.per_page)
        });
        loadingEl.hidden = false;
        api('/appointments.php?' + params.toString(), { signal: controller.signal })
            .then(function (d) {
                if (!d.ok) { throw new Error(d.error || 'Request failed'); }
                applyPayload(d);
            })
            .catch(function (err) {
                if (err && (err.name === 'AbortError' || err.message === 'unauthorized')) { return; }
                toast('Unable to load appointments. Please try again.');
            })
            .then(function () { loadingEl.hidden = true; });
    }

    state.page = parseInt(pagerEl.getAttribute('data-page') || '1', 10);
    state.pages = parseInt(pagerEl.getAttribute('data-pages') || '1', 10);
    renderPager();

    // Deep link from Customer Management: appointments.php?q=BVO-0007
    try {
        var deepQ = new URLSearchParams(window.location.search).get('q');
        var searchBox = $('#fSearch');
        if (deepQ && searchBox) {
            state.q = deepQ.slice(0, 100);
            searchBox.value = state.q;
            load();
        }
    } catch (e) { /* ignore */ }

    $$('#statusTabs .tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            $$('#statusTabs .tab').forEach(function (t) {
                var on = t === tab;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            state.status = tab.getAttribute('data-status') || 'all';
            state.page = 1;
            load();
        });
    });

    var fSearch = $('#fSearch');
    var fDate = $('#fDate');
    var fTherapist = $('#fTherapist');
    var fService = $('#fService');

    fSearch.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { state.q = fSearch.value.trim(); state.page = 1; load(); }, 300);
    });
    fDate.addEventListener('change', function () { state.date = fDate.value; state.page = 1; load(); });
    fTherapist.addEventListener('change', function () { state.therapist = fTherapist.value; state.page = 1; load(); });
    fService.addEventListener('change', function () { state.service = fService.value; state.page = 1; load(); });
    $('#perPage').addEventListener('change', function (ev) {
        state.per_page = parseInt(ev.target.value, 10) || 10;
        state.page = 1;
        load();
    });
    $('#fReset').addEventListener('click', function () {
        fSearch.value = '';
        fDate.value = '';
        fTherapist.value = '';
        fService.value = '';
        state.q = ''; state.date = ''; state.therapist = ''; state.service = ''; state.status = 'all'; state.page = 1;
        $$('#statusTabs .tab').forEach(function (t) {
            var on = t.getAttribute('data-status') === 'all';
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        load();
    });

    var globalSearch = $('#globalSearch');
    var globalInput = $('#globalSearchInput');
    if (globalSearch && globalInput) {
        globalSearch.addEventListener('submit', function (ev) {
            ev.preventDefault();
            fSearch.value = globalInput.value;
            state.q = globalInput.value.trim();
            state.page = 1;
            load();
        });
    }

    var confirmModal = $('#confirmModal');
    var confirmCallback = null;
    function askConfirm(title, text, label, cb) {
        $('#confirmTitle').textContent = title;
        $('#confirmText').textContent = text;
        $('#confirmYes').textContent = label;
        confirmCallback = cb;
        openModal(confirmModal);
        $('#confirmYes').focus();
    }
    $('#confirmNo').addEventListener('click', function () { confirmCallback = null; closeModal(confirmModal); });
    $('#confirmYes').addEventListener('click', function () {
        var cb = confirmCallback;
        confirmCallback = null;
        closeModal(confirmModal);
        if (cb) { cb(); }
    });

    var ACTIONS = {
        view:     { label: 'View Details' },
        edit:     { label: 'Edit Appointment' },
        confirm:  { label: 'Confirm Appointment', title: 'Confirm appointment', text: 'Confirm this appointment? The guest will be marked as confirmed.', button: 'Confirm' },
        complete: { label: 'Mark as Completed', title: 'Mark as completed', text: 'Mark this appointment as completed? This cannot be undone from this list.', button: 'Mark Completed' },
        no_show:  { label: 'Mark as No Show', title: 'Mark as no show', text: 'Mark this guest as a no show? This cannot be undone from this list.', button: 'Mark No Show', danger: true },
        cancel:   { label: 'Cancel Appointment', title: 'Cancel appointment', text: 'Cancel this appointment? This cannot be undone from this list.', button: 'Cancel Appointment', danger: true },
        'delete': { label: 'Delete Appointment', title: 'Delete appointment', text: 'Permanently delete this appointment together with its payment records and check-in history? This cannot be undone.', button: 'Delete Permanently', danger: true }
    };
    var ORDER = ['view', 'edit', 'confirm', 'complete', 'no_show', 'cancel', 'delete'];

    var menu = $('#rowMenuPop');
    var menuBtn = null;
    function hideMenu() {
        menu.hidden = true;
        if (menuBtn) { menuBtn.classList.remove('is-open'); menuBtn.setAttribute('aria-expanded', 'false'); }
        menuBtn = null;
    }

    function runAction(action, id) {
        var def = ACTIONS[action];
        post('/appointment-action.php', { action: action, id: String(id) }).then(function (d) {
            if (!d.ok) { toast(d.error || 'Action failed.'); return; }
            toast(d.message || def.label + ' done.');
            load();
            if (!$('#detailModal').hidden) { closeModal($('#detailModal')); }
        }).catch(function () { toast('Network error. Please try again.'); });
    }

    function handleAction(action, id) {
        if (action === 'view') { openDetail(id); return; }
        if (action === 'edit') { openEdit(id); return; }
        var def = ACTIONS[action];
        if (action === 'cancel') {
            post('/appointment-action.php', { action: 'cancel_preview', id: String(id) }).then(function (d) {
                if (!d.ok) { toast(d.error || 'Action failed.'); return; }
                askConfirm(d.late ? 'Late cancellation' : def.title, d.message + ' Cancel this appointment?', def.button, function () { runAction(action, id); });
            }).catch(function () { toast('Network error. Please try again.'); });
            return;
        }
        askConfirm(def.title, def.text, def.button, function () { runAction(action, id); });
    }

    bodyEl.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.row-menu');
        if (!btn) { return; }
        ev.stopPropagation();
        if (menuBtn === btn && !menu.hidden) { hideMenu(); return; }
        hideMenu();

        var id = parseInt(btn.getAttribute('data-id'), 10);
        var allowed = (btn.getAttribute('data-actions') || '').split(',').filter(Boolean);
        menu.innerHTML = '';
        ORDER.forEach(function (key) {
            if (key !== 'view' && allowed.indexOf(key) === -1) { return; }
            if (key === 'cancel' || key === 'no_show' || key === 'delete') {
                if (!menu.querySelector('.dd-sep')) { menu.appendChild(el('div', 'dd-sep')); }
            }
            var item = el('button', 'dd-item' + (ACTIONS[key].danger ? ' dd-danger' : ''), ACTIONS[key].label);
            item.type = 'button';
            item.setAttribute('role', 'menuitem');
            item.addEventListener('click', function () { hideMenu(); handleAction(key, id); });
            menu.appendChild(item);
        });

        menu.hidden = false;
        var r = btn.getBoundingClientRect();
        var w = menu.offsetWidth;
        var h = menu.offsetHeight;
        var left = Math.min(Math.max(8, r.right - w), window.innerWidth - w - 8);
        var top = r.bottom + 6;
        if (top + h > window.innerHeight - 8) { top = Math.max(8, r.top - h - 6); }
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
        btn.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        menuBtn = btn;
    });
    document.addEventListener('click', function (ev) { if (!menu.contains(ev.target)) { hideMenu(); } });
    window.addEventListener('resize', hideMenu);
    window.addEventListener('scroll', hideMenu, true);

    (function () {
        var pay = document.getElementById('ap_pay');
        var wrap = document.getElementById('apDownWrap');
        var down = document.getElementById('ap_down');
        if (!pay || !wrap || !down) { return; }
        function sync() {
            var on = pay.value === 'deposit';
            wrap.hidden = !on;
            down.required = on;
            if (!on) { down.value = ''; }
        }
        pay.addEventListener('change', sync);
        if (pay.form) { pay.form.addEventListener('reset', function () { setTimeout(sync, 0); }); }
        sync();
    }());

    var detailModal = $('#detailModal');
    function dtItem(dl, label, value, sub) {
        var wrap = el('div');
        wrap.appendChild(el('dt', null, label));
        var dd = el('dd', null, value);
        if (sub) { dd.appendChild(el('span', 'sub', sub)); }
        wrap.appendChild(dd);
        dl.appendChild(wrap);
    }

    function openDetail(id) {
        api('/appointment-detail.php?id=' + encodeURIComponent(id)).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load the appointment.'); return; }
            var a = d.appointment;
            $('#detailKicker').textContent = 'Appointment #' + a.code;
            $('#detailTitle').textContent = a.customer_name;

            var box = $('#detailBody');
            box.innerHTML = '';
            var badges = el('div', 'detail-badges');
            badges.appendChild(el('span', 'badge st-' + a.status, a.status_label));
            badges.appendChild(el('span', 'badge pay-' + a.payment_status, 'Payment: ' + a.payment_label));
            box.appendChild(badges);

            var dl = el('dl', 'detail-grid');
            var contact = [a.customer_email, a.customer_phone].filter(Boolean);
            dtItem(dl, 'Customer', a.customer_name, contact.join(' · '));
            dtItem(dl, 'Service', a.service_name, a.duration + ' min');
            dtItem(dl, 'Date', a.date_label);
            dtItem(dl, 'Time', a.start_label + ' – ' + a.end_label);
            dtItem(dl, 'Therapist', a.therapist_name);
            dtItem(dl, 'Room', a.room_name);
            dtItem(dl, 'Total Amount', a.total_label);
            dtItem(dl, 'Amount Received', a.paid_label);
            dtItem(dl, 'Downpayment', a.downpayment_label, a.forfeited ? 'Forfeited (late cancellation or reschedule)' : '');
            dtItem(dl, 'Balance Due', a.balance_label);
            box.appendChild(dl);

            box.appendChild(el('p', 'detail-h', 'Payment Records'));
            if (!a.payments.length) {
                box.appendChild(el('p', 'cell-sub', 'No payment records yet.'));
            } else {
                var ul = el('ul', 'pay-list');
                a.payments.forEach(function (p) {
                    var li = el('li');
                    li.appendChild(el('span', null, p.amount + ' · ' + p.status));
                    li.appendChild(el('span', 'muted', p.method + ' · ' + p.date));
                    ul.appendChild(li);
                });
                box.appendChild(ul);
            }

            var foot = $('#detailFoot');
            foot.innerHTML = '';
            a.actions.forEach(function (key) {
                if (!ACTIONS[key]) { return; }
                var kind = key === 'edit' || key === 'confirm' || key === 'complete' ? 'btn-primary' : (key === 'cancel' || key === 'delete' ? 'btn-outline btn-danger-soft' : 'btn-outline');
                var b = el('button', 'btn ' + kind, ACTIONS[key].label);
                b.type = 'button';
                b.addEventListener('click', function () { closeModal(detailModal); handleAction(key, a.id); });
                foot.appendChild(b);
            });
            openModal(detailModal);
        }).catch(function () { toast('Unable to load the appointment.'); });
    }

    var apptModal = $('#apptModal');
    var form = $('#apptForm');
    var errBox = $('#apptError');
    var submitBtn = $('#apptSubmit');
    var fAction = $('#ap_action');
    var fId = $('#ap_id');
    var fService2 = $('#ap_service');
    var fStart = $('#ap_start');
    var fEnd = $('#ap_end');
    var fAmount = $('#ap_amount');
    var fDate2 = $('#ap_date');
    fDate2.setAttribute('data-today', fDate2.min);

    function showErr(msg) { errBox.textContent = msg; errBox.hidden = false; }

    function syncFromService(updateAmount) {
        var opt = fService2.options[fService2.selectedIndex];
        if (!opt || !opt.value) { return; }
        if (updateAmount) { fAmount.value = opt.getAttribute('data-price') || ''; }
        var dur = parseInt(opt.getAttribute('data-duration') || '0', 10);
        if (dur > 0 && fStart.value) {
            var p = fStart.value.split(':');
            var mins = parseInt(p[0], 10) * 60 + parseInt(p[1], 10) + dur;
            if (mins < 1440) {
                var hh = String(Math.floor(mins / 60)).padStart(2, '0');
                var mm = String(mins % 60).padStart(2, '0');
                fEnd.value = hh + ':' + mm;
            } else {
                fEnd.value = '';
            }
        }
    }
    fService2.addEventListener('change', function () { syncFromService(true); });
    fStart.addEventListener('change', function () { syncFromService(false); });

    function resetForm() {
        form.reset();
        errBox.hidden = true;
        fId.value = '';
        fAction.value = 'create';
        var t = fDate2.getAttribute('data-today');
        fDate2.min = t;
        fDate2.value = t;
    }

    $('#openAppt').addEventListener('click', function () {
        resetForm();
        $('#apptModalKicker').textContent = 'Reservation Desk';
        $('#apptModalTitle').textContent = 'New Appointment';
        openModal(apptModal);
        $('#ap_customer').focus();
    });

    function openEdit(id) {
        api('/appointment-detail.php?id=' + encodeURIComponent(id)).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load the appointment.'); return; }
            var a = d.appointment;
            if (a.actions.indexOf('edit') === -1) { toast('Only pending or confirmed appointments can be edited.'); return; }
            resetForm();
            fAction.value = 'update';
            fId.value = String(a.id);
            $('#ap_customer').value = String(a.customer_id);
            fService2.value = String(a.service_id);
            $('#ap_health').value = a.health_conditions || '';
            $('#ap_injuries').value = a.health_injuries || '';
            $('#ap_preg').value = (a.health_conditions || a.health_injuries) ? String(a.is_pregnant) : '';
            $('#ap_therapist').value = String(a.therapist_id);
            $('#ap_room').value = String(a.room_id);
            fDate2.min = a.date < fDate2.getAttribute('data-today') ? a.date : fDate2.getAttribute('data-today');
            fDate2.value = a.date;
            fStart.value = a.start;
            fEnd.value = a.end;
            fAmount.value = a.total;
            $('#ap_pay').value = a.payment_status === 'refunded' ? 'pending' : a.payment_status;
            $('#ap_pay').dispatchEvent(new Event('change'));
            $('#ap_down').value = a.payment_status === 'deposit' ? a.downpayment : '';
            $('#ap_status').value = a.status;
            $('#apptModalKicker').textContent = 'Appointment #' + a.code;
            $('#apptModalTitle').textContent = 'Edit Appointment';
            openModal(apptModal);
        }).catch(function () { toast('Unable to load the appointment.'); });
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        errBox.hidden = true;
        var missing = $$('select[required], input[required], textarea[required]', form).some(function (f) { return !f.value; });
        if (missing) { showErr('Please complete every required field.'); return; }

        save(false);
    });

    function save(confirmLate) {
        submitBtn.disabled = true;
        var fd = new FormData(form);
        if (confirmLate) { fd.append('confirm_late', '1'); }
        api('/appointment-action.php', { method: 'POST', body: fd }).then(function (d) {
            if (!d.ok) {
                if (d.code === 'late_notice') {
                    askConfirm('Late reschedule', d.error, 'Reschedule anyway', function () { save(true); });
                    return;
                }
                showErr(d.error || 'Unable to save the appointment.');
                return;
            }
            closeModal(apptModal);
            toast(d.message || 'Appointment saved.');
            load();
        }).catch(function () { showErr('Network error. Please try again.'); })
            .then(function () { submitBtn.disabled = false; });
    }

    var availModal = $('#availModal');
    var availDate = $('#availDate');

    function statusSelect(type, id, current, options) {
        var sel = el('select', 'ctl ctl-sm');
        sel.setAttribute('aria-label', 'Change ' + type + ' status');
        options.forEach(function (o) {
            var opt = el('option', null, o[1]);
            opt.value = o[0];
            if (o[0] === current) { opt.selected = true; }
            sel.appendChild(opt);
        });
        if (!options.some(function (o) { return o[0] === current; })) {
            var extra = el('option', null, current.replace(/_/g, ' '));
            extra.value = current;
            extra.selected = true;
            extra.disabled = true;
            sel.appendChild(extra);
        }
        sel.addEventListener('change', function () {
            sel.disabled = true;
            post('/availability.php', { type: type, id: String(id), status: sel.value }).then(function (d) {
                toast(d.ok ? (d.message || 'Availability updated.') : (d.error || 'Update failed.'));
                loadAvailability();
            }).catch(function () { toast('Network error. Please try again.'); loadAvailability(); });
        });
        return sel;
    }

    function renderAvailItem(type, item, options) {
        var li = el('li', 'avail-item');
        var top = el('div', 'avail-top');
        var info = el('div');
        info.appendChild(el('p', 'avail-name', item.name));
        if (item.type) { info.appendChild(el('p', 'avail-type', item.type)); }
        top.appendChild(info);
        top.appendChild(statusSelect(type, item.id, item.status, options));
        li.appendChild(top);

        var slots = el('div', 'avail-slots');
        if (!item.slots.length) {
            slots.appendChild(el('span', 'free', 'No bookings on this date'));
        } else {
            item.slots.forEach(function (s) {
                var row = el('div', 'slot', s.time);
                if (s.customer) { row.appendChild(el('span', 'who', ' · ' + s.customer)); }
                slots.appendChild(row);
            });
        }
        li.appendChild(slots);
        return li;
    }

    function loadAvailability() {
        api('/availability.php?date=' + encodeURIComponent(availDate.value || '')).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load availability.'); return; }
            $('#availDateLabel').textContent = d.date_label;
            var rooms = $('#availRooms');
            var ths = $('#availTherapists');
            rooms.innerHTML = '';
            ths.innerHTML = '';
            d.rooms.forEach(function (r) {
                rooms.appendChild(renderAvailItem('room', r, [['available', 'Available'], ['cleaning', 'Cleaning'], ['maintenance', 'Maintenance']]));
            });
            d.therapists.forEach(function (t) {
                ths.appendChild(renderAvailItem('therapist', t, [['available', 'Available'], ['on_leave', 'On leave']]));
            });
            if (!d.rooms.length) { rooms.appendChild(el('li', 'empty', 'No rooms configured.')); }
            if (!d.therapists.length) { ths.appendChild(el('li', 'empty', 'No therapists configured.')); }
        }).catch(function () { toast('Unable to load availability.'); });
    }

    $('#openAvailability').addEventListener('click', function () {
        openModal(availModal);
        loadAvailability();
    });
    availDate.addEventListener('change', loadAvailability);
    availModal.addEventListener('mousedown', function (ev) {
        if (ev.target === availModal) { load(); }
    });
    $$('[data-close]', availModal).forEach(function (b) { b.addEventListener('click', load); });
}());