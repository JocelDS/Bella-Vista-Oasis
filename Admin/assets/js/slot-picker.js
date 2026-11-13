(function () {
    'use strict';

    var SELECTOR = '#ap_start, #bk_start, #rs_start, #bk_time, #cwTime';
    var ISO = /^\d{4}-\d{2}-\d{2}$/;
    var apiBase = document.body.getAttribute('data-api') || '';
    var selectValue = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
    var pickers = [];

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function visible(node) {
        return !!(node.offsetParent || node.getClientRects().length);
    }

    function unset(v) {
        return v === '' || v === '0' || v === null || v === undefined;
    }

    function watch(node, onSet) {
        if (!node) { return; }
        var d = Object.getOwnPropertyDescriptor(node, 'value')
            || Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value')
            || Object.getOwnPropertyDescriptor(node.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype, 'value');
        Object.defineProperty(node, 'value', {
            configurable: true,
            enumerable: true,
            get: function () { return d.get.call(node); },
            set: function (v) { d.set.call(node, v); onSet(); }
        });
    }

    function showNote(p, text, kind) {
        p.note.textContent = text || '';
        p.note.className = 'dp-note' + (kind ? ' is-' + kind : '');
        p.note.hidden = !text;
    }

    function setOptions(p, placeholder, items) {
        var sel = p.sel;
        p.internal = true;
        sel.textContent = '';
        var first = el('option', null, placeholder);
        first.value = '';
        sel.appendChild(first);
        items.forEach(function (it) {
            var o = el('option', null, it.text);
            o.value = it.value;
            if (it.disabled) { o.disabled = true; }
            sel.appendChild(o);
        });
        p.internal = false;
    }

    function applyValue(p, before) {
        var sel = p.sel;
        var want = p.want;
        var chosen = '';
        if (want) {
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === want && !sel.options[i].disabled) { chosen = want; break; }
            }
        }
        p.internal = true;
        selectValue.set.call(sel, chosen);
        p.internal = false;
        if (chosen !== before) {
            p.internal = true;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            p.internal = false;
        }
    }

    function reset(p, text, note, kind) {
        var before = selectValue.get.call(p.sel);
        setOptions(p, text, []);
        p.slotRooms = {};
        showNote(p, note || '', kind || '');
        applyValue(p, before);
    }

    function filterRooms(p, ids) {
        if (!p.room) { return; }
        Array.prototype.forEach.call(p.room.options, function (o) {
            if (unset(o.value)) { return; }
            var ok = ids.indexOf(parseInt(o.value, 10)) !== -1;
            if (o.getAttribute('data-sp-off') === null) {
                o.setAttribute('data-sp-off', o.disabled ? '1' : '0');
            }
            o.hidden = !ok;
            o.disabled = !ok || o.getAttribute('data-sp-off') === '1';
        });
    }

    var PRIORITY = { 'Booked': 3, 'Cleaning gap': 2, 'Already passed': 1 };

    function render(p, d) {
        var before = selectValue.get.call(p.sel);
        filterRooms(p, d.service_rooms || []);

        var roomVal = p.room ? p.room.value : '';
        if (d.room_mismatch && !unset(roomVal)) {
            p.room.value = '';
            return;
        }

        var rooms = d.rooms || [];
        var usable = rooms.filter(function (r) { return r.usable; });
        var byStart = {};
        var order = [];
        p.slotRooms = {};

        usable.forEach(function (r) {
            r.slots.forEach(function (s) {
                var it = byStart[s.start];
                if (!it) {
                    it = { start: s.start, label: s.label, free: 0, own: false, reason: '', rank: 0 };
                    byStart[s.start] = it;
                    order.push(s.start);
                    p.slotRooms[s.start] = [];
                }
                if (s.available) {
                    it.free++;
                    p.slotRooms[s.start].push(r.id);
                    if (s.own) { it.own = true; }
                } else if ((PRIORITY[s.reason] || 0) > it.rank) {
                    it.rank = PRIORITY[s.reason] || 0;
                    it.reason = s.reason;
                }
            });
        });
        order.sort();

        var single = usable.length === 1;
        var items = order.map(function (k) {
            var it = byStart[k];
            var text = it.label;
            if (it.free > 0) {
                if (it.own) { text += ' · current'; }
                else if (!single && it.free > 1) { text += ' · ' + it.free + ' suites free'; }
            } else {
                text += ' · ' + (it.reason || 'Unavailable');
            }
            return { value: it.start, text: text, disabled: it.free === 0 };
        });

        var enabled = items.filter(function (i) { return !i.disabled; }).length;
        setOptions(p, enabled ? 'Select a start time' : 'No free start times', items);

        var note = '';
        var kind = '';
        if (d.date_status && d.date_status.message) {
            note = 'Not available: ' + d.date_status.message + '.';
            kind = 'error';
        } else if (!usable.length) {
            note = 'No ' + (d.service ? d.service.room_code : '') + ' suite can take bookings on this date (maintenance).';
            kind = 'error';
        } else if (!enabled) {
            note = 'No free start times for this date' + (!unset(roomVal) && single ? ' in ' + usable[0].name : '') + '.';
            kind = 'error';
        }
        showNote(p, note, kind);
        applyValue(p, before);
    }

    function refresh(p) {
        if (!visible(p.sel)) { return; }
        var svc = p.service ? p.service.value : '';
        var date = p.date ? p.date.value.trim() : '';
        if (!/^\d+$/.test(svc) || svc === '0') {
            reset(p, 'Select a service first', '', '');
            return;
        }
        if (!ISO.test(date)) {
            reset(p, 'Pick a date first', '', '');
            return;
        }
        var room = p.room ? p.room.value : '';
        var exclude = p.idField ? p.idField.value : '';
        var url = apiBase + '/slots.php?date=' + encodeURIComponent(date) + '&service_id=' + encodeURIComponent(svc)
            + (!unset(room) ? '&room_id=' + encodeURIComponent(room) : '')
            + (/^\d+$/.test(exclude) && exclude !== '0' ? '&exclude_id=' + encodeURIComponent(exclude) : '');
        var my = ++p.seq;
        p.sel.setAttribute('aria-busy', 'true');
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (res) {
                if (res.status === 401) { window.location.reload(); throw new Error('unauthorized'); }
                return res.json();
            })
            .then(function (d) {
                if (my !== p.seq) { return; }
                p.sel.removeAttribute('aria-busy');
                if (!d || !d.ok) {
                    reset(p, 'Time slots unavailable', (d && d.error) || 'Unable to load time slots.', 'error');
                    return;
                }
                render(p, d);
            })
            .catch(function () {
                if (my !== p.seq) { return; }
                p.sel.removeAttribute('aria-busy');
                reset(p, 'Time slots unavailable', 'Unable to load time slots. The start time is still verified when you save.', 'error');
            });
    }

    function queue(p) {
        clearTimeout(p.timer);
        p.timer = setTimeout(function () { refresh(p); }, 0);
    }

    function attach(sel) {
        if (!sel || sel.tagName !== 'SELECT' || sel.getAttribute('data-sp') === '1') { return; }
        sel.setAttribute('data-sp', '1');
        var form = sel.form;
        if (!form) { return; }

        var p = {
            sel: sel,
            form: form,
            service: form.querySelector('select[name="service_id"]'),
            room: form.querySelector('select[name="room_id"]'),
            date: form.querySelector('input[name="appointment_date"]'),
            idField: form.querySelector('input[name="id"]'),
            note: el('p', 'dp-note'),
            want: '',
            seq: 0,
            timer: null,
            internal: false,
            slotRooms: {}
        };
        p.note.hidden = true;
        p.note.setAttribute('role', 'status');
        p.note.setAttribute('aria-live', 'polite');
        sel.insertAdjacentElement('afterend', p.note);

        Object.defineProperty(sel, 'value', {
            configurable: true,
            enumerable: true,
            get: function () { return selectValue.get.call(sel); },
            set: function (v) {
                var s = v === null || v === undefined ? '' : String(v);
                p.want = s;
                selectValue.set.call(sel, s);
            }
        });

        sel.addEventListener('change', function () {
            if (p.internal) { return; }
            p.want = selectValue.get.call(sel);
            if (!p.want || !p.room || p.room.value !== '') { return; }
            var ids = p.slotRooms[p.want] || [];
            if (ids.length) {
                p.room.value = String(ids[0]);
                p.room.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        var trigger = function () { queue(p); };
        [p.service, p.room, p.date].forEach(function (n) {
            if (!n) { return; }
            watch(n, trigger);
            n.addEventListener('change', trigger);
        });
        if (p.date) { p.date.addEventListener('input', trigger); }

        form.addEventListener('reset', function () {
            p.want = '';
            setTimeout(trigger, 0);
        });

        var modal = sel.closest ? sel.closest('.modal') : null;
        if (modal && window.MutationObserver) {
            new MutationObserver(function () {
                if (!modal.hidden) { queue(p); }
            }).observe(modal, { attributes: true, attributeFilter: ['hidden'] });
        }

        pickers.push(p);
        queue(p);
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll(SELECTOR), attach);
    }

    window.BvoSlotPicker = {
        refresh: function (node) {
            pickers.forEach(function (p) { if (!node || p.sel === node) { queue(p); } });
        }
    };

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
}());