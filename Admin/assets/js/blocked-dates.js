(function () {
    'use strict';

    var form = document.getElementById('bdForm');
    var listEl = document.getElementById('bdList');
    if (!form || !listEl) { return; }

    var apiBase = document.body.getAttribute('data-api') || '';
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var dateInput = document.getElementById('bdDate');
    var reasonInput = document.getElementById('bdReason');
    var errEl = document.getElementById('bdError');
    var warnEl = document.getElementById('bdWarn');
    var countEl = document.getElementById('bdCount');
    var submitBtn = document.getElementById('bdSubmit');
    var toastEl = document.getElementById('toast');
    var toastTimer = null;
    var todayIso = dateInput.getAttribute('min') || '';

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function toast(text) {
        if (!toastEl) { return; }
        toastEl.textContent = text;
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

    function showError(text) {
        errEl.textContent = text;
        errEl.hidden = !text;
    }

    function showWarning(text) {
        warnEl.textContent = text || '';
        warnEl.hidden = !text;
    }

    function post(fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.set(k, fields[k]); });
        fd.set('csrf', csrf);
        return api('/blocked-dates.php', { method: 'POST', body: fd });
    }

    function render(rows) {
        listEl.textContent = '';
        var upcoming = rows.filter(function (r) { return !r.past; }).length;
        if (countEl) { countEl.textContent = upcoming + (upcoming === 1 ? ' upcoming block' : ' upcoming blocks'); }

        if (!rows.length) {
            listEl.appendChild(el('li', 'bd-empty', 'No dates are blocked right now.'));
            return;
        }

        rows.forEach(function (r) {
            var li = el('li', 'bd-item' + (r.past ? ' is-past' : ''));
            var main = el('div', 'bd-main');
            var top = el('div', 'bd-top');
            top.appendChild(el('strong', 'bd-date', r.label));
            if (r.past) { top.appendChild(el('span', 'sc-tag bd-tag', 'Past')); }
            if (r.bookings > 0) { top.appendChild(el('span', 'sc-tag bd-tag bd-tag-warn', r.bookings + (r.bookings === 1 ? ' booking' : ' bookings'))); }
            main.appendChild(top);
            main.appendChild(el('p', 'bd-reason', r.reason || 'No reason given'));

            var actions = el('div', 'bd-actions');
            var remove = el('button', 'btn btn-outline btn-sm', 'Remove');
            remove.type = 'button';
            remove.setAttribute('aria-label', 'Remove block on ' + r.label);
            remove.addEventListener('click', function () { askRemove(li, actions, r); });
            actions.appendChild(remove);

            li.appendChild(main);
            li.appendChild(actions);
            listEl.appendChild(li);
        });
    }

    function askRemove(li, actions, r) {
        actions.textContent = '';
        li.classList.add('is-confirming');
        var text = r.bookings > 0
            ? 'Remove this block? ' + r.bookings + (r.bookings === 1 ? ' booking' : ' bookings') + ' on this date will be bookable again.'
            : 'Remove this block?';
        var note = el('p', 'bd-confirm', text);
        note.setAttribute('role', 'alert');
        var yes = el('button', 'btn btn-primary btn-sm', 'Yes, remove');
        yes.type = 'button';
        var no = el('button', 'btn btn-outline btn-sm', 'Keep');
        no.type = 'button';
        actions.appendChild(note);
        actions.appendChild(yes);
        actions.appendChild(no);
        yes.focus();

        no.addEventListener('click', function () { load(); });
        yes.addEventListener('click', function () {
            yes.disabled = true;
            no.disabled = true;
            post({ action: 'remove', blocked_date: r.date }).then(function (res) {
                if (res.dates) { render(res.dates); }
                if (res.today) { todayIso = res.today; }
                if (!res.ok) { toast(res.error || 'Unable to remove the block.'); return; }
                showError('');
                showWarning('');
                toast(res.message || 'Blocked date removed.');
            }).catch(function () {
                toast('Unable to remove the block right now.');
                load();
            });
        });
    }

    function load() {
        return api('/blocked-dates.php').then(function (res) {
            if (!res.ok) {
                listEl.textContent = '';
                listEl.appendChild(el('li', 'bd-empty', res.error || 'Unable to load blocked dates.'));
                return;
            }
            if (res.today) { todayIso = res.today; }
            render(res.dates || []);
        }).catch(function () {
            listEl.textContent = '';
            listEl.appendChild(el('li', 'bd-empty', 'Unable to load blocked dates.'));
        });
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        showError('');
        showWarning('');
        var date = dateInput.value.trim();
        var reason = reasonInput.value.trim();

        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            showError('Select a valid date.');
            dateInput.focus();
            return;
        }
        if (todayIso && date < todayIso) {
            showError('Choose today or a later date. Past dates cannot be blocked.');
            dateInput.focus();
            return;
        }
        if (reason === '') {
            showError('Enter a reason for the closure.');
            reasonInput.focus();
            return;
        }

        submitBtn.disabled = true;
        post({ action: 'add', blocked_date: date, reason: reason }).then(function (res) {
            if (res.today) { todayIso = res.today; }
            if (!res.ok) {
                showError(res.error || 'Unable to save the blocked date.');
                return;
            }
            form.reset();
            render(res.dates || []);
            toast(res.message || 'Blocked date saved.');
            if (res.warning) { showWarning(res.warning); }
        }).catch(function () {
            showError('Unable to save the blocked date right now.');
        }).then(function () {
            submitBtn.disabled = false;
        });
    });

    load();
}());