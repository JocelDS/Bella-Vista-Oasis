(function () {
    'use strict';

    var SELECTOR = '#bk_date, #rs_date, #ap_date, #cwDate';
    var STALE_MS = 60000;
    var MAX_AHEAD = 12;
    var ISO = /^\d{4}-\d{2}-\d{2}$/;
    var DOW = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
    var CHEVRON_L = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>';
    var CHEVRON_R = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>';

    var apiBase = document.body.getAttribute('data-api') || '';
    var nativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    var cache = {};
    var inflight = {};
    var serverToday = '';
    var pickers = [];
    var current = null;
    var pop = null;
    var grid = null;
    var title = null;
    var msg = null;
    var prevBtn = null;
    var nextBtn = null;
    var toastTimer = null;

    function pad(n) { return n < 10 ? '0' + n : String(n); }
    function fmt(y, m, d) { return y + '-' + pad(m) + '-' + pad(d); }
    function parse(iso) { var a = iso.split('-'); return { y: +a[0], m: +a[1], d: +a[2] }; }
    function validIso(s) {
        if (!ISO.test(s)) { return false; }
        var p = parse(s);
        var dt = new Date(p.y, p.m - 1, p.d);
        return dt.getFullYear() === p.y && dt.getMonth() === p.m - 1 && dt.getDate() === p.d;
    }
    function localToday() {
        var n = new Date();
        return fmt(n.getFullYear(), n.getMonth() + 1, n.getDate());
    }
    function today() { return serverToday || localToday(); }
    function addDays(iso, n) {
        var p = parse(iso);
        var d = new Date(p.y, p.m - 1, p.d + n);
        return fmt(d.getFullYear(), d.getMonth() + 1, d.getDate());
    }
    function ym(iso) { return iso.slice(0, 7); }
    function monthShift(m, n) {
        var a = m.split('-');
        var d = new Date(+a[0], +a[1] - 1 + n, 1);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1);
    }
    function lastDayOf(m) {
        var a = m.split('-');
        return fmt(+a[0], +a[1], new Date(+a[0], +a[1], 0).getDate());
    }
    function dayDiff(a, b) {
        var x = parse(a), y = parse(b);
        return Math.round((Date.UTC(y.y, y.m - 1, y.d) - Date.UTC(x.y, x.m - 1, x.d)) / 86400000);
    }
    function dowIndex(iso) {
        var p = parse(iso);
        return (new Date(p.y, p.m - 1, p.d).getDay() + 6) % 7;
    }
    function shiftMonthDate(iso, n) {
        var p = parse(iso);
        var m = monthShift(ym(iso), n);
        var a = m.split('-');
        var max = new Date(+a[0], +a[1], 0).getDate();
        return fmt(+a[0], +a[1], Math.min(p.d, max));
    }
    function longLabel(iso) {
        var p = parse(iso);
        return new Date(p.y, p.m - 1, p.d).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    }
    function shortLabel(iso) {
        var p = parse(iso);
        return new Date(p.y, p.m - 1, p.d).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    }
    function monthLabel(m) {
        var a = m.split('-');
        return new Date(+a[0], +a[1] - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function toast(text) {
        var t = document.getElementById('toast');
        if (!t) { return; }
        t.textContent = text;
        t.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.hidden = true; }, 4600);
    }

    function serviceOf(input) {
        var f = input.form;
        var s = f ? f.querySelector('select[name="service_id"], input[name="service_id"]') : null;
        var v = s ? String(s.value || '') : String(input.getAttribute('data-service-id') || '');
        return /^\d+$/.test(v) && v !== '0' ? v : '0';
    }

    function isEdit(input) {
        var m = input.getAttribute('data-dp-mode');
        if (m) { return m === 'edit'; }
        if (input.id === 'rs_date') { return true; }
        var a = input.form ? input.form.querySelector('input[name="action"]') : null;
        return !!a && /^(edit|update|reschedule)$/.test(String(a.value));
    }

    function visible(input) {
        return !!(input.offsetParent || input.getClientRects().length);
    }

    function entryKey(svc, m) { return svc + '|' + m; }

    function loadMonth(svc, m, force) {
        var key = entryKey(svc, m);
        var hit = cache[key];
        if (hit && !force && Date.now() - hit.at < STALE_MS) { return Promise.resolve(hit); }
        if (inflight[key]) { return inflight[key]; }
        var t = today();
        var last = lastDayOf(m);
        if (last < t) {
            cache[key] = { at: Date.now(), map: {} };
            return Promise.resolve(cache[key]);
        }
        var first = m + '-01';
        var start = first < t ? t : first;
        var days = dayDiff(start, last) + 1;
        var url = apiBase + '/unavailable-dates.php?start=' + encodeURIComponent(start) + '&days=' + days + (svc !== '0' ? '&service_id=' + encodeURIComponent(svc) : '');
        inflight[key] = fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (res) {
                if (res.status === 401) { window.location.reload(); throw new Error('unauthorized'); }
                return res.json();
            })
            .then(function (d) {
                if (!d || !d.ok || !Array.isArray(d.dates)) { throw new Error('bad response'); }
                if (d.today) { serverToday = d.today; }
                var map = {};
                d.dates.forEach(function (x) { map[x.date] = x; });
                cache[key] = { at: Date.now(), map: map };
                return cache[key];
            })
            .catch(function () {
                cache[key] = { at: Date.now() - STALE_MS + 5000, map: {}, failed: true };
                return cache[key];
            })
            .then(function (e) {
                delete inflight[key];
                return e;
            });
        return inflight[key];
    }

    function statusOf(p, iso) {
        if (!validIso(iso)) { return { code: 'invalid', text: 'Enter a valid date as YYYY-MM-DD.' }; }
        if (isEdit(p.input) && iso === p.origin) { return null; }
        if (iso < today()) { return { code: 'past', text: 'Not available: date has passed' }; }
        var e = cache[entryKey(serviceOf(p.input), ym(iso))];
        if (!e) { return undefined; }
        var u = e.map[iso];
        return u ? { code: u.code, text: 'Not available: ' + u.message } : null;
    }

    function showNote(p, text, kind) {
        p.note.textContent = text || '';
        p.note.className = 'dp-note' + (kind ? ' is-' + kind : '');
        p.note.hidden = !text;
    }

    function formBox(p) {
        return p.input.form ? p.input.form.querySelector('.form-error') : null;
    }

    function apply(p, st) {
        var input = p.input;
        var box = formBox(p);
        if (st) {
            input.setCustomValidity(st.text);
            input.setAttribute('aria-invalid', 'true');
            showNote(p, st.text, 'error');
            return;
        }
        input.setCustomValidity('');
        input.removeAttribute('aria-invalid');
        if (p.infoValue && p.infoValue === input.value) { return; }
        showNote(p, '', '');
        if (box && p.boxMsg && box.textContent === p.boxMsg) { box.hidden = true; }
        p.boxMsg = '';
    }

    function setInternal(p, iso, fire) {
        p.internal = true;
        nativeValue.set.call(p.input, iso);
        if (fire) {
            p.input.dispatchEvent(new Event('input', { bubbles: true }));
            p.input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        p.internal = false;
    }

    function advanceFrom(p, from, st) {
        var svc = serviceOf(p.input);
        var limit = monthShift(ym(today()), MAX_AHEAD);
        function scan(m, startIso) {
            if (m > limit) { return Promise.resolve(null); }
            return loadMonth(svc, m, false).then(function (e) {
                var last = lastDayOf(m);
                var d = startIso;
                while (d <= last) {
                    if (d >= today() && !e.map[d]) { return d; }
                    d = addDays(d, 1);
                }
                var next = monthShift(m, 1);
                return scan(next, next + '-01');
            });
        }
        var begin = from < today() ? today() : addDays(from, 1);
        return scan(ym(begin), begin).then(function (found) {
            if (!found) { apply(p, st); return; }
            setInternal(p, found, true);
            p.infoValue = found;
            var why = st.text.replace(/^Not available: /, '');
            var text = shortLabel(from) + ' is not available (' + why + '). The date was moved to ' + shortLabel(found) + '.';
            apply(p, null);
            showNote(p, text, 'info');
            toast(text);
        });
    }

    function evaluate(p, advance) {
        var input = p.input;
        var v = input.value.trim();
        var my = ++p.seq;
        if (v === '') {
            apply(p, null);
            return Promise.resolve();
        }
        if (!validIso(v)) {
            apply(p, statusOf(p, v));
            return Promise.resolve();
        }
        var svc = serviceOf(input);
        return loadMonth(svc, ym(v), false).then(function () {
            if (my !== p.seq || input.value.trim() !== v || serviceOf(input) !== svc) { return; }
            var st = statusOf(p, v);
            if (st && advance && !isEdit(input)) { return advanceFrom(p, v, st); }
            apply(p, st || null);
        });
    }

    function queue(p, advance) {
        if (advance) { p.wantAdvance = true; }
        clearTimeout(p.timer);
        p.timer = setTimeout(function () {
            if (!visible(p.input)) { return; }
            var adv = p.wantAdvance;
            p.wantAdvance = false;
            evaluate(p, adv);
        }, 0);
    }

    function minMonth(p) {
        var tm = ym(today());
        if (isEdit(p.input) && validIso(p.origin || '') && ym(p.origin) < tm) { return ym(p.origin); }
        return tm;
    }
    function maxMonth() { return monthShift(ym(today()), MAX_AHEAD); }

    function setMsg(text, error) {
        if (!msg) { return; }
        msg.textContent = text;
        msg.className = 'dp-msg' + (error ? ' is-error' : '');
    }

    function hint(p, iso, st, own) {
        if (st) { setMsg('Not available: ' + st.message, true); return; }
        setMsg(longLabel(iso) + (own ? ' (current booking date)' : ' is available.'), false);
    }

    function buildPop() {
        if (pop) { return; }
        pop = el('div', 'dp-pop');
        pop.id = 'dpPop';
        pop.setAttribute('role', 'dialog');
        pop.setAttribute('aria-label', 'Choose a date');
        pop.hidden = true;

        var head = el('div', 'dp-head');
        prevBtn = el('button', 'dp-nav');
        prevBtn.type = 'button';
        prevBtn.setAttribute('aria-label', 'Previous month');
        prevBtn.innerHTML = CHEVRON_L;
        nextBtn = el('button', 'dp-nav');
        nextBtn.type = 'button';
        nextBtn.setAttribute('aria-label', 'Next month');
        nextBtn.innerHTML = CHEVRON_R;
        title = el('div', 'dp-title');
        title.setAttribute('aria-live', 'polite');
        head.appendChild(prevBtn);
        head.appendChild(title);
        head.appendChild(nextBtn);

        var dow = el('div', 'dp-dow');
        dow.setAttribute('aria-hidden', 'true');
        DOW.forEach(function (d) { dow.appendChild(el('span', null, d)); });

        grid = el('div', 'dp-grid');
        grid.setAttribute('role', 'grid');
        msg = el('p', 'dp-msg');
        msg.setAttribute('role', 'status');
        msg.setAttribute('aria-live', 'polite');

        pop.appendChild(head);
        pop.appendChild(dow);
        pop.appendChild(grid);
        pop.appendChild(msg);
        document.body.appendChild(pop);

        prevBtn.addEventListener('click', function () { stepMonth(-1); });
        nextBtn.addEventListener('click', function () { stepMonth(1); });
        grid.addEventListener('keydown', onGridKey);
        pop.addEventListener('keydown', onPopKey);
    }

    function place() {
        if (!current || !pop || pop.hidden) { return; }
        var r = current.input.getBoundingClientRect();
        var ph = pop.offsetHeight;
        var pw = pop.offsetWidth;
        var top = r.bottom + 6;
        if (top + ph > window.innerHeight - 8 && r.top - ph - 6 > 8) { top = r.top - ph - 6; }
        var left = Math.min(Math.max(8, r.left), Math.max(8, window.innerWidth - pw - 8));
        pop.style.top = Math.max(8, top) + 'px';
        pop.style.left = left + 'px';
    }

    function render(p, focusAfter) {
        var svc = serviceOf(p.input);
        var e = cache[entryKey(svc, p.month)];
        var edit = isEdit(p.input);
        var t = today();
        var selected = p.input.value.trim();
        title.textContent = monthLabel(p.month);
        prevBtn.disabled = p.month <= minMonth(p);
        nextBtn.disabled = p.month >= maxMonth();
        grid.textContent = '';
        grid.setAttribute('aria-busy', e ? 'false' : 'true');
        grid.classList.toggle('is-loading', !e);

        var a = p.month.split('-');
        var y = +a[0], m = +a[1];
        var lead = (new Date(y, m - 1, 1).getDay() + 6) % 7;
        var total = new Date(y, m, 0).getDate();
        var cells = [];
        var i;
        for (i = 0; i < lead; i++) {
            var blank = el('div', 'dp-blank');
            blank.setAttribute('role', 'gridcell');
            blank.setAttribute('aria-hidden', 'true');
            cells.push(blank);
        }
        for (i = 1; i <= total; i++) {
            cells.push(dayCell(p, fmt(y, m, i), i, e, edit, t, selected));
        }
        while (cells.length % 7 !== 0) {
            var tail = el('div', 'dp-blank');
            tail.setAttribute('role', 'gridcell');
            tail.setAttribute('aria-hidden', 'true');
            cells.push(tail);
        }
        for (i = 0; i < cells.length; i += 7) {
            var row = el('div', 'dp-row');
            row.setAttribute('role', 'row');
            for (var j = i; j < i + 7; j++) { row.appendChild(cells[j]); }
            grid.appendChild(row);
        }

        if (!e) { setMsg('Checking availability…', false); }
        else if (e.failed) { setMsg('Availability could not be loaded. Dates are still verified when you save.', true); }
        else { setMsg('Select an available date.', false); }

        if (focusAfter) {
            var b = grid.querySelector('[data-iso="' + p.focusIso + '"]');
            if (b) { b.focus(); }
        }
        place();
    }

    function dayCell(p, iso, d, e, edit, t, selected) {
        var own = edit && iso === p.origin;
        var st = null;
        if (!own) {
            if (iso < t) { st = { message: 'date has passed' }; }
            else if (e && e.map[iso]) { st = e.map[iso]; }
        }
        var cell = el('div');
        cell.setAttribute('role', 'gridcell');
        var b = el('button', 'dp-day' + (st ? ' is-off' : '') + (iso === t ? ' is-today' : '') + (iso === selected ? ' is-selected' : '') + (own ? ' is-own' : ''), String(d));
        b.type = 'button';
        b.setAttribute('data-iso', iso);
        b.tabIndex = iso === p.focusIso ? 0 : -1;
        if (st) { b.setAttribute('aria-disabled', 'true'); }
        if (iso === t) { b.setAttribute('aria-current', 'date'); }
        if (iso === selected) { b.setAttribute('aria-pressed', 'true'); }
        b.setAttribute('aria-label', longLabel(iso) + (st ? ', not available: ' + st.message : (own ? ', current booking date' : ', available')));
        b.addEventListener('mouseenter', function () { if (e) { hint(p, iso, st, own); } });
        b.addEventListener('focus', function () {
            p.focusIso = iso;
            if (e) { hint(p, iso, st, own); }
        });
        b.addEventListener('click', function () {
            p.focusIso = iso;
            if (!e) { setMsg('Checking availability…', false); return; }
            if (st) {
                hint(p, iso, st, own);
                var text = 'Not available: ' + st.message;
                p.input.setAttribute('aria-invalid', 'true');
                showNote(p, text, 'error');
                return;
            }
            pick(p, iso);
        });
        cell.appendChild(b);
        return cell;
    }

    function refreshView(p) {
        if (current !== p || !pop || pop.hidden) { return; }
        var had = grid.contains(document.activeElement);
        render(p, had);
    }

    function ensureView(p) {
        var svc = serviceOf(p.input);
        var before = cache[entryKey(svc, p.month)];
        loadMonth(svc, p.month, false).then(function (e) {
            if (current !== p || serviceOf(p.input) !== svc) { return; }
            if (e !== before || !before) { refreshView(p); }
        });
    }

    function open(p, focusGrid) {
        buildPop();
        if (current && current !== p) { current.input.setAttribute('aria-expanded', 'false'); }
        current = p;
        var v = p.input.value.trim();
        var base = validIso(v) ? v : today();
        var min = minMonth(p);
        var max = maxMonth();
        if (ym(base) < min) { base = min === ym(today()) ? today() : min + '-01'; }
        if (ym(base) > max) { base = max + '-01'; }
        p.focusIso = base;
        p.month = ym(base);
        pop.hidden = false;
        p.input.setAttribute('aria-expanded', 'true');
        render(p, focusGrid);
        ensureView(p);
    }

    function close(returnFocus) {
        if (!pop || pop.hidden) { return; }
        pop.hidden = true;
        var p = current;
        current = null;
        if (p) {
            p.input.setAttribute('aria-expanded', 'false');
            if (returnFocus) { p.input.focus(); }
        }
    }

    function pick(p, iso) {
        setInternal(p, iso, true);
        p.infoValue = '';
        p.seq++;
        apply(p, null);
        close(false);
        p.input.focus();
        evaluate(p, false);
    }

    function stepMonth(n) {
        var p = current;
        if (!p) { return; }
        var next = monthShift(p.month, n);
        if (next < minMonth(p) || next > maxMonth()) { return; }
        p.month = next;
        p.focusIso = shiftMonthDate(p.focusIso, n);
        if (ym(p.focusIso) !== next) { p.focusIso = next + '-01'; }
        render(p, false);
        ensureView(p);
    }

    function moveTo(p, iso) {
        var m = ym(iso);
        if (m < minMonth(p) || m > maxMonth()) { return; }
        p.focusIso = iso;
        if (m !== p.month) {
            p.month = m;
            render(p, true);
            ensureView(p);
            return;
        }
        var b = grid.querySelector('[data-iso="' + iso + '"]');
        var old = grid.querySelector('.dp-day[tabindex="0"]');
        if (old) { old.tabIndex = -1; }
        if (b) { b.tabIndex = 0; b.focus(); }
    }

    function onGridKey(ev) {
        var p = current;
        var b = ev.target.closest ? ev.target.closest('.dp-day') : null;
        if (!p || !b) { return; }
        var iso = b.getAttribute('data-iso');
        var next = null;
        switch (ev.key) {
            case 'ArrowLeft': next = addDays(iso, -1); break;
            case 'ArrowRight': next = addDays(iso, 1); break;
            case 'ArrowUp': next = addDays(iso, -7); break;
            case 'ArrowDown': next = addDays(iso, 7); break;
            case 'Home': next = addDays(iso, -dowIndex(iso)); break;
            case 'End': next = addDays(iso, 6 - dowIndex(iso)); break;
            case 'PageUp': next = shiftMonthDate(iso, ev.shiftKey ? -12 : -1); break;
            case 'PageDown': next = shiftMonthDate(iso, ev.shiftKey ? 12 : 1); break;
            case 'Enter':
            case ' ':
                ev.preventDefault();
                b.click();
                return;
            default:
                return;
        }
        ev.preventDefault();
        moveTo(p, next);
    }

    function onPopKey(ev) {
        var p = current;
        if (!p) { return; }
        if (ev.key === 'Escape') {
            ev.preventDefault();
            ev.stopPropagation();
            close(true);
            return;
        }
        if (ev.key === 'Tab') {
            var onDay = ev.target.classList && ev.target.classList.contains('dp-day');
            if ((!ev.shiftKey && onDay) || (ev.shiftKey && ev.target === prevBtn)) {
                ev.preventDefault();
                close(true);
            }
        }
    }

    function formError(p, text) {
        var box = formBox(p);
        if (!box) { return; }
        box.textContent = text;
        box.hidden = false;
        p.boxMsg = text;
    }

    function formPickers(form) {
        return pickers.filter(function (p) { return p.input.form === form && visible(p.input); });
    }

    function onSubmit(ev) {
        var form = ev.target;
        if (!form || form.tagName !== 'FORM') { return; }
        var list = formPickers(form);
        if (!list.length) { return; }
        var blocked = null;
        var unknown = [];
        list.forEach(function (p) {
            var v = p.input.value.trim();
            if (v === '') { return; }
            var st = statusOf(p, v);
            if (st === undefined) { unknown.push(p); }
            else if (st && !blocked) { blocked = { p: p, st: st }; }
        });
        if (blocked) {
            ev.preventDefault();
            ev.stopImmediatePropagation();
            apply(blocked.p, blocked.st);
            formError(blocked.p, blocked.st.text);
            blocked.p.input.focus();
            return;
        }
        if (!unknown.length) { return; }
        ev.preventDefault();
        ev.stopImmediatePropagation();
        Promise.all(unknown.map(function (p) { return loadMonth(serviceOf(p.input), ym(p.input.value.trim()), false); })).then(function () {
            var bad = null;
            unknown.forEach(function (p) {
                var st = statusOf(p, p.input.value.trim());
                if (st && !bad) { bad = { p: p, st: st }; }
            });
            if (bad) {
                apply(bad.p, bad.st);
                formError(bad.p, bad.st.text);
                bad.p.input.focus();
                return;
            }
            if (form.requestSubmit) { form.requestSubmit(); }
        });
    }

    function attach(input) {
        if (!input || input.getAttribute('data-dp') === '1') { return null; }
        input.setAttribute('data-dp', '1');
        if (input.type !== 'text') { input.type = 'text'; }

        var p = {
            input: input,
            note: el('p', 'dp-note'),
            origin: input.value,
            month: '',
            focusIso: '',
            infoValue: '',
            boxMsg: '',
            seq: 0,
            timer: null,
            wantAdvance: false,
            internal: false,
            touched: false
        };
        p.note.hidden = true;
        p.note.setAttribute('role', 'status');
        p.note.setAttribute('aria-live', 'polite');
        p.note.id = 'dpNote_' + (input.id || pickers.length);
        input.insertAdjacentElement('afterend', p.note);

        input.classList.add('dp-input');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('maxlength', '10');
        input.setAttribute('placeholder', 'YYYY-MM-DD');
        input.setAttribute('aria-haspopup', 'dialog');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', 'dpPop');
        input.setAttribute('aria-describedby', p.note.id);

        Object.defineProperty(input, 'value', {
            configurable: true,
            enumerable: true,
            get: function () { return nativeValue.get.call(input); },
            set: function (v) {
                nativeValue.set.call(input, v);
                if (p.internal) { return; }
                p.touched = true;
                p.origin = nativeValue.get.call(input);
                p.infoValue = '';
                queue(p, true);
            }
        });

        input.addEventListener('click', function () { open(p, false); });
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowDown') {
                ev.preventDefault();
                if (current === p && pop && !pop.hidden) {
                    var b = grid.querySelector('.dp-day[tabindex="0"]');
                    if (b) { b.focus(); }
                } else {
                    open(p, true);
                }
                return;
            }
            if (ev.key === 'Escape' && current === p && pop && !pop.hidden) {
                ev.preventDefault();
                ev.stopPropagation();
                close(false);
            }
        });
        input.addEventListener('input', function () {
            if (p.internal) { return; }
            p.infoValue = '';
            var v = input.value.trim();
            if (v.length === 10) {
                evaluate(p, false);
                if (current === p && validIso(v) && ym(v) >= minMonth(p) && ym(v) <= maxMonth()) {
                    p.focusIso = v;
                    p.month = ym(v);
                    render(p, false);
                    ensureView(p);
                }
            } else {
                input.setCustomValidity('');
                input.removeAttribute('aria-invalid');
                showNote(p, '', '');
            }
        });
        input.addEventListener('change', function () {
            if (p.internal) { return; }
            p.infoValue = '';
            evaluate(p, false);
        });

        if (input.form) {
            input.form.addEventListener('reset', function () {
                p.touched = false;
                setTimeout(function () {
                    if (!p.touched) { p.origin = nativeValue.get.call(input); }
                    p.infoValue = '';
                    showNote(p, '', '');
                    input.setCustomValidity('');
                    input.removeAttribute('aria-invalid');
                    queue(p, true);
                }, 0);
            });
        }

        var modal = input.closest ? input.closest('.modal') : null;
        if (modal && window.MutationObserver) {
            new MutationObserver(function () {
                if (!modal.hidden) { queue(p, true); }
            }).observe(modal, { attributes: true, attributeFilter: ['hidden'] });
        }

        pickers.push(p);
        queue(p, true);
        return p;
    }

    function findPicker(input) {
        for (var i = 0; i < pickers.length; i++) {
            if (pickers[i].input === input) { return pickers[i]; }
        }
        return null;
    }

    document.addEventListener('mousedown', function (ev) {
        if (!current || !pop || pop.hidden) { return; }
        if (pop.contains(ev.target) || ev.target === current.input) { return; }
        close(false);
    });
    document.addEventListener('submit', onSubmit, true);
    document.addEventListener('change', function (ev) {
        var t = ev.target;
        if (!t || t.name !== 'service_id' || !t.form) { return; }
        pickers.forEach(function (p) {
            if (p.input.form !== t.form) { return; }
            p.infoValue = '';
            evaluate(p, false);
            if (current === p) {
                render(p, false);
                ensureView(p);
            }
        });
    });
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll(SELECTOR), attach);
    }

    window.BvoDatePicker = {
        attach: attach,
        refresh: function (input) {
            var p = findPicker(input);
            if (p) { queue(p, false); }
        },
        invalidate: function () {
            cache = {};
            pickers.forEach(function (p) { queue(p, false); });
            if (current) { ensureView(current); }
        }
    };

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
}());