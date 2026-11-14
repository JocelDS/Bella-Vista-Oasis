(function () {
  'use strict';
  var BV = window.BV = window.BV || {};
  var form = document.getElementById('bookingForm');
  if (!form) return;

  BV.cfg = JSON.parse(document.getElementById('bkInit').textContent);
  BV.state = { slotLabel: '' };

  var $ = function (id) { return document.getElementById(id); };
  var guests = $('bkGuests'), roomSel = $('bkRoom'), therapist = $('bkTherapist');
  var dateHidden = $('bkDate'), startHidden = $('bkStart'), datePick = $('bkDatePick');
  var datesBox = $('bkDates'), slotsBox = $('bkSlots'), dateMsg = $('bkDateMsg');
  var unavailable = {};
  var token = 0;

  function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function api(params) {
    return fetch(BV.cfg.endpoint + '?' + new URLSearchParams(params).toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected response.' }; }); });
  }
  function setMsg(t, err) { dateMsg.textContent = t || ''; dateMsg.className = 'bk-msg' + (err ? ' err' : ''); }
  function clearSlot() { startHidden.value = ''; BV.state.slotLabel = ''; }

  /* ---- Step 2: service drives guests and chamber options ---- */
  function applyService(keepGuests) {
    var svc = BV.selectedService();
    guests.innerHTML = '';
    if (!svc) { guests.disabled = true; return; }
    guests.disabled = false;
    for (var g = +svc.dataset.min; g <= +svc.dataset.max; g++) {
      var o = document.createElement('option');
      o.value = g;
      o.textContent = g + (g === 1 ? ' guest' : ' guests') + (+svc.dataset.extra > 0 && g > +svc.dataset.base ? ' (extra guest fee applies)' : '');
      guests.appendChild(o);
    }
    guests.value = keepGuests && +keepGuests >= +svc.dataset.min && +keepGuests <= +svc.dataset.max ? keepGuests : svc.dataset.base;
    [].slice.call(roomSel.options).forEach(function (o) {
      if (!o.value) return;
      var match = o.getAttribute('data-code') === svc.dataset.code;
      o.hidden = !match;
      if (!match && o.selected) roomSel.value = '';
    });
    document.querySelectorAll('.bk-svc').forEach(function (l) { l.classList.toggle('on', l.querySelector('input').checked); });
  }

  /* ---- Step 3: dates and slots ---- */
  function renderSlots(res) {
    slotsBox.innerHTML = '';
    if (!res.ok) { slotsBox.innerHTML = '<p class="bk-hint"></p>'; slotsBox.firstChild.textContent = res.error || 'Unable to load times.'; return; }
    setMsg(res.date_status ? 'Unavailable: ' + res.date_status.message + '.' : '', !!res.date_status);
    if (!res.slots.length) { slotsBox.innerHTML = '<p class="bk-hint">No times are open on this date. Please choose another date.</p>'; return; }
    res.slots.forEach(function (s) {
      var b = document.createElement('button');
      b.type = 'button';
      var full = !s.available && /fully booked|booked/i.test(s.reason || '');
      b.className = 'bk-slot' + (s.available ? '' : (full ? ' full' : ' off'));
      b.disabled = !s.available;
      b.innerHTML = '<span></span><small></small>';
      b.firstChild.textContent = s.label;
      b.lastChild.textContent = s.available ? 'Available' : (full ? 'Fully booked' : (s.reason || 'Unavailable'));
      if (s.available && startHidden.value === s.start + ':00') { b.classList.add('on'); BV.state.slotLabel = s.label; }
      b.addEventListener('click', function () {
        slotsBox.querySelectorAll('.bk-slot.on').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        startHidden.value = s.start + ':00';
        BV.state.slotLabel = s.label;
        BV.steps.message(3, '');
        BV.summary();
      });
      slotsBox.appendChild(b);
    });
  }

  function loadSlots() {
    var svc = BV.selectedService();
    if (!svc) { slotsBox.innerHTML = '<p class="bk-hint">Choose a treatment first.</p>'; return; }
    if (!dateHidden.value) { slotsBox.innerHTML = '<p class="bk-hint">Select a date to see the available times.</p>'; return; }
    var my = ++token, keep = startHidden.value;
    slotsBox.innerHTML = '<p class="bk-hint">Loading times…</p>';
    api({ service_id: svc.value, date: dateHidden.value }).then(function (res) {
      if (my !== token) return;
      if (!(res.ok && res.slots.some(function (s) { return s.available && s.start + ':00' === keep; }))) clearSlot();
      renderSlots(res);
      BV.summary();
    }).catch(function () { if (my === token) slotsBox.innerHTML = '<p class="bk-hint">Unable to load times. Check your connection and try again.</p>'; });
  }

  function selectDate(v) {
    dateHidden.value = v;
    datePick.value = v;
    datesBox.querySelectorAll('.bk-date').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-date') === v); });
    clearSlot();
    setMsg(unavailable[v] ? 'Unavailable: ' + unavailable[v] + '.' : '', !!unavailable[v]);
    BV.summary();
    loadSlots();
  }

  function buildDates() {
    datesBox.innerHTML = '';
    var today = new Date();
    for (var i = 0; i < 10; i++) {
      var d = new Date(today.getFullYear(), today.getMonth(), today.getDate() + i), v = iso(d);
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'bk-date';
      b.setAttribute('data-date', v);
      b.innerHTML = '<span></span><b></b><span></span>';
      b.children[0].textContent = d.toLocaleDateString('en-PH', { weekday: 'short' });
      b.children[1].textContent = d.getDate();
      b.children[2].textContent = d.toLocaleDateString('en-PH', { month: 'short' });
      if (unavailable[v]) { b.disabled = true; b.title = unavailable[v]; }
      b.addEventListener('click', (function (val) { return function () { selectDate(val); }; })(v));
      datesBox.appendChild(b);
    }
    if (dateHidden.value) datesBox.querySelectorAll('.bk-date').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-date') === dateHidden.value); });
  }

  function loadUnavailable() {
    var svc = BV.selectedService();
    unavailable = {};
    if (!svc) { buildDates(); return; }
    api({ service_id: svc.value, mode: 'dates' }).then(function (res) {
      if (res.ok) res.dates.forEach(function (d) { unavailable[d.date] = d.message; });
    }).catch(function () {}).then(buildDates);
  }

  /* ---- Events ---- */
  document.querySelectorAll('input[name="service_id"]').forEach(function (r) {
    r.addEventListener('change', function () {
      applyService(null);
      clearSlot();
      BV.steps.message(2, '');
      loadUnavailable();
      loadSlots();
      BV.summary();
    });
  });
  [guests, roomSel, therapist].forEach(function (el) { el.addEventListener('change', function () { BV.steps.message(2, ''); BV.summary(); }); });
  datePick.addEventListener('change', function () { if (datePick.value) selectDate(datePick.value); });
  form.addEventListener('change', function (e) { if (e.target.closest('section[data-step="4"]')) BV.steps.message(4, ''); });

  BV.onStep = function (n) { if (n === 3) loadSlots(); };

  /* ---- Confirm: validate every step, then ask once before sending ---- */
  var modal = $('bkModal'), confirmBtn = $('bkConfirm'), sending = false;
  form.addEventListener('submit', function (e) {
    if (sending) return;
    e.preventDefault();
    var bad = BV.firstInvalid();
    if (bad) { BV.steps.go(bad.step); BV.steps.message(bad.step, bad.message); return; }
    var p = BV.price();
    $('bkModalText').textContent = p.name + ' · ' + $('sumDate').textContent + ' · ' + $('sumTime').textContent + ' · ' + $('sumGuests').textContent + ' · Total ' + BV.peso(p.total) + '.';
    modal.hidden = false;
    $('bkModalYes').focus();
  });
  $('bkModalNo').addEventListener('click', function () { modal.hidden = true; });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.hidden = true; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') modal.hidden = true; });
  $('bkModalYes').addEventListener('click', function () {
    if (sending) return;
    sending = true;
    modal.hidden = true;
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Confirming…';
    form.submit();
  });

  /* ---- Start-up: restore a previous attempt or a ?service_id= choice ---- */
  var ini = BV.cfg.initial || {};
  if (ini.service) {
    var r = document.querySelector('input[name="service_id"][value="' + ini.service + '"]');
    if (r) r.checked = true;
  }
  applyService(ini.guests);
  if (ini.room) roomSel.value = ini.room;
  if (ini.therapist) therapist.value = ini.therapist;
  if (ini.date) dateHidden.value = ini.date;
  if (ini.start) startHidden.value = ini.start;
  loadUnavailable();
  if (dateHidden.value) { datePick.value = dateHidden.value; loadSlots(); }
  BV.steps.go(ini.service && ini.date && ini.start ? 4 : (ini.service ? 2 : 1));
  BV.summary();
})();