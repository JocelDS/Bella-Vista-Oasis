(function () {
    'use strict';

    var apiBase = document.body.getAttribute('data-api') || '';
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

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

    function post(fd) {
        fd.set('csrf', csrf);
        return fetch(apiBase + '/account.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (res) {
                if (res.status === 401) { window.location.reload(); throw new Error('unauthorized'); }
                return res.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; });
            })
            .catch(function (err) {
                if (err && err.message === 'unauthorized') { throw err; }
                return { ok: false, error: 'Network error. Check your connection and try again.' };
            });
    }
    function act(action, fields) {
        var fd = new FormData();
        fd.set('action', action);
        Object.keys(fields || {}).forEach(function (k) { fd.set(k, fields[k]); });
        return post(fd);
    }

    /* ---------- Tabs ---------- */
    function showTab(name) {
        $$('.sc-tab').forEach(function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        $$('.sc-panel').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
        var bar = $('#accBar');
        if (bar) { bar.classList.toggle('is-hidden', name !== 'profile'); }
    }
    $$('.sc-tab').forEach(function (t) {
        t.addEventListener('click', function () { showTab(t.getAttribute('data-tab')); });
    });

    /* ---------- Modals ---------- */
    var openModals = [];
    function openModal(m) { if (openModals.indexOf(m) === -1) { openModals.push(m); } m.hidden = false; var f = $('input', m); if (f) { f.focus(); } }
    function closeModal(m) { m.hidden = true; openModals = openModals.filter(function (x) { return x !== m; }); }
    $$('.modal').forEach(function (m) {
        m.addEventListener('mousedown', function (ev) { if (ev.target === m) { closeModal(m); } });
        $$('[data-close]', m).forEach(function (b) { b.addEventListener('click', function () { closeModal(m); }); });
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && openModals.length) { closeModal(openModals[openModals.length - 1]); }
    });

    /* ---------- Form state ---------- */
    var profileFields = $$('[data-profile]');
    var prefFields = $$('[data-pref]');
    var langBtns = $$('[data-lang]');
    var dirtyTag = $('#accDirty');

    function readState() {
        var s = { profile: {}, prefs: {} };
        profileFields.forEach(function (f) { s.profile[f.getAttribute('data-profile')] = f.value.trim(); });
        s.profile.languages = langBtns.filter(function (b) { return b.classList.contains('is-on'); }).map(function (b) { return b.getAttribute('data-lang'); });
        prefFields.forEach(function (f) { s.prefs[f.getAttribute('data-pref')] = f.type === 'checkbox' ? (f.checked ? '1' : '0') : f.value; });
        return s;
    }
    var saved = JSON.stringify(readState());

    function isDirty() { return JSON.stringify(readState()) !== saved; }
    function refreshDirty() { if (dirtyTag) { dirtyTag.hidden = !isDirty(); } }
    profileFields.concat(prefFields).forEach(function (f) {
        f.addEventListener('input', refreshDirty);
        f.addEventListener('change', refreshDirty);
    });
    var nameLive = $('#accNameLive');
    var nameInput = $('#accName');
    if (nameInput && nameLive) { nameInput.addEventListener('input', function () { nameLive.textContent = nameInput.value.trim() || ' '; }); }

    langBtns.forEach(function (b) {
        b.addEventListener('click', function () {
            var on = !b.classList.contains('is-on');
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            refreshDirty();
        });
    });
    window.addEventListener('beforeunload', function (ev) { if (isDirty()) { ev.preventDefault(); ev.returnValue = ''; } });

    function clearErrors() {
        $$('[data-err]').forEach(function (n) { n.textContent = ''; });
        $$('.field.has-error').forEach(function (f) { f.classList.remove('has-error'); });
    }
    function showErrors(fields) {
        Object.keys(fields || {}).forEach(function (k) {
            var n = $('[data-err="' + k + '"]');
            if (n) {
                n.textContent = fields[k];
                var fld = n.closest('.field');
                if (fld) { fld.classList.add('has-error'); }
            }
        });
    }

    /* ---------- Save ---------- */
    var commitBtn = $('#accCommit');
    var topBtn = $('#accSaveTop');
    var emailInput = $('#accEmail');
    var confirmModal = $('#accConfirmModal');
    var confirmPass = $('#accConfPass');
    var confirmErr = $('#accConfErr');

    function setSaving(on) {
        [commitBtn, topBtn].forEach(function (b) { if (b) { b.disabled = on; } });
    }

    function save(confirmPassword) {
        clearErrors();
        var state = readState();
        var fd = new FormData();
        fd.set('action', 'save_all');
        fd.set('payload', JSON.stringify(Object.assign({}, state, confirmPassword ? { confirm_password: confirmPassword } : {})));
        setSaving(true);
        return post(fd).then(function (res) {
            setSaving(false);
            if (res.ok) {
                saved = JSON.stringify(state);
                if (emailInput) { emailInput.setAttribute('data-original', state.profile.email.toLowerCase()); }
                refreshDirty();
                if (confirmModal) { closeModal(confirmModal); }
                if (confirmPass) { confirmPass.value = ''; }
                if (res.name) {
                    $$('.profile-name').forEach(function (n) { n.textContent = res.name; });
                    $$('.avatar-dark').forEach(function (n) { n.textContent = res.initials || n.textContent; });
                    var init = $('#accAvatarInit');
                    if (init) { init.textContent = res.initials || init.textContent; }
                }
                document.body.setAttribute('data-density', state.prefs.density);
                document.body.setAttribute('data-idle', state.prefs.idle_minutes);
                toast(res.message || 'Account settings saved.');
                return;
            }
            if (res.need_password) {
                if (confirmErr) { confirmErr.textContent = res.error; confirmErr.hidden = !res.error; }
                if (confirmModal) { openModal(confirmModal); }
                return;
            }
            if (confirmModal && !confirmModal.hidden && confirmErr) { confirmErr.textContent = res.error || 'Unable to save.'; confirmErr.hidden = false; }
            showErrors(res.fields);
            toast(res.error || 'Unable to save your changes.');
        });
    }

    function commit() {
        var emailChanged = emailInput && emailInput.value.trim().toLowerCase() !== (emailInput.getAttribute('data-original') || '').toLowerCase();
        if (emailChanged) {
            if (confirmErr) { confirmErr.hidden = true; }
            if (confirmPass) { confirmPass.value = ''; }
            if (confirmModal) { openModal(confirmModal); return; }
        }
        save('');
    }
    if (commitBtn) { commitBtn.addEventListener('click', commit); }
    if (topBtn) { topBtn.addEventListener('click', commit); }
    var confirmOk = $('#accConfOk');
    if (confirmOk) {
        confirmOk.addEventListener('click', function () {
            if (!confirmPass || !confirmPass.value) {
                if (confirmErr) { confirmErr.textContent = 'Enter your master passphrase.'; confirmErr.hidden = false; }
                return;
            }
            save(confirmPass.value);
        });
    }

    var discard = $('#accDiscard');
    if (discard) {
        discard.addEventListener('click', function () {
            if (!isDirty()) { toast('There are no unsaved changes.'); return; }
            window.location.reload();
        });
    }

    /* ---------- Photo ---------- */
    var photoBtn = $('#accPhotoBtn');
    var photoInput = $('#accPhotoInput');
    var photoRemove = $('#accPhotoRemove');
    var avatarImg = $('#accAvatarImg');
    var avatarInit = $('#accAvatarInit');
    if (photoBtn && photoInput) {
        photoBtn.addEventListener('click', function () { photoInput.click(); });
        photoInput.addEventListener('change', function () {
            var file = photoInput.files && photoInput.files[0];
            if (!file) { return; }
            if (file.size > 2 * 1024 * 1024) { toast('The image must be 2 MB or smaller.'); photoInput.value = ''; return; }
            var fd = new FormData();
            fd.set('action', 'upload_avatar');
            fd.set('avatar', file);
            photoBtn.disabled = true;
            post(fd).then(function (res) {
                photoBtn.disabled = false;
                photoInput.value = '';
                if (res.ok && res.url) {
                    avatarImg.src = res.url + '?t=' + Date.now();
                    avatarImg.hidden = false;
                    avatarInit.hidden = true;
                    photoRemove.hidden = false;
                }
                toast(res.ok ? res.message : (res.error || 'Unable to upload the photo.'));
            });
        });
    }
    if (photoRemove) {
        photoRemove.addEventListener('click', function () {
            act('remove_avatar').then(function (res) {
                if (res.ok) {
                    avatarImg.hidden = true;
                    avatarImg.removeAttribute('src');
                    avatarInit.hidden = false;
                    photoRemove.hidden = true;
                }
                toast(res.ok ? res.message : (res.error || 'Unable to remove the photo.'));
            });
        });
    }

    /* ---------- Passphrase ---------- */
    var passModal = $('#accPassModal');
    var passErr = $('#accPassErr');
    var passBtn = $('#accPassBtn');
    var passSave = $('#accPassSave');
    var pc = $('#accPassCur'), pn = $('#accPassNew'), pk = $('#accPassCon');
    if (passBtn && passModal) {
        passBtn.addEventListener('click', function () {
            [pc, pn, pk].forEach(function (i) { i.value = ''; });
            passErr.hidden = true;
            openModal(passModal);
        });
    }
    if (passSave) {
        passSave.addEventListener('click', function () {
            passErr.hidden = true;
            if (!pc.value || !pn.value || !pk.value) { passErr.textContent = 'Fill in all three fields.'; passErr.hidden = false; return; }
            passSave.disabled = true;
            act('change_password', { current: pc.value, 'new': pn.value, confirm: pk.value }).then(function (res) {
                passSave.disabled = false;
                if (res.ok) {
                    closeModal(passModal);
                    [pc, pn, pk].forEach(function (i) { i.value = ''; });
                    var rv = $('#accRevokeBtn');
                    if (rv) { rv.disabled = true; }
                    toast(res.message);
                } else {
                    passErr.textContent = res.error || 'Unable to update the passphrase.';
                    passErr.hidden = false;
                }
            });
        });
    }

    /* ---------- Remembered devices ---------- */
    var revoke = $('#accRevokeBtn');
    if (revoke) {
        revoke.addEventListener('click', function () {
            revoke.disabled = true;
            act('revoke_devices').then(function (res) {
                if (!res.ok) { revoke.disabled = false; }
                toast(res.ok ? res.message : (res.error || 'Unable to sign out devices.'));
            });
        });
    }
})();