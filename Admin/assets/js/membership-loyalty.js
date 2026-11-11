(function () {
    'use strict';

    var apiBase = document.body.getAttribute('data-api') || '';
    var statsEl = document.querySelector('.stats-ml');
    var photoBase = statsEl ? statsEl.getAttribute('data-photo-base') : '';

    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }
    function btn(label, cls, fn) {
        var b = el('button', cls, label);
        b.type = 'button';
        b.addEventListener('click', fn);
        return b;
    }
    function num(n) { return Number(n || 0).toLocaleString('en-US'); }
    function peso(n) { return '₱' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function dateLabel(s) {
        if (!s) { return '–'; }
        var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1] : s;
    }
    function dateTimeLabel(s) {
        var m = String(s || '').match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/);
        if (!m) { return dateLabel(s); }
        var h = parseInt(m[2], 10);
        return dateLabel(m[1]) + ' · ' + ((h % 12) || 12) + ':' + m[3] + ' ' + (h >= 12 ? 'PM' : 'AM');
    }
    function initials(name) {
        var w = String(name || '').match(/[A-Za-zÀ-ɏ]+/g) || [];
        if (!w.length) { return '?'; }
        return (w[0][0] + (w.length > 1 ? w[w.length - 1][0] : '')).toUpperCase();
    }
    function token() {
        var a = new Uint8Array(12);
        (window.crypto || window.msCrypto).getRandomValues(a);
        return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }
    function debounce(fn, ms) {
        var t = null;
        return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); };
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
    function get(params) {
        var q = Object.keys(params).filter(function (k) { return params[k] !== '' && params[k] !== null && params[k] !== undefined; })
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
        return api('/membership.php?' + q);
    }
    function postForm(form, extra) {
        var fd = new FormData(form);
        if (extra) { Object.keys(extra).forEach(function (k) { fd.set(k, extra[k]); }); }
        return api('/membership-action.php', { method: 'POST', body: fd });
    }
    function postData(obj) {
        var fd = new FormData();
        var csrf = $('input[name="csrf"]');
        fd.set('csrf', csrf ? csrf.value : '');
        Object.keys(obj).forEach(function (k) { fd.set(k, obj[k]); });
        return api('/membership-action.php', { method: 'POST', body: fd });
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

    function showError(id, msg) {
        var n = $(id);
        if (!n) { return; }
        n.textContent = msg || '';
        n.hidden = !msg;
    }
    function busy(button, on, label) {
        if (!button) { return; }
        button.disabled = on;
        var s = $('span', button);
        if (s) {
            if (on) { button.setAttribute('data-label', s.textContent); s.textContent = label || 'Saving…'; }
            else if (button.hasAttribute('data-label')) { s.textContent = button.getAttribute('data-label'); }
        }
    }

    var data = null;
    var tiersById = {};
    var ledger = { page: 1, sort: 'desc' };
    var currentMember = null;

    function statusBadge(status) {
        var map = { active: 'ok', completed: 'ok', inactive: 'off', expired: 'bad', reversed: 'bad', cancelled: 'bad', pending: 'warn' };
        var b = el('span', 'ml-badge is-' + (map[status] || 'off'));
        b.appendChild(el('i', 'ml-dot'));
        b.appendChild(document.createTextNode(String(status).charAt(0).toUpperCase() + String(status).slice(1)));
        return b;
    }
    function tierDot(color, name) {
        var s = el('span', 'tier-chip');
        var d = el('i', 'tier-dot');
        d.style.background = color || '#85877F';
        s.appendChild(d);
        s.appendChild(document.createTextNode(name || '–'));
        return s;
    }
    function tierColor(name) {
        for (var k in tiersById) { if (tiersById[k].name === name) { return tiersById[k].color; } }
        return '#85877F';
    }

    function loadOverview() {
        var p = { view: 'overview', from: $('#rgFrom').value, to: $('#rgTo').value };
        return get(p).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load membership data.'); return; }
            data = d;
            tiersById = {};
            d.tiers.forEach(function (t) { tiersById[t.id] = t; });
            renderStats();
            renderTiers();
            renderRewards();
            fillTierSelects();
            if (!$('#rulesModal').hidden) { renderRules(); }
            if (!$('#rewardsModal').hidden) { renderRewardList(); }
        }).catch(function () { toast('Unable to load membership data.'); });
    }

    function renderStats() {
        var s = data.stats;
        $('#sMembers').textContent = num(s.total_members);
        $('#sMembersNote').textContent = s.total_members ? 'Registered memberships' : 'No members yet';
        $('#sActive').textContent = num(s.active_members);
        $('#sActiveNote').textContent = s.total_members ? (s.total_members - s.active_members) + ' expired or inactive' : 'Currently valid';
        $('#sIssued').textContent = num(s.issued);
        $('#sRedeemed').textContent = num(s.redeemed);
        $('#sRedeemedNote').textContent = num(s.outstanding) + ' points outstanding';
        $('#sRate').textContent = (s.rate || 0).toFixed(1).replace(/\.0$/, '') + '%';
        $('#sRateNote').textContent = s.issued ? 'Redeemed vs issued points' : 'No points issued yet';
    }

    function listBlock(title, items) {
        var w = el('div', 'tier-list');
        w.appendChild(el('p', 'tier-list-h', title));
        if (!items.length) { w.appendChild(el('p', 'cell-sub', 'None set')); return w; }
        var ul = el('ul');
        items.forEach(function (i) { ul.appendChild(el('li', null, i)); });
        w.appendChild(ul);
        return w;
    }

    function renderTiers() {
        var g = $('#tierGrid');
        g.textContent = '';
        if (!data.tiers.length) {
            g.appendChild(el('p', 'empty', 'No membership tiers yet. Create the first tier.'));
            return;
        }
        data.tiers.forEach(function (t) {
            var c = el('article', 'tier-card' + (t.status === 'active' ? '' : ' is-off'));
            var top = el('div', 'tier-top');
            top.style.background = t.color;
            top.appendChild(el('span', 'tier-badge', t.name.charAt(0).toUpperCase()));
            var heads = el('div', 'tier-heads');
            heads.appendChild(el('h3', 'tier-name', t.name));
            heads.appendChild(el('p', 'tier-members', num(t.members) + (t.members === 1 ? ' member' : ' members')));
            top.appendChild(heads);
            c.appendChild(top);
            var body = el('div', 'tier-body');
            var row = el('div', 'tier-row');
            row.appendChild(statusBadge(t.status));
            row.appendChild(el('span', 'tier-disc', t.discount + '% off'));
            body.appendChild(row);
            if (t.description) { body.appendChild(el('p', 'tier-desc', t.description)); }
            var req = el('div', 'tier-req');
            req.appendChild(el('span', 'tier-list-h', 'Qualification'));
            var rt = [];
            if (t.min_spending > 0) { rt.push(peso(t.min_spending) + ' qualifying spend'); }
            if (t.min_points > 0) { rt.push(num(t.min_points) + ' points earned'); }
            req.appendChild(el('span', 'tier-req-v', rt.length ? rt.join(' · ') : 'Entry level, no minimum'));
            req.appendChild(el('span', 'cell-sub', t.multiplier + 'x points multiplier'));
            body.appendChild(req);
            body.appendChild(listBlock('Benefits', t.benefits));
            body.appendChild(listBlock('Privileges', t.privileges));
            c.appendChild(body);
            var foot = el('div', 'tier-foot');
            foot.appendChild(btn('Edit Tier', 'btn btn-outline btn-sm', function () { openTier(t); }));
            foot.appendChild(btn(t.status === 'active' ? 'Deactivate' : 'Activate', 'btn btn-soft btn-sm', function () {
                if (t.status === 'active') {
                    askConfirm('Deactivate ' + t.name + '?', 'No new members can be assigned to this tier. Existing members and their history are kept.', 'Deactivate', function () { setTierStatus(t, 'inactive'); });
                } else { setTierStatus(t, 'active'); }
            }));
            c.appendChild(foot);
            g.appendChild(c);
        });
    }

    function setTierStatus(t, status) {
        postData({ action: 'tier_status', id: t.id, status: status }).then(function (r) {
            toast(r.message || r.error || 'Done.');
            if (r.ok) { refreshAll(); }
        });
    }

    function fillTierSelects() {
        [['#mbTier', 'All tiers'], ['#lgTier', 'All tiers']].forEach(function (x) {
            var s = $(x[0]);
            var cur = s.value;
            s.textContent = '';
            var o = el('option', null, x[1]);
            o.value = '';
            s.appendChild(o);
            data.tiers.forEach(function (t) { var op = el('option', null, t.name); op.value = String(t.id); s.appendChild(op); });
            s.value = cur;
        });
    }

    function imageNode(r, cls) {
        var w = el('div', cls);
        if (r.image) {
            var img = el('img');
            img.src = photoBase + r.image;
            img.alt = r.name;
            img.loading = 'lazy';
            img.addEventListener('error', function () { w.removeChild(img); w.classList.add('is-blank'); w.appendChild(el('span', 'rw-ph', initials(r.name))); });
            w.appendChild(img);
        } else {
            w.classList.add('is-blank');
            w.appendChild(el('span', 'rw-ph', initials(r.name)));
        }
        return w;
    }

    function rewardAvail(r) {
        var today = new Date();
        var t = today.getFullYear() + '-' + ('0' + (today.getMonth() + 1)).slice(-2) + '-' + ('0' + today.getDate()).slice(-2);
        if (r.status !== 'active') { return { cls: 'off', text: 'Inactive' }; }
        if (r.from && r.from > t) { return { cls: 'warn', text: 'Starts ' + dateLabel(r.from) }; }
        if (r.until && r.until < t) { return { cls: 'bad', text: 'Expired' }; }
        if (r.quantity !== null && r.quantity <= 0) { return { cls: 'bad', text: 'Out of stock' }; }
        return { cls: 'ok', text: 'Available' };
    }

    function renderRewards() {
        var g = $('#rewardGrid');
        g.textContent = '';
        var rows = data.rewards.filter(function (r) { return r.status === 'active'; });
        if (!rows.length) {
            g.appendChild(el('p', 'empty', data.rewards.length ? 'No active rewards. Activate one from Manage Rewards.' : 'No rewards yet. Click Create Reward to add one.'));
            return;
        }
        rows.forEach(function (r) {
            var c = el('article', 'reward-card');
            var im = imageNode(r, 'reward-img');
            im.appendChild(el('span', 'reward-cat', r.category));
            c.appendChild(im);
            var b = el('div', 'reward-body');
            b.appendChild(el('h3', 'reward-name', r.name));
            b.appendChild(el('p', 'reward-desc', r.description || 'No description'));
            var pts = el('p', 'reward-points');
            pts.appendChild(el('strong', null, num(r.points)));
            pts.appendChild(document.createTextNode(' points'));
            b.appendChild(pts);
            var meta = [];
            meta.push(r.quantity === null ? 'Unlimited stock' : num(r.quantity) + ' left');
            if (r.max_member !== null) { meta.push('Limit ' + r.max_member + ' per member'); }
            if (r.until) { meta.push('Until ' + dateLabel(r.until)); }
            b.appendChild(el('p', 'cell-sub', meta.join(' · ')));
            var av = rewardAvail(r);
            var row = el('div', 'reward-foot');
            var badge = el('span', 'ml-badge is-' + av.cls);
            badge.appendChild(el('i', 'ml-dot'));
            badge.appendChild(document.createTextNode(av.text));
            row.appendChild(badge);
            row.appendChild(btn('Edit Reward', 'link-btn', function () { openReward(r); }));
            b.appendChild(row);
            c.appendChild(b);
            g.appendChild(c);
        });
    }

    function renderRewardList() {
        var tb = $('#rwList');
        tb.textContent = '';
        if (!data.rewards.length) {
            var tr = el('tr', 'empty-row');
            var td = el('td', null, 'No rewards yet.');
            td.colSpan = 7;
            tr.appendChild(td);
            tb.appendChild(tr);
            return;
        }
        data.rewards.forEach(function (r) {
            var tr = el('tr');
            var c1 = el('td');
            var g = el('div', 'guest-cell');
            g.appendChild(imageNode(r, 'rw-thumb'));
            var tx = el('div');
            tx.appendChild(el('span', 'guest-name', r.name));
            tx.appendChild(el('span', 'cell-sub', r.description || ''));
            g.appendChild(tx);
            c1.appendChild(g);
            tr.appendChild(c1);
            tr.appendChild(el('td', null, r.category));
            tr.appendChild(el('td', 'num', num(r.points)));
            tr.appendChild(el('td', null, r.quantity === null ? 'Unlimited' : num(r.quantity) + ' left'));
            tr.appendChild(el('td', null, r.max_member === null ? 'No limit' : r.max_member + ' / member'));
            var st = el('td');
            var av = rewardAvail(r);
            var badge = el('span', 'ml-badge is-' + av.cls);
            badge.appendChild(el('i', 'ml-dot'));
            badge.appendChild(document.createTextNode(av.text));
            st.appendChild(badge);
            tr.appendChild(st);
            var ac = el('td', 'ml-actions');
            ac.appendChild(btn('Edit', 'link-btn', function () { openReward(r); }));
            ac.appendChild(btn(r.status === 'active' ? 'Deactivate' : 'Activate', 'link-btn', function () {
                postData({ action: 'reward_status', id: r.id, status: r.status === 'active' ? 'inactive' : 'active' }).then(function (res) {
                    toast(res.message || res.error || 'Done.');
                    if (res.ok) { loadOverview(); }
                });
            }));
            tr.appendChild(ac);
            tb.appendChild(tr);
        });
    }

    var memberFilter = { q: '', tier: '', status: '' };
    function loadMembers() {
        return get({ view: 'members', q: memberFilter.q, tier: memberFilter.tier, status: memberFilter.status }).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load members.'); return; }
            var tb = $('#mbBody');
            tb.textContent = '';
            if (!d.members.length) {
                var tr = el('tr', 'empty-row');
                var td = el('td', null, memberFilter.q || memberFilter.tier || memberFilter.status ? 'No members match these filters.' : 'No members yet.');
                td.colSpan = 8;
                tr.appendChild(td);
                tb.appendChild(tr);
            }
            d.members.forEach(function (m) {
                var tr = el('tr');
                var c1 = el('td');
                var g = el('div', 'guest-cell');
                g.appendChild(el('span', 'avatar', initials(m.name)));
                var tx = el('div');
                tx.appendChild(el('span', 'guest-name', m.name));
                tx.appendChild(el('span', 'cell-sub', m.code));
                g.appendChild(tx);
                c1.appendChild(g);
                tr.appendChild(c1);
                var c2 = el('td');
                c2.appendChild(tierDot(m.color, m.tier));
                tr.appendChild(c2);
                tr.appendChild(el('td', 'num', num(m.balance)));
                var c4 = el('td');
                c4.appendChild(statusBadge(m.status));
                tr.appendChild(c4);
                tr.appendChild(el('td', 'nw', dateLabel(m.start)));
                tr.appendChild(el('td', 'nw', dateLabel(m.expiry)));
                tr.appendChild(el('td', 'num', peso(m.spent)));
                var ac = el('td', 'ml-actions');
                ac.appendChild(btn('View', 'link-btn', function () { openMember(m.customer_id); }));
                ac.appendChild(btn('Change Tier', 'link-btn', function () { openTierChange(m.customer_id); }));
                tr.appendChild(ac);
                tb.appendChild(tr);
            });
            $('#mbCount').textContent = d.total + (d.total === 1 ? ' member' : ' members');
        }).catch(function () { toast('Unable to load members.'); });
    }

    function ledgerParams() {
        return {
            view: 'ledger', q: $('#lgSearch').value.trim(), type: $('#lgType').value, tier: $('#lgTier').value, status: $('#lgStatus').value,
            from: $('#lgFrom').value, to: $('#lgTo').value, page: ledger.page, per: 10, sort: ledger.sort
        };
    }
    function loadLedger() {
        return get(ledgerParams()).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load transactions.'); return; }
            ledger.page = d.page;
            var tb = $('#lgBody');
            tb.textContent = '';
            if (!d.rows.length) {
                var tr = el('tr', 'empty-row');
                var td = el('td', null, 'No loyalty transactions found.');
                td.colSpan = 10;
                tr.appendChild(td);
                tb.appendChild(tr);
            }
            d.rows.forEach(function (r) {
                var tr = el('tr');
                tr.appendChild(el('td', 'mono', r.code));
                var c2 = el('td');
                c2.appendChild(btn(r.member, 'link-btn link-name', function () { openMember(r.customer_id); }));
                tr.appendChild(c2);
                var c3 = el('td');
                c3.appendChild(tierDot(tierColor(r.tier), r.tier));
                tr.appendChild(c3);
                tr.appendChild(el('td', 'nw', r.type_label));
                tr.appendChild(el('td', 'num pos', r.earned ? '+' + num(r.earned) : '–'));
                tr.appendChild(el('td', 'num neg', r.redeemed ? '−' + num(r.redeemed) : '–'));
                tr.appendChild(el('td', 'ml-desc', r.description || '–'));
                tr.appendChild(el('td', 'nw', dateTimeLabel(r.date)));
                var c9 = el('td');
                c9.appendChild(statusBadge(r.status));
                tr.appendChild(c9);
                var ac = el('td', 'ml-actions');
                ac.appendChild(btn('Details', 'link-btn', function () { openTx(r.id); }));
                tr.appendChild(ac);
                tb.appendChild(tr);
            });
            var from = d.total ? (d.page - 1) * d.per + 1 : 0;
            var to = Math.min(d.total, d.page * d.per);
            $('#lgCount').textContent = d.total ? 'Showing ' + from + '–' + to + ' of ' + d.total : '0 transactions';
            renderPager(d);
        }).catch(function () { toast('Unable to load transactions.'); });
    }
    function renderPager(d) {
        var p = $('#lgPager');
        p.textContent = '';
        if (d.pages <= 1) { return; }
        function pg(label, page, active, disabled) {
            var b = el('button', 'pg' + (active ? ' is-active' : ''), label);
            b.type = 'button';
            b.disabled = !!disabled;
            b.addEventListener('click', function () { ledger.page = page; loadLedger(); });
            p.appendChild(b);
        }
        pg('‹', d.page - 1, false, d.page <= 1);
        var start = Math.max(1, d.page - 2);
        var end = Math.min(d.pages, start + 4);
        start = Math.max(1, end - 4);
        for (var i = start; i <= end; i++) { pg(String(i), i, i === d.page, false); }
        pg('›', d.page + 1, false, d.page >= d.pages);
    }

    function refreshAll() {
        return Promise.all([loadOverview(), loadMembers(), loadLedger()]);
    }

    var tierForm = $('#tierForm');
    function openTier(t) {
        tierForm.reset();
        showError('#tierError', '');
        if (t) {
            $('#tr_action').value = 'update_tier';
            $('#tr_id').value = t.id;
            $('#tierTitle').textContent = 'Edit ' + t.name;
            $('#tierKicker').textContent = 'Update Tier';
            $('#tr_name').value = t.name;
            $('#tr_color').value = t.color;
            $('#tr_desc').value = t.description;
            $('#tr_spend').value = t.min_spending;
            $('#tr_points').value = t.min_points;
            $('#tr_disc').value = t.discount;
            $('#tr_mult').value = t.multiplier;
            $('#tr_ben').value = t.benefits.join('\n');
            $('#tr_prv').value = t.privileges.join('\n');
            $('#tr_status').value = t.status;
            $('#tierSave span').textContent = 'Update Tier';
        } else {
            $('#tr_action').value = 'create_tier';
            $('#tr_id').value = '';
            $('#tierTitle').textContent = 'New Tier';
            $('#tierKicker').textContent = 'Membership Tier';
            $('#tr_color').value = '#C4B58D';
            $('#tierSave span').textContent = 'Save Tier';
        }
        openModal($('#tierModal'));
        $('#tr_name').focus();
    }
    $('#btnNewTier').addEventListener('click', function () { openTier(null); });
    $('#ruNewTier').addEventListener('click', function () { openTier(null); });
    tierForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        showError('#tierError', '');
        var b = $('#tierSave');
        busy(b, true);
        postForm(tierForm).then(function (r) {
            busy(b, false);
            if (!r.ok) { showError('#tierError', r.error || 'Unable to save tier.'); return; }
            closeModal($('#tierModal'));
            toast(r.message || 'Tier saved.');
            refreshAll();
        }).catch(function () { busy(b, false); showError('#tierError', 'Network error. Please try again.'); });
    });

    var rewardForm = $('#rewardForm');
    var rwPreview = $('#rwPreview');
    function rwPreviewSet(src, name) {
        rwPreview.textContent = '';
        if (src) {
            var img = el('img');
            img.src = src;
            img.alt = '';
            rwPreview.appendChild(img);
        } else {
            rwPreview.textContent = initials(name || '');
            if (!name) { rwPreview.textContent = '?'; }
        }
    }
    function openReward(r) {
        rewardForm.reset();
        showError('#rewardError', '');
        $('#rwRemoveRow').hidden = true;
        var sel = $('#rw_min');
        sel.textContent = '';
        var none = el('option', null, 'No minimum');
        none.value = '';
        sel.appendChild(none);
        var box = $('#rwTiers');
        box.textContent = '';
        data.tiers.forEach(function (t) {
            var o = el('option', null, t.name);
            o.value = String(t.id);
            sel.appendChild(o);
            var l = el('label', 'chk');
            var cb = el('input');
            cb.type = 'checkbox';
            cb.name = 'eligible_tiers[]';
            cb.value = String(t.id);
            if (r && r.tiers.indexOf(t.id) !== -1) { cb.checked = true; }
            l.appendChild(cb);
            l.appendChild(document.createTextNode(' ' + t.name));
            box.appendChild(l);
        });
        if (r) {
            $('#rw_action').value = 'update_reward';
            $('#rw_id').value = r.id;
            $('#rewardTitle').textContent = 'Edit Reward';
            $('#rewardKicker').textContent = 'Update Reward';
            $('#rw_name').value = r.name;
            $('#rw_cat').value = r.category;
            $('#rw_desc').value = r.description;
            $('#rw_points').value = r.points;
            $('#rw_qty').value = r.quantity === null ? '' : r.quantity;
            $('#rw_max').value = r.max_member === null ? '' : r.max_member;
            $('#rw_status').value = r.status;
            $('#rw_from').value = r.from || '';
            $('#rw_until').value = r.until || '';
            $('#rw_min').value = r.min_tier === null ? '' : String(r.min_tier);
            rwPreviewSet(r.image ? photoBase + r.image : '', r.name);
            $('#rwRemoveRow').hidden = !r.image;
            $('#rwSave span').textContent = 'Update Reward';
            $('#rwAnother').hidden = true;
        } else {
            $('#rw_action').value = 'create_reward';
            $('#rw_id').value = '';
            $('#rewardTitle').textContent = 'Create Reward';
            $('#rewardKicker').textContent = 'Reward Catalog';
            rwPreviewSet('', '');
            $('#rwSave span').textContent = 'Save Reward';
            $('#rwAnother').hidden = false;
        }
        openModal($('#rewardModal'));
        $('#rw_name').focus();
    }
    $('#btnCreateReward').addEventListener('click', function () { openReward(null); });
    $('#rwListNew').addEventListener('click', function () { openReward(null); });
    $('#btnRewards').addEventListener('click', function () { renderRewardList(); openModal($('#rewardsModal')); });
    $('#rw_image').addEventListener('change', function () {
        var f = this.files && this.files[0];
        showError('#rewardError', '');
        if (!f) { return; }
        var ext = f.name.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'webp'].indexOf(ext) === -1) {
            showError('#rewardError', 'Image must be a JPG, JPEG, PNG or WEBP file.');
            this.value = '';
            return;
        }
        if (f.size > 3145728) {
            showError('#rewardError', 'The image is too large (max 3 MB).');
            this.value = '';
            return;
        }
        var rd = new FileReader();
        rd.onload = function () { rwPreviewSet(String(rd.result), ''); };
        rd.readAsDataURL(f);
    });
    rewardForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        showError('#rewardError', '');
        var another = ev.submitter && ev.submitter.id === 'rwAnother';
        var b = ev.submitter || $('#rwSave');
        busy(b, true);
        postForm(rewardForm).then(function (r) {
            busy(b, false);
            if (!r.ok) { showError('#rewardError', r.error || 'Unable to save reward.'); return; }
            toast(r.message || 'Reward saved.');
            loadOverview().then(function () {
                if (another) { openReward(null); } else { closeModal($('#rewardModal')); }
            });
        }).catch(function () { busy(b, false); showError('#rewardError', 'Network error. Please try again.'); });
    });

    function renderRules() {
        var s = data.settings;
        $('#ru_peso').value = s.peso_per_point;
        $('#ru_term').value = s.membership_term_months;
        $('#ru_auto').checked = !!s.auto_award;
        explainRules();
        var tb = $('#ruTiers');
        tb.textContent = '';
        data.tiers.forEach(function (t) {
            var tr = el('tr');
            var c1 = el('td');
            c1.appendChild(tierDot(t.color, t.name));
            tr.appendChild(c1);
            tr.appendChild(el('td', 'num', peso(t.min_spending)));
            tr.appendChild(el('td', 'num', num(t.min_points)));
            tr.appendChild(el('td', 'num', t.discount + '%'));
            tr.appendChild(el('td', 'num', t.multiplier + 'x'));
            var c6 = el('td');
            c6.appendChild(statusBadge(t.status));
            tr.appendChild(c6);
            var ac = el('td', 'ml-actions');
            ac.appendChild(btn('Edit', 'link-btn', function () { openTier(t); }));
            ac.appendChild(btn(t.status === 'active' ? 'Deactivate' : 'Activate', 'link-btn', function () {
                if (t.status === 'active') {
                    askConfirm('Deactivate ' + t.name + '?', 'Existing members and history are kept. No new members can join this tier.', 'Deactivate', function () { setTierStatus(t, 'inactive'); });
                } else { setTierStatus(t, 'active'); }
            }));
            tr.appendChild(ac);
            tb.appendChild(tr);
        });
    }
    function explainRules() {
        var peso1 = parseInt($('#ru_peso').value, 10) || data.settings.peso_per_point;
        var tiers = data.tiers.filter(function (t) { return t.status === 'active'; });
        var parts = ['Every ' + peso(peso1) + ' of a paid, completed appointment earns 1 base point, multiplied by the member’s tier.'];
        if (tiers.length) {
            parts.push('Example: a ' + peso(peso1 * 20) + ' payment earns ' + tiers.map(function (t) { return Math.floor(20 * t.multiplier) + ' pts (' + t.name + ')'; }).join(', ') + '.');
        }
        parts.push('Points are never awarded twice for the same payment. Cancelled, no-show and unpaid appointments earn nothing. Tiers change only when an administrator changes them.');
        $('#ruleExplain').textContent = parts.join(' ');
    }
    $('#ru_peso').addEventListener('input', function () { if (data) { explainRules(); } });
    $('#btnRules').addEventListener('click', function () { renderRules(); showError('#rulesError', ''); openModal($('#rulesModal')); });
    $('#rulesForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        showError('#rulesError', '');
        var b = $('#rulesSave');
        busy(b, true);
        postForm($('#rulesForm'), { auto_award: $('#ru_auto').checked ? '1' : '' }).then(function (r) {
            busy(b, false);
            if (!r.ok) { showError('#rulesError', r.error || 'Unable to save rules.'); return; }
            toast(r.message || 'Rules saved.');
            loadOverview();
        }).catch(function () { busy(b, false); showError('#rulesError', 'Network error. Please try again.'); });
    });
    $('#rulesSync').addEventListener('click', function () {
        var b = this;
        busy(b, true, 'Working…');
        postData({ action: 'sync' }).then(function (r) {
            busy(b, false);
            toast(r.message || r.error || 'Done.');
            if (r.ok) { refreshAll(); }
        }).catch(function () { busy(b, false); toast('Network error. Please try again.'); });
    });

    function customerPicker(input, list, onPick) {
        var run = debounce(function () {
            get({ view: 'customers', q: input.value.trim() }).then(function (d) {
                list.textContent = '';
                if (!d.ok || !d.customers.length) {
                    list.appendChild(el('p', 'cell-sub pick-empty', 'No customers found.'));
                    list.hidden = false;
                    return;
                }
                d.customers.forEach(function (c) {
                    var b = el('button', 'pick-item');
                    b.type = 'button';
                    var l = el('span', 'pick-name', c.name);
                    var s = el('span', 'cell-sub', (c.tier ? c.tier + ' · ' + num(c.balance) + ' pts' : 'No membership') + (c.email ? ' · ' + c.email : ''));
                    b.appendChild(l);
                    b.appendChild(s);
                    b.addEventListener('click', function () { list.hidden = true; onPick(c); });
                    list.appendChild(b);
                });
                list.hidden = false;
            });
        }, 220);
        input.addEventListener('input', run);
        input.addEventListener('focus', run);
    }

    var adjustForm = $('#adjustForm');
    function openAdjust(c) {
        adjustForm.reset();
        showError('#adjustError', '');
        $('#aj_customer').value = '';
        $('#aj_chosen').hidden = true;
        $('#aj_results').hidden = true;
        $('#aj_search').hidden = false;
        if (c) { pickAdjust(c); }
        openModal($('#adjustModal'));
        if (!c) { $('#aj_search').focus(); }
    }
    function pickAdjust(c) {
        $('#aj_customer').value = c.id;
        $('#aj_search').value = '';
        $('#aj_search').hidden = true;
        var ch = $('#aj_chosen');
        ch.textContent = '';
        ch.appendChild(el('strong', null, c.name));
        ch.appendChild(document.createTextNode(' · ' + (c.tier || 'No membership') + ' · ' + num(c.balance) + ' pts '));
        ch.appendChild(btn('Change', 'link-btn', function () {
            $('#aj_customer').value = '';
            ch.hidden = true;
            $('#aj_search').hidden = false;
            $('#aj_search').focus();
        }));
        ch.hidden = false;
    }
    customerPicker($('#aj_search'), $('#aj_results'), pickAdjust);
    $('#btnAdjust').addEventListener('click', function () { openAdjust(null); });
    adjustForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        showError('#adjustError', '');
        var b = $('#adjustSave');
        busy(b, true);
        postForm(adjustForm).then(function (r) {
            busy(b, false);
            if (!r.ok) { showError('#adjustError', r.error || 'Unable to save adjustment.'); return; }
            closeModal($('#adjustModal'));
            toast(r.message || 'Points adjusted.');
            refreshAll();
            if (currentMember && !$('#memberModal').hidden) { openMember(currentMember); }
        }).catch(function () { busy(b, false); showError('#adjustError', 'Network error. Please try again.'); });
    });

    var tcState = null;
    function openTierChange(cid) {
        showError('#tcError', '');
        $('#tc_override').value = '';
        $('#tc_reason').value = '';
        $('#tc_customer').value = String(cid);
        get({ view: 'member', id: cid }).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load member.'); return; }
            tcState = d;
            var m = d.member;
            $('#tcKicker').textContent = m ? 'Upgrade or Downgrade' : 'Enroll Customer';
            $('#tcTitle').textContent = d.customer.name;
            var info = $('#tcInfo');
            info.textContent = '';
            function row(k, v) { var w = el('div'); w.appendChild(el('dt', null, k)); w.appendChild(el('dd', null, v)); info.appendChild(w); }
            row('Current tier', m ? m.tier : 'Not enrolled');
            row('Status', m ? m.status : '–');
            row('Qualifying spend', peso(d.totals.spent));
            row('Points earned', num(d.totals.earned));
            row('Points balance', num(d.totals.balance));
            row('Expires', m ? dateLabel(m.expiry) : '–');
            var sel = $('#tc_tier');
            sel.textContent = '';
            d.tiers.filter(function (t) { return t.status === 'active' && !(m && m.tier_id === t.id && m.status === 'active'); }).forEach(function (t) {
                var o = el('option', null, t.name + ' · ' + t.discount + '% off · ' + t.multiplier + 'x');
                o.value = String(t.id);
                sel.appendChild(o);
            });
            $('#tcSave span').textContent = m ? 'Update Membership' : 'Enroll Customer';
            showTcReq();
            openModal($('#tierChangeModal'));
        });
    }
    function showTcReq() {
        var box = $('#tcReq');
        box.textContent = '';
        if (!tcState) { return; }
        var t = tiersById[parseInt($('#tc_tier').value, 10)];
        if (!t) { box.appendChild(el('p', 'cell-sub', 'No other active tier is available.')); return; }
        var okSpend = tcState.totals.spent >= t.min_spending;
        var okPts = tcState.totals.earned >= t.min_points;
        box.appendChild(el('p', 'tier-list-h', t.name + ' requirements'));
        var ul = el('ul', 'req-list');
        ul.appendChild(el('li', okSpend ? 'is-met' : 'is-miss', (okSpend ? '✓ ' : '✗ ') + peso(t.min_spending) + ' qualifying spend (member has ' + peso(tcState.totals.spent) + ')'));
        ul.appendChild(el('li', okPts ? 'is-met' : 'is-miss', (okPts ? '✓ ' : '✗ ') + num(t.min_points) + ' points earned (member has ' + num(tcState.totals.earned) + ')'));
        box.appendChild(ul);
        if (!okSpend || !okPts) { box.appendChild(el('p', 'cell-sub', 'Moving up without meeting these needs an override and a reason.')); }
    }
    $('#tc_tier').addEventListener('change', showTcReq);
    $('#tierChangeForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        showError('#tcError', '');
        var form = $('#tierChangeForm');
        var b = $('#tcSave');
        function send() {
            busy(b, true);
            postForm(form).then(function (r) {
                busy(b, false);
                if (!r.ok && r.needs_override) {
                    askConfirm('Override qualification?', r.error, 'Override', function () { $('#tc_override').value = '1'; send(); });
                    return;
                }
                if (!r.ok) { showError('#tcError', r.error || 'Unable to update membership.'); $('#tc_override').value = ''; return; }
                closeModal($('#tierChangeModal'));
                toast(r.message || 'Membership updated.');
                refreshAll();
                if (currentMember && !$('#memberModal').hidden) { openMember(currentMember); }
            }).catch(function () { busy(b, false); showError('#tcError', 'Network error. Please try again.'); });
        }
        send();
    });

    customerPicker($('#en_search'), $('#en_results'), function (c) {
        closeModal($('#enrollModal'));
        openTierChange(c.id);
    });
    $('#btnEnroll').addEventListener('click', function () {
        $('#en_search').value = '';
        $('#en_results').hidden = true;
        openModal($('#enrollModal'));
        $('#en_search').focus();
    });

    var rdCustomer = null;
    function openRedeem(c) {
        showError('#redeemError', '');
        $('#rd_search').value = '';
        $('#rd_results').hidden = true;
        $('#rdBody').hidden = true;
        rdCustomer = null;
        openModal($('#redeemModal'));
        if (c) { loadRedeem(c.id); } else { $('#rd_search').focus(); }
    }
    customerPicker($('#rd_search'), $('#rd_results'), function (c) { $('#rd_search').value = c.name; loadRedeem(c.id); });
    $('#btnRedeem').addEventListener('click', function () { openRedeem(null); });
    function loadRedeem(cid) {
        showError('#redeemError', '');
        get({ view: 'eligible', customer: cid }).then(function (d) {
            if (!d.ok) { $('#rdBody').hidden = true; showError('#redeemError', d.error || 'Unable to load rewards.'); return; }
            rdCustomer = d.member;
            $('#rd_search').value = d.member.name;
            var sm = $('#rdSummary');
            sm.textContent = '';
            sm.appendChild(tierDot(d.member.color, d.member.tier));
            sm.appendChild(el('span', 'rd-bal', num(d.member.balance) + ' points available'));
            sm.appendChild(statusBadge(d.member.status));
            var list = $('#rdList');
            list.textContent = '';
            if (!d.rewards.length) { list.appendChild(el('p', 'empty', 'No active rewards in the catalog.')); }
            d.rewards.forEach(function (r) {
                var row = el('div', 'rd-item' + (r.block ? ' is-blocked' : ''));
                row.appendChild(imageNode(r, 'rw-thumb'));
                var tx = el('div', 'rd-text');
                tx.appendChild(el('strong', null, r.name));
                tx.appendChild(el('span', 'cell-sub', num(r.points) + ' points · ' + r.category + (r.max_member !== null ? ' · limit ' + r.max_member + ' per member' : '') + (r.quantity !== null ? ' · ' + r.quantity + ' left' : '')));
                row.appendChild(tx);
                var tk = token();
                if (r.block) {
                    row.appendChild(el('span', 'rd-block', r.block));
                } else {
                    row.appendChild(btn('Redeem', 'btn btn-primary btn-sm', function () {
                        askConfirm('Redeem ' + r.name + '?', num(r.points) + ' points will be deducted from ' + d.member.name + '. Balance after: ' + num(d.member.balance - r.points) + '.', 'Confirm Redemption', function () {
                            postData({ action: 'redeem', customer_id: d.member.customer_id, reward_id: r.id, request_token: tk }).then(function (res) {
                                if (!res.ok) { showError('#redeemError', res.error || 'Unable to redeem.'); if (res.duplicate) { loadRedeem(cid); } return; }
                                toast(res.message || 'Reward redeemed.');
                                refreshAll();
                                loadRedeem(cid);
                            }).catch(function () { showError('#redeemError', 'Network error. Check the ledger before retrying.'); });
                        });
                    }));
                }
                list.appendChild(row);
            });
            $('#rdBody').hidden = false;
        });
    }

    function sectionTitle(t) { return el('p', 'detail-h', t); }
    function simpleTable(heads, rows) {
        var w = el('div', 'table-wrap');
        var t = el('table', 'manifest ml-table');
        var th = el('tr');
        heads.forEach(function (h) { th.appendChild(el('th', null, h)); });
        t.appendChild(el('thead')).appendChild(th);
        var tb = el('tbody');
        if (!rows.length) {
            var tr = el('tr', 'empty-row');
            var td = el('td', null, 'Nothing recorded yet.');
            td.colSpan = heads.length;
            tr.appendChild(td);
            tb.appendChild(tr);
        }
        rows.forEach(function (r) {
            var tr = el('tr');
            r.forEach(function (c) {
                var td = el('td');
                if (c instanceof Node) { td.appendChild(c); } else { td.textContent = c; }
                tr.appendChild(td);
            });
            tb.appendChild(tr);
        });
        t.appendChild(tb);
        w.appendChild(t);
        return w;
    }
    function openMember(cid) {
        currentMember = cid;
        get({ view: 'member', id: cid }).then(function (d) {
            if (!d.ok) { toast(d.error || 'Unable to load member.'); return; }
            var m = d.member;
            $('#memberTitle').textContent = d.customer.name;
            $('#memberKicker').textContent = 'Member Loyalty History · CUS-' + ('0000' + cid).slice(-4);
            var body = $('#memberBody');
            body.textContent = '';
            var tiles = el('div', 'mini-stats');
            [['Current Balance', num(d.totals.balance)], ['Points Earned', num(d.totals.earned)], ['Points Redeemed', num(d.totals.redeemed)], ['Manual Adjustments', (d.totals.adjust > 0 ? '+' : '') + num(d.totals.adjust)], ['Qualifying Spend', peso(d.totals.spent)]].forEach(function (x) {
                var t = el('div', 'mini-stat');
                t.appendChild(el('span', 'label-small', x[0]));
                t.appendChild(el('strong', null, x[1]));
                tiles.appendChild(t);
            });
            body.appendChild(tiles);
            var info = el('dl', 'ins-grid');
            function row(k, v) { var w = el('div'); w.appendChild(el('dt', null, k)); var dd = el('dd'); if (v instanceof Node) { dd.appendChild(v); } else { dd.textContent = v; } w.appendChild(dd); info.appendChild(w); }
            row('Membership tier', m ? tierDot(m.color, m.tier) : 'Not enrolled');
            row('Status', m ? statusBadge(m.status) : '–');
            row('Started', m ? dateLabel(m.start) : '–');
            row('Expires', m ? dateLabel(m.expiry) : '–');
            row('Email', d.customer.email || '–');
            row('Contact', d.customer.phone || '–');
            if (d.next) {
                row('Next tier', d.next.name + ' · ' + (d.next.spend_gap > 0 ? peso(d.next.spend_gap) + ' more spend' : 'spend met') + (d.next.min_points > 0 ? ' · ' + (d.next.points_gap > 0 ? num(d.next.points_gap) + ' more points' : 'points met') : ''));
            }
            body.appendChild(info);
            body.appendChild(sectionTitle('Redemption History'));
            body.appendChild(simpleTable(['Reference', 'Reward', 'Points', 'Date', 'Status'], d.redemptions.map(function (r) {
                return [r.redemption_reference, r.reward_name, num(r.points_used), dateTimeLabel(r.redeemed_at), statusBadge(r.redemption_status)];
            })));
            body.appendChild(sectionTitle('Membership Tier History'));
            body.appendChild(simpleTable(['Date', 'Change', 'From', 'To', 'By', 'Reason'], d.history.map(function (h) {
                return [dateTimeLabel(h.date), h.type.charAt(0).toUpperCase() + h.type.slice(1), h.from, h.to, h.by, h.reason || '–'];
            })));
            body.appendChild(sectionTitle('Loyalty Transactions'));
            body.appendChild(simpleTable(['Reference', 'Type', 'Points', 'Description', 'Date', 'Status'], d.ledger.map(function (r) {
                return [r.code, r.type_label, (r.earned ? '+' + num(r.earned) : '−' + num(r.redeemed)), r.description || '–', dateTimeLabel(r.date), statusBadge(r.status)];
            })));
            var foot = $('#memberFoot');
            foot.textContent = '';
            if (m) {
                foot.appendChild(btn('Renew', 'btn btn-outline', function () {
                    askConfirm('Renew membership?', 'Extends the membership by the configured term and records it in history.', 'Renew', function () {
                        postData({ action: 'renew', customer_id: cid }).then(function (r) {
                            toast(r.message || r.error || 'Done.');
                            if (r.ok) { refreshAll(); openMember(cid); }
                        });
                    });
                }));
            }
            foot.appendChild(btn(m ? 'Change Tier' : 'Enroll', 'btn btn-outline', function () { openTierChange(cid); }));
            if (m) {
                foot.appendChild(btn('Redeem', 'btn btn-outline', function () { openRedeem({ id: cid }); }));
                foot.appendChild(btn('Adjust Points', 'btn btn-primary', function () { openAdjust({ id: cid, name: d.customer.name, tier: m.tier, balance: d.totals.balance }); }));
            }
            openModal($('#memberModal'));
        });
    }

    function openTx(id) {
        showError('#txError', '');
        $('#txReverseRow').hidden = true;
        $('#tx_reason').value = '';
        get({ view: 'tx', id: id }).then(function (d) {
            if (!d.ok) { toast(d.error || 'Transaction not found.'); return; }
            var t = d.tx;
            $('#txTitle').textContent = t.code;
            var body = $('#txBody');
            body.textContent = '';
            var dl = el('dl', 'ins-grid');
            function row(k, v) { var w = el('div'); w.appendChild(el('dt', null, k)); var dd = el('dd'); if (v instanceof Node) { dd.appendChild(v); } else { dd.textContent = v; } w.appendChild(dd); dl.appendChild(w); }
            row('Member', t.member);
            row('Tier', t.tier);
            row('Type', t.type_label);
            row('Points', t.earned ? '+' + num(t.earned) : '−' + num(t.redeemed));
            row('Status', statusBadge(t.status));
            row('Date', dateTimeLabel(t.date));
            row('Processed by', t.admin);
            row('Description', t.description || '–');
            if (t.reference) { row(t.reference.label, t.reference.text); }
            if (t.notes) { row('Notes', t.notes); }
            body.appendChild(dl);
            var foot = $('#txFoot');
            foot.textContent = '';
            if (t.reversible) {
                foot.appendChild(btn('Reverse Transaction', 'btn btn-outline btn-danger-soft', function () {
                    var rr = $('#txReverseRow');
                    if (rr.hidden) { rr.hidden = false; $('#tx_reason').focus(); return; }
                    var reason = $('#tx_reason').value.trim();
                    if (!reason) { showError('#txError', 'Enter a reason for the reversal.'); return; }
                    askConfirm('Reverse this transaction?', 'The record stays in the ledger as Reversed and the points are returned or removed from the balance.', 'Reverse', function () {
                        postData({ action: 'reverse', id: t.id, reason: reason }).then(function (r) {
                            if (!r.ok) { showError('#txError', r.error || 'Unable to reverse.'); return; }
                            closeModal($('#txModal'));
                            toast(r.message || 'Transaction reversed.');
                            refreshAll();
                        });
                    });
                }));
            }
            openModal($('#txModal'));
        });
    }

    var reloadMembers = debounce(function () { loadMembers(); }, 250);
    $('#mbSearch').addEventListener('input', function () { memberFilter.q = this.value.trim(); reloadMembers(); });
    $('#mbTier').addEventListener('change', function () { memberFilter.tier = this.value; loadMembers(); });
    $('#mbStatus').addEventListener('change', function () { memberFilter.status = this.value; loadMembers(); });

    var reloadLedger = debounce(function () { ledger.page = 1; loadLedger(); }, 250);
    $('#lgSearch').addEventListener('input', reloadLedger);
    ['#lgType', '#lgTier', '#lgStatus', '#lgFrom', '#lgTo'].forEach(function (s) { $(s).addEventListener('change', function () { ledger.page = 1; loadLedger(); }); });
    $('#lgReset').addEventListener('click', function () {
        ['#lgSearch', '#lgType', '#lgTier', '#lgStatus', '#lgFrom', '#lgTo'].forEach(function (s) { $(s).value = ''; });
        ledger.page = 1;
        loadLedger();
    });
    $('#lgSort').addEventListener('click', function () {
        ledger.sort = ledger.sort === 'desc' ? 'asc' : 'desc';
        $('#lgSortArrow').textContent = ledger.sort === 'desc' ? '↓' : '↑';
        ledger.page = 1;
        loadLedger();
    });
    $('#rgFrom').addEventListener('change', loadOverview);
    $('#rgTo').addEventListener('change', loadOverview);
    $('#rgReset').addEventListener('click', function () { $('#rgFrom').value = ''; $('#rgTo').value = ''; loadOverview(); });

    refreshAll();
})();