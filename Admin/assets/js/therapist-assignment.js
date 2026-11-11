(function () {
    'use strict';

    var apiBase = document.body.getAttribute('data-api') || '';
    var statsEl = $('.stats-ta');
    var appointmentsUrl = statsEl ? statsEl.getAttribute('data-appointments-url') : '';
    var photoBase = statsEl ? statsEl.getAttribute('data-photo-base') : '';
    var profilesOn = statsEl ? statsEl.getAttribute('data-profiles') === '1' : false;

    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    function localDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function addDays(str, n) {
        var d = new Date(str + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().slice(0, 10);
    }
    function initials(name) {
        var w = String(name || '').match(/[A-Za-zÀ-ɏ]+/g) || [];
        if (!w.length) { return '?'; }
        return (w[0][0] + (w.length > 1 ? w[w.length - 1][0] : '')).toUpperCase();
    }
    function avatar(o, cls) {
        var a = el('span', 'avatar ' + cls);
        if (o.photo) {
            var img = el('img');
            img.src = photoBase + o.photo;
            img.alt = '';
            img.addEventListener('error', function () { a.removeChild(img); a.textContent = initials(o.name); });
            a.appendChild(img);
        } else {
            a.textContent = initials(o.name);
        }
        return a;
    }
    function btn(label, cls, fn) {
        var b = el('button', cls, label);
        b.type = 'button';
        b.addEventListener('click', fn);
        return b;
    }

    var toastEl = $('#toast');
    var toastTimer = null;
    function toast(msg) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 4600);
    }

    function api(path, options) {
        return fetch(apiBase + path, Object.assign({ credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, options || {}))
            .then(function (res) {
                if (res.status === 401) { window.location.reload(); throw new Error('unauthorized'); }
                return res.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; });
            });
    }
    function postForm(form, extra) {
        var fd = new FormData(form);
        if (extra) { Object.keys(extra).forEach(function (k) { fd.set(k, extra[k]); }); }
        return api('/therapist-action.php', { method: 'POST', body: fd });
    }

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
    }
    $('#confirmNo').addEventListener('click', function () { confirmCb = null; closeModal(confirmModal); });
    $('#confirmYes').addEventListener('click', function () {
        var cb = confirmCb;
        confirmCb = null;
        closeModal(confirmModal);
        if (cb) { cb(); }
    });

    var data = null;
    var week = { date: null, therapist: '' };
    var weekData = null;

    function therapistById(id) {
        var f = data.therapists.filter(function (t) { return t.id === id; });
        return f.length ? f[0] : null;
    }

    function load() {
        return api('/therapists.php').then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load therapist data.'); return; }
            data = d;
            if (!week.date) { week.date = d.today; }
            renderAll();
            loadWeek();
        }).catch(function (e) {
            if (e && e.message === 'unauthorized') { return; }
            toast('Unable to load therapist data. Please try again.');
        });
    }

    function badge(state) {
        var cls = state === 'on_leave' ? 'on_leave' : (state === 'inactive' ? 'inactive' : 'available');
        var b = el('span', 'badge ts-' + cls);
        b.appendChild(el('span', 'dot'));
        b.appendChild(document.createTextNode(state === 'on_leave' ? 'On Leave' : (state === 'inactive' ? 'Inactive' : 'Available')));
        return b;
    }
    function stBadge(status, label) { return el('span', 'badge st-' + status, label); }

    function renderAll() {
        var s = data.summary;
        $('#sTotal').textContent = s.total;
        $('#sTotalNote').textContent = s.inactive ? s.inactive + ' inactive · history kept' : 'On the spa roster';
        $('#sAvail').textContent = s.available;
        $('#sAvailNote').textContent = s.on_leave ? s.on_leave + ' on leave today' : 'Ready for assignments';
        $('#sAssigned').textContent = s.assigned;
        $('#sCover').textContent = s.coverage === null ? 'N/A' : s.coverage + '%';
        $('#sCoverNote').textContent = s.eligible ? s.covered + ' of ' + s.eligible + ' eligible bookings covered' : 'No upcoming bookings to cover';
        $('#sWork').textContent = s.workload;
        $('#sWorkNote').textContent = s.utilization === null ? 'Sessions today · set daily capacity for utilization' : 'Sessions today · ' + s.utilization + '% of daily capacity';

        fillSelects();
        renderQueue();
        renderAvail();
        renderAlerts();
        renderGrid();
    }

    function fillSelects() {
        var sv = $('#thService');
        var cur = sv.value;
        sv.innerHTML = '';
        sv.appendChild(new Option('All services', ''));
        data.services.forEach(function (n) { sv.appendChild(new Option(n, n)); });
        sv.value = data.services.indexOf(cur) > -1 ? cur : '';

        [['#wkTherapist', 'All therapists'], ['#av_therapist', 'Select a therapist']].forEach(function (p) {
            var sel = $(p[0]);
            var keep = sel.value;
            sel.innerHTML = '';
            sel.appendChild(new Option(p[1], ''));
            data.therapists.forEach(function (t) { sel.appendChild(new Option(t.name, String(t.id))); });
            sel.value = keep;
        });
    }

    function renderQueue() {
        var list = $('#queueList');
        list.innerHTML = '';
        $('#queueCount').textContent = data.queue.length;
        if (!data.queue.length) {
            list.appendChild(el('p', 'empty', 'All upcoming bookings have an available therapist.'));
            return;
        }
        data.queue.forEach(function (q) {
            var c = el('article', 'queue-item');
            var top = el('div', 'queue-top');
            top.appendChild(el('strong', null, '#' + q.code));
            top.appendChild(stBadge(q.status, q.status_label));
            c.appendChild(top);
            c.appendChild(el('p', 'queue-name', q.customer));
            c.appendChild(el('p', 'queue-line', q.service));
            c.appendChild(el('p', 'queue-line', q.date_label + ' · ' + q.start_label + ' – ' + q.end_label));
            c.appendChild(el('p', 'queue-line', q.room + ' · ' + q.amount));
            c.appendChild(el('p', 'queue-tag ' + (q.reason === 'on_leave' ? 'is-leave' : 'is-open'), q.reason === 'on_leave' ? 'Assigned therapist (' + q.therapist + ') is unavailable' : 'No therapist assigned'));
            c.appendChild(btn(q.reason === 'on_leave' ? 'Reassign' : 'Assign', 'btn btn-primary btn-sm', function () { openAssign(q.id); }));
            list.appendChild(c);
        });
    }

    function renderAvail() {
        var list = $('#availList');
        list.innerHTML = '';
        data.therapists.forEach(function (t) {
            var li = el('li');
            var b = el('button', 'avail-btn');
            b.type = 'button';
            b.title = 'Manage availability';
            b.appendChild(avatar(t, 'avatar-sm'));
            var info = el('span', 'avail-info');
            info.appendChild(el('span', 'avail-name', t.name));
            info.appendChild(el('span', 'avail-sub', t.workload.today + (t.workload.today === 1 ? ' session today' : ' sessions today')));
            b.appendChild(info);
            b.appendChild(badge(t.status));
            b.addEventListener('click', function () { openAvail(t.id); });
            li.appendChild(b);
            list.appendChild(li);
        });
    }

    function renderAlerts() {
        var list = $('#alertList');
        list.innerHTML = '';
        $('#alertCount').textContent = data.alerts.length;
        if (!data.alerts.length) {
            list.appendChild(el('li', 'empty', 'No assignment alerts right now.'));
            return;
        }
        data.alerts.forEach(function (a) {
            var li = el('li', 'alert-item al-' + a.level);
            var txt = el('div', 'alert-text');
            txt.appendChild(el('strong', null, a.title));
            txt.appendChild(el('span', null, a.detail));
            li.appendChild(txt);
            li.appendChild(btn(a.label, 'link-btn', function () { runAlert(a); }));
            list.appendChild(li);
        });
    }

    function runAlert(a) {
        if (a.action === 'queue') { $('#queueCard').scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        else if (a.action === 'availability') { openAvail(a.id); }
        else if (a.action === 'assign') { openAssign(a.id); }
        else if (a.action === 'schedule') { openProfile(a.id, 'schedule'); }
        else if (a.action === 'appointments' && appointmentsUrl) { window.location.href = appointmentsUrl; }
    }

    var fSearch = $('#thSearch');
    var fStatus = $('#thStatus');
    var fService = $('#thService');

    function match(t) {
        if (fStatus.value && t.status !== fStatus.value) { return false; }
        if (fService.value && t.services.indexOf(fService.value) === -1) { return false; }
        var q = fSearch.value.trim().toLowerCase();
        return !q || (t.name + ' ' + t.specialization).toLowerCase().indexOf(q) > -1;
    }

    function stat(label, value) {
        var d = el('div', 'th-stat');
        d.appendChild(el('strong', null, String(value)));
        d.appendChild(el('span', null, label));
        return d;
    }

    function renderGrid() {
        var grid = $('#thGrid');
        grid.innerHTML = '';
        var list = data.therapists.filter(match);
        $('#thCount').textContent = 'Showing ' + list.length + ' of ' + data.therapists.length + ' therapists';
        if (!list.length) {
            grid.appendChild(el('p', 'empty card', 'No therapists match the selected filters.'));
            return;
        }
        list.forEach(function (t) {
            var w = t.workload;
            var c = el('article', 'th-card' + (t.status !== 'available' ? ' is-leave' : ''));

            var head = el('div', 'th-head');
            head.appendChild(avatar(t, 'avatar-lg'));
            var who = el('div', 'th-who');
            who.appendChild(el('h3', 'th-name', t.name));
            who.appendChild(el('p', 'th-spec', t.specialization || 'Therapist'));
            head.appendChild(who);
            head.appendChild(badge(t.status));
            c.appendChild(head);
            if (t.leave_reason) { c.appendChild(el('p', 'th-leave', 'Leave note: ' + t.leave_reason)); }

            var stats = el('div', 'th-stats');
            stats.appendChild(stat('Current (today)', w.current));
            stats.appendChild(stat('Scheduled', w.upcoming));
            stats.appendChild(stat('Completed', w.completed));
            c.appendChild(stats);
            c.appendChild(el('p', 'th-sub', w.pending + ' pending · ' + w.confirmed + ' confirmed · ' + w.today + ' today · ' + w.total + ' total'));

            var bar = el('div', 'th-bar' + (t.over_capacity ? ' is-over' : '') + (t.capacity ? '' : ' is-empty'));
            var fill = el('span');
            fill.style.width = (t.capacity ? t.cap_pct : 0) + '%';
            bar.appendChild(fill);
            c.appendChild(bar);
            c.appendChild(el('p', 'th-sub', t.capacity ? w.today + ' of ' + t.capacity + ' daily sessions' + (t.over_capacity ? ' · over capacity' : '') : 'Daily capacity not set'));

            var sch = el('div', 'th-sched');
            sch.appendChild(el('p', 'label-small', 'Schedule'));
            if (t.next) { sch.appendChild(el('p', 'th-next', 'Next: ' + t.next.label + ' · ' + t.next.customer)); }
            else { sch.appendChild(el('p', 'th-next muted', 'No upcoming sessions')); }
            t.today_list.slice(0, 3).forEach(function (x) {
                var r = el('p', 'th-today');
                r.appendChild(el('b', null, x.time));
                r.appendChild(document.createTextNode(' ' + x.customer + ' · ' + x.service));
                sch.appendChild(r);
            });
            if (t.today_list.length > 3) { sch.appendChild(el('p', 'th-sub', '+' + (t.today_list.length - 3) + ' more today')); }
            var free = el('p', 'th-free');
            if (t.status !== 'available') { free.textContent = t.status === 'inactive' ? 'Inactive · not accepting new assignments' : 'On leave today'; free.className = 'th-free muted'; }
            else if (t.free_today === null) { free.textContent = 'Shift not set — open Manage Availability to define working hours.'; free.className = 'th-free muted'; }
            else if (!t.free_today.length) { free.textContent = 'No open slots left today.'; free.className = 'th-free muted'; }
            else { free.textContent = 'Open today: ' + t.free_today.join(', '); }
            sch.appendChild(free);
            c.appendChild(sch);

            var svc = el('div', 'th-svc');
            t.services.forEach(function (n) { svc.appendChild(el('span', 'chip', n)); });
            if (!t.services.length) { svc.appendChild(el('span', 'muted', 'No matching services')); }
            c.appendChild(svc);

            var acts = el('div', 'th-actions');
            acts.appendChild(btn('View Profile', 'btn btn-outline btn-sm', function () { openProfile(t.id, 'profile'); }));
            acts.appendChild(btn('View Schedule', 'btn btn-outline btn-sm', function () { openProfile(t.id, 'schedule'); }));
            var as = btn('Assign Appointment', 'btn btn-primary btn-sm', function () { openAssign(null, t.id); });
            as.disabled = t.status !== 'available';
            acts.appendChild(as);
            acts.appendChild(btn('Manage Availability', 'btn btn-outline btn-sm', function () { openAvail(t.id); }));
            if (profilesOn) { acts.appendChild(btn('Edit Profile', 'btn btn-outline btn-sm', function () { openTherapist(t); })); }
            c.appendChild(acts);
            grid.appendChild(c);
        });
    }

    [fSearch, fStatus, fService].forEach(function (f) { f.addEventListener('input', renderGrid); });
    $('#thReset').addEventListener('click', function () { fSearch.value = ''; fStatus.value = ''; fService.value = ''; renderGrid(); });

    function loadWeek() {
        var q = '?date=' + encodeURIComponent(week.date) + (week.therapist ? '&therapist=' + encodeURIComponent(week.therapist) : '');
        return api('/therapist-week.php' + q).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load the weekly schedule.'); return; }
            weekData = d;
            renderWeek();
        }).catch(function (e) {
            if (e && e.message === 'unauthorized') { return; }
            toast('Unable to load the weekly schedule.');
        });
    }

    function renderWeek() {
        $('#wkRange').textContent = weekData.label;
        $('#wkToday').disabled = weekData.is_current;
        var t = $('#wkTable');
        t.innerHTML = '';
        var thead = el('thead');
        var hr = el('tr');
        hr.appendChild(el('th', 'wk-name-h', 'Therapist'));
        weekData.days.forEach(function (d) {
            var th = el('th', d.is_today ? 'is-today' : '');
            th.appendChild(el('span', null, d.weekday + ' · ' + d.month));
            th.appendChild(el('strong', null, d.day));
            hr.appendChild(th);
        });
        thead.appendChild(hr);
        t.appendChild(thead);
        var tb = el('tbody');
        if (!weekData.rows.length) {
            var er = el('tr', 'empty-row');
            var etd = el('td', null, 'No therapists to show.');
            etd.colSpan = 8;
            er.appendChild(etd);
            tb.appendChild(er);
        }
        weekData.rows.forEach(function (r) {
            var tr = el('tr');
            var n = el('th');
            n.scope = 'row';
            var nin = el('div', 'wk-name');
            nin.appendChild(avatar(r, 'avatar-sm'));
            var nt = el('span', 'wk-name-t');
            nt.appendChild(el('strong', null, r.name));
            nt.appendChild(el('em', null, r.specialization));
            nin.appendChild(nt);
            n.appendChild(nin);
            tr.appendChild(n);
            r.cells.forEach(function (c, i) {
                var cell = el('td', 'wk-' + c.state + (c.conflict ? ' has-conflict' : '') + (weekData.days[i].is_today ? ' is-today' : ''));
                var td = el('div', 'wk-cell');
                cell.appendChild(td);
                if (c.state === 'leave') {
                    td.appendChild(el('span', 'wk-flag', 'On Leave'));
                    if (c.leave) { td.appendChild(el('span', 'wk-sub', c.leave)); }
                } else if (c.state === 'inactive') {
                    td.appendChild(el('span', 'wk-flag', 'Inactive'));
                    c.sessions.forEach(function (s) {
                        var chip = el('span', 'wk-chip is-' + s.status);
                        chip.title = s.customer + ' · ' + s.service + ' · ' + s.time;
                        chip.appendChild(el('b', null, s.start_label));
                        chip.appendChild(document.createTextNode(' ' + s.customer));
                        td.appendChild(chip);
                    });
                } else if (c.state === 'off') {
                    td.appendChild(el('span', 'wk-flag', 'Day Off'));
                } else {
                    if (c.shift) { td.appendChild(el('span', 'wk-shift', c.shift)); }
                    c.sessions.forEach(function (s) {
                        var chip = el('span', 'wk-chip is-' + s.status + (s.conflict ? ' is-conflict' : ''));
                        chip.title = s.customer + ' · ' + s.service + ' · ' + s.time + (s.conflict ? ' · CONFLICT' : '');
                        chip.appendChild(el('b', null, s.start_label));
                        chip.appendChild(document.createTextNode(' ' + s.customer));
                        td.appendChild(chip);
                    });
                    if (c.state === 'none') { td.appendChild(el('span', 'wk-sub', 'No shift set')); }
                    else if (c.free && c.free.length) { td.appendChild(el('span', 'wk-free', 'Open: ' + c.free.join(', '))); }
                    else if (c.state === 'open' || c.state === 'busy') { if (c.shift && weekData.days[i].date >= data.today) { td.appendChild(el('span', 'wk-sub', 'Fully booked')); } }
                    if (c.conflict) { td.appendChild(el('span', 'wk-conf', 'Schedule conflict')); }
                }
                tr.appendChild(cell);
            });
            tb.appendChild(tr);
        });
        t.appendChild(tb);
    }

    $('#wkPrev').addEventListener('click', function () { week.date = addDays(weekData.from, -7); loadWeek(); });
    $('#wkNext').addEventListener('click', function () { week.date = addDays(weekData.from, 7); loadWeek(); });
    $('#wkToday').addEventListener('click', function () { week.date = data.today; loadWeek(); });
    $('#wkTherapist').addEventListener('change', function () { week.therapist = this.value; loadWeek(); });
    $('#btnRoster').addEventListener('click', function () { $('#weekSection').scrollIntoView({ behavior: 'smooth', block: 'start' }); });

    var assignModal = $('#assignModal');
    var assignForm = $('#assignForm');
    var asAppt = $('#as_appt');
    var asRec = null;
    var asPreset = 0;

    function apptLabel(a) {
        return a.code + ' · ' + a.customer + ' · ' + a.service + ' · ' + a.date_label + ' ' + a.start_label + (a.therapist ? ' (' + a.therapist + ')' : ' (Unassigned)');
    }

    function openAssign(apptId, therapistId) {
        assignForm.reset();
        $('#assignError').hidden = true;
        $('#as_therapist').value = '';
        $('#asOverrideRow').hidden = true;
        $('#asUnassign').hidden = true;
        $('#asInfo').innerHTML = '';
        $('#asList').innerHTML = '';
        $('#asList').appendChild(el('p', 'cell-sub', 'Choose an appointment to see eligible therapists.'));
        asRec = null;
        asPreset = therapistId || 0;
        asAppt.innerHTML = '';
        asAppt.appendChild(new Option('Select an appointment', ''));
        var unassignedFirst = data.assignable.slice().sort(function (a, b) {
            var ra = a.therapist_id ? 1 : 0, rb = b.therapist_id ? 1 : 0;
            return ra - rb;
        });
        unassignedFirst.forEach(function (a) { asAppt.appendChild(new Option(apptLabel(a), String(a.id))); });
        openModal(assignModal);
        if (apptId) { asAppt.value = String(apptId); loadRecommend(apptId); }
        else { asAppt.focus(); }
    }

    function infoItem(dl, k, v) {
        var w = el('div');
        w.appendChild(el('dt', null, k));
        w.appendChild(el('dd', null, v));
        dl.appendChild(w);
    }

    function loadRecommend(id) {
        var list = $('#asList');
        list.innerHTML = '';
        list.appendChild(el('p', 'cell-sub', 'Checking availability…'));
        api('/therapist-recommend.php?appointment_id=' + encodeURIComponent(id)).then(function (d) {
            list.innerHTML = '';
            if (!d.ok) { list.appendChild(el('p', 'cell-sub', d.error || 'Unable to check therapists.')); return; }
            asRec = d;
            var a = d.appointment;
            var info = $('#asInfo');
            info.innerHTML = '';
            infoItem(info, 'Customer', a.customer);
            infoItem(info, 'Service', a.service);
            infoItem(info, 'Date', a.date_label);
            infoItem(info, 'Time', a.start_label + ' – ' + a.end_label);
            infoItem(info, 'Room', a.room);
            infoItem(info, 'Current Therapist', a.therapist || 'Unassigned');
            $('#asUnassign').hidden = !a.therapist_id;

            var groups = [
                ['Recommended · specialization match', function (t) { return t.eligible && t.compatible; }],
                ['Other available therapists', function (t) { return t.eligible && !t.compatible; }],
                ['Unavailable', function (t) { return !t.eligible; }]
            ];
            groups.forEach(function (g) {
                var items = d.therapists.filter(g[1]);
                if (!items.length) { return; }
                list.appendChild(el('p', 'as-group', g[0]));
                items.forEach(function (t) {
                    var row = el('label', 'as-item' + (t.eligible ? '' : ' is-off') + (t.current ? ' is-current' : ''));
                    var r = el('input');
                    r.type = 'radio';
                    r.name = 'pick';
                    r.value = String(t.id);
                    r.disabled = !t.eligible || t.current;
                    r.addEventListener('change', function () { pick(t); });
                    row.appendChild(r);
                    row.appendChild(avatar(t, 'avatar-sm'));
                    var tx = el('span', 'as-text');
                    tx.appendChild(el('strong', null, t.name));
                    tx.appendChild(el('em', null, t.specialization + (t.current ? ' · currently assigned' : '') + (t.reason ? ' · ' + t.reason : '')));
                    row.appendChild(tx);
                    if (t.eligible && !t.compatible) { row.appendChild(el('span', 'badge st-pending', 'No match')); }
                    if (t.eligible && t.compatible) { row.appendChild(el('span', 'badge rs-available', 'Match')); }
                    list.appendChild(row);
                    if (asPreset === t.id && t.eligible && !t.current) { r.checked = true; pick(t); }
                });
            });
        }).catch(function () {
            list.innerHTML = '';
            list.appendChild(el('p', 'cell-sub', 'Unable to check therapists.'));
        });
    }

    function pick(t) {
        $('#as_therapist').value = String(t.id);
        var row = $('#asOverrideRow');
        row.hidden = t.compatible;
        $('#as_override').checked = false;
        if (!t.compatible) { $('#asOverrideText').textContent = t.name + ' (' + t.specialization + ') is not a specialization match for this service. Override and assign anyway.'; }
    }

    asAppt.addEventListener('change', function () {
        $('#as_therapist').value = '';
        $('#asOverrideRow').hidden = true;
        if (asAppt.value) { asPreset = 0; loadRecommend(asAppt.value); }
    });

    function afterChange(msg) {
        toast(msg);
        load();
    }

    assignForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var err = $('#assignError');
        err.hidden = true;
        if (!asAppt.value) { err.textContent = 'Select an appointment.'; err.hidden = false; return; }
        if (!$('#as_therapist').value) { err.textContent = 'Select a therapist.'; err.hidden = false; return; }
        var b = $('#assignSubmit');
        b.disabled = true;
        postForm(assignForm).then(function (d) {
            if (!d.ok) {
                err.textContent = d.error || 'Unable to save the assignment.';
                err.hidden = false;
                if (d.needs_override) { $('#asOverrideRow').hidden = false; }
                return;
            }
            closeModal(assignModal);
            afterChange(d.message);
        }).catch(function (e) {
            if (e && e.message === 'unauthorized') { return; }
            err.textContent = 'Network error. Please try again.';
            err.hidden = false;
        }).then(function () { b.disabled = false; });
    });

    $('#asUnassign').addEventListener('click', function () {
        var id = asAppt.value;
        if (!id) { return; }
        askConfirm('Remove therapist', 'Remove the assigned therapist? The booking returns to the unassigned queue.', 'Remove', function () {
            postForm(assignForm, { therapist_id: '0', override: '' }).then(function (d) {
                if (!d.ok) { $('#assignError').textContent = d.error || 'Unable to remove the therapist.'; $('#assignError').hidden = false; return; }
                closeModal(assignModal);
                afterChange(d.message);
            });
        });
    });
    $('#btnAssign').addEventListener('click', function () { openAssign(null, null); });

    var availModal = $('#availModal');
    var availForm = $('#availForm');
    var shiftForm = $('#shiftForm');
    var avSel = $('#av_therapist');
    var avId = 0;

    function setAvTab(name) {
        $$('#avTabs .tab').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-av') === name); });
        availForm.hidden = name !== 'status';
        shiftForm.hidden = name !== 'shifts';
    }
    $$('#avTabs .tab').forEach(function (b) { b.addEventListener('click', function () { setAvTab(b.getAttribute('data-av')); }); });

    function buildShiftGrid(t) {
        var g = $('#shiftGrid');
        g.innerHTML = '';
        t.shifts.forEach(function (s) {
            var row = el('div', 'shift-row');
            var lab = el('label', 'shift-day');
            var cb = el('input');
            cb.type = 'checkbox';
            cb.name = 'shift_on[' + s.day + ']';
            cb.value = '1';
            cb.checked = s.on;
            lab.appendChild(cb);
            lab.appendChild(document.createTextNode(' ' + s.label));
            row.appendChild(lab);
            var a = el('input');
            a.type = 'time';
            a.name = 'shift_start[' + s.day + ']';
            a.value = s.start || '09:00';
            var b = el('input');
            b.type = 'time';
            b.name = 'shift_end[' + s.day + ']';
            b.value = s.end || '17:00';
            row.appendChild(a);
            row.appendChild(el('span', 'shift-to', 'to'));
            row.appendChild(b);
            g.appendChild(row);
        });
        $('#av_cap').value = t.capacity || '';
    }

    function loadAvail(id) {
        avId = id;
        $('#av_tid_a').value = String(id);
        $('#av_tid_s').value = String(id);
        $('#availError').hidden = true;
        $('#shiftError').hidden = true;
        availForm.classList.toggle('is-disabled', !id);
        shiftForm.classList.toggle('is-disabled', !id);
        if (!id) { $('#avCurrent').textContent = 'Select a therapist to continue.'; $('#shiftGrid').innerHTML = ''; return; }
        var t = therapistById(id);
        $('#av_status').value = t.status === 'on_leave' ? 'available' : 'on_leave';
        $('#av_date').value = data.today;
        $('#av_end').value = '';
        $('#av_notes').value = '';
        $('#avCurrent').textContent = t.name + ' is currently ' + (t.status === 'on_leave' ? 'on leave' : 'available') + (t.leave_reason ? ' (' + t.leave_reason + ')' : '') + '.';
        toggleEnd();
        buildShiftGrid(t);
    }

    function toggleEnd() { $('#avEndRow').hidden = $('#av_status').value !== 'on_leave'; }
    $('#av_status').addEventListener('change', toggleEnd);
    avSel.addEventListener('change', function () { loadAvail(parseInt(avSel.value, 10) || 0); });

    function openAvail(id) {
        avSel.value = id ? String(id) : '';
        setAvTab('status');
        loadAvail(id || 0);
        openModal(availModal);
    }
    $('#btnAvail').addEventListener('click', function () { openAvail(0); });

    availForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var err = $('#availError');
        err.hidden = true;
        if (!avId) { err.textContent = 'Select a therapist.'; err.hidden = false; return; }
        if (!$('#av_date').value) { err.textContent = 'Choose an effective date.'; err.hidden = false; return; }
        var b = $('#availSubmit');
        b.disabled = true;
        postForm(availForm).then(function (d) {
            if (!d.ok) { err.textContent = d.error || 'Unable to save.'; err.hidden = false; return; }
            closeModal(availModal);
            afterChange(d.message);
        }).catch(function (e) {
            if (e && e.message === 'unauthorized') { return; }
            err.textContent = 'Network error. Please try again.';
            err.hidden = false;
        }).then(function () { b.disabled = false; });
    });

    function saveShifts(extra) {
        var err = $('#shiftError');
        err.hidden = true;
        if (!avId) { err.textContent = 'Select a therapist.'; err.hidden = false; return; }
        var b = $('#shiftSubmit');
        b.disabled = true;
        postForm(shiftForm, extra).then(function (d) {
            if (!d.ok) { err.textContent = d.error || 'Unable to save.'; err.hidden = false; return; }
            closeModal(availModal);
            afterChange(d.message);
        }).catch(function (e) {
            if (e && e.message === 'unauthorized') { return; }
            err.textContent = 'Network error. Please try again.';
            err.hidden = false;
        }).then(function () { b.disabled = false; });
    }
    shiftForm.addEventListener('submit', function (ev) { ev.preventDefault(); saveShifts(null); });
    $('#shiftClear').addEventListener('click', function () {
        askConfirm('Clear shifts', 'Remove all saved shifts for this therapist? Working hours will be treated as not set.', 'Clear', function () { saveShifts({ clear_shifts: '1' }); });
    });
    $('#shiftFill').addEventListener('click', function () {
        $$('#shiftGrid .shift-row').forEach(function (r) {
            var inputs = $$('input', r);
            inputs[0].checked = true;
            inputs[1].value = '09:00';
            inputs[2].value = '21:00';
        });
    });

    var profileModal = $('#profileModal');
    function section(title) {
        var s = el('section', 'folio-sec');
        s.appendChild(el('p', 'detail-h', title));
        return s;
    }
    function kv(dl, k, v) {
        var w = el('div');
        w.appendChild(el('dt', null, k));
        w.appendChild(el('dd', null, v));
        dl.appendChild(w);
    }

    function openProfile(id, mode) {
        var box = $('#profileBody');
        box.innerHTML = '';
        box.appendChild(el('p', 'empty', 'Loading…'));
        openModal(profileModal);
        api('/therapist-detail.php?id=' + encodeURIComponent(id)).then(function (d) {
            box.innerHTML = '';
            if (!d.ok) { box.appendChild(el('p', 'empty', d.error || 'Unable to load the therapist.')); return; }
            var t = d.therapist;
            $('#profileKicker').textContent = mode === 'schedule' ? 'Therapist Schedule' : 'Therapist Profile';
            $('#profileTitle').textContent = t.name;
            var wrap = el('div', 'folio');

            if (mode === 'profile') {
                var a = section('Profile');
                var top = el('div', 'th-head');
                top.appendChild(avatar(t, 'avatar-lg'));
                var who = el('div', 'th-who');
                who.appendChild(el('h3', 'th-name', t.name));
                who.appendChild(el('p', 'th-spec', t.specialization));
                top.appendChild(who);
                top.appendChild(badge(t.status));
                a.appendChild(top);
                var dl = el('dl', 'ins-grid');
                kv(dl, 'Total Sessions', String(t.workload.total));
                kv(dl, 'Completed', String(t.workload.completed));
                kv(dl, 'Pending / Confirmed', t.workload.pending + ' / ' + t.workload.confirmed);
                kv(dl, 'Daily Capacity', t.capacity ? String(t.capacity) : 'Not set');
                kv(dl, 'Email', t.email || 'Not on file');
                kv(dl, 'Contact', t.phone || 'Not on file');
                kv(dl, 'Employment', (t.emp_type_label || 'Not set') + ' · ' + ({ active: 'Active', inactive: 'Inactive', on_leave: 'On Leave' }[t.emp_status] || 'Active'));
                kv(dl, 'Employed Since', t.hired_label || 'Not set');
                kv(dl, 'Experience', t.years === null ? 'Not set' : t.years + (t.years === 1 ? ' year' : ' years'));
                kv(dl, 'Certifications', t.certifications || 'None recorded');
                if (t.address) { kv(dl, 'Address', t.address); }
                a.appendChild(dl);
                wrap.appendChild(a);

                var s = section('Compatible Services');
                var sv = el('div', 'th-svc');
                t.services.forEach(function (n) { sv.appendChild(el('span', 'chip', n)); });
                if (!t.services.length) { sv.appendChild(el('span', 'muted', 'No matching services.')); }
                s.appendChild(sv);
                wrap.appendChild(s);

                var sh = section('Weekly Shift');
                if (!t.shifts_defined) { sh.appendChild(el('p', 'cell-sub', 'Shift not set.')); }
                else {
                    var ul = el('ul', 'pay-list');
                    t.shifts.forEach(function (x) {
                        var li = el('li');
                        li.appendChild(el('span', null, x.label));
                        li.appendChild(el('span', 'muted', x.on ? x.start + ' – ' + x.end : 'Day off'));
                        ul.appendChild(li);
                    });
                    sh.appendChild(ul);
                }
                wrap.appendChild(sh);

                var lv = section('Leave History');
                if (!d.leaves.length) { lv.appendChild(el('p', 'cell-sub', 'No leave records.')); }
                else {
                    var ll = el('ul', 'pay-list');
                    d.leaves.forEach(function (x) {
                        var li = el('li');
                        li.appendChild(el('span', null, x.from + ' → ' + x.to));
                        li.appendChild(el('span', 'muted', x.reason || '—'));
                        ll.appendChild(li);
                    });
                    lv.appendChild(ll);
                }
                wrap.appendChild(lv);
            } else {
                var td = section('Today');
                if (t.today_list.length) {
                    var tl = el('ul', 'pay-list');
                    t.today_list.forEach(function (x) {
                        var li = el('li');
                        li.appendChild(el('span', null, x.time + ' · ' + x.customer));
                        li.appendChild(el('span', 'muted', x.service + ' · ' + x.status));
                        tl.appendChild(li);
                    });
                    td.appendChild(tl);
                } else { td.appendChild(el('p', 'cell-sub', 'No sessions today.')); }
                td.appendChild(el('p', 'cell-sub', t.status === 'inactive' ? 'Inactive.' : (t.status === 'on_leave' ? 'On leave today.' : (t.free_today === null ? 'Shift not set.' : (t.free_today.length ? 'Open today: ' + t.free_today.join(', ') : 'No open slots left today.')))));
                wrap.appendChild(td);
            }

            var up = section('Upcoming Sessions');
            if (!d.upcoming.length) { up.appendChild(el('p', 'cell-sub', 'No upcoming sessions.')); }
            else {
                var ul2 = el('ul', 'pay-list');
                d.upcoming.forEach(function (x) {
                    var li = el('li');
                    li.appendChild(el('span', null, x.date_label + ' · ' + x.start_label + ' – ' + x.end_label + ' · ' + x.customer));
                    li.appendChild(el('span', 'muted', x.service + ' · ' + x.room + ' · ' + x.status_label));
                    ul2.appendChild(li);
                });
                up.appendChild(ul2);
            }
            wrap.appendChild(up);

            var acts = el('div', 'th-actions');
            var asn = btn('Assign Appointment', 'btn btn-primary btn-sm', function () { closeModal(profileModal); openAssign(null, t.id); });
            asn.disabled = t.status !== 'available';
            acts.appendChild(asn);
            acts.appendChild(btn('Manage Availability', 'btn btn-outline btn-sm', function () { closeModal(profileModal); openAvail(t.id); }));
            if (profilesOn) { acts.appendChild(btn('Edit Profile', 'btn btn-outline btn-sm', function () { closeModal(profileModal); openTherapist(t); })); }
            wrap.appendChild(acts);
            box.appendChild(wrap);
        }).catch(function () {
            box.innerHTML = '';
            box.appendChild(el('p', 'empty', 'Unable to load the therapist.'));
        });
    }

    var therapistModal = $('#therapistModal');
    var therapistForm = $('#therapistForm');
    var tfPreview = $('#tfPreview');
    var tfPhoto = $('#tf_photo');
    var tfEditing = null;

    function tfPreviewSet(o) {
        tfPreview.innerHTML = '';
        if (o && o.photo) {
            var img = el('img');
            img.src = photoBase + o.photo;
            img.alt = '';
            tfPreview.appendChild(img);
        } else {
            tfPreview.textContent = o && o.name ? initials(o.name) : '?';
        }
    }

    function openTherapist(t) {
        if (!therapistModal) { return; }
        tfEditing = t || null;
        therapistForm.reset();
        $('#therapistError').hidden = true;
        $('#tf_action').value = t ? 'update_therapist' : 'create_therapist';
        $('#tf_id').value = t ? String(t.id) : '';
        $('#therapistKicker').textContent = t ? 'Edit Profile' : 'New Hire Registration';
        $('#therapistTitle').textContent = t ? t.name : 'Add New Therapist';
        $('#tfAnother').hidden = !!t;
        $('#tfSave').querySelector('span').textContent = t ? 'Save Changes' : 'Save Therapist';
        $('#tfRemoveRow').hidden = !(t && t.photo);

        var dl = $('#specList');
        dl.innerHTML = '';
        var seen = [];
        data.therapists.forEach(function (x) { if (x.specialization && seen.indexOf(x.specialization) === -1) { seen.push(x.specialization); dl.appendChild(new Option(x.specialization, x.specialization)); } });

        if (t) {
            $('#tf_first').value = t.first_name;
            $('#tf_mid').value = t.middle_name;
            $('#tf_last').value = t.last_name;
            $('#tf_email').value = t.email;
            $('#tf_phone').value = t.phone;
            $('#tf_addr').value = t.address;
            $('#tf_spec').value = t.specialization;
            $('#tf_years').value = t.years === null ? '' : t.years;
            $('#tf_cert').value = t.certifications;
            $('#tf_hired').value = t.hired;
            $('#tf_type').value = t.emp_type;
            $('#tf_emp').value = t.emp_status;
            $('#tf_avail').value = t.availability_pref;
        } else {
            $('#tf_hired').value = data.today;
            $('#tf_emp').value = 'active';
            $('#tf_avail').value = 'available';
        }
        tfPreviewSet(t);
        openModal(therapistModal);
        $('#tf_first').focus();
    }

    function tfSyncAvail() {
        var off = $('#tf_emp').value !== 'active';
        if (off) { $('#tf_avail').value = 'unavailable'; }
    }

    if (therapistForm) {
        $('#tf_emp').addEventListener('change', tfSyncAvail);
        tfPhoto.addEventListener('change', function () {
            var f = tfPhoto.files[0];
            var err = $('#therapistError');
            err.hidden = true;
            if (!f) { tfPreviewSet(tfEditing); return; }
            if (!/\.(jpe?g|png|webp)$/i.test(f.name) || ['image/jpeg', 'image/png', 'image/webp'].indexOf(f.type) === -1) {
                err.textContent = 'Photo must be a JPG, JPEG, PNG or WEBP image.';
                err.hidden = false;
                tfPhoto.value = '';
                return;
            }
            if (f.size > 2097152) {
                err.textContent = 'The photo is too large (max 2 MB).';
                err.hidden = false;
                tfPhoto.value = '';
                return;
            }
            tfPreview.innerHTML = '';
            var img = el('img');
            img.src = URL.createObjectURL(f);
            img.alt = '';
            tfPreview.appendChild(img);
        });

        therapistForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var again = ev.submitter && ev.submitter.id === 'tfAnother';
            var err = $('#therapistError');
            err.hidden = true;
            var missing = $$('[required]', therapistForm).filter(function (f) { return !String(f.value).trim(); });
            if (missing.length) {
                err.textContent = 'Please complete every required field.';
                err.hidden = false;
                missing[0].focus();
                return;
            }
            var buttons = [$('#tfSave'), $('#tfAnother')];
            buttons.forEach(function (b) { b.disabled = true; });
            postForm(therapistForm).then(function (d) {
                if (!d.ok) { err.textContent = d.error || 'Unable to save the therapist.'; err.hidden = false; return; }
                toast(d.message);
                if (again && !tfEditing) {
                    therapistForm.reset();
                    $('#tf_hired').value = data.today;
                    tfPreviewSet(null);
                    $('#tf_first').focus();
                } else {
                    closeModal(therapistModal);
                }
                load();
            }).catch(function (e) {
                if (e && e.message === 'unauthorized') { return; }
                err.textContent = 'Network error. Please try again.';
                err.hidden = false;
            }).then(function () { buttons.forEach(function (b) { b.disabled = false; }); });
        });

        var addBtn = $('#btnAddTherapist');
        if (addBtn) { addBtn.addEventListener('click', function () { openTherapist(null); }); }
    }

    load();
}());