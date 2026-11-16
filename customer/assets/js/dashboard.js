(function () {
  'use strict';

  var dataEl = document.getElementById('cdData');
  var data = dataEl ? JSON.parse(dataEl.textContent) : {};
  var modal = document.getElementById('cdModal');
  var title = document.getElementById('cdModalTitle');
  var body = document.getElementById('cdModalBody');

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }
  function open(t) { title.textContent = t; body.innerHTML = ''; modal.hidden = false; document.body.classList.add('cd-lock'); }
  function close() { modal.hidden = true; document.body.classList.remove('cd-lock'); }

  function find(id) {
    var all = (data.history || []).slice();
    if (data.next) all.push(data.next);
    for (var i = 0; i < all.length; i++) if (all[i].id === id) return all[i];
    return null;
  }

  function hidden(form, name, value) {
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = name; i.value = value;
    form.appendChild(i);
  }
  function actionForm(action, id) {
    var f = document.createElement('form');
    f.method = 'post'; f.action = data.action;
    hidden(f, 'csrf', data.csrf); hidden(f, 'action', action); hidden(f, 'appointment_id', String(id));
    return f;
  }
  function buttons(form, items) {
    var row = el('div', 'cd-modal-actions');
    items.forEach(function (it) { row.appendChild(it); });
    form.appendChild(row);
  }
  function btn(cls, text, type) { var b = el('button', 'lp-btn ' + cls, text); b.type = type || 'button'; return b; }

  function showDetails(a) {
    open(a.service + ' · ' + a.code);
    var dl = el('dl', 'cd-dl');
    [['Date', a.date], ['Time', a.time], ['Therapist', a.therapist], ['Room', a.room], ['Status', a.status], ['Payment', a.payment],
     ['Guests', String(a.guests)], ['Total', a.total], ['Paid', a.paid], ['Balance', a.balance]].forEach(function (r) {
      var d = el('div'); d.appendChild(el('dt', null, r[0])); d.appendChild(el('dd', null, r[1])); dl.appendChild(d);
    });
    body.appendChild(dl);
    if (a.description) body.appendChild(el('p', null, a.description));
    var row = el('div', 'cd-modal-actions');
    var c = btn('lp-btn-line', 'Close'); c.addEventListener('click', close); row.appendChild(c);
    body.appendChild(row);
  }

  function showCancel(a) {
    open('Cancel Reservation');
    body.appendChild(el('p', null, 'Cancel ' + a.service + ' on ' + a.date + ' at ' + a.time + '?'));
    if (data.cancelMsg) body.appendChild(el('div', 'cd-note', data.cancelMsg));
    var f = actionForm('cancel', a.id);
    var keep = btn('lp-btn-line', 'Keep Reservation');
    keep.addEventListener('click', close);
    var go = btn('lp-btn-danger', 'Yes, Cancel', 'submit');
    f.addEventListener('submit', function () { go.disabled = true; });
    buttons(f, [keep, go]);
    body.appendChild(f);
  }

  function showReschedule(a) {
    open('Reschedule Appointment');
    body.appendChild(el('p', null, a.service + ' · currently ' + a.date + ', ' + a.time + '. Choose a new date to see the open times.'));
    if (data.reschedMsg) body.appendChild(el('div', 'cd-note', data.reschedMsg));
    var f = actionForm('reschedule', a.id);
    var wrap1 = el('div', 'cd-field'); wrap1.appendChild(el('label', null, 'New date'));
    var date = document.createElement('input'); date.type = 'date'; date.name = 'date'; date.min = data.today; date.required = true;
    wrap1.appendChild(date);
    var wrap2 = el('div', 'cd-field'); wrap2.appendChild(el('label', null, 'Available times'));
    var sel = document.createElement('select'); sel.name = 'start'; sel.required = true; sel.disabled = true;
    var ph = document.createElement('option'); ph.value = ''; ph.textContent = 'Choose a date first'; sel.appendChild(ph);
    wrap2.appendChild(sel);
    var msg = el('p', 'cd-slot-msg');
    f.appendChild(wrap1); f.appendChild(wrap2); f.appendChild(msg);
    var cancel = btn('lp-btn-line', 'Close'); cancel.addEventListener('click', close);
    var ok = btn('lp-btn-purple', 'Confirm New Time', 'submit'); ok.disabled = true;
    buttons(f, [cancel, ok]);
    body.appendChild(f);

    date.addEventListener('change', function () {
      sel.innerHTML = ''; sel.disabled = true; ok.disabled = true; msg.textContent = '';
      if (!date.value) return;
      var o = document.createElement('option'); o.textContent = 'Checking availability…'; sel.appendChild(o);
      fetch(data.slots + '?appointment_id=' + a.id + '&date=' + encodeURIComponent(date.value), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          sel.innerHTML = '';
          if (!d.ok || !d.slots.length) {
            var n = document.createElement('option'); n.value = ''; n.textContent = 'No open times'; sel.appendChild(n);
            msg.textContent = d.error || d.message || 'No open times on that date.';
            return;
          }
          var first = document.createElement('option'); first.value = ''; first.textContent = 'Select a time'; sel.appendChild(first);
          d.slots.forEach(function (s) { var x = document.createElement('option'); x.value = s.start; x.textContent = s.label; sel.appendChild(x); });
          sel.disabled = false;
        })
        .catch(function () { sel.innerHTML = ''; msg.textContent = 'Could not load times. Please try again.'; });
    });
    sel.addEventListener('change', function () { ok.disabled = !sel.value; });
    f.addEventListener('submit', function (e) { if (!date.value || !sel.value) { e.preventDefault(); return; } ok.disabled = true; });
  }

  document.addEventListener('click', function (e) {
    var t;
    if ((t = e.target.closest('[data-view]'))) { var a = find(parseInt(t.getAttribute('data-view'), 10)); if (a) showDetails(a); }
    else if ((t = e.target.closest('[data-cancel]'))) { if (data.next) showCancel(data.next); }
    else if ((t = e.target.closest('[data-reschedule]'))) { if (data.next) showReschedule(data.next); }
    else if (e.target.closest('[data-close]') || e.target === modal) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

  var filters = document.querySelectorAll('.cd-filters button');
  var rows = document.querySelectorAll('.cd-table tbody tr');
  var none = document.querySelector('.cd-none');
  filters.forEach(function (b) {
    b.addEventListener('click', function () {
      filters.forEach(function (x) { x.classList.toggle('active', x === b); });
      var f = b.getAttribute('data-filter'), shown = 0;
      rows.forEach(function (r) {
        var show = f === 'all' || r.getAttribute('data-status') === f;
        r.hidden = !show;
        if (show) shown++;
      });
      if (none) none.hidden = shown !== 0;
    });
  });
})();