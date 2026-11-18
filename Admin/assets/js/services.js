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


    // Spa Ritual Add-ons: integrated into this page and backed by the existing API.
    var addonRows = $('#addonRows');
    var addonSummary = $('#addonSummary');
    var addonModal = $('#addonModal');
    var addonForm = $('#addonForm');
    var addonError = $('#addonError');
    var addonSaveBtn = $('#addonSaveBtn');
    var addons = [];

    function addonRequest(options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = options.headers || { 'Accept': 'application/json' };
        return fetch(apiBase + '/service-addons.php', options).then(function (res) {
            if (res.status === 401) { window.location.reload(); throw new Error('unauthorized'); }
            return res.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; });
        });
    }

    function addonEscape(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c];
        });
    }

    function addonMoney(value) {
        return '₱' + Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderAddons() {
        var active = addons.filter(function (a) { return a.active; }).length;
        addonSummary.innerHTML = '<span><strong>' + addons.length + '</strong> Total Add-ons</span>' +
            '<span><strong>' + active + '</strong> Active</span>' +
            '<span><strong>' + (addons.length - active) + '</strong> Inactive</span>';
        if (!addons.length) {
            addonRows.innerHTML = '<tr><td colspan="6" class="sv-empty">No spa ritual add-ons yet. Click “Add New Add-on” to create one.</td></tr>';
            return;
        }
        addonRows.innerHTML = addons.map(function (a) {
            return '<tr>' +
                '<td><p class="cell-title">' + addonEscape(a.name) + '</p><p class="cell-sub">' + addonEscape(a.description || '') + '</p></td>' +
                '<td class="num">' + addonMoney(a.price) + '</td>' +
                '<td>' + Number(a.sort_order || 0) + '</td>' +
                '<td>' + (a.commissionable ? 'Yes' : 'No') + '</td>' +
                '<td><span class="badge ' + (a.active ? 'rs-available' : 'rs-maintenance') + '">' + (a.active ? 'Active' : 'Inactive') + '</span></td>' +
                '<td class="num"><div class="sv-actions"><button type="button" class="btn btn-outline btn-sm" data-addon-edit="' + a.id + '">Edit</button>' +
                '<button type="button" class="btn btn-outline btn-sm" data-addon-toggle="' + a.id + '" data-active="' + (a.active ? '1' : '0') + '">' + (a.active ? 'Deactivate' : 'Activate') + '</button></div></td>' +
                '</tr>';
        }).join('');
    }

    function loadAddons() {
        if (!addonRows) return;
        addonRequest().then(function (res) {
            if (!res.ok) {
                addonRows.innerHTML = '<tr><td colspan="6" class="sv-empty">' + addonEscape(res.error || 'Unable to load add-ons.') + '</td></tr>';
                addonSummary.textContent = res.setup_required ? 'Database setup required' : 'Add-ons unavailable';
                return;
            }
            addons = Array.isArray(res.addons) ? res.addons : [];
            renderAddons();
        }).catch(function () {
            addonRows.innerHTML = '<tr><td colspan="6" class="sv-empty">Connection error while loading add-ons. Refresh the page to try again.</td></tr>';
        });
    }

    function closeAddonModal() { if (addonModal) closeModal(addonModal); }
    function openAddonModal(addon) {
        addonForm.reset();
        $('#addonId').value = addon ? addon.id : '';
        $('#addonAction').value = addon ? 'update' : 'create';
        $('#addonName').value = addon ? addon.name : '';
        $('#addonDescription').value = addon ? addon.description : '';
        $('#addonPrice').value = addon ? addon.price : '';
        $('#addonSort').value = addon ? addon.sort_order : 0;
        $('#addonCommissionable').checked = addon ? !!addon.commissionable : true;
        $('#addonActive').checked = addon ? !!addon.active : true;
        $('#addonTitle').textContent = addon ? 'Edit Add-on' : 'Add New Add-on';
        addonSaveBtn.textContent = 'Save Add-on';
        addonSaveBtn.disabled = false;
        showError(addonError, '');
        openModal(addonModal);
        $('#addonName').focus();
    }

    if (addonModal && addonForm) {
        $('#addonAddBtn').addEventListener('click', function () { openAddonModal(null); });
        $$('[data-addon-close]').forEach(function (b) { b.addEventListener('click', closeAddonModal); });
        addonModal.addEventListener('mousedown', function (ev) { if (ev.target === addonModal) closeAddonModal(); });

        addonRows.addEventListener('click', function (ev) {
            var editBtn = ev.target.closest('[data-addon-edit]');
            var toggleBtn = ev.target.closest('[data-addon-toggle]');
            if (editBtn) {
                var found = addons.find(function (a) { return String(a.id) === editBtn.getAttribute('data-addon-edit'); });
                if (found) openAddonModal(found);
            }
            if (toggleBtn) {
                var id = toggleBtn.getAttribute('data-addon-toggle');
                var next = toggleBtn.getAttribute('data-active') !== '1';
                var body = new FormData(addonForm);
                body.set('action', 'set_active');
                body.set('id', id);
                body.set('is_active', next ? '1' : '0');
                addonRequest({ method: 'POST', body: body }).then(function (res) {
                    if (!res.ok) { toast(res.error || 'Unable to update add-on status.'); return; }
                    toast(res.message || 'Add-on status updated.');
                    loadAddons();
                }).catch(function () { toast('Unable to update add-on status.'); });
            }
        });

        addonForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            showError(addonError, '');
            addonSaveBtn.disabled = true;
            addonSaveBtn.textContent = 'Saving…';
            addonRequest({ method: 'POST', body: new FormData(addonForm) }).then(function (res) {
                if (!res.ok) { showError(addonError, res.error || 'Unable to save add-on.'); return; }
                closeAddonModal();
                toast(res.message || 'Add-on saved successfully.');
                loadAddons();
            }).catch(function () {
                showError(addonError, 'Network problem. Please try again.');
            }).finally(function () {
                addonSaveBtn.disabled = false;
                addonSaveBtn.textContent = 'Save Add-on';
            });
        });
        loadAddons();
    }

}());