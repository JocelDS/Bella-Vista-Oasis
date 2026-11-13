(function () {
    'use strict';

    var body = document.body;
    var apiBase = body.getAttribute('data-api') || '';
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var app = document.getElementById('calApp');
    if (!app) { return; }

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    var STATE_LABEL = { session: 'In Session', confirmed: 'Confirmed', pending: 'Pending', completed: 'Completed', cancelled: 'Cancelled', no_show: 'No Show' };
    var CARD_H = 76;
    var LANE_GAP = 6;

    function pad(n) { return n < 10 ? '0' + n : String(n); }
    function fmtTime(m) {
        m = Math.round(m);
        var h = Math.floor(m / 60) % 24;
        var mm = m % 60;
        var ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + pad(mm) + ' ' + ap;
    }
    function hhmm(m) { return pad(Math.floor(m / 60)) + ':' + pad(m % 60); }
    function fmtDur(m) {
        if (m < 60) { return m + ' min'; }
        var h = Math.floor(m / 60);
        var r = m % 60;
        return h + 'h' + (r ? ' ' + r + 'm' : '');
    }
    function initials(name) {
        var w = String(name || '').match(/[A-Za-zÀ-ɏ]+/g) || [];
        if (!w.length) { return '?'; }
        return (w[0][0] + (w.length > 1 ? w[w.length - 1][0] : '')).toUpperCase();
    }
    function addDays(str, n) {
        var d = new Date(str + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().slice(0, 10);
    }
    function dateShort(str) {
        var d = new Date(str + 'T00:00:00Z');
        return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' });
    }

    var toastEl = $('#toast');
    var toastTimer = null;
    function toast(msg) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3600);
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
        if (ev.key === 'Escape' && openModals.length) { closeModal(openModals[openModals.length - 1]); }
    });

    var confirmModal = $('#confirmModal');
    var confirmCb = null;
    function askConfirm(title, text, label, cb) {
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

    var state = {
        view: app.getAttribute('data-view') || 'multi',
        date: app.getAttribute('data-date'),
        room: app.getAttribute('data-room') || '',
        therapist: app.getAttribute('data-therapist') || '',
        status: app.getAttribute('data-status') || '',
        service: app.getAttribute('data-service') || ''
    };
    var today = app.getAttribute('data-today');
    var data = null;
    var loadedAt = 0;
    var selectedId = null;
    var controller = null;
    var nowTimer = null;
    var detailCache = null;

    var bodyEl = $('#calBody');
    var loadingEl = $('#calLoading');
    var dateLabel = $('#dateLabel');
    var datePick = $('#datePick');
    var fRoom = $('#cfRoom');
    var fTherapist = $('#cfTherapist');
    var fStatus = $('#cfStatus');
    var fService = $('#cfService');

    fRoom.value = state.room;
    fTherapist.value = state.therapist;
    fStatus.value = state.status;
    fService.value = state.service;

    function queryString() {
        var p = new URLSearchParams();
        p.set('view', state.view);
        p.set('date', state.date);
        ['room', 'therapist', 'status', 'service'].forEach(function (k) { if (state[k]) { p.set(k, state[k]); } });
        return p;
    }
    function syncUrl() {
        try { history.replaceState(null, '', '?' + queryString().toString()); } catch (e) {}
    }

    function load() {
        if (controller) { controller.abort(); }
        controller = new AbortController();
        loadingEl.hidden = false;
        syncUrl();
        api('/calendar.php?' + queryString().toString(), { signal: controller.signal })
            .then(function (d) {
                if (!d.ok) { throw new Error(d.error || 'Request failed'); }
                data = d;
                loadedAt = Date.now();
                render();
                var still = selectedId && data.appointments.some(function (a) { return a.id === selectedId; });
                if (still) { selectAppt(selectedId, false); } else { pickDefault(); }
            })
            .catch(function (err) {
                if (err && (err.name === 'AbortError' || err.message === 'unauthorized')) { return; }
                toast('Unable to load the calendar. Please try again.');
            })
            .then(function () { loadingEl.hidden = true; });
    }

    function render() {
        $$('#viewSwitch .seg-btn').forEach(function (b) {
            var on = b.getAttribute('data-view') === state.view;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        dateLabel.textContent = data.label;
        datePick.value = state.date;

        var titles = { multi: 'Suite Timeline Matrix', day: 'Daily Sanctuary Timeline', week: 'Weekly Sanctuary Schedule' };
        var kickers = { multi: 'Live Room Schedule', day: 'Hour-by-hour Bookings', week: 'All Suites · Seven Days' };
        $('#matrixTitle').textContent = titles[state.view];
        $('#calKicker').textContent = kickers[state.view];
        $('#calHint').textContent = state.view === 'multi' ? 'Click any open slot to reserve or a card to inspect' : (state.view === 'week' ? 'Click a day to open it, or a booking to inspect' : 'Click a booking to inspect');
        $('#calHours').textContent = state.view === 'week' ? '7 Days' : ((data.axis.end - data.axis.start) / 60) + ' Operating Hours';

        var s = data.summary;
        var parts = [s.total + (s.total === 1 ? ' appointment' : ' appointments')];
        ['session', 'confirmed', 'pending', 'completed', 'cancelled', 'no_show'].forEach(function (k) {
            if (s[k]) { parts.push(s[k] + ' ' + STATE_LABEL[k].toLowerCase()); }
        });
        $('#calSummary').textContent = parts.join(' · ');

        bodyEl.innerHTML = '';
        if (state.view === 'multi') { bodyEl.appendChild(renderMatrix()); }
        else if (state.view === 'day') { bodyEl.appendChild(renderDay()); }
        else { bodyEl.appendChild(renderWeek()); }

        renderOpenings();
        renderRoster();
        startNowTicker();
        markSelected();
    }

    function nowMinute() {
        if (data.now_min === null) { return null; }
        return data.now_min + (Date.now() - loadedAt) / 60000;
    }

    function assignLanes(items) {
        var lanes = [];
        var out = [];
        items.slice().sort(function (a, b) { return a.start - b.start || a.id - b.id; }).forEach(function (it) {
            var lane = 0;
            while (lane < lanes.length && lanes[lane] > it.start) { lane++; }
            lanes[lane] = it.end;
            out.push({ item: it, lane: lane });
        });
        return { placed: out, count: Math.max(1, lanes.length) };
    }

    function pct(min, axis) { return ((min - axis.start) / (axis.end - axis.start)) * 100; }

    function renderMatrix() {
        var axis = data.axis;
        var hours = (axis.end - axis.start) / 60;
        var mx = el('div', 'mx');
        mx.style.setProperty('--hours', String(hours));

        var head = el('div', 'mx-head');
        head.appendChild(el('div', 'mx-corner', 'Suites / Rooms'));
        var hh = el('div', 'mx-hours');
        for (var m = axis.start; m < axis.end; m += 60) { hh.appendChild(el('div', 'mx-hour', fmtTime(m))); }
        head.appendChild(hh);
        mx.appendChild(head);

        if (!data.rooms.length) {
            mx.appendChild(el('p', 'empty', 'No suites match the selected filter.'));
        }

        data.rooms.forEach(function (room) {
            var off = room.status !== 'available';
            var row = el('div', 'mx-row' + (off ? ' is-off' : ''));

            var label = el('div', 'mx-room');
            label.appendChild(el('p', 'mx-room-name', room.name));
            label.appendChild(el('p', 'mx-room-type', room.type));
            var meta = el('div', 'mx-room-meta');
            var badge = el('span', 'badge rs-' + (['available', 'cleaning', 'maintenance'].indexOf(room.status) > -1 ? room.status : 'other'));
            badge.appendChild(el('span', 'dot'));
            badge.appendChild(document.createTextNode(room.status.charAt(0).toUpperCase() + room.status.slice(1).replace(/_/g, ' ')));
            meta.appendChild(badge);
            meta.appendChild(el('span', null, room.count + (room.count === 1 ? ' booking' : ' bookings')));
            label.appendChild(meta);
            row.appendChild(label);

            var track = el('div', 'mx-track');
            track.setAttribute('data-room', String(room.id));
            var items = data.appointments.filter(function (a) { return a.room_id === room.id && a.date === data.date; });
            var laid = assignLanes(items);
            track.style.height = (14 + laid.count * (CARD_H + LANE_GAP)) + 'px';

            room.windows.forEach(function (w) {
                var o = el('div', 'mx-open' + (w.is_now ? ' is-now' : ''));
                o.style.left = 'calc(' + pct(w.start, axis) + '% + 3px)';
                o.style.width = 'calc(' + (pct(w.end, axis) - pct(w.start, axis)) + '% - 6px)';
                o.textContent = (w.is_now ? 'Available now · ' : 'Available · ') + fmtDur(w.minutes);
                track.appendChild(o);
            });

            var active = laid.placed.filter(function (p) { return p.item.status !== 'cancelled' && p.item.status !== 'no_show'; });
            active.forEach(function (p) {
                var nextStart = axis.end;
                active.forEach(function (q) { if (q.item.start >= p.item.end && q.item.start < nextStart) { nextStart = q.item.start; } });
                var gapEnd = Math.min(p.item.end + data.gap, nextStart, axis.end);
                if (gapEnd - p.item.end < 5) { return; }
                var g = el('div', 'mx-gap');
                g.title = data.gap + '-minute sanitation gap';
                g.style.left = 'calc(' + pct(p.item.end, axis) + '% + 2px)';
                g.style.width = 'calc(' + (pct(gapEnd, axis) - pct(p.item.end, axis)) + '% - 4px)';
                g.style.top = (7 + p.lane * (CARD_H + LANE_GAP)) + 'px';
                g.style.height = CARD_H + 'px';
                track.appendChild(g);
            });

            laid.placed.forEach(function (p) {
                var a = p.item;
                var c = el('button', 'mx-card is-' + a.state);
                c.type = 'button';
                c.setAttribute('data-id', String(a.id));
                c.style.left = 'calc(' + pct(a.start, axis) + '% + 2px)';
                c.style.width = 'calc(' + (pct(a.end, axis) - pct(a.start, axis)) + '% - 4px)';
                c.style.top = (7 + p.lane * (CARD_H + LANE_GAP)) + 'px';
                c.style.height = CARD_H + 'px';
                c.title = a.customer + ' · ' + a.service + '\n' + fmtTime(a.start) + ' – ' + fmtTime(a.end) + ' · ' + a.therapist + '\n' + (STATE_LABEL[a.state] || a.status_label) + ' · ' + a.code;
                var top = el('span', 'mx-card-top');
                top.appendChild(el('span', 'mx-tag', STATE_LABEL[a.state] || a.status_label));
                top.appendChild(el('span', 'mx-time', fmtTime(a.start) + ' – ' + fmtTime(a.end)));
                c.appendChild(top);
                c.appendChild(el('strong', 'mx-name', a.customer + (a.pregnant ? ' · Pregnant' : '')));
                c.appendChild(el('span', 'mx-svc', a.service + ' · ' + a.duration + 'm'));
                c.appendChild(el('span', 'mx-ther', a.therapist));
                c.addEventListener('click', function (ev) { ev.stopPropagation(); selectAppt(a.id, true); });
                track.appendChild(c);
            });

            if (off) {
                track.appendChild(el('div', 'mx-off', room.status === 'maintenance' ? 'Under maintenance · not accepting bookings' : 'Cleaning in progress · not accepting bookings'));
            }

            track.addEventListener('click', function (ev) { onTrackClick(room, track, ev); });
            row.appendChild(track);
            mx.appendChild(row);
        });

        var nowWrap = el('div', 'mx-now');
        nowWrap.id = 'mxNow';
        nowWrap.hidden = true;
        nowWrap.appendChild(el('div', 'mx-now-line'));
        nowWrap.appendChild(el('div', 'mx-now-tag'));
        mx.appendChild(nowWrap);
        return mx;
    }

    function onTrackClick(room, track, ev) {
        if (ev.target.closest('.mx-card')) { return; }
        if (room.status !== 'available') {
            toast(room.name + ' is ' + room.status + ' and cannot take bookings.');
            return;
        }
        if (state.date < today) {
            toast('Past dates cannot take new bookings.');
            return;
        }
        var axis = data.axis;
        var rect = track.getBoundingClientRect();
        var t = axis.start + ((ev.clientX - rect.left) / rect.width) * (axis.end - axis.start);
        t = Math.floor(t / 15) * 15;
        var win = null;
        room.windows.forEach(function (w) { if (!win && t >= w.start && t < w.end) { win = w; } });
        if (!win) {
            toast('That time is occupied or inside the ' + data.gap + '-minute cleaning gap.');
            return;
        }
        t = Math.max(win.start, Math.min(t, win.end - data.min_slot));
        openBooking({ room: room.id, date: state.date, start: t });
    }

    function startNowTicker() {
        clearInterval(nowTimer);
        nowTimer = null;
        updateNow();
        if (data.now_min !== null && state.view !== 'week') { nowTimer = setInterval(updateNow, 30000); }
    }

    function updateNow() {
        var wrap = $('#mxNow');
        var nm = nowMinute();
        if (!wrap || nm === null) { return; }
        var axis = data.axis;
        if (nm < axis.start || nm > axis.end) { wrap.hidden = true; return; }
        wrap.hidden = false;
        var left = pct(nm, axis) + '%';
        wrap.firstChild.style.left = left;
        wrap.lastChild.style.left = left;
        wrap.lastChild.textContent = fmtTime(nm) + ' · LIVE';
    }

    function roomName(id) {
        var r = data.rooms.filter(function (x) { return x.id === id; })[0];
        return r ? r.name : '';
    }

    function dayCard(a) {
        var c = el('button', 'day-card is-' + a.state);
        c.type = 'button';
        c.setAttribute('data-id', String(a.id));
        var top = el('div', 'day-card-top');
        top.appendChild(el('span', 'day-time', fmtTime(a.start) + ' – ' + fmtTime(a.end) + ' · ' + fmtDur(a.end - a.start)));
        top.appendChild(el('span', 'mx-tag', STATE_LABEL[a.state] || a.status_label));
        c.appendChild(top);
        c.appendChild(el('strong', 'day-name', a.customer + (a.membership ? ' · ' + a.membership : '')));
        c.appendChild(el('span', 'day-line', a.service));
        c.appendChild(el('span', 'day-line', a.therapist + ' · ' + roomName(a.room_id)));
        var badges = el('div', 'day-badges');
        badges.appendChild(el('span', 'badge pay-' + a.payment_status, a.payment));
        badges.appendChild(el('span', 'day-line', a.amount));
        if (a.pregnant) { badges.appendChild(el('span', 'badge rs-cleaning', 'Pregnant')); }
        c.appendChild(badges);
        c.addEventListener('click', function () { selectAppt(a.id, true); });
        return c;
    }

    function renderDay() {
        var axis = data.axis;
        var list = el('div', 'day-list');
        var nm = nowMinute();
        for (var h = axis.start; h < axis.end; h += 60) {
            var hour = h;
            var row = el('div', 'day-row' + (nm !== null && nm >= hour && nm < hour + 60 ? ' is-now' : ''));
            row.appendChild(el('div', 'day-hour', fmtTime(hour)));
            var slots = el('div', 'day-slots');
            var items = data.appointments.filter(function (a) { return a.date === data.date && a.start >= hour && a.start < hour + 60; });
            if (!items.length) { slots.appendChild(el('span', 'day-empty', 'No appointments start this hour')); }
            items.forEach(function (a) { slots.appendChild(dayCard(a)); });
            row.appendChild(slots);
            list.appendChild(row);
        }
        return list;
    }

    function renderWeek() {
        var wk = el('div', 'wk');
        wk.appendChild(el('div', 'wk-corner', 'Suites / Rooms'));
        data.days.forEach(function (d) {
            var cell = el('div', 'wk-head' + (d.is_today ? ' is-today' : '') + (d.date === state.date ? ' is-picked' : ''));
            var b = el('button', 'wk-day');
            b.type = 'button';
            b.appendChild(el('span', null, d.weekday + ' · ' + d.month));
            b.appendChild(el('strong', null, d.day));
            b.addEventListener('click', function () { state.view = 'multi'; state.date = d.date; load(); });
            cell.appendChild(b);
            wk.appendChild(cell);
        });
        data.rooms.forEach(function (room) {
            var rc = el('div', 'wk-room');
            rc.appendChild(el('p', 'mx-room-name', room.name));
            rc.appendChild(el('p', 'mx-room-type', room.type));
            wk.appendChild(rc);
            data.days.forEach(function (d) {
                var cell = el('div', 'wk-cell' + (d.is_today ? ' is-today' : ''));
                var items = data.appointments.filter(function (a) { return a.room_id === room.id && a.date === d.date; });
                if (!items.length) { cell.appendChild(el('span', 'wk-none', '—')); }
                items.forEach(function (a) {
                    var chip = el('button', 'wk-chip is-' + a.state);
                    chip.type = 'button';
                    chip.setAttribute('data-id', String(a.id));
                    chip.title = a.customer + ' · ' + a.service + ' · ' + fmtTime(a.start) + ' – ' + fmtTime(a.end);
                    chip.appendChild(el('b', null, fmtTime(a.start)));
                    chip.appendChild(el('span', null, a.customer));
                    chip.appendChild(el('span', null, a.therapist));
                    chip.addEventListener('click', function () { selectAppt(a.id, true); });
                    cell.appendChild(chip);
                });
                wk.appendChild(cell);
            });
        });
        return wk;
    }

    function renderOpenings() {
        var list = $('#openList');
        list.innerHTML = '';
        $('#openKicker').textContent = 'Ready for Walk-ins · ' + dateShort(state.date);
        if (!data.openings.length) {
            var msg = state.date < today ? 'Past dates cannot take new bookings.' : 'No open suites for this date. Rooms under cleaning or maintenance are excluded.';
            list.appendChild(el('li', 'empty', msg));
            return;
        }
        data.openings.forEach(function (o) {
            var li = el('li', 'open-item' + (o.is_now ? ' is-now' : ''));
            var info = el('div', 'open-info');
            info.appendChild(el('p', 'open-room', o.room));
            info.appendChild(el('p', 'open-time', (o.is_now ? 'Open now until ' + fmtTime(o.end) : fmtTime(o.start) + ' – ' + fmtTime(o.end)) + ' · ' + fmtDur(o.minutes)));
            li.appendChild(info);
            var badge = el('span', 'badge rs-available');
            badge.appendChild(el('span', 'dot'));
            badge.appendChild(document.createTextNode('Available'));
            li.appendChild(badge);
            var b = el('button', 'btn btn-primary btn-book', 'Book');
            b.type = 'button';
            b.addEventListener('click', function () { openBooking({ room: o.room_id, date: state.date, start: o.start }); });
            li.appendChild(b);
            list.appendChild(li);
        });
    }

    function renderRoster() {
        var list = $('#rosterList');
        list.innerHTML = '';
        data.roster.forEach(function (t) {
            var li = el('li');
            var b = el('button', 'roster-btn' + (String(t.id) === state.therapist ? ' is-active' : ''));
            b.type = 'button';
            b.title = String(t.id) === state.therapist ? 'Show all therapists' : 'Filter the calendar by ' + t.name;
            b.appendChild(el('span', 'avatar avatar-sm', initials(t.name)));
            var info = el('span', 'roster-info');
            info.appendChild(el('span', 'roster-name', t.name));
            info.appendChild(document.createElement('br'));
            var sub = t.specialization + ' · ' + t.count + (t.count === 1 ? ' appointment' : ' appointments') + (t.next !== null ? ' · next ' + fmtTime(t.next) : '');
            info.appendChild(el('span', 'roster-sub', sub));
            b.appendChild(info);
            var key = t.status === 'on_leave' ? 'on_leave' : (t.in_session ? 'session' : 'available');
            var label = key === 'on_leave' ? 'On leave' : (key === 'session' ? 'In session' : 'Available');
            b.appendChild(el('span', 'badge ts-' + key, label));
            b.addEventListener('click', function () {
                state.therapist = String(t.id) === state.therapist ? '' : String(t.id);
                fTherapist.value = state.therapist;
                load();
            });
            li.appendChild(b);
            list.appendChild(li);
        });
    }

    function markSelected() {
        $$('[data-id]', bodyEl).forEach(function (n) {
            n.classList.toggle('is-selected', selectedId !== null && n.getAttribute('data-id') === String(selectedId));
        });
    }

    function pickDefault() {
        var items = data.appointments.filter(function (a) { return a.date === data.date; });
        if (!items.length) { items = data.appointments; }
        if (!items.length) { clearInspect(); return; }
        var nm = data.now_min;
        var pick = items.filter(function (a) { return a.state === 'session'; })[0]
            || items.filter(function (a) { return nm !== null && a.start >= nm && a.status !== 'cancelled' && a.status !== 'no_show' && a.status !== 'completed'; })[0]
            || items[0];
        selectAppt(pick.id, false);
    }

    function clearInspect() {
        selectedId = null;
        detailCache = null;
        $('#inspectCode').hidden = true;
        var box = $('#inspectBody');
        box.innerHTML = '';
        box.appendChild(el('p', 'empty', 'No appointments to inspect for this selection.'));
    }

    function selectAppt(id, scroll) {
        selectedId = id;
        markSelected();
        api('/calendar-detail.php?id=' + encodeURIComponent(id)).then(function (d) {
            if (selectedId !== id) { return; }
            if (!d.ok) { toast(d.error || 'Unable to load the appointment.'); return; }
            detailCache = d.appointment;
            renderInspect(d.appointment);
            if (scroll) {
                var card = $('#inspect');
                var r = card.getBoundingClientRect();
                if (r.top > window.innerHeight - 120 || r.bottom < 80) { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            }
        }).catch(function () { toast('Unable to load the appointment.'); });
    }

    function dlItem(dl, label, value, sub) {
        var wrap = el('div');
        wrap.appendChild(el('dt', null, label));
        var dd = el('dd', null, value);
        if (sub) { dd.appendChild(el('span', 'sub', sub)); }
        wrap.appendChild(dd);
        dl.appendChild(wrap);
    }

    function renderInspect(a) {
        var code = $('#inspectCode');
        code.textContent = '#' + a.code;
        code.hidden = false;
        var box = $('#inspectBody');
        box.innerHTML = '';

        var top = el('div', 'ins-top');
        top.appendChild(el('span', 'avatar avatar-dark avatar-lg', initials(a.customer_name)));
        var who = el('div', 'ins-who');
        who.appendChild(el('p', 'ins-name', a.customer_name));
        var contact = [a.customer_email, a.customer_phone].filter(Boolean).join(' · ');
        who.appendChild(el('p', 'ins-contact', contact || 'No contact information on file'));
        top.appendChild(who);
        box.appendChild(top);

        var badges = el('div', 'ins-badges');
        badges.appendChild(el('span', 'badge st-' + a.status, a.state === 'session' ? 'In Session' : a.status_label));
        badges.appendChild(el('span', 'badge pay-' + a.payment_status, 'Payment: ' + a.payment_label));
        if (a.membership) { badges.appendChild(el('span', 'badge rs-available', a.membership + ' Member' + (a.membership_until ? ' · until ' + a.membership_until : ''))); }
        if (a.is_pregnant) { badges.appendChild(el('span', 'badge rs-cleaning', 'Pregnant')); }
        box.appendChild(badges);

        var dl = el('dl', 'ins-grid');
        dlItem(dl, 'Sanctuary Suite', a.room_name, a.room_type);
        dlItem(dl, 'Time Slot', a.start_label + ' – ' + a.end_label, a.date_label + ' · ' + fmtDur(a.duration));
        dlItem(dl, 'Ritual', a.service_name, a.duration + ' min');
        dlItem(dl, 'Total Amount', a.total_label, 'Received ' + a.paid_label);
        box.appendChild(dl);

        var th = el('div', 'ins-therapist');
        th.appendChild(el('span', 'avatar avatar-sm', initials(a.therapist_name)));
        var thWho = el('div', 'who');
        thWho.appendChild(el('strong', null, a.therapist_name));
        thWho.appendChild(el('span', null, a.therapist_spec || 'Therapist'));
        th.appendChild(thWho);
        var reassign = el('button', 'link-btn', 'Reassign');
        reassign.type = 'button';
        reassign.disabled = !a.can_reschedule;
        reassign.addEventListener('click', function () { openReschedule(a, true); });
        th.appendChild(reassign);
        box.appendChild(th);

        var pay = el('div', 'ins-pay');
        pay.appendChild(el('p', 'detail-h', 'Payment Records'));
        if (!a.payments.length) {
            pay.appendChild(el('p', 'cell-sub', 'No payment records yet.'));
        } else {
            var ul = el('ul', 'pay-list');
            a.payments.forEach(function (p) {
                var li = el('li');
                li.appendChild(el('span', null, p.amount + ' · ' + p.status));
                li.appendChild(el('span', 'muted', p.method + ' · ' + p.date));
                ul.appendChild(li);
            });
            pay.appendChild(ul);
        }
        box.appendChild(pay);

        var hs = el('div', 'ins-pay');
        hs.appendChild(el('p', 'detail-h', 'Health Declaration'));
        var hd = el('dl', 'ins-grid');
        var recorded = !!(a.health_conditions || a.health_injuries);
        dlItem(hd, 'Medical Conditions', a.health_conditions || 'Not recorded');
        dlItem(hd, 'Injuries', a.health_injuries || 'Not recorded');
        dlItem(hd, 'Pregnant', recorded ? (a.is_pregnant ? 'Yes' : 'No') : 'Not recorded');
        hs.appendChild(hd);
        box.appendChild(hs);

        var actions = el('div', 'ins-actions');
        var primary = el('button', 'btn btn-primary', a.primary.label);
        primary.type = 'button';
        primary.disabled = a.primary.disabled || !a.primary.action;
        primary.addEventListener('click', function () { runPrimary(a); });
        actions.appendChild(primary);
        var row = el('div', 'ins-actions-row');
        var resch = el('button', 'btn btn-outline', 'Reschedule');
        resch.type = 'button';
        resch.disabled = !a.can_reschedule;
        resch.addEventListener('click', function () { openReschedule(a, false); });
        var intake = el('button', 'btn btn-outline', 'Intake Folio');
        intake.type = 'button';
        intake.addEventListener('click', function () { openIntake(a); });
        row.appendChild(resch);
        row.appendChild(intake);
        actions.appendChild(row);
        if (a.primary.hint) { actions.appendChild(el('p', 'ins-hint', a.primary.hint)); }
        else if (!a.can_reschedule) { actions.appendChild(el('p', 'ins-hint', 'Only pending or confirmed appointments can be rescheduled.')); }
        box.appendChild(actions);
    }

    function runPrimary(a) {
        var action = a.primary.action;
        var text = action === 'confirm'
            ? 'Confirm ' + a.customer_name + "'s appointment on " + a.date_label + ' at ' + a.start_label + '?'
            : 'Mark ' + a.customer_name + "'s session as completed? This cannot be undone from the calendar.";
        askConfirm(a.primary.label, text, action === 'confirm' ? 'Confirm' : 'Mark Completed', function () {
            post('/appointment-action.php', { action: action, id: String(a.id) }).then(function (d) {
                if (!d.ok) { toast(d.error || 'Action failed.'); return; }
                toast(d.message || 'Appointment updated.');
                load();
            }).catch(function () { toast('Network error. Please try again.'); });
        });
    }

    function folioSection(title) {
        var s = el('section', 'folio-sec');
        s.appendChild(el('p', 'detail-h', title));
        return s;
    }

    function renderFolio(f) {
        var a = f.appointment;
        var box = $('#intakeBody');
        box.innerHTML = '';
        $('#intakeKicker').textContent = 'Intake Folio · #' + a.code;
        $('#intakeTitle').textContent = a.customer_name;

        var g = folioSection('Guest');
        var gl = el('dl', 'ins-grid');
        dlItem(gl, 'Email', a.customer_email || 'Not on file');
        dlItem(gl, 'Phone', a.customer_phone || 'Not on file');
        dlItem(gl, 'Membership', f.membership ? f.membership.type : 'None', f.membership ? f.membership.status + (f.membership.until ? ' · until ' + f.membership.until : '') : '');
        dlItem(gl, 'Lifetime Paid', f.spent);
        g.appendChild(gl);
        box.appendChild(g);

        var v = folioSection('This Visit');
        var vl = el('dl', 'ins-grid');
        dlItem(vl, 'Ritual', a.service_name, a.duration + ' min');
        dlItem(vl, 'Schedule', a.date_label, a.start_label + ' – ' + a.end_label);
        dlItem(vl, 'Suite', a.room_name, a.room_type);
        dlItem(vl, 'Therapist', a.therapist_name, a.therapist_spec);
        dlItem(vl, 'Status', a.state === 'session' ? 'In Session' : a.status_label, 'Payment: ' + a.payment_label);
        dlItem(vl, 'Amount', a.total_label, 'Received ' + a.paid_label);
        v.appendChild(vl);
        box.appendChild(v);

        var h = folioSection('Visit History');
        var hl = el('dl', 'ins-grid');
        dlItem(hl, 'Total Bookings', String(f.stats.total), f.stats.completed + ' completed · ' + f.stats.upcoming + ' upcoming');
        dlItem(hl, 'Cancelled / No-show', f.stats.cancelled + ' / ' + f.stats.no_show);
        dlItem(hl, 'First Visit', f.first_visit || '—');
        dlItem(hl, 'Last Visit', f.last_visit || '—');
        dlItem(hl, 'Usual Therapist', f.top_therapist || '—');
        dlItem(hl, 'Usual Ritual', f.top_service || '—');
        h.appendChild(hl);
        box.appendChild(h);

        var r = folioSection('Other Bookings');
        if (!f.recent.length) {
            r.appendChild(el('p', 'cell-sub', 'No other bookings for this guest.'));
        } else {
            var ul = el('ul', 'pay-list');
            f.recent.forEach(function (x) {
                var li = el('li');
                li.appendChild(el('span', null, x.date + ' · ' + x.time + ' · ' + x.service));
                li.appendChild(el('span', 'muted', x.therapist + ' · ' + x.room + ' · ' + x.status + ' · ' + x.amount));
                ul.appendChild(li);
            });
            r.appendChild(ul);
        }
        box.appendChild(r);

        var hf = folioSection('Health Declaration');
        if (!f.health) {
            hf.appendChild(el('p', 'cell-sub', 'No health declaration has been recorded for this guest.'));
        } else {
            var hl2 = el('dl', 'ins-grid');
            dlItem(hl2, 'Medical Conditions', f.health.conditions || '—');
            dlItem(hl2, 'Injuries', f.health.injuries || '—');
            dlItem(hl2, 'Pregnant', f.health.pregnant ? 'Yes' : 'No', 'Declared for #' + f.health.code + ' · ' + f.health.date);
            hf.appendChild(hl2);
            if (f.health.pregnant) { hf.appendChild(el('span', 'badge rs-cleaning', 'Pregnant')); }
        }
        box.appendChild(hf);
    }

    function openIntake(a) {
        var box = $('#intakeBody');
        box.innerHTML = '';
        box.appendChild(el('p', 'empty', 'Loading folio…'));
        $('#intakeKicker').textContent = 'Intake Folio · #' + a.code;
        $('#intakeTitle').textContent = a.customer_name;
        openModal($('#intakeModal'));
        api('/calendar-folio.php?id=' + encodeURIComponent(a.id)).then(function (d) {
            if (!d.ok) { box.innerHTML = ''; box.appendChild(el('p', 'empty', d.error || 'Unable to load the folio.')); return; }
            renderFolio(d.folio);
        }).catch(function () {
            box.innerHTML = '';
            box.appendChild(el('p', 'empty', 'Unable to load the folio.'));
        });
    }

    var bookModal = $('#bookModal');
    var bookForm = $('#bookForm');
    var bookError = $('#bookError');
    var bkService = $('#bk_service');
    var bkStart = $('#bk_start');
    var bkEnd = $('#bk_end');
    var bkAmount = $('#bk_amount');
    var bkDate = $('#bk_date');
    bkDate.setAttribute('data-today', bkDate.min);

    function bookSync(updateAmount) {
        var opt = bkService.options[bkService.selectedIndex];
        if (!opt || !opt.value) { return; }
        if (updateAmount) { bkAmount.value = opt.getAttribute('data-price') || ''; }
        var dur = parseInt(opt.getAttribute('data-duration') || '0', 10);
        if (dur > 0 && bkStart.value) {
            var p = bkStart.value.split(':');
            var mins = parseInt(p[0], 10) * 60 + parseInt(p[1], 10) + dur;
            bkEnd.value = mins < 1440 ? hhmm(mins) : '';
        }
    }
    bkService.addEventListener('change', function () { bookSync(true); });
    bkStart.addEventListener('change', function () { bookSync(false); });

    function openBooking(pre) {
        bookForm.reset();
        bookError.hidden = true;
        bkDate.min = bkDate.getAttribute('data-today');
        bkDate.value = (pre && pre.date && pre.date >= bkDate.min) ? pre.date : bkDate.min;
        if (pre && pre.room) { $('#bk_room').value = String(pre.room); }
        if (pre && typeof pre.start === 'number') { bkStart.value = hhmm(pre.start); }
        openModal(bookModal);
        $('#bk_customer').focus();
    }
    $('#openBooking').addEventListener('click', function () { openBooking({ date: state.date }); });

    bookForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        bookError.hidden = true;
        var missing = $$('select[required], input[required], textarea[required]', bookForm).some(function (f) { return !f.value; });
        if (missing) { bookError.textContent = 'Please complete every required field.'; bookError.hidden = false; return; }
        var btn = $('#bookSubmit');
        btn.disabled = true;
        api('/calendar-action.php', { method: 'POST', body: new FormData(bookForm) }).then(function (d) {
            if (!d.ok) { bookError.textContent = d.error || 'Unable to save the booking.'; bookError.hidden = false; return; }
            closeModal(bookModal);
            toast(d.message || 'Booking saved.');
            if (d.id) { selectedId = d.id; }
            load();
        }).catch(function () {
            bookError.textContent = 'Network error. Please try again.';
            bookError.hidden = false;
        }).then(function () { btn.disabled = false; });
    });

    var reschedModal = $('#reschedModal');
    var reschedForm = $('#reschedForm');
    var reschedError = $('#reschedError');
    var reschedCheck = $('#reschedCheck');
    var rsDate = $('#rs_date');
    var rsStart = $('#rs_start');
    var rsRoom = $('#rs_room');
    var rsTherapist = $('#rs_therapist');
    var checkTimer = null;
    var checkSeq = 0;

    function runCheck() {
        var id = $('#rs_id').value;
        if (!id || !rsDate.value || !rsStart.value) { reschedCheck.hidden = true; return; }
        var seq = ++checkSeq;
        var p = new URLSearchParams({
            id: id,
            appointment_date: rsDate.value,
            start_time: rsStart.value,
            room_id: rsRoom.value,
            therapist_id: rsTherapist.value
        });
        api('/calendar-check.php?' + p.toString()).then(function (d) {
            if (seq !== checkSeq) { return; }
            if (!d.ok) { reschedCheck.hidden = true; return; }
            reschedCheck.textContent = d.message;
            reschedCheck.classList.toggle('is-bad', !d.available);
            reschedCheck.hidden = false;
        }).catch(function () { reschedCheck.hidden = true; });
    }
    function scheduleCheck() {
        clearTimeout(checkTimer);
        checkTimer = setTimeout(runCheck, 250);
    }
    [rsDate, rsStart, rsRoom, rsTherapist].forEach(function (f) { f.addEventListener('change', scheduleCheck); });

    function openReschedule(a, focusTherapist) {
        reschedForm.reset();
        reschedError.hidden = true;
        reschedCheck.hidden = true;
        $('#rs_id').value = String(a.id);
        $('#reschedKicker').textContent = 'Appointment #' + a.code;
        $('#reschedNote').textContent = 'Moving ' + a.customer_name + "'s " + a.service_name + ' (' + fmtDur(a.duration) + '). Currently ' + a.date_label + ', ' + a.start_label + ' in ' + a.room_name + ' with ' + a.therapist_name + '. The session length stays the same.';
        rsDate.min = a.date < today ? a.date : today;
        rsDate.value = a.date;
        rsStart.value = a.start;
        rsRoom.value = String(a.room_id);
        rsTherapist.value = String(a.therapist_id);
        openModal(reschedModal);
        (focusTherapist ? rsTherapist : rsDate).focus();
        runCheck();
    }

    reschedForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        reschedError.hidden = true;
        var btn = $('#reschedSubmit');
        btn.disabled = true;
        api('/calendar-action.php', { method: 'POST', body: new FormData(reschedForm) }).then(function (d) {
            if (!d.ok) { reschedError.textContent = d.error || 'Unable to reschedule.'; reschedError.hidden = false; return; }
            closeModal(reschedModal);
            toast(d.message || 'Appointment rescheduled.');
            load();
        }).catch(function () {
            reschedError.textContent = 'Network error. Please try again.';
            reschedError.hidden = false;
        }).then(function () { btn.disabled = false; });
    });

    $$('#viewSwitch .seg-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            var v = b.getAttribute('data-view');
            if (v === state.view) { return; }
            state.view = v;
            load();
        });
    });

    function step(dir) {
        state.date = addDays(state.date, dir * (state.view === 'week' ? 7 : 1));
        load();
    }
    $('#datePrev').addEventListener('click', function () { step(-1); });
    $('#dateNext').addEventListener('click', function () { step(1); });
    $('#dateToday').addEventListener('click', function () { state.date = today; load(); });
    datePick.addEventListener('change', function () {
        if (!datePick.value) { return; }
        state.date = datePick.value;
        load();
    });
    dateLabel.addEventListener('click', function () {
        if (typeof datePick.showPicker === 'function') { try { datePick.showPicker(); } catch (e) { datePick.focus(); } } else { datePick.focus(); }
    });

    [[fRoom, 'room'], [fTherapist, 'therapist'], [fStatus, 'status'], [fService, 'service']].forEach(function (pair) {
        pair[0].addEventListener('change', function () { state[pair[1]] = pair[0].value; load(); });
    });
    $('#cfReset').addEventListener('click', function () {
        state.room = state.therapist = state.status = state.service = '';
        fRoom.value = fTherapist.value = fStatus.value = fService.value = '';
        load();
    });

    var globalSearch = $('#globalSearch');
    if (globalSearch) {
        globalSearch.addEventListener('submit', function (ev) { ev.preventDefault(); });
    }

    load();
}());