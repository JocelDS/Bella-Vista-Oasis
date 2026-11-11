(function () {
    'use strict';

    var apiBase = document.body.getAttribute('data-api') || '';

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
    function localTime(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') : ''; }

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
    function postForm(form) { return api('/room-action.php', { method: 'POST', body: new FormData(form) }); }

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

    var data = null;
    var tab = 'all';
    var sort = { key: 'name', dir: 1 };
    var detailId = null;

    var grid = $('#roomGrid');
    var fSearch = $('#rmSearch');
    var fType = $('#rmType');
    var fTemp = $('#rmTemp');
    var fHum = $('#rmHum');

    function roomById(id) {
        var f = data.rooms.filter(function (r) { return r.id === id; });
        return f.length ? f[0] : null;
    }

    function load() {
        return api('/rooms.php').then(function (d) {
            if (!d.ok) {
                grid.innerHTML = '';
                grid.appendChild(el('p', 'empty card', d.error || 'Unable to load the suites.'));
                return;
            }
            data = d;
            renderAll();
        }).catch(function (e) {
            if (e && e.message === 'unauthorized') { return; }
            toast('Unable to load the suites. Please try again.');
        });
    }

    function badge(status) {
        var b = el('span', 'badge rs-' + (['available', 'cleaning', 'maintenance'].indexOf(status) > -1 ? status : 'other'));
        b.appendChild(el('span', 'dot'));
        b.appendChild(document.createTextNode(cap(status)));
        return b;
    }
    function cbadge(state) { return el('span', 'cb cb-' + state.key, state.label); }
    function mbadge(status, label) { return el('span', 'mb mb-' + status, label); }

    function renderAll() {
        var s = data.summary;
        $('#sTotal').textContent = s.total;
        $('#sAvail').textContent = s.available;
        $('#sClean').textContent = s.cleaning;
        $('#sMaint').textContent = s.maintenance;
        $('#sTemp').textContent = s.avg_temp_label || 'N/A';
        $('#sHum').textContent = s.avg_hum_label || 'N/A';
        var note = s.readings ? 'Manually recorded · ' + s.readings + (s.readings === 1 ? ' suite' : ' suites') : 'No readings recorded yet';
        $('#sTempNote').textContent = note;
        $('#sHumNote').textContent = note;

        var st = data.settings;
        $('#rangeText').textContent = 'Recommended: ' + st.min_temp + '–' + st.max_temp + '°C · ' + st.min_hum + '–' + st.max_hum + '% humidity' + (st.updated ? ' · updated ' + st.updated : '');
        $('#rmLegend').textContent = 'Climate indicators compare the latest manually recorded reading with the recommended range (' + st.min_temp + '–' + st.max_temp + '°C, ' + st.min_hum + '–' + st.max_hum + '%).';

        var counts = { all: data.rooms.length, available: s.available, cleaning: s.cleaning, maintenance: s.maintenance, climate: s.readings, attention: s.attention };
        $$('[data-count]').forEach(function (c) { c.textContent = counts[c.getAttribute('data-count')]; });

        fillSelects();
        renderGrid();
        renderClimate();
        renderTable();
    }

    function fillSelects() {
        var types = [];
        data.rooms.forEach(function (r) { if (r.type && types.indexOf(r.type) === -1) { types.push(r.type); } });
        types.sort();
        var cur = fType.value;
        fType.innerHTML = '';
        fType.appendChild(new Option('All room types', ''));
        types.forEach(function (t) { fType.appendChild(new Option(t, t)); });
        fType.value = types.indexOf(cur) > -1 ? cur : '';
        var dl = $('#typeList');
        dl.innerHTML = '';
        types.forEach(function (t) { dl.appendChild(new Option(t, t)); });

        [['#cl_room', false], ['#mn_room', false], ['#logRoom', true]].forEach(function (p) {
            var sel = $(p[0]);
            var keep = sel.value;
            sel.innerHTML = '';
            if (p[1]) { sel.appendChild(new Option('All suites', '')); }
            else { sel.appendChild(new Option('Select a suite', '')); }
            data.rooms.forEach(function (r) { sel.appendChild(new Option(r.name, String(r.id))); });
            sel.value = keep;
        });
    }

    function matches(r) {
        if (tab === 'available' || tab === 'cleaning' || tab === 'maintenance') { if (r.status !== tab) { return false; } }
        if (tab === 'climate' && !r.climate) { return false; }
        if (tab === 'attention' && !r.attention) { return false; }
        var q = fSearch.value.trim().toLowerCase();
        if (q && (r.name + ' ' + r.type + ' ' + r.description).toLowerCase().indexOf(q) === -1) { return false; }
        if (fType.value && r.type !== fType.value) { return false; }
        var tk = r.climate ? r.climate.temp.key : 'none';
        var hk = r.climate ? r.climate.hum.key : 'none';
        if (fTemp.value && tk !== fTemp.value) { return false; }
        if (fHum.value && hk !== fHum.value) { return false; }
        return true;
    }

    function metric(label, value, state) {
        var m = el('div', 'rc-metric');
        m.appendChild(el('span', 'rc-metric-l', label));
        m.appendChild(el('strong', 'rc-metric-v', value));
        if (state) { m.appendChild(cbadge(state)); }
        return m;
    }

    function actionBtn(label, cls, fn) {
        var b = el('button', cls, label);
        b.type = 'button';
        b.addEventListener('click', fn);
        return b;
    }

    function renderGrid() {
        grid.innerHTML = '';
        var list = data.rooms.filter(matches);
        if (!list.length) {
            grid.appendChild(el('p', 'empty card', data.rooms.length ? 'No suites match the selected filters.' : 'No suites yet. Add your first sanctuary suite.'));
            return;
        }
        list.forEach(function (r) {
            var c = el('article', 'room-card rc-' + r.status);
            var head = el('div', 'rc-head');
            var tt = el('div', 'rc-title');
            tt.appendChild(el('h3', 'rc-name', r.name));
            tt.appendChild(el('p', 'rc-type', r.type || 'Treatment Suite'));
            head.appendChild(tt);
            head.appendChild(badge(r.status));
            c.appendChild(head);
            c.appendChild(el('p', 'rc-desc', r.description || 'No description provided.'));

            var cond = el('div', 'rc-cond');
            var ch = el('div', 'rc-cond-head');
            ch.appendChild(el('span', 'label-small', 'Room Condition'));
            var upd = el('button', 'link-btn', 'Update');
            upd.type = 'button';
            upd.addEventListener('click', function () { openClimate(r.id); });
            ch.appendChild(upd);
            cond.appendChild(ch);
            if (r.climate) {
                var row = el('div', 'rc-metrics');
                row.appendChild(metric('Temperature', r.climate.temp_label, r.climate.temp));
                row.appendChild(metric('Humidity', r.climate.hum_label, r.climate.hum));
                cond.appendChild(row);
                cond.appendChild(el('p', 'rc-foot', 'Manually recorded · Last updated ' + r.climate.recorded_label));
            } else {
                cond.appendChild(el('p', 'rc-none', 'No climate reading recorded yet.'));
            }
            c.appendChild(cond);

            var det = el('dl', 'rc-details');
            var d1 = el('div'); d1.appendChild(el('dt', null, 'Amenities'));
            var dd = el('dd');
            if (r.amenity_list.length) { r.amenity_list.forEach(function (a) { dd.appendChild(el('span', 'chip', a)); }); }
            else { dd.textContent = 'Not recorded'; dd.className = 'muted'; }
            d1.appendChild(dd); det.appendChild(d1);
            var d2 = el('div'); d2.appendChild(el('dt', null, 'Max Capacity'));
            d2.appendChild(el('dd', r.capacity ? '' : 'muted', r.capacity ? r.capacity + (r.capacity === 1 ? ' guest' : ' guests') : 'Not recorded'));
            det.appendChild(d2);
            var d3 = el('div'); d3.appendChild(el('dt', null, 'Current Booking'));
            var cur = 'None right now';
            if (r.current) { cur = r.current.customer + ' · ' + r.current.service + (r.current.in_session ? ' (until ' + r.current.end_label + ')' : ' (ended ' + r.current.end_label + ')'); }
            else if (r.next) { cur = 'Next: ' + r.next.customer + ' at ' + r.next.start_label; }
            d3.appendChild(el('dd', r.current || r.next ? '' : 'muted', cur));
            det.appendChild(d3);
            var d4 = el('div'); d4.appendChild(el('dt', null, 'Availability'));
            d4.appendChild(el('dd', null, r.availability));
            det.appendChild(d4);
            c.appendChild(det);

            if (r.reasons.length) {
                var w = el('p', 'rc-warn');
                w.textContent = r.reasons.join(' · ');
                c.appendChild(w);
            }

            var acts = el('div', 'rc-actions');
            acts.appendChild(actionBtn('View Details', 'btn btn-primary btn-sm', function () { openDetail(r.id); }));
            acts.appendChild(actionBtn('Edit Room', 'btn btn-outline btn-sm', function () { openSuite(r); }));
            acts.appendChild(actionBtn('Update Status', 'btn btn-outline btn-sm', function () { openStatus(r); }));
            acts.appendChild(actionBtn('Maintenance', 'btn btn-outline btn-sm', function () { openMaint(r.id, null); }));
            acts.appendChild(actionBtn('View Schedule', 'btn btn-outline btn-sm', function () { openSchedule(r.id); }));
            c.appendChild(acts);
            grid.appendChild(c);
        });
    }

    function renderClimate() {
        var box = $('#climateGrid');
        box.innerHTML = '';
        if (!data.rooms.length) { box.appendChild(el('p', 'empty', 'No records available.')); return; }
        data.rooms.forEach(function (r) {
            var c = el('div', 'clim-card' + (r.climate && !r.climate.ok ? ' is-warn' : ''));
            var h = el('div', 'clim-head');
            h.appendChild(el('strong', null, r.name));
            h.appendChild(el('span', 'cell-sub', r.type));
            c.appendChild(h);
            if (r.climate) {
                var row = el('div', 'clim-row');
                var t = el('div'); t.appendChild(el('span', 'rc-metric-l', 'Temperature')); t.appendChild(el('strong', 'clim-v', r.climate.temp_label)); t.appendChild(cbadge(r.climate.temp));
                var u = el('div'); u.appendChild(el('span', 'rc-metric-l', 'Humidity')); u.appendChild(el('strong', 'clim-v', r.climate.hum_label)); u.appendChild(cbadge(r.climate.hum));
                row.appendChild(t); row.appendChild(u);
                c.appendChild(row);
                c.appendChild(el('p', 'rc-foot', (r.climate.ok ? 'Within Recommended Range' : 'Needs Attention') + ' · Manually Recorded'));
                c.appendChild(el('p', 'rc-foot', 'Last Updated ' + r.climate.recorded_label + (r.climate.by ? ' by ' + r.climate.by : '')));
                if (r.climate.notes) { c.appendChild(el('p', 'clim-notes', r.climate.notes)); }
            } else {
                c.appendChild(el('p', 'rc-none', 'No records available.'));
            }
            c.appendChild(actionBtn('Record Reading', 'btn btn-outline btn-sm', function () { openClimate(r.id); }));
            box.appendChild(c);
        });
    }

    var mtSearch = $('#mtSearch');
    var mtStatus = $('#mtStatus');

    function sortVal(r, k) {
        if (k === 'name') { return r.name.toLowerCase(); }
        if (k === 'type') { return r.type.toLowerCase(); }
        if (k === 'temp') { return r.climate ? r.climate.temperature : null; }
        if (k === 'hum') { return r.climate ? r.climate.humidity : null; }
        if (k === 'maint') { return r.maintenance ? r.maintenance.date : null; }
        return r.status;
    }

    function renderTable() {
        var body = $('#mtBody');
        body.innerHTML = '';
        var q = mtSearch.value.trim().toLowerCase();
        var rows = data.rooms.filter(function (r) {
            if (mtStatus.value && r.status !== mtStatus.value) { return false; }
            if (!q) { return true; }
            var m = r.maintenance ? r.maintenance.title + ' ' + r.maintenance.description : '';
            return (r.name + ' ' + r.type + ' ' + m).toLowerCase().indexOf(q) > -1;
        });
        rows.sort(function (a, b) {
            var x = sortVal(a, sort.key), y = sortVal(b, sort.key);
            if (x === null && y === null) { return 0; }
            if (x === null) { return 1; }
            if (y === null) { return -1; }
            return (x < y ? -1 : x > y ? 1 : 0) * sort.dir;
        });
        $$('.th-sort').forEach(function (b) {
            var on = b.getAttribute('data-sort') === sort.key;
            b.classList.toggle('is-sorted', on);
            b.setAttribute('data-dir', on ? (sort.dir === 1 ? 'asc' : 'desc') : '');
        });
        if (!rows.length) {
            var tr = el('tr', 'empty-row');
            var td = el('td', null, data.rooms.length ? 'No suites match your search.' : 'No records available.');
            td.colSpan = 7;
            tr.appendChild(td);
            body.appendChild(tr);
        }
        rows.forEach(function (r) {
            var tr = el('tr');
            var n = el('td'); n.appendChild(el('span', 'cell-main', r.name)); tr.appendChild(n);
            tr.appendChild(el('td', null, r.type || '—'));
            var t = el('td');
            if (r.climate) { t.appendChild(el('span', 'cell-main', r.climate.temp_label)); t.appendChild(cbadge(r.climate.temp)); } else { t.appendChild(el('span', 'muted', 'No records available.')); }
            tr.appendChild(t);
            var h = el('td');
            if (r.climate) { h.appendChild(el('span', 'cell-main', r.climate.hum_label)); h.appendChild(cbadge(r.climate.hum)); } else { h.appendChild(el('span', 'muted', 'No records available.')); }
            tr.appendChild(h);
            var m = el('td');
            if (r.maintenance) { m.appendChild(el('span', 'cell-main', r.maintenance.date_label)); m.appendChild(mbadge(r.maintenance.status, r.maintenance.status_label)); } else { m.appendChild(el('span', 'muted', 'No records available.')); }
            tr.appendChild(m);
            var d = el('td');
            if (r.maintenance) { d.appendChild(el('span', 'cell-main', r.maintenance.title)); if (r.maintenance.description) { d.appendChild(el('span', 'cell-sub clamp', r.maintenance.description)); } } else { d.appendChild(el('span', 'muted', 'No records available.')); }
            tr.appendChild(d);
            var s = el('td'); s.appendChild(badge(r.status)); tr.appendChild(s);
            body.appendChild(tr);
        });
        $('#mtFoot').textContent = 'Showing ' + rows.length + ' of ' + data.rooms.length + ' suites';
    }

    $$('.th-sort').forEach(function (b) {
        b.addEventListener('click', function () {
            var k = b.getAttribute('data-sort');
            if (sort.key === k) { sort.dir = -sort.dir; } else { sort.key = k; sort.dir = 1; }
            renderTable();
        });
    });
    [mtSearch, mtStatus].forEach(function (f) { f.addEventListener('input', renderTable); });

    $$('#rmTabs .tab').forEach(function (b) {
        b.addEventListener('click', function () {
            tab = b.getAttribute('data-tab');
            $$('#rmTabs .tab').forEach(function (x) { x.classList.toggle('is-active', x === b); });
            renderGrid();
        });
    });
    [fSearch, fType, fTemp, fHum].forEach(function (f) { f.addEventListener('input', renderGrid); });
    $('#rmReset').addEventListener('click', function () {
        fSearch.value = ''; fType.value = ''; fTemp.value = ''; fHum.value = '';
        tab = 'all';
        $$('#rmTabs .tab').forEach(function (x) { x.classList.toggle('is-active', x.getAttribute('data-tab') === 'all'); });
        renderGrid();
    });

    function submitForm(form, errEl, btn, done) {
        errEl.hidden = true;
        var bad = $$('[required]', form).some(function (f) { return !String(f.value).trim(); });
        if (bad) { errEl.textContent = 'Please complete every required field.'; errEl.hidden = false; return; }
        btn.disabled = true;
        postForm(form).then(function (d) {
            if (!d.ok) { errEl.textContent = d.error || 'Unable to save.'; errEl.hidden = false; return; }
            done(d);
        }).catch(function (e) {
            if (e && e.message === 'unauthorized') { return; }
            errEl.textContent = 'Network error. Please try again.';
            errEl.hidden = false;
        }).then(function () { btn.disabled = false; });
    }

    var suiteModal = $('#suiteModal');
    var suiteForm = $('#suiteForm');
    function openSuite(r) {
        suiteForm.reset();
        $('#suiteError').hidden = true;
        $('#su_action').value = r ? 'update_room' : 'create_room';
        $('#su_id').value = r ? String(r.id) : '';
        $('#suiteKicker').textContent = r ? 'Edit Room' : 'Sanctuary Suite';
        $('#suiteTitle').textContent = r ? r.name : 'New Sanctuary Suite';
        if (r) {
            $('#su_name').value = r.name;
            $('#su_type').value = r.type;
            $('#su_status').value = r.status;
            $('#su_desc').value = r.description;
            $('#su_amen').value = r.amenities;
            $('#su_cap').value = r.capacity || '';
        }
        openModal(suiteModal);
        $('#su_name').focus();
    }
    $('#openNewRoom').addEventListener('click', function () { openSuite(null); });
    suiteForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        submitForm(suiteForm, $('#suiteError'), $('#suiteSubmit'), function (d) {
            closeModal(suiteModal);
            toast(d.message);
            load().then(refreshOpen);
        });
    });

    var statusModal = $('#statusModal');
    var statusForm = $('#statusForm');
    function openStatus(r) {
        $('#statusError').hidden = true;
        $('#st_id').value = String(r.id);
        $('#statusTitle').textContent = r.name;
        $('#st_status').value = r.status;
        openModal(statusModal);
    }
    statusForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        submitForm(statusForm, $('#statusError'), $('#statusSubmit'), function (d) {
            closeModal(statusModal);
            toast(d.message);
            load().then(refreshOpen);
        });
    });

    function setStatusQuick(roomId, status) {
        var fd = new FormData(statusForm);
        fd.set('id', String(roomId));
        fd.set('room_status', status);
        api('/room-action.php', { method: 'POST', body: fd }).then(function (d) {
            toast(d.ok ? d.message : (d.error || 'Unable to update the status.'));
            if (d.ok) { load().then(refreshOpen); }
        });
    }

    var climateModal = $('#climateModal');
    var climateForm = $('#climateForm');
    function openClimate(roomId) {
        climateForm.reset();
        $('#climateError').hidden = true;
        var now = new Date(Date.now() - 60000);
        $('#cl_date').value = localDate(now);
        $('#cl_date').max = localDate(new Date());
        $('#cl_time').value = localTime(now);
        if (roomId) { $('#cl_room').value = String(roomId); }
        openModal(climateModal);
        (roomId ? $('#cl_temp') : $('#cl_room')).focus();
    }
    $('#openClimate').addEventListener('click', function () { openClimate(0); });
    climateForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        submitForm(climateForm, $('#climateError'), $('#climateSubmit'), function (d) {
            closeModal(climateModal);
            toast(d.message);
            load().then(refreshOpen);
        });
    });

    var settingsModal = $('#settingsModal');
    var settingsForm = $('#settingsForm');
    $('#openSettings').addEventListener('click', function () {
        $('#settingsError').hidden = true;
        $('#se_tmin').value = data.settings.min_temp;
        $('#se_tmax').value = data.settings.max_temp;
        $('#se_hmin').value = data.settings.min_hum;
        $('#se_hmax').value = data.settings.max_hum;
        openModal(settingsModal);
    });
    settingsForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        submitForm(settingsForm, $('#settingsError'), $('#settingsSubmit'), function (d) {
            closeModal(settingsModal);
            toast(d.message);
            load().then(refreshOpen);
        });
    });

    var maintModal = $('#maintModal');
    var maintForm = $('#maintForm');
    function openMaint(roomId, rec) {
        maintForm.reset();
        $('#maintError').hidden = true;
        $('#mn_id').value = rec ? String(rec.id) : '';
        $('#maintKicker').textContent = rec ? 'Edit Record' : 'Maintenance';
        $('#maintTitle').textContent = rec ? 'Edit Maintenance Record' : 'Record Maintenance';
        $('#mn_date').value = rec ? rec.date : localDate(new Date());
        if (rec) {
            $('#mn_room').value = String(rec.room_id);
            $('#mn_title').value = rec.title;
            $('#mn_desc').value = rec.description;
            $('#mn_status').value = rec.status;
        } else if (roomId) {
            $('#mn_room').value = String(roomId);
        }
        openModal(maintModal);
        $('#mn_title').focus();
    }
    maintForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        submitForm(maintForm, $('#maintError'), $('#maintSubmit'), function (d) {
            closeModal(maintModal);
            toast(d.message);
            load().then(refreshOpen);
        });
    });

    var logModal = $('#logModal');
    var logRoom = $('#logRoom');
    var logStatus = $('#logStatus');
    var logRows = [];

    function openLog(roomId) {
        if (roomId !== undefined) { logRoom.value = roomId ? String(roomId) : ''; }
        openModal(logModal);
        loadLog();
    }
    function loadLog() {
        var list = $('#logList');
        list.innerHTML = '';
        list.appendChild(el('p', 'empty', 'Loading records…'));
        api('/room-maintenance.php' + (logRoom.value ? '?room=' + encodeURIComponent(logRoom.value) : '')).then(function (d) {
            logRows = d.ok ? d.logs : [];
            renderLog(d.ok ? '' : (d.error || 'Unable to load records.'));
        }).catch(function () { renderLog('Unable to load records.'); });
    }
    function renderLog(err) {
        var list = $('#logList');
        list.innerHTML = '';
        var rows = logRows.filter(function (x) { return !logStatus.value || x.status === logStatus.value; });
        if (err || !rows.length) { list.appendChild(el('p', 'empty', err || 'No records available.')); return; }
        rows.forEach(function (x) {
            var c = el('div', 'log-item');
            var top = el('div', 'log-top');
            var who = el('div');
            who.appendChild(el('strong', null, x.title));
            who.appendChild(el('span', 'cell-sub', x.room_name + ' · ' + x.date_label + (x.completed ? ' · completed ' + x.completed : '') + (x.by ? ' · by ' + x.by : '')));
            top.appendChild(who);
            top.appendChild(mbadge(x.status, x.status_label));
            c.appendChild(top);
            if (x.description) { c.appendChild(el('p', 'log-desc', x.description)); }
            var acts = el('div', 'log-actions');
            acts.appendChild(actionBtn('Edit', 'btn btn-outline btn-sm', function () { openMaint(x.room_id, x); }));
            if (x.status === 'scheduled') { acts.appendChild(actionBtn('Start', 'btn btn-outline btn-sm', function () { quickMaint(x, 'in_progress'); })); }
            if (x.status !== 'completed') { acts.appendChild(actionBtn('Mark Completed', 'btn btn-primary btn-sm', function () { quickMaint(x, 'completed'); })); }
            var room = roomById(x.room_id);
            if (x.status === 'completed' && room && room.status === 'maintenance') {
                acts.appendChild(actionBtn('Verified · Mark Suite Available', 'btn btn-primary btn-sm', function () { setStatusQuick(x.room_id, 'available'); }));
            }
            c.appendChild(acts);
            list.appendChild(c);
        });
    }
    function quickMaint(x, status) {
        var fd = new FormData(maintForm);
        fd.set('action', 'save_maintenance');
        fd.set('id', String(x.id));
        fd.set('room_id', String(x.room_id));
        fd.set('maintenance_title', x.title);
        fd.set('description', x.description);
        fd.set('scheduled_date', x.date);
        fd.set('maintenance_status', status);
        api('/room-action.php', { method: 'POST', body: fd }).then(function (d) {
            toast(d.ok ? d.message : (d.error || 'Unable to update the record.'));
            if (d.ok) { load().then(refreshOpen); }
        });
    }
    $('#openLog').addEventListener('click', function () { logRoom.value = ''; logStatus.value = ''; openLog(0); });
    $('#mtLog').addEventListener('click', function () { logRoom.value = ''; logStatus.value = ''; openLog(0); });
    $('#logAdd').addEventListener('click', function () { openMaint(logRoom.value ? parseInt(logRoom.value, 10) : 0, null); });
    logRoom.addEventListener('change', loadLog);
    logStatus.addEventListener('change', function () { renderLog(''); });

    function kv(dl, k, v, muted) {
        var w = el('div');
        w.appendChild(el('dt', null, k));
        w.appendChild(el('dd', muted ? 'muted' : '', v));
        dl.appendChild(w);
    }

    function scheduleTable(sch) {
        var wrap = el('div');
        if (!sch.items.length) { wrap.appendChild(el('p', 'cell-sub', 'No upcoming appointments for this suite.')); return wrap; }
        var ul = el('ul', 'pay-list');
        sch.items.forEach(function (a) {
            var li = el('li');
            li.appendChild(el('span', null, a.date + ' · ' + a.time + ' · ' + a.customer));
            li.appendChild(el('span', 'muted', a.service + ' · ' + a.therapist + ' · ' + a.status));
            ul.appendChild(li);
        });
        wrap.appendChild(ul);
        return wrap;
    }

    function detailSection(title) {
        var s = el('section', 'folio-sec');
        s.appendChild(el('p', 'detail-h', title));
        return s;
    }

    function openDetail(id) {
        detailId = id;
        var box = $('#detailBody');
        box.innerHTML = '';
        box.appendChild(el('p', 'empty', 'Loading…'));
        openModal($('#detailModal'));
        api('/room-detail.php?id=' + encodeURIComponent(id)).then(function (d) {
            box.innerHTML = '';
            if (!d.ok) { box.appendChild(el('p', 'empty', d.error || 'Unable to load the suite.')); return; }
            var r = d.room;
            $('#detailKicker').textContent = r.type || 'Suite Details';
            $('#detailTitle').textContent = r.name;
            var wrap = el('div', 'folio');

            var a = detailSection('Room Information');
            var top = el('div', 'rc-head');
            top.appendChild(el('p', 'rc-desc', r.description || 'No description provided.'));
            top.appendChild(badge(r.status));
            a.appendChild(top);
            var dl = el('dl', 'ins-grid');
            kv(dl, 'Room Type', r.type || '—');
            kv(dl, 'Max Capacity', r.capacity ? String(r.capacity) : 'Not recorded', !r.capacity);
            kv(dl, 'Amenities', r.amenities || 'Not recorded', !r.amenities);
            kv(dl, 'Availability', r.availability);
            kv(dl, 'Bookings Today', String(r.today_count));
            kv(dl, 'Open Maintenance', String(r.open_maintenance));
            a.appendChild(dl);
            if (r.reasons.length) { a.appendChild(el('p', 'rc-warn', r.reasons.join(' · '))); }
            wrap.appendChild(a);

            var c = detailSection('Climate Records · Manually Recorded');
            if (!d.climate.length) { c.appendChild(el('p', 'cell-sub', 'No records available.')); }
            else {
                var ul = el('ul', 'pay-list');
                d.climate.forEach(function (x) {
                    var li = el('li');
                    li.appendChild(el('span', null, x.temp_label + ' (' + x.temp.label + ') · ' + x.hum_label + ' (' + x.hum.label + ')'));
                    li.appendChild(el('span', 'muted', x.recorded_label + (x.by ? ' · ' + x.by : '') + (x.notes ? ' · ' + x.notes : '')));
                    ul.appendChild(li);
                });
                c.appendChild(ul);
            }
            wrap.appendChild(c);

            var s = detailSection('Booking Schedule');
            if (d.schedule.free_today.length) { s.appendChild(el('p', 'cell-sub', 'Open today: ' + d.schedule.free_today.join(', '))); }
            s.appendChild(scheduleTable(d.schedule));
            wrap.appendChild(s);

            var m = detailSection('Maintenance History');
            if (!d.maintenance.length) { m.appendChild(el('p', 'cell-sub', 'No records available.')); }
            else {
                var ml = el('ul', 'pay-list');
                d.maintenance.forEach(function (x) {
                    var li = el('li');
                    li.appendChild(el('span', null, x.title + ' · ' + x.status_label));
                    li.appendChild(el('span', 'muted', x.date_label + (x.description ? ' · ' + x.description : '')));
                    ml.appendChild(li);
                });
                m.appendChild(ml);
            }
            wrap.appendChild(m);

            var acts = el('div', 'rc-actions');
            acts.appendChild(actionBtn('Edit Room', 'btn btn-outline btn-sm', function () { closeModal($('#detailModal')); openSuite(r); }));
            acts.appendChild(actionBtn('Update Status', 'btn btn-outline btn-sm', function () { closeModal($('#detailModal')); openStatus(r); }));
            acts.appendChild(actionBtn('Update Climate', 'btn btn-outline btn-sm', function () { closeModal($('#detailModal')); openClimate(r.id); }));
            acts.appendChild(actionBtn('Add Maintenance', 'btn btn-primary btn-sm', function () { closeModal($('#detailModal')); openMaint(r.id, null); }));
            wrap.appendChild(acts);
            box.appendChild(wrap);
        }).catch(function () {
            box.innerHTML = '';
            box.appendChild(el('p', 'empty', 'Unable to load the suite.'));
        });
    }

    function openSchedule(id) {
        var box = $('#scheduleBody');
        box.innerHTML = '';
        box.appendChild(el('p', 'empty', 'Loading…'));
        openModal($('#scheduleModal'));
        api('/room-detail.php?id=' + encodeURIComponent(id)).then(function (d) {
            box.innerHTML = '';
            if (!d.ok) { box.appendChild(el('p', 'empty', d.error || 'Unable to load the schedule.')); return; }
            $('#scheduleKicker').textContent = 'Suite Schedule';
            $('#scheduleTitle').textContent = d.room.name;
            var wrap = el('div', 'folio');
            var s1 = detailSection('Availability Today');
            s1.appendChild(el('p', 'cell-sub', d.room.availability));
            if (d.room.status === 'available') {
                s1.appendChild(el('p', 'cell-sub', d.schedule.free_today.length ? 'Open windows (with the ' + data.gap + '-minute cleaning gap): ' + d.schedule.free_today.join(', ') : 'No open windows left today.'));
            }
            wrap.appendChild(s1);
            var s2 = detailSection('Upcoming Appointments');
            s2.appendChild(scheduleTable(d.schedule));
            wrap.appendChild(s2);
            box.appendChild(wrap);
        }).catch(function () {
            box.innerHTML = '';
            box.appendChild(el('p', 'empty', 'Unable to load the schedule.'));
        });
    }

    function refreshOpen() {
        if (!logModal.hidden) { loadLog(); }
        if (!$('#detailModal').hidden && detailId) { openDetail(detailId); }
    }

    load();
}());