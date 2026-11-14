(function () {
  'use strict';

  var list = document.getElementById('qrList');
  var box = document.getElementById('qrPass');
  if (!list || !box) return;

  var endpoint = list.getAttribute('data-endpoint');
  var csrf = list.getAttribute('data-csrf');
  var canvas = document.getElementById('qrCanvas');
  var title = document.getElementById('qrTitle');
  var meta = document.getElementById('qrMeta');

  function toast(msg) {
    var t = document.getElementById('cuToast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function () { t.classList.remove('show'); }, 4200);
  }

  function draw(text) {
    var q = window.qrcode(0, 'M');
    q.addData(text);
    q.make();
    var n = q.getModuleCount();
    var cell = 6;
    var margin = 4;
    var size = (n + margin * 2) * cell;
    canvas.width = size;
    canvas.height = size;
    var c = canvas.getContext('2d');
    c.fillStyle = '#ffffff';
    c.fillRect(0, 0, size, size);
    c.fillStyle = '#49364f';
    for (var r = 0; r < n; r++) {
      for (var col = 0; col < n; col++) {
        if (q.isDark(r, col)) c.fillRect((col + margin) * cell, (r + margin) * cell, cell, cell);
      }
    }
  }

  list.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-qr]');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    var body = new URLSearchParams();
    body.set('appointment_id', btn.getAttribute('data-qr'));
    body.set('csrf', csrf);
    fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected response.' }; }); })
      .then(function (j) {
        if (!j.ok) { toast(j.error || 'Unable to create your QR pass.'); return; }
        draw(j.payload);
        title.textContent = j.pass.code + ' · ' + j.pass.service;
        meta.textContent = j.pass.date + ' · ' + j.pass.time + ' · valid until ' + j.expires_label;
        box.hidden = false;
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
      })
      .catch(function () { toast('Network error. Please try again.'); })
      .then(function () { btn.disabled = false; });
  });
})();
