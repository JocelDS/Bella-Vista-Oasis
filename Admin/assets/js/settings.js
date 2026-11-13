(function () {
    'use strict';

    var apiBase = document.body.getAttribute('data-api') || '';
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
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
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 4200);
    }

    function api(path, options) {
        return fetch(apiBase + path, Object.assign({ credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, options || {}))
            .then(function (res) {
                if (res.status === 401) { window.location.reload(); throw new Error('unauthorized'); }
                return res.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; });
            });
    }
    function post(fd) { fd.set('csrf', csrf); return api('/settings.php', { method: 'POST', body: fd }); }

    /* ---------- Modals ---------- */
    var openModals = [];
    function openModal(m) { if (openModals.indexOf(m) === -1) { openModals.push(m); } m.style.zIndex = String(100 + openModals.length); m.hidden = false; }
    function closeModal(m) { m.hidden = true; m.style.zIndex = ''; openModals = openModals.filter(function (x) { return x !== m; }); }
    $$('.modal').forEach(function (m) {
        m.addEventListener('mousedown', function (ev) { if (ev.target === m) { closeModal(m); } });
        $$('[data-close]', m).forEach(function (b) { b.addEventListener('click', function () { closeModal(m); }); });
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && openModals.length) { closeModal(openModals[openModals.length - 1]); } });

    var confirmModal = $('#scConfirmModal');
    var confirmOk = $('#scCOk');
    var confirmTitle = $('#scCTitle');
    var confirmText = $('#scCText');
    var confirmFn = null;
    function ask(title, text, okLabel, fn) {
        if (!confirmModal || !confirmOk || !confirmTitle || !confirmText) { return; }
        confirmTitle.textContent = title; confirmText.textContent = text; confirmOk.textContent = okLabel; confirmFn = fn;
        openModal(confirmModal);
    }
    if (confirmModal && confirmOk) {
        confirmOk.addEventListener('click', function () { var f = confirmFn; confirmFn = null; closeModal(confirmModal); if (f) { f(); } });
    }

    /* ---------- Tabs ---------- */
    var tabs = $$('.sc-tab');
    function showTab(name) {
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        $$('.sc-panel').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
    }
    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { showTab(t.getAttribute('data-tab')); });
        t.addEventListener('keydown', function (ev) {
            var n = ev.key === 'ArrowRight' ? i + 1 : ev.key === 'ArrowLeft' ? i - 1 : null;
            if (n === null) { return; }
            var target = tabs[(n + tabs.length) % tabs.length];
            target.focus(); showTab(target.getAttribute('data-tab'));
        });
    });
    $$('[data-goto]').forEach(function (b) { b.addEventListener('click', function () { showTab(b.getAttribute('data-goto')); window.scrollTo({ top: 0, behavior: 'smooth' }); }); });

    /* ---------- Form state ---------- */
    var saveBtn = $('#scSave'), resetBtn = $('#scReset');
    var rows = $$('.sc-table tbody tr[data-day]');
    var standardOpen = $('#scStdOpen');
    var standardClose = $('#scStdClose');

    if (!rows.length) {
        return;
    }

    function collect() {
        var out = { hours: {}, config: {}, therapists: {} };
        rows.forEach(function (r) {
            out.hours[r.getAttribute('data-day')] = {
                open: $('[data-hours-start]', r).value,
                close: $('[data-hours-end]', r).value,
                is_open: $('[data-hours-open]', r).checked ? 1 : 0
            };
        });
        $$('[data-cfg]').forEach(function (n) {
            var k = n.getAttribute('data-cfg');
            if (k in out.config) { return; }
            out.config[k] = n.type === 'checkbox' ? (n.checked ? '1' : '0') : n.value;
        });
        $$('[data-therapist]').forEach(function (n) { out.therapists[n.getAttribute('data-therapist')] = n.value; });
        return out;
    }

    var snapshot = JSON.stringify(collect());

    function refreshDirty() {
        var dirty = JSON.stringify(collect()) !== snapshot;
        if (saveBtn) { saveBtn.disabled = !dirty; }
        if (resetBtn) { resetBtn.disabled = !dirty; }
        return dirty;
    }

    function syncRow(r) {
        var on = $('[data-hours-open]', r).checked;
        r.classList.toggle('is-off', !on);
        $('[data-hours-start]', r).disabled = !on;
        $('[data-hours-end]', r).disabled = !on;
        var tile = $('[data-day-tile="' + r.getAttribute('data-day') + '"]');
        if (tile) {
            tile.classList.toggle('is-on', on);
            tile.setAttribute('aria-pressed', on ? 'true' : 'false');
            $('strong', tile).textContent = on ? 'ON' : 'OFF';
        }
    }
    function syncStandard() {
        if (!standardOpen || !standardClose) { return; }
        var first = rows.filter(function (r) { return $('[data-hours-open]', r).checked; })[0] || rows[0];
        standardOpen.value = $('[data-hours-start]', first).value;
        standardClose.value = $('[data-hours-end]', first).value;
    }

    rows.forEach(function (r) {
        syncRow(r);
        $('[data-hours-open]', r).addEventListener('change', function () { syncRow(r); syncStandard(); refreshDirty(); });
        $$('input[type="time"]', r).forEach(function (n) { n.addEventListener('input', function () { syncStandard(); refreshDirty(); }); });
    });
    $$('[data-day-tile]').forEach(function (t) {
        t.addEventListener('click', function () {
            var r = $('.sc-table tbody tr[data-day="' + t.getAttribute('data-day-tile') + '"]');
            var cb = $('[data-hours-open]', r);
            cb.checked = !cb.checked;
            syncRow(r); syncStandard(); refreshDirty();
        });
    });
    syncStandard();

    if (standardOpen) {
        standardOpen.addEventListener('input', function (ev) {
            rows.forEach(function (r) { if ($('[data-hours-open]', r).checked && ev.target.value) { $('[data-hours-start]', r).value = ev.target.value; } });
            refreshDirty();
        });
    }
    if (standardClose) {
        standardClose.addEventListener('input', function (ev) {
            rows.forEach(function (r) { if ($('[data-hours-open]', r).checked && ev.target.value) { $('[data-hours-end]', r).value = ev.target.value; } });
            refreshDirty();
        });
    }

    // Same setting shown in two places (cleaning gap) stays in sync.
    $$('[data-cfg]').forEach(function (n) {
        n.addEventListener('input', function () {
            var k = n.getAttribute('data-cfg');
            $$('[data-cfg="' + k + '"]').forEach(function (o) { if (o !== n && o.type !== 'checkbox') { o.value = n.value; } });
            refreshDirty();
        });
        n.addEventListener('change', function () {
            var k = n.getAttribute('data-cfg');
            $$('[data-cfg="' + k + '"]').forEach(function (o) { if (o !== n && o.type === 'checkbox') { o.checked = n.checked; } });
            refreshDirty();
        });
    });
    $$('[data-therapist]').forEach(function (n) { n.addEventListener('change', refreshDirty); });

    function apply(state) {
        rows.forEach(function (r) {
            var h = state.hours[r.getAttribute('data-day')];
            if (!h) { return; }
            $('[data-hours-start]', r).value = h.open;
            $('[data-hours-end]', r).value = h.close;
            $('[data-hours-open]', r).checked = !!h.is_open;
            syncRow(r);
        });
        $$('[data-cfg]').forEach(function (n) {
            var v = state.config[n.getAttribute('data-cfg')];
            if (v === undefined) { return; }
            if (n.type === 'checkbox') { n.checked = v === '1'; } else { n.value = v; }
        });
        $$('[data-therapist]').forEach(function (n) { var v = state.therapists[n.getAttribute('data-therapist')]; if (v !== undefined) { n.value = v; } });
        syncStandard();
        refreshDirty();
    }

    function updateStatus(s) {
        if (!s) { return; }
        var statusText = $('#scStatusText');
        var statusDot = $('#scDot');
        if (statusText) { statusText.textContent = s.text; }
        if (statusDot) { statusDot.className = 'sc-dot ' + (s.open ? 'is-open' : 'is-closed'); }
        if (s.last) {
            var lastText = $('#scLastText');
            var lastBar = $('#scBarLast');
            if (lastText) { lastText.textContent = s.last; }
            if (lastBar) { lastBar.textContent = s.last + ' by ' + (s.last_by || 'Administrator'); }
        }
    }

    /* ---------- Save / reset ---------- */
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var current = collect();
            var fd = new FormData();
            fd.set('action', 'save_all');
            fd.set('payload', JSON.stringify(current));
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving…';
            post(fd).then(function (res) {
                if (res.ok) {
                    snapshot = JSON.stringify(current);
                    updateStatus(res.status);
                    toast(res.message);
                } else {
                    toast(res.error || 'Unable to save the configuration.');
                }
            }).catch(function () { toast('Unable to save the configuration right now.'); })
              .then(function () { saveBtn.textContent = 'Save Configuration Changes'; refreshDirty(); });
        });
    }
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            ask('Reset unsaved changes?', 'Everything you edited since the last save will be discarded.', 'Reset changes', function () {
                apply(JSON.parse(snapshot));
                toast('Unsaved changes were reset.');
            });
        });
    }
    window.addEventListener('beforeunload', function (ev) { if (JSON.stringify(collect()) !== snapshot) { ev.preventDefault(); ev.returnValue = ''; } });

    /* ---------- Review ---------- */
    var reviewBtn = $('#scReview');
    if (reviewBtn) {
        reviewBtn.addEventListener('click', function () {
            var body = $('#rvBody');
            if (!body) { return; }
            body.textContent = '';
            body.appendChild(el('p', 'empty', 'Loading…'));
            var reviewModal = $('#scReviewModal');
            if (reviewModal) { openModal(reviewModal); }
            api('/settings.php?view=review').then(function (d) {
                body.textContent = '';
                if (!d.ok) { body.appendChild(el('p', 'empty', d.error || 'Unable to load the review.')); return; }
                if (refreshDirty()) { body.appendChild(el('p', 'sc-note', 'You have unsaved changes. This review shows the saved configuration.')); }
                var dl = el('dl', 'sc-review-list');
                d.items.forEach(function (it) { var w = el('div'); w.appendChild(el('dt', null, it[0])); w.appendChild(el('dd', null, it[1])); dl.appendChild(w); });
                body.appendChild(dl);
                if (d.warnings.length) {
                    var w = el('div', 'sc-warn'); w.appendChild(el('strong', null, 'Needs attention'));
                    var ul = el('ul'); d.warnings.forEach(function (t) { ul.appendChild(el('li', null, t)); });
                    w.appendChild(ul); body.appendChild(w);
                } else { body.appendChild(el('p', 'sc-ok', 'Everything is configured and ready.')); }
            });
        });
    }

    /* ---------- Room rates ---------- */
    var rooms = window.SC_ROOMS || [];
    function money(v) { return '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function imgClass(type) {
        var t = type.toLowerCase();
        if (t.indexOf('couple') !== -1 || t.indexOf('family') !== -1) { return 'sc-img-2'; }
        if (t.indexOf('single') !== -1 || t.indexOf('massage') !== -1) { return 'sc-img-1'; }
        return 'sc-img-3';
    }
    function renderRooms() {
        var grid = $('#scRoomGrid'), sum = $('#scTariffSummary');
        grid.textContent = ''; sum.textContent = '';
        if (!rooms.length) { grid.appendChild(el('p', 'sc-empty', 'No rooms are registered yet.')); sum.appendChild(el('p', 'sc-note', 'No rooms are registered yet.')); return; }
        rooms.forEach(function (c) {
            var card = el('article', 'sc-room');
            card.appendChild(el('div', 'sc-room-img ' + imgClass(c.type)));
            var b = el('div', 'sc-room-body');
            b.appendChild(el('h3', null, c.type));
            b.appendChild(el('p', 'sc-room-count', c.count + (c.count === 1 ? ' room' : ' rooms') + ': ' + c.rooms.map(function (r) { return r.name; }).join(', ')));
            b.appendChild(el('p', 'sc-room-rate' + (c.current === null ? ' is-unset' : ''), c.label));
            var f = el('div', 'sc-room-foot');
            var btn = el('button', 'btn btn-outline btn-sm', c.current === null ? 'Set Rate' : 'Edit Rate');
            btn.type = 'button';
            btn.addEventListener('click', function () { openRate(c); });
            f.appendChild(btn); b.appendChild(f); card.appendChild(b); grid.appendChild(card);

            var t = el('div', 'sc-tariff');
            var l = el('div'); l.appendChild(el('strong', null, c.type)); l.appendChild(el('span', null, c.count + (c.count === 1 ? ' room' : ' rooms')));
            t.appendChild(l);
            t.appendChild(el('b', c.current === null ? 'is-unset' : '', c.current === null ? 'No rate set' : c.label.split(' · ')[0]));
            sum.appendChild(t);
        });
    }

    var rateModal = $('#scRateModal'), rateType = null;
    function openRate(c) {
        rateType = c.type;
        $('#srTitle').textContent = c.type;
        $('#srError').hidden = true;
        $('#srCurrent').textContent = c.current === null ? 'No rate has been set for this category yet.' : 'Current rate: ' + c.label;
        var sel = $('#srApply'); sel.textContent = '';
        var all = el('option', null, 'All ' + c.count + (c.count === 1 ? ' room' : ' rooms') + ' in this category'); all.value = '0'; sel.appendChild(all);
        if (c.count > 1) { c.rooms.forEach(function (r) { var o = el('option', null, r.name + (r.rate !== null ? ' (' + money(r.rate) + ')' : '')); o.value = String(r.id); sel.appendChild(o); }); }
        $('#srRate').value = c.current === null ? '' : c.current;
        openModal(rateModal);
        $('#srRate').focus();
    }
    $('#scRateForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var errEl = $('#srError'), v = $('#srRate').value.trim();
        errEl.hidden = true;
        if (v === '' || isNaN(Number(v)) || Number(v) <= 0) {
            errEl.textContent = 'Enter a valid rate greater than zero.'; errEl.hidden = false; $('#srRate').focus(); return;
        }
        var fd = new FormData();
        fd.set('action', 'save_rate'); fd.set('room_type', rateType); fd.set('room_id', $('#srApply').value); fd.set('rate', v);
        var btn = $('#srSave'); btn.disabled = true;
        post(fd).then(function (res) {
            if (!res.ok) { errEl.textContent = res.error || 'Unable to save the rate.'; errEl.hidden = false; return; }
            rooms = res.rooms; renderRooms(); updateStatus(res.status);
            closeModal(rateModal); toast(res.message);
        }).catch(function () { errEl.textContent = 'Unable to save the rate right now.'; errEl.hidden = false; })
          .then(function () { btn.disabled = false; });
    });

    renderRooms();
    refreshDirty();
})();