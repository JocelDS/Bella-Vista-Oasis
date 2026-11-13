(function () {
    'use strict';

    var body = document.body;
    var apiBase = body.getAttribute('data-api') || '';
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function safeGet(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } }
    function safeSet(key, val) { try { window.localStorage.setItem(key, val); } catch (e) {} }

    var toastEl = $('#toast');
    var toastTimer = null;
    function toast(msg) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3200);
    }

    var scrim = $('#scrim');
    var menuBtn = $('#menuBtn');
    function setNav(open) {
        body.classList.toggle('nav-open', open);
        if (scrim) { scrim.hidden = !open; }
        if (menuBtn) {
            menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }
    if (menuBtn) { menuBtn.addEventListener('click', function () { setNav(!body.classList.contains('nav-open')); }); }
    if (scrim) { scrim.addEventListener('click', function () { setNav(false); }); }
    window.addEventListener('resize', function () { if (window.innerWidth > 1024) { setNav(false); } });

    function closeDropdowns(except) {
        $$('[data-dropdown]').forEach(function (dd) {
            if (dd === except) { return; }
            var menu = $('[data-dropdown-menu]', dd);
            var toggle = $('[data-dropdown-toggle]', dd);
            if (menu) { menu.hidden = true; }
            if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
        });
    }
    $$('[data-dropdown]').forEach(function (dd) {
        var toggle = $('[data-dropdown-toggle]', dd);
        var menu = $('[data-dropdown-menu]', dd);
        if (!toggle || !menu) { return; }
        toggle.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var willOpen = menu.hidden;
            closeDropdowns(dd);
            menu.hidden = !willOpen;
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen && toggle.id === 'bellBtn') {
                var latest = toggle.getAttribute('data-latest') || '0';
                safeSet('bvo_seen_notif', latest);
                var dot = $('#bellDot');
                if (dot) { dot.hidden = true; }
            }
        });
        menu.addEventListener('click', function (ev) { ev.stopPropagation(); });
    });
    document.addEventListener('click', function () { closeDropdowns(null); });

    var bell = $('#bellBtn');
    var bellDot = $('#bellDot');
    if (bell && bellDot) {
        var latestId = parseInt(bell.getAttribute('data-latest') || '0', 10);
        var seenId = parseInt(safeGet('bvo_seen_notif') || '0', 10);
        bellDot.hidden = !(latestId > seenId);
    }

    document.addEventListener('click', function (ev) {
        var target = ev.target;
        if (!target || !target.closest) { return; }
        var soon = target.closest('[data-soon]');
        if (soon) {
            ev.preventDefault();
            toast(soon.getAttribute('data-soon') + ' is not available yet.');
            setNav(false);
        }
    });

    var globalSearch = $('#globalSearch');
    var globalInput = $('#globalSearchInput');
    var scheduleSearch = $('#scheduleSearch');
    if (globalSearch && globalInput) {
        globalSearch.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (scheduleSearch) {
                scheduleSearch.value = globalInput.value;
                state.q = globalInput.value.trim();
                state.page = 1;
                load();
                var manifest = $('#manifest');
                if (manifest) { manifest.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            }
        });
    }

    var chartCanvas = $('#flowChart');
    if (chartCanvas && window.Chart) {
        var payload = {};
        try { payload = JSON.parse(chartCanvas.getAttribute('data-chart') || '{}'); } catch (e) { payload = {}; }
        var ctx = chartCanvas.getContext('2d');
        var grad = ctx.createLinearGradient(0, 0, 0, 260);
        grad.addColorStop(0, 'rgba(23, 61, 48, .22)');
        grad.addColorStop(1, 'rgba(23, 61, 48, 0)');
        var maxVal = Math.max.apply(null, (payload.values || [0]).concat([1]));
        new window.Chart(chartCanvas, {
            type: 'line',
            data: {
                labels: payload.labels || [],
                datasets: [{
                    label: 'Appointments',
                    data: payload.values || [],
                    borderColor: '#173D30',
                    backgroundColor: grad,
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.38,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#FFFFFF',
                    pointBorderColor: '#173D30',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#102B22',
                        titleColor: '#F5F2EA',
                        bodyColor: '#F5F2EA',
                        padding: 10,
                        displayColors: false,
                        callbacks: {
                            title: function (items) {
                                var i = items[0].dataIndex;
                                return (payload.full && payload.full[i]) || items[0].label;
                            },
                            label: function (item) {
                                var n = item.parsed.y;
                                return n + (n === 1 ? ' appointment' : ' appointments');
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#85877F', font: { size: 11 } }, border: { color: '#E5E2DA' } },
                    y: {
                        beginAtZero: true,
                        suggestedMax: maxVal + 1,
                        ticks: { color: '#85877F', precision: 0, stepSize: maxVal > 8 ? undefined : 1, font: { size: 11 } },
                        grid: { color: 'rgba(229, 226, 218, .7)' },
                        border: { display: false }
                    }
                }
            }
        });
    }

    var manifest = $('#manifest');
    var state = { status: 'all', q: '', page: 1, pages: 1 };
    var bodyEl = $('#scheduleBody');
    var summaryEl = $('#scheduleSummary');
    var pagerEl = $('#schedulePager');
    var loadingEl = $('#scheduleLoading');
    var filterWrap = $('#statusFilters');
    var controller = null;
    var searchTimer = null;

    function renderPager() {
        if (!pagerEl) { return; }
        pagerEl.innerHTML = '';
        var pages = state.pages;
        function btn(label, page, opts) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'pg' + (opts && opts.active ? ' is-active' : '');
            b.textContent = label;
            if (opts && opts.aria) { b.setAttribute('aria-label', opts.aria); }
            if (opts && opts.active) { b.setAttribute('aria-current', 'page'); }
            if (opts && opts.disabled) { b.disabled = true; }
            b.addEventListener('click', function () {
                if (page !== state.page) { state.page = page; load(); }
            });
            return b;
        }
        pagerEl.appendChild(btn('‹', state.page - 1, { disabled: state.page <= 1, aria: 'Previous page' }));
        var start = Math.max(1, state.page - 2);
        var end = Math.min(pages, start + 4);
        start = Math.max(1, end - 4);
        for (var i = start; i <= end; i++) {
            pagerEl.appendChild(btn(String(i), i, { active: i === state.page, aria: 'Page ' + i }));
        }
        pagerEl.appendChild(btn('›', state.page + 1, { disabled: state.page >= pages, aria: 'Next page' }));
    }

    function load() {
        if (!bodyEl) { return; }
        if (controller) { controller.abort(); }
        controller = new AbortController();
        var params = new URLSearchParams({ status: state.status, q: state.q, page: String(state.page) });
        if (loadingEl) { loadingEl.hidden = false; }
        fetch(apiBase + '/schedule.php?' + params.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: controller.signal
        }).then(function (res) {
            if (res.status === 401) {
                window.location.reload();
                throw new Error('unauthorized');
            }
            return res.json();
        }).then(function (data) {
            if (!data || !data.ok) { throw new Error((data && data.error) || 'Request failed'); }
            bodyEl.innerHTML = data.html;
            if (summaryEl) { summaryEl.textContent = data.summary; }
            state.page = data.page;
            state.pages = data.pages;
            renderPager();
        }).catch(function (err) {
            if (err && (err.name === 'AbortError' || err.message === 'unauthorized')) { return; }
            toast('Unable to load the schedule. Please try again.');
        }).then(function () {
            if (loadingEl) { loadingEl.hidden = true; }
        });
    }

    if (manifest) {
        state.page = parseInt(manifest.getAttribute('data-page') || '1', 10);
        state.pages = parseInt(manifest.getAttribute('data-pages') || '1', 10);
        renderPager();
    }

    if (filterWrap) {
        filterWrap.addEventListener('click', function (ev) {
            var b = ev.target.closest('.filter');
            if (!b) { return; }
            $$('.filter', filterWrap).forEach(function (f) { f.classList.toggle('is-active', f === b); });
            state.status = b.getAttribute('data-status') || 'all';
            state.page = 1;
            load();
        });
    }

    if (scheduleSearch) {
        scheduleSearch.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                state.q = scheduleSearch.value.trim();
                state.page = 1;
                load();
            }, 300);
        });
    }

    var modal = $('#bookingModal');
    var openBtn = $('#openBooking');
    var closeBtn = $('#closeBooking');
    var cancelBtn = $('#cancelBooking');
    var form = $('#bookingForm');
    var errBox = $('#bookingError');
    var submitBtn = $('#bookingSubmit');
    var lastFocus = null;

    function openModal() {
        if (!modal) { return; }
        lastFocus = document.activeElement;
        modal.hidden = false;
        if (errBox) { errBox.hidden = true; }
        var first = $('select, input', form);
        if (first) { first.focus(); }
    }
    function closeModal() {
        if (!modal) { return; }
        modal.hidden = true;
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }
    if (openBtn) { openBtn.addEventListener('click', openModal); }
    if (closeBtn) { closeBtn.addEventListener('click', closeModal); }
    if (cancelBtn) { cancelBtn.addEventListener('click', closeModal); }
    if (modal) {
        modal.addEventListener('mousedown', function (ev) { if (ev.target === modal) { closeModal(); } });
    }
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
            if (modal && !modal.hidden) { closeModal(); }
            closeDropdowns(null);
            setNav(false);
        }
    });

    function showFormError(msg) {
        if (!errBox) { return; }
        errBox.textContent = msg;
        errBox.hidden = false;
    }

    if (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (errBox) { errBox.hidden = true; }

            var missing = $$('select[required], input[required], textarea[required]', form).some(function (f) { return !f.value; });
            if (missing) { showFormError('Please complete every field.'); return; }

            var fd = new FormData(form);
            var bookedDate = fd.get('appointment_date');
            if (submitBtn) { submitBtn.disabled = true; }

            fetch(apiBase + '/quick-booking.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf }
            }).then(function (res) {
                return res.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; });
            }).then(function (data) {
                if (!data.ok) { showFormError(data.error || 'Unable to create the booking.'); return; }
                closeModal();
                form.reset();
                var dateInput = $('#bk_date');
                if (dateInput) { dateInput.value = dateInput.min; }
                toast(data.message || 'Booking created.');
                var todayStr = dateInput ? dateInput.min : '';
                if (bookedDate === todayStr) {
                    setTimeout(function () { window.location.reload(); }, 900);
                }
            }).catch(function () {
                showFormError('Network error. Please try again.');
            }).then(function () {
                if (submitBtn) { submitBtn.disabled = false; }
            });
        });
    }
}());