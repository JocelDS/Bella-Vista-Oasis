(function () {
    'use strict';

    var apiBase = document.body.getAttribute('data-api') || '';
    var adminBase = apiBase.replace(/\/api$/, '');
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
    function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') : ''; }
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    function debounce(fn, ms) { var t; return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); }; }

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
    function post(formData) {
        formData.set('csrf', csrf);
        return api('/customer-action.php', { method: 'POST', body: formData });
    }

    /* ---------- Modals ---------- */
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

    var confirmModal = $('#cmConfirmModal');
    var confirmAction = null;
    function ask(title, text, okLabel, onOk) {
        $('#ccTitle').textContent = title;
        $('#ccText').textContent = text;
        $('#ccOk').textContent = okLabel || 'Confirm';
        confirmAction = onOk;
        openModal(confirmModal);
    }
    $('#ccOk').addEventListener('click', function () {
        var fn = confirmAction;
        confirmAction = null;
        closeModal(confirmModal);
        if (fn) { fn(); }
    });

    /* ---------- Shared builders ---------- */
    var SVG_PATHS = {
        chat: 'M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2zM8 9h.01M12 9h.01M16 9h.01M8 13h8',
        note: 'M3 6h11M3 12h7M3 18h5M20.5 11.5l-1-1a1.4 1.4 0 0 0-2 0L11 17l-.8 2.8L13 19l6.5-6.5a1.4 1.4 0 0 0 1-1z',
        upload: 'M12 17V5m0 0-5 5m5-5 5 5M6 21h12'
    };
    function svgIcon(name) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('class', 'cm-act-ico');
        svg.setAttribute('aria-hidden', 'true');
        var path = document.createElementNS(ns, 'path');
        path.setAttribute('d', SVG_PATHS[name]);
        svg.appendChild(path);
        return svg;
    }
    function avatar(g, cls) {
        var a = el('span', 'cm-avatar' + (cls ? ' ' + cls : ''), g.photo ? '' : g.initials);
        if (g.photo) { a.style.backgroundImage = 'url("' + g.photo + '")'; }
        a.setAttribute('aria-hidden', 'true');
        return a;
    }
    function badge(text, cls) { return el('span', 'cm-badge ' + (cls || ''), text); }
    function statusBadge(s) { return badge(cap(s), 'is-' + s); }
    function kv(list, cls) {
        var dl = el('dl', cls || 'cm-kv');
        list.forEach(function (p) {
            var d = el('div');
            d.appendChild(el('dt', null, p[0]));
            d.appendChild(el('dd', null, p[1] === null || p[1] === '' ? '—' : String(p[1])));
            dl.appendChild(d);
        });
        return dl;
    }
    function apptLink(code) {
        var a = el('a', null, code);
        a.href = adminBase + '/appointments.php?q=' + encodeURIComponent(code);
        return a;
    }

    /* ---------- State ---------- */
    var state = { q: '', status: 'all', tier: 'all', reg: 'any', spend: 'any', sort: 'newest', period: '30', page: 1 };
    var selectedId = null;
    var listController = null;
    var listCache = {};

    function params(extra) {
        var p = new URLSearchParams({ q: state.q, status: state.status, tier: state.tier, reg: state.reg, spend: state.spend, sort: state.sort, period: state.period, page: String(state.page) });
        Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
        return p;
    }

    /* ---------- Stats ---------- */
    function renderStats(s) {
        if (!s) { return; }
        ['total', 'new', 'active', 'revenue', 'retention'].forEach(function (k) {
            var v = $('[data-cm-stat="' + k + '"]');
            var n = $('[data-cm-note="' + k + '"]');
            if (v) { v.textContent = s[k]; }
            if (n) { n.textContent = s.notes[k]; }
        });
    }

    /* ---------- Directory ---------- */
    var listEl = $('#cmList');
    function guestCard(g) {
        listCache[g.id] = g;
        var card = el('article', 'cm-guest' + (g.id === selectedId ? ' is-selected' : ''));
        card.setAttribute('data-id', String(g.id));
        card.tabIndex = 0;
        card.appendChild(avatar(g));

        var main = el('div', 'cm-main');
        var top = el('div', 'cm-top');
        top.appendChild(el('h3', 'cm-name', g.name));
        top.appendChild(el('span', 'cm-code', g.code));
        top.appendChild(badge(cap(g.status), 'is-' + g.status));
        top.appendChild(g.tier ? badge(g.tier, 'is-tier') : badge('No membership', ''));
        main.appendChild(top);

        var contact = el('div', 'cm-contact');
        contact.appendChild(el('span', null, g.email || 'No email on file'));
        contact.appendChild(el('span', null, g.phone || 'No contact number'));
        main.appendChild(contact);

        main.appendChild(kv([
            ['Registered', g.registered],
            ['Appointments', g.appts],
            ['Last visit', g.last_visit],
            ['Upcoming', g.upcoming || 'None booked'],
            ['Total spending', g.spent_label],
            ['Favorite service', g.top_service || '—']
        ], 'cm-meta'));

        var actions = el('div', 'cm-actions');
        var view = el('button', 'btn btn-primary btn-sm', 'View Profile'); view.type = 'button';
        var edit = el('button', 'btn btn-outline btn-sm', 'Edit'); edit.type = 'button';
        var hist = el('button', 'btn btn-soft btn-sm', 'Appointment History'); hist.type = 'button';
        var more = el('div', 'cm-more');
        var moreBtn = el('button', 'btn btn-outline btn-sm', 'More Actions'); moreBtn.type = 'button';
        moreBtn.setAttribute('aria-haspopup', 'true');
        more.appendChild(moreBtn);
        actions.appendChild(view); actions.appendChild(edit); actions.appendChild(hist); actions.appendChild(more);
        main.appendChild(actions);
        card.appendChild(main);

        view.addEventListener('click', function (ev) { ev.stopPropagation(); select(g.id); openProfile(g.id, false); });
        edit.addEventListener('click', function (ev) { ev.stopPropagation(); select(g.id); openForm(g.id); });
        hist.addEventListener('click', function (ev) { ev.stopPropagation(); select(g.id); openProfile(g.id, true); });
        moreBtn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var existing = $('.cm-menu', more);
            closeMenus();
            if (existing) { return; }
            var menu = el('div', 'cm-menu');
            menu.setAttribute('role', 'menu');
            function item(label, fn, cls) {
                var b = el('button', cls || '', label); b.type = 'button'; b.setAttribute('role', 'menuitem');
                b.addEventListener('click', function (e) { e.stopPropagation(); closeMenus(); fn(); });
                menu.appendChild(b);
            }
            item('Book appointment', function () { openWalk(g); });
            item('Copy customer ID', function () {
                if (navigator.clipboard) { navigator.clipboard.writeText(g.code).then(function () { toast(g.code + ' copied.'); }, function () { toast(g.code); }); } else { toast(g.code); }
            });
            var next = g.status === 'active' ? 'inactive' : 'active';
            item('Mark as ' + next, function () { changeStatus(g, next); }, next === 'inactive' ? 'is-danger' : '');
            item('Delete guest', function () { deleteGuest(g); }, 'is-danger');
            more.appendChild(menu);
        });

        card.addEventListener('click', function () { select(g.id); });
        card.addEventListener('keydown', function (ev) {
            if ((ev.key === 'Enter' || ev.key === ' ') && ev.target === card) { ev.preventDefault(); select(g.id); }
        });
        return card;
    }
    function closeMenus() { $$('.cm-menu').forEach(function (m) { m.parentNode.removeChild(m); }); }
    document.addEventListener('click', closeMenus);

    function renderList(d) {
        listEl.textContent = '';
        listCache = {};
        if (!d.rows.length) {
            var none = el('div', 'empty');
            none.appendChild(el('p', null, state.q || state.status !== 'all' || state.tier !== 'all' || state.reg !== 'any' || state.spend !== 'any'
                ? 'No guests match these filters.' : 'No guests registered yet.'));
            listEl.appendChild(none);
        }
        d.rows.forEach(function (g) { listEl.appendChild(guestCard(g)); });
        $('#cmCount').textContent = d.total + (d.total === 1 ? ' guest' : ' guests');
        $('#cmSummary').textContent = d.summary;
        renderPager(d);
    }

    function renderPager(d) {
        var nav = $('#cmPager');
        nav.textContent = '';
        if (d.pages <= 1) { return; }
        function btn(label, page, opts) {
            var b = el('button', opts && opts.current ? 'is-current' : '', label);
            b.type = 'button';
            if (opts && opts.disabled) { b.disabled = true; }
            if (opts && opts.aria) { b.setAttribute('aria-label', opts.aria); }
            if (opts && opts.current) { b.setAttribute('aria-current', 'page'); }
            b.addEventListener('click', function () { state.page = page; loadList(false); });
            nav.appendChild(b);
        }
        btn('‹', d.page - 1, { disabled: d.page <= 1, aria: 'Previous page' });
        var start = Math.max(1, Math.min(d.page - 2, d.pages - 4));
        var end = Math.min(d.pages, start + 4);
        for (var i = start; i <= end; i++) { btn(String(i), i, { current: i === d.page }); }
        btn('›', d.page + 1, { disabled: d.page >= d.pages, aria: 'Next page' });
    }

    function loadList(withStats, keepSelection) {
        if (listController) { listController.abort(); }
        listController = new AbortController();
        var p = params({ stats: withStats ? '1' : '0' });
        return api('/customers.php?' + p.toString(), { signal: listController.signal }).then(function (d) {
            if (!d.ok) { throw new Error(d.error || 'Request failed'); }
            state.page = d.page;
            renderList(d);
            if (withStats) { renderStats(d.stats); }
            if (!keepSelection && d.rows.length && !d.rows.some(function (g) { return g.id === selectedId; })) { select(d.rows[0].id); }
            if (!d.rows.length && !keepSelection) { selectedId = null; renderSideEmpty(); }
        }).catch(function (err) {
            if (err && (err.name === 'AbortError' || err.message === 'unauthorized')) { return; }
            toast('Unable to load guests. Please try again.');
        });
    }
    function refreshAll() { return loadList(true, true).then(function () { if (selectedId) { loadSide(selectedId); } }); }

    /* ---------- Filters ---------- */
    var fields = { q: '#fQ', status: '#fStatus', tier: '#fTier', reg: '#fReg', spend: '#fSpend', sort: '#fSort' };
    var debouncedSearch = debounce(function () { state.page = 1; loadList(false); }, 280);
    Object.keys(fields).forEach(function (k) {
        var n = $(fields[k]);
        n.addEventListener(k === 'q' ? 'input' : 'change', function () {
            state[k] = n.value.trim();
            if (k === 'q') { debouncedSearch(); } else { state.page = 1; loadList(false); }
        });
    });
    $('#cmPeriod').addEventListener('change', function (ev) {
        state.period = ev.target.value;
        api('/customers.php?' + params({ stats: '1', per: '1' }).toString()).then(function (d) { if (d.ok) { renderStats(d.stats); } });
    });
    $('#fClear').addEventListener('click', function () {
        state.q = ''; state.status = 'all'; state.tier = 'all'; state.reg = 'any'; state.spend = 'any'; state.sort = 'newest'; state.page = 1;
        Object.keys(fields).forEach(function (k) { $(fields[k]).value = state[k]; });
        loadList(false);
    });
    $('#cmExport').addEventListener('click', function () {
        var p = params(); p.delete('page');
        window.location.href = apiBase + '/customer-export.php?' + p.toString();
    });

    /* ---------- Side panel ---------- */
    var sideEl = $('#cmSide');
    var sideToken = 0;
    function renderSideEmpty() { sideEl.textContent = ''; sideEl.appendChild(el('p', 'empty', 'Select a guest to see their details.')); }
    function select(id) {
        selectedId = id;
        $$('.cm-guest').forEach(function (c) { c.classList.toggle('is-selected', Number(c.getAttribute('data-id')) === id); });
        loadSide(id);
    }
    function loadSide(id) {
        var token = ++sideToken;
        api('/customer-detail.php?id=' + id).then(function (d) {
            if (token !== sideToken) { return; }
            if (!d.ok) { sideEl.textContent = ''; sideEl.appendChild(el('p', 'empty', d.error || 'Unable to load this guest.')); return; }
            renderSide(d);
        });
    }
    function renderSide(d) {
        var c = d.customer;
        sideEl.textContent = '';
        var head = el('div', 'cm-side-head');
        head.appendChild(avatar(c, 'cm-avatar-xl'));
        head.appendChild(el('p', 'label-small', 'Selected guest · ' + c.code));
        head.appendChild(el('h2', 'cm-side-name', c.name));
        var bs = el('div', 'cm-side-badges');
        bs.appendChild(badge(cap(c.status), 'is-' + c.status));
        bs.appendChild(c.tier ? badge(c.tier, 'is-tier') : badge('No membership', ''));
        head.appendChild(bs);
        head.appendChild(el('p', 'cm-side-contact', c.email || 'No email on file'));
        head.appendChild(el('p', 'cm-side-contact', c.phone || 'No contact number'));
        sideEl.appendChild(head);

        var info = el('div', 'cm-block');
        info.appendChild(el('p', 'cm-block-title', 'Customer information'));
        info.appendChild(kv([['Registered', c.registered], ['Total visits', d.counts.completed], ['Total spending', c.spent_label], ['Last visit', c.last_visit]]));
        sideEl.appendChild(info);

        var al = el('div', 'cm-block');
        al.appendChild(el('p', 'cm-block-title', 'Customer alerts'));
        if (!d.alerts.length) { al.appendChild(el('p', 'cm-empty-note', 'No alerts for this guest.')); }
        d.alerts.forEach(function (a) {
            var n = el('div', 'cm-alert' + (a.tone === 'warn' ? ' is-warn' : ''));
            n.appendChild(el('strong', null, a.title));
            n.appendChild(el('span', null, a.text));
            al.appendChild(n);
        });
        sideEl.appendChild(al);

        var act = el('div', 'cm-block');
        act.appendChild(el('p', 'cm-block-title', 'Recent activity'));
        if (!d.activity.length) { act.appendChild(el('p', 'cm-empty-note', 'No activity recorded yet.')); }
        var ul = el('ul', 'cm-feed');
        d.activity.forEach(function (a) {
            var li = el('li');
            var w = el('div');
            w.appendChild(el('strong', null, a.title));
            w.appendChild(el('span', null, a.meta));
            li.appendChild(w);
            ul.appendChild(li);
        });
        act.appendChild(ul);
        sideEl.appendChild(act);

        var btns = el('div', 'cm-side-actions');
        function abtn(label, icon, cls, fn) {
            var b = el('button', 'cm-act ' + cls);
            b.type = 'button';
            b.appendChild(svgIcon(icon));
            b.appendChild(el('span', null, label));
            b.addEventListener('click', fn);
            btns.appendChild(b);
        }
        abtn('Send Concierge Welcome SMS', 'chat', 'cm-act-sms', function () { welcomeSms(c); });
        abtn('Add Private Note', 'note', 'cm-act-soft', function () { openForm(c.id, 'cfNotes'); });
        abtn('Adjust Tier', 'upload', 'cm-act-soft', function () { adjustTier(c); });
        sideEl.appendChild(btns);
    }

    /* ---------- Side-panel actions ---------- */
    function welcomeSms(c) {
        var phone = (c.phone || '').replace(/[^\d+]/g, '');
        if (!phone) { toast('Add a contact number for this guest first.'); return; }
        var first = (c.first_name || c.name.split(' ')[0] || '').trim();
        var body = 'Welcome to Bella Vista Oasis' + (first ? ', ' + first : '') + '. We are delighted to have you. Reply to this message anytime for bookings or special requests.';
        ask('Send concierge welcome?',
            'This opens your messaging app with a welcome message for ' + c.name + ' (' + c.phone + '). Nothing is sent until you press send there.',
            'Open messages',
            function () { window.location.href = 'sms:' + phone + '?body=' + encodeURIComponent(body); });
    }
    function adjustTier(c) {
        var url = sideEl.getAttribute('data-memberships');
        if (url) { window.location.href = url; return; }
        toast('Membership tiers are managed in Membership & Loyalty, which is not available yet.');
    }

    /* ---------- Status change ---------- */
    function changeStatus(g, next) {
        ask(next === 'inactive' ? 'Mark guest inactive?' : 'Reactivate guest?',
            g.name + ' (' + g.code + ') will be marked ' + next + '. Appointment and payment history is kept.',
            next === 'inactive' ? 'Mark inactive' : 'Reactivate',
            function () {
                var fd = new FormData();
                fd.set('action', 'status'); fd.set('id', String(g.id)); fd.set('status', next);
                post(fd).then(function (res) {
                    toast(res.ok ? res.message : (res.error || 'Unable to update the guest.'));
                    if (res.ok) { refreshAll(); }
                });
            });
    }

    function deleteGuest(g) {
        ask('Delete guest permanently?',
            g.name + ' (' + g.code + ') and all related appointments, payments, membership and loyalty records will be removed. This cannot be undone.',
            'Delete permanently',
            function () {
                var fd = new FormData();
                fd.set('action', 'delete'); fd.set('id', String(g.id));
                post(fd).then(function (res) {
                    toast(res.ok ? res.message : (res.error || 'Unable to delete the guest.'));
                    if (res.ok) { refreshAll(); }
                });
            });
    }

    /* ---------- Customer form ---------- */
    var form = $('#cmForm');
    var formModal = $('#cmFormModal');
    var preview = $('#cfPreview');
    var addAnother = false;

    function clearErrors(f, errEl) {
        $$('.is-invalid', f).forEach(function (n) { n.classList.remove('is-invalid'); });
        errEl.hidden = true; errEl.textContent = '';
    }
    function showErrors(f, errEl, res) {
        errEl.textContent = res.error || 'Unable to save.';
        errEl.hidden = false;
        if (res.fields) {
            Object.keys(res.fields).forEach(function (k) {
                var n = f.querySelector('[name="' + k + '"]');
                if (n) { n.classList.add('is-invalid'); }
            });
        }
        var first = f.querySelector('.is-invalid');
        if (first) { first.focus(); }
    }
    function resetPreview(name, url) {
        preview.style.backgroundImage = url ? 'url("' + url + '")' : '';
        preview.textContent = url ? '' : (name || '?');
    }
    $('#cfPhoto').addEventListener('change', function (ev) {
        var f = ev.target.files && ev.target.files[0];
        if (!f) { resetPreview('?', null); return; }
        if (f.size > 2097152 || ['image/jpeg', 'image/png', 'image/webp'].indexOf(f.type) === -1) {
            ev.target.value = '';
            resetPreview('?', null);
            toast('Photo must be a JPG, PNG or WebP image up to 2 MB.');
            return;
        }
        resetPreview('', URL.createObjectURL(f));
    });

    function today() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    function splitName(c) {
        if (c.first_name || c.last_name) { return [c.first_name, c.middle_name, c.last_name]; }
        var parts = c.name.trim().split(/\s+/);
        return [parts[0] || '', '', parts.slice(1).join(' ')];
    }

    function openForm(id, focusField) {
        clearErrors(form, $('#cfError'));
        form.reset();
        $('#cfPhoto').value = '';
        var editing = !!id;
        $('#cfAction').value = editing ? 'update' : 'create';
        $('#cfId').value = editing ? String(id) : '';
        $('#cmFormKicker').textContent = editing ? 'Edit Guest' : 'Guest Registration';
        $('#cmFormTitle').textContent = editing ? 'Edit Customer' : 'New Customer';
        $('#cfSave').textContent = editing ? 'Save Changes' : 'Save Customer';
        $('#cfSaveAdd').hidden = editing;
        $('#cfReg').disabled = editing;
        resetPreview('?', null);
        if (!editing) {
            $('#cfReg').value = today();
            openModal(formModal);
            $('#cfFirst').focus();
            return;
        }
        api('/customer-detail.php?id=' + id).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load this guest.'); return; }
            var c = d.customer, n = splitName(c);
            $('#cfFirst').value = n[0]; $('#cfMiddle').value = n[1]; $('#cfLast').value = n[2];
            $('#cfEmail').value = c.email; $('#cfPhone').value = c.phone; $('#cfDob').value = c.dob;
            $('#cfGender').value = c.gender; $('#cfAddress').value = c.address;
            $('#cfReg').value = c.registered_raw; $('#cfStatus').value = c.status;
            $('#cfNotes').value = c.notes; $('#cfPrefs').value = c.preferences; $('#cfSpecial').value = c.special_requests;
            resetPreview(c.initials, c.photo);
            openModal(formModal);
            var target = focusField ? $('#' + focusField) : $('#cfFirst');
            target.focus();
            if (focusField) { target.scrollIntoView({ block: 'center' }); }
        });
    }
    $('#cmNew').addEventListener('click', function () { openForm(null); });

    function clientValidate() {
        var bad = [];
        [['cfFirst', 'First name'], ['cfLast', 'Last name'], ['cfPhone', 'Contact number']].forEach(function (p) {
            var n = $('#' + p[0]);
            if (!n.value.trim()) { n.classList.add('is-invalid'); bad.push(p[1]); }
        });
        if (!$('#cfId').value && !$('#cfEmail').value.trim()) { $('#cfEmail').classList.add('is-invalid'); bad.push('Email address'); }
        if ($('#cfEmail').value.trim() && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test($('#cfEmail').value.trim())) { $('#cfEmail').classList.add('is-invalid'); bad.push('a valid email'); }
        if (bad.length) {
            var e = $('#cfError');
            e.textContent = 'Please complete: ' + bad.join(', ') + '.';
            e.hidden = false;
            var f = form.querySelector('.is-invalid');
            if (f) { f.focus(); }
            return false;
        }
        return true;
    }

    function submitForm() {
        clearErrors(form, $('#cfError'));
        if (!clientValidate()) { return; }
        var btn = addAnother ? $('#cfSaveAdd') : $('#cfSave');
        btn.disabled = true;
        var fd = new FormData(form);
        if ($('#cfReg').disabled) { fd.delete('registered_at'); }
        post(fd).then(function (res) {
            if (!res.ok) { showErrors(form, $('#cfError'), res); return; }
            toast(res.message);
            selectedId = res.id;
            if (addAnother) {
                var keepDate = $('#cfReg').value;
                form.reset(); $('#cfPhoto').value = ''; resetPreview('?', null);
                $('#cfReg').value = keepDate;
                $('#cfFirst').focus();
            } else {
                closeModal(formModal);
            }
            state.page = 1;
            refreshAll();
        }).catch(function () { toast('Unable to save the guest right now.'); })
          .then(function () { btn.disabled = false; addAnother = false; });
    }
    form.addEventListener('submit', function (ev) { ev.preventDefault(); addAnother = false; submitForm(); });
    $('#cfSaveAdd').addEventListener('click', function () { addAnother = true; submitForm(); });

    /* ---------- Walk-in / booking ---------- */
    var walkModal = $('#cmWalkModal');
    var walkForm = $('#cmWalkForm');
    var suggestEl = $('#cwSuggest');

    function setExisting(g) {
        $('#cwCustomerId').value = String(g.id);
        $('#cwName').value = g.name; $('#cwPhone').value = g.phone || ''; $('#cwEmail').value = g.email || '';
        ['cwName', 'cwPhone', 'cwEmail'].forEach(function (id) { $('#' + id).readOnly = true; });
        $('#cwExistingName').textContent = g.name;
        $('#cwExistingCode').textContent = '(' + g.code + ')';
        $('#cwExisting').hidden = false;
        suggestEl.hidden = true;
    }
    function clearExisting(keepText) {
        $('#cwCustomerId').value = '';
        ['cwName', 'cwPhone', 'cwEmail'].forEach(function (id) { var n = $('#' + id); n.readOnly = false; if (!keepText) { n.value = ''; } });
        $('#cwExisting').hidden = true;
    }
    function openWalk(guest) {
        clearErrors(walkForm, $('#cwError'));
        walkForm.reset();
        clearExisting(false);
        suggestEl.hidden = true;
        var d = new Date();
        var mins = Math.ceil((d.getMinutes() + 30) / 15) * 15;
        d.setMinutes(mins, 0, 0);
        $('#cwDate').value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        $('#cwTime').value = pad(d.getHours()) + ':' + pad(d.getMinutes());
        $('#cwDate').min = today();
        if (guest) {
            $('#cwKicker').textContent = 'Book Appointment';
            $('#cwTitle').textContent = 'Book for ' + guest.name;
            $('#cwSave').textContent = 'Book Appointment';
            setExisting(guest);
        } else {
            $('#cwKicker').textContent = 'Walk-in Guest';
            $('#cwTitle').textContent = 'Register Walk-in Guest';
            $('#cwSave').textContent = 'Register & Book';
        }
        openModal(walkModal);
        (guest ? $('#cwService') : $('#cwName')).focus();
    }
    $('#cmWalkin').addEventListener('click', function () { openWalk(null); });
    $('#cwUseNew').addEventListener('click', function () { clearExisting(true); $('#cwName').focus(); });

    var suggest = debounce(function () {
        var q = $('#cwName').value.trim();
        if ($('#cwCustomerId').value || q.length < 2) { suggestEl.hidden = true; return; }
        api('/customers.php?stats=0&per=5&q=' + encodeURIComponent(q)).then(function (d) {
            if (!d.ok || !d.rows.length || $('#cwCustomerId').value) { suggestEl.hidden = true; return; }
            suggestEl.textContent = '';
            d.rows.forEach(function (g) {
                var li = el('li');
                li.tabIndex = 0;
                li.appendChild(el('span', null, g.name));
                li.appendChild(el('small', null, g.code + ' · ' + (g.phone || g.email)));
                function pick() { setExisting(g); }
                li.addEventListener('click', pick);
                li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { pick(); } });
                suggestEl.appendChild(li);
            });
            suggestEl.hidden = false;
        });
    }, 250);
    $('#cwName').addEventListener('input', suggest);
    document.addEventListener('click', function (ev) { if (!ev.target.closest('.cm-suggest-wrap')) { suggestEl.hidden = true; } });

    walkForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        clearErrors(walkForm, $('#cwError'));
        var bad = [];
        var existing = !!$('#cwCustomerId').value;
        var req = existing ? ['cwService', 'cwDate', 'cwTime', 'cwHealth', 'cwInjuries', 'cwPreg'] : ['cwName', 'cwPhone', 'cwService', 'cwDate', 'cwTime', 'cwHealth', 'cwInjuries', 'cwPreg'];
        req.forEach(function (id) { var n = $('#' + id); if (!n.value.trim()) { n.classList.add('is-invalid'); bad.push(id); } });
        if (bad.length) {
            $('#cwError').textContent = 'Please complete the highlighted fields.';
            $('#cwError').hidden = false;
            walkForm.querySelector('.is-invalid').focus();
            return;
        }
        var btn = $('#cwSave');
        btn.disabled = true;
        post(new FormData(walkForm)).then(function (res) {
            if (res.customer_id && !res.ok) {
                // Guest was saved; keep them selected so a retry never creates a duplicate.
                setExisting({ id: res.customer_id, name: $('#cwName').value.trim(), code: 'CUS-' + String(res.customer_id).padStart(4, '0'), phone: $('#cwPhone').value, email: $('#cwEmail').value });
                refreshAll();
            }
            if (!res.ok) { showErrors(walkForm, $('#cwError'), res); return; }
            toast(res.message + (res.customer_reused ? ' Existing guest record used.' : ''));
            closeModal(walkModal);
            selectedId = res.customer_id;
            state.page = 1;
            refreshAll();
        }).catch(function () { toast('Unable to complete the booking right now.'); })
          .then(function () { btn.disabled = false; });
    });

    /* ---------- Profile ---------- */
    var profileModal = $('#cmProfileModal');
    var profileBody = $('#cpBody');
    var profileId = null;

    function table(headers, rows, emptyText) {
        var wrap = el('div', 'cm-table-wrap');
        var t = el('table', 'cm-table');
        var thead = el('thead'), tr = el('tr');
        headers.forEach(function (h) { tr.appendChild(el('th', null, h)); });
        thead.appendChild(tr); t.appendChild(thead);
        var tb = el('tbody');
        if (!rows.length) {
            var r = el('tr'); var td = el('td', 'cm-none', emptyText); td.colSpan = headers.length; r.appendChild(td); tb.appendChild(r);
        }
        rows.forEach(function (cells) {
            var r = el('tr');
            cells.forEach(function (c) { var td = el('td'); if (c instanceof Node) { td.appendChild(c); } else { td.textContent = c; } r.appendChild(td); });
            tb.appendChild(r);
        });
        t.appendChild(tb); wrap.appendChild(t);
        return wrap;
    }
    function historyRows(list) {
        return list.map(function (a) {
            return [apptLink(a.code), a.service, a.therapist, a.room, a.date, a.time, statusBadge(a.status), statusBadge(a.payment), a.amount];
        });
    }
    function section(title, node) {
        var s = el('div', 'cm-block');
        s.appendChild(el('p', 'cm-block-title', title));
        s.appendChild(node);
        return s;
    }
    function textBlock(t) { return t ? el('div', 'cm-text-block', t) : el('p', 'cm-empty-note', 'Nothing recorded.'); }

    var histHolder = null;
    function renderHistory(d) {
        histHolder.textContent = '';
        var tabs = el('div', 'tabs');
        tabs.setAttribute('role', 'tablist');
        [['all', 'All Appointments'], ['upcoming', 'Upcoming'], ['completed', 'Completed'], ['cancelled', 'Cancelled'], ['no_show', 'No-show']].forEach(function (t) {
            var b = el('button', 'tab' + (d.filter === t[0] ? ' is-active' : ''));
            b.type = 'button';
            b.appendChild(document.createTextNode(t[1] + ' '));
            b.appendChild(el('span', 'count', String(d.counts[t[0]])));
            b.addEventListener('click', function () {
                api('/customer-detail.php?id=' + profileId + '&filter=' + t[0]).then(function (r) { if (r.ok) { renderHistory(r); } });
            });
            tabs.appendChild(b);
        });
        histHolder.appendChild(tabs);
        var gap = el('div'); gap.style.height = '12px'; histHolder.appendChild(gap);
        histHolder.appendChild(table(['ID', 'Service', 'Therapist', 'Room', 'Date', 'Time', 'Status', 'Payment', 'Amount'], historyRows(d.history), 'No appointments in this view.'));
    }

    function renderProfile(d, toHistory) {
        var c = d.customer;
        $('#cpTitle').textContent = c.name;
        profileBody.textContent = '';

        var head = el('div', 'cm-p-head');
        head.appendChild(avatar(c, 'cm-avatar-xl'));
        var mid = el('div');
        mid.appendChild(el('p', 'label-small', c.code));
        mid.appendChild(el('h3', 'cm-side-name', c.name));
        var bs = el('div', 'cm-side-badges'); bs.style.justifyContent = 'flex-start'; bs.style.marginTop = '6px';
        bs.appendChild(badge(cap(c.status), 'is-' + c.status));
        bs.appendChild(c.tier ? badge(c.tier + (c.tier_expiry !== '—' ? ' · until ' + c.tier_expiry : ''), 'is-tier') : badge('No membership', ''));
        bs.appendChild(badge(d.stats.returning ? 'Returning guest' : 'Guest', ''));
        mid.appendChild(bs);
        head.appendChild(mid);
        var hb = el('div', 'cm-actions');
        var e1 = el('button', 'btn btn-outline btn-sm', 'Edit'); e1.type = 'button';
        e1.addEventListener('click', function () { closeModal(profileModal); openForm(c.id); });
        var e2 = el('button', 'btn btn-primary btn-sm', 'Book Appointment'); e2.type = 'button';
        e2.addEventListener('click', function () { openWalk(c); });
        hb.appendChild(e1); hb.appendChild(e2);
        head.appendChild(hb);
        profileBody.appendChild(head);

        profileBody.appendChild(section('Customer overview', kv([
            ['Total spending', c.spent_label], ['Total appointments', c.appts], ['Last visit', c.last_visit],
            ['Upcoming', c.upcoming || 'None booked'], ['Membership', c.tier ? c.tier : 'None']
        ], 'cm-p-grid')));

        var cols = el('div', 'cm-p-cols cm-block');
        var left = el('div'); left.appendChild(el('p', 'cm-block-title', 'Personal details'));
        left.appendChild(kv([
            ['Customer ID', c.code], ['Full name', c.name], ['Email', c.email], ['Contact number', c.phone],
            ['Address', c.address], ['Date of birth', c.dob_label], ['Registered', c.registered]
        ], 'cm-kv cm-kv-1'));
        var right = el('div'); right.appendChild(el('p', 'cm-block-title', 'Customer statistics'));
        right.appendChild(kv([
            ['Most booked service', d.stats.top_service], ['Average spend / paid visit', d.stats.avg_spend],
            ['Completed appointments', d.stats.completed], ['Lifetime spending', d.stats.lifetime],
            ['Cancelled bookings', d.counts.cancelled], ['Upcoming bookings', d.counts.upcoming]
        ]));
        cols.appendChild(left); cols.appendChild(right);
        profileBody.appendChild(cols);

        histHolder = el('div');
        var hs = section('Appointment history', histHolder);
        hs.id = 'cpHistory';
        profileBody.appendChild(hs);
        renderHistory(d);

        profileBody.appendChild(section('Payment history', table(['Appointment', 'Amount', 'Method', 'Status', 'Date'],
            d.payments.map(function (p) { return [apptLink(p.code), p.amount, p.method, statusBadge(p.status), p.date]; }), 'No payments recorded.')));

        var prefs = el('div', 'cm-p-cols');
        var pl = el('div');
        if (d.preferred.length) {
            var chips = el('div');
            d.preferred.forEach(function (s) { chips.appendChild(el('span', 'chip', s)); });
            pl.appendChild(section('Preferred services', chips));
        } else { pl.appendChild(section('Preferred services', el('p', 'cm-empty-note', 'No bookings yet.'))); }
        pl.appendChild(section('Preferences', textBlock(c.preferences)));
        var pr = el('div');
        pr.appendChild(section('Special requests', textBlock(c.special_requests)));
        pr.appendChild(section('Internal notes', textBlock(c.notes)));
        prefs.appendChild(pl); prefs.appendChild(pr);
        profileBody.appendChild(prefs);

        if (toHistory) { hs.scrollIntoView({ block: 'start' }); } else { profileBody.parentNode.scrollTop = 0; }
    }

    function openProfile(id, toHistory) {
        profileId = id;
        profileBody.textContent = '';
        profileBody.appendChild(el('p', 'empty', 'Loading…'));
        openModal(profileModal);
        api('/customer-detail.php?id=' + id).then(function (d) {
            if (!d.ok) { profileBody.textContent = ''; profileBody.appendChild(el('p', 'empty', d.error || 'Unable to load this guest.')); return; }
            renderProfile(d, toHistory);
        });
    }

    /* ---------- Init ---------- */
    loadList(true, false);
})();