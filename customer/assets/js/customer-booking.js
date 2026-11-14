(function () {
  'use strict';

  var form = document.getElementById('bookingForm');
  if (!form) return;

  var endpoint = form.getAttribute('data-endpoint');
  var serviceId = form.getAttribute('data-service');
  var price = parseFloat(form.getAttribute('data-price')) || 0;
  var base = parseInt(form.getAttribute('data-base'), 10) || 1;
  var extra = parseFloat(form.getAttribute('data-extra')) || 0;

  var dateInput = document.getElementById('bkDate');
  var datesBox = document.getElementById('bkDates');
  var dateMsg = document.getElementById('bkDateMsg');
  var slotsBox = document.getElementById('bkSlots');
  var startInput = document.getElementById('bkStart');
  var guestsInput = document.getElementById('bkGuests');
  var submit = document.getElementById('bkSubmit');
  var sumDate = document.getElementById('sumDate');
  var sumTime = document.getElementById('sumTime');
  var sumGuests = document.getElementById('sumGuests');
  var sumTotal = document.getElementById('sumTotal');

  var unavailable = {};
  var token = 0;
  var chosenLabel = '';

  function peso(n) {
    return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
  }

  function iso(d) {
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
  }

  function api(params) {
    return fetch(endpoint + '?' + new URLSearchParams(params).toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected response.' }; }); });
  }

  function updateSummary() {
    var g = parseInt(guestsInput.value, 10) || base;
    var total = price + (extra > 0 && g > base ? (g - base) * extra : 0);
    sumGuests.textContent = g;
    sumTotal.textContent = peso(total);
    sumDate.textContent = dateInput.value ? new Date(dateInput.value + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) : '—';
    sumTime.textContent = startInput.value ? chosenLabel : '—';
    submit.disabled = !(dateInput.value && startInput.value);
  }

  function setMsg(text, err) {
    dateMsg.textContent = text || '';
    dateMsg.className = 'bk-msg' + (err ? ' err' : '');
  }

  function clearSlot() {
    startInput.value = '';
    chosenLabel = '';
  }

  function renderSlots(res) {
    slotsBox.innerHTML = '';
    if (!res.ok) {
      slotsBox.innerHTML = '<p class="bk-hint"></p>';
      slotsBox.firstChild.textContent = res.error || 'Unable to load times.';
      return;
    }
    if (res.date_status) {
      setMsg('Unavailable: ' + res.date_status.message + '.', true);
    } else {
      setMsg('', false);
    }
    if (!res.slots.length) {
      slotsBox.innerHTML = '<p class="bk-hint">No times available on this date. Please choose another date.</p>';
      return;
    }
    res.slots.forEach(function (s) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'bk-slot';
      b.disabled = !s.available;
      b.innerHTML = '<span></span><small></small>';
      b.firstChild.textContent = s.label;
      b.lastChild.textContent = s.available ? 'Available' : (s.reason || 'Unavailable');
      if (s.available && startInput.value === s.start + ':00') {
        b.classList.add('on');
        chosenLabel = s.label;
      }
      b.addEventListener('click', function () {
        slotsBox.querySelectorAll('.bk-slot.on').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        startInput.value = s.start + ':00';
        chosenLabel = s.label;
        updateSummary();
      });
      slotsBox.appendChild(b);
    });
  }

  function loadSlots() {
    var date = dateInput.value;
    if (!date) return;
    var my = ++token;
    var keep = startInput.value;
    slotsBox.innerHTML = '<p class="bk-hint">Loading times…</p>';
    api({ service_id: serviceId, date: date }).then(function (res) {
      if (my !== token) return;
      var found = res.ok && res.slots.some(function (s) { return s.available && s.start + ':00' === keep; });
      if (!found) clearSlot();
      renderSlots(res);
      updateSummary();
    }).catch(function () {
      if (my !== token) return;
      slotsBox.innerHTML = '<p class="bk-hint">Unable to load times. Check your connection and try again.</p>';
    });
  }

  function selectDate(value) {
    dateInput.value = value;
    datesBox.querySelectorAll('.bk-date').forEach(function (b) {
      b.classList.toggle('on', b.getAttribute('data-date') === value);
    });
    clearSlot();
    updateSummary();
    if (unavailable[value]) {
      setMsg('Unavailable: ' + unavailable[value] + '.', true);
    } else {
      setMsg('', false);
    }
    loadSlots();
  }

  function buildQuickDates() {
    var today = new Date();
    for (var i = 0; i < 10; i++) {
      var d = new Date(today.getFullYear(), today.getMonth(), today.getDate() + i);
      var v = iso(d);
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'bk-date';
      b.setAttribute('data-date', v);
      b.innerHTML = '<span></span><b></b><span></span>';
      b.children[0].textContent = d.toLocaleDateString('en-PH', { weekday: 'short' });
      b.children[1].textContent = d.getDate();
      b.children[2].textContent = d.toLocaleDateString('en-PH', { month: 'short' });
      if (unavailable[v]) {
        b.disabled = true;
        b.title = unavailable[v];
      }
      b.addEventListener('click', (function (val) { return function () { selectDate(val); }; })(v));
      datesBox.appendChild(b);
    }
    if (dateInput.value) {
      datesBox.querySelectorAll('.bk-date').forEach(function (b) {
        b.classList.toggle('on', b.getAttribute('data-date') === dateInput.value);
      });
    }
  }

  dateInput.addEventListener('change', function () { if (dateInput.value) selectDate(dateInput.value); });
  guestsInput.addEventListener('change', updateSummary);

  form.addEventListener('submit', function (e) {
    var ok = dateInput.value && startInput.value &&
      document.getElementById('hc').value.trim() !== '' &&
      document.getElementById('hi').value.trim() !== '' &&
      form.querySelector('input[name="is_pregnant"]:checked');
    if (!ok) {
      e.preventDefault();
      setMsg('Please choose a date and time, and complete the health declaration.', true);
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Sending…';
  });

  api({ service_id: serviceId, mode: 'dates' }).then(function (res) {
    if (res.ok) {
      res.dates.forEach(function (d) { unavailable[d.date] = d.message; });
    }
  }).catch(function () {}).then(function () {
    buildQuickDates();
    updateSummary();
    if (dateInput.value) selectDate(dateInput.value);
  });
})();
