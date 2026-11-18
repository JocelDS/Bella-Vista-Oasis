(function () {
    'use strict';

    var apiBase = document.body.getAttribute('data-api') || '';

    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

    var toastEl = $('#toast');
    var toastTimer = null;
    function toast(msg) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 4200);
    }

    function post(form) {
        return fetch(apiBase + '/service-action.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            body: new FormData(form)
        }).then(function (res) {
            if (res.status === 401) { window.location.reload(); throw new Error('unauthorized'); }
            return res.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; });
        });
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

    function showError(node, msg) {
        node.textContent = msg;
        node.hidden = !msg;
    }

    function busy(btn, on, label) {
        btn.disabled = on;
        var span = btn.querySelector('span');
        if (span) { span.textContent = on ? 'Saving…' : label; }
    }

    function reloadSoon(msg) {
        toast(msg);
        setTimeout(function () { window.location.reload(); }, 1100);
    }

    var modal = $('#svModal');
    var form = $('#svForm');
    var errBox = $('#svError');
    var submit = $('#svSubmit');
    var codeInput = $('#sv_code');

    function fill(data) {
        $('#svId').value = data ? data.id : '';
        $('#sv_name').value = data ? data.service_name : '';
        codeInput.value = data ? data.room_code : '';
        $('#sv_price').value = data ? data.price : '';
        $('#sv_duration').value = data ? data.duration : 60;
        $('#sv_base').value = data ? data.base_capacity : 1;
        $('#sv_max').value = data ? data.max_capacity : 1;
        $('#sv_desc').value = data ? data.description : '';
        codeInput.readOnly = !!(data && data.bookings > 0);
        codeInput.title = codeInput.readOnly ? 'The room code is locked because this treatment has bookings.' : '';
    }

    function openCreate() {
        fill(null);
        $('#svAction').value = 'create_service';
        $('#svKicker').textContent = 'New Treatment';
        $('#svTitle').textContent = 'Add Treatment';
        showError(errBox, '');
        openModal(modal);
        $('#sv_name').focus();
    }

    function openEdit(data) {
        fill(data);
        $('#svAction').value = 'update_service';
        $('#svKicker').textContent = 'Edit Treatment';
        $('#svTitle').textContent = data.service_name;
        showError(errBox, '');
        openModal(modal);
        $('#sv_name').focus();
    }

    var newBtn = $('#svNew');
    if (newBtn) { newBtn.addEventListener('click', openCreate); }

    $$('[data-edit]').forEach(function (b) {
        b.addEventListener('click', function () {
            try { openEdit(JSON.parse(b.getAttribute('data-edit'))); } catch (e) { toast('Could not open this treatment.'); }
        });
    });

    codeInput.addEventListener('input', function () {
        var pos = codeInput.selectionStart;
        codeInput.value = codeInput.value.toUpperCase().replace(/[^A-Z]/g, '');
        if (pos !== null) { codeInput.setSelectionRange(pos, pos); }
    });

    function clientCheck() {
        var name = $('#sv_name').value.trim();
        var code = codeInput.value.trim();
        var dur = parseInt($('#sv_duration').value, 10);
        var price = $('#sv_price').value;
        var base = parseInt($('#sv_base').value, 10);
        var max = parseInt($('#sv_max').value, 10);
        if (name.length < 2) { return 'Enter the treatment name.'; }
        if (!/^[A-Za-z]{2,4}$/.test(code)) { return 'Room code must be 2 to 4 letters, for example SR, CS or FL.'; }
        if (price === '' || isNaN(parseFloat(price)) || parseFloat(price) < 0) { return 'Enter a valid price.'; }
        if (isNaN(dur) || dur < 15 || dur > 480 || dur % 5 !== 0) { return 'Duration must be between 15 and 480 minutes, in steps of 5.'; }
        if (isNaN(base) || base < 1 || base > 20) { return 'Base guests must be from 1 to 20.'; }
        if (isNaN(max) || max < 1 || max > 20) { return 'Maximum guests must be from 1 to 20.'; }
        if (max < base) { return 'Maximum guests cannot be lower than base guests.'; }
        return '';
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var problem = clientCheck();
        if (problem) { showError(errBox, problem); return; }
        showError(errBox, '');
        busy(submit, true, 'Save Treatment');
        post(form).then(function (res) {
            if (!res.ok) { showError(errBox, res.error || 'Unable to save the treatment.'); busy(submit, false, 'Save Treatment'); return; }
            closeModal(modal);
            reloadSoon(res.message || 'Saved.');
        }).catch(function () {
            showError(errBox, 'Network problem. Please try again.');
            busy(submit, false, 'Save Treatment');
        });
    });

    var delModal = $('#svDeleteModal');
    var delForm = $('#svDeleteForm');
    var delErr = $('#svDelError');
    var delBtn = $('#svDelSubmit');

    $$('[data-delete]').forEach(function (b) {
        b.addEventListener('click', function () {
            var data;
            try { data = JSON.parse(b.getAttribute('data-delete')); } catch (e) { toast('Could not open this treatment.'); return; }
            $('#svDelId').value = data.id;
            showError(delErr, '');
            $('#svDelText').textContent = data.bookings > 0
                ? data.service_name + ' has ' + data.bookings + ' booking' + (data.bookings === 1 ? '' : 's') + ' on record and cannot be deleted.'
                : 'This removes ' + data.service_name + ' from the customer booking page. This cannot be undone.';
            delBtn.disabled = data.bookings > 0;
            openModal(delModal);
        });
    });

    delForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        busy(delBtn, true, 'Delete Treatment');
        post(delForm).then(function (res) {
            if (!res.ok) { showError(delErr, res.error || 'Unable to delete the treatment.'); busy(delBtn, false, 'Delete Treatment'); return; }
            closeModal(delModal);
            reloadSoon(res.message || 'Deleted.');
        }).catch(function () {
            showError(delErr, 'Network problem. Please try again.');
            busy(delBtn, false, 'Delete Treatment');
        });
    });
}());