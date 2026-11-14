(function () {
  'use strict';
  var box = document.getElementById('qrBox');
  if (!box) return;
  var canvas = document.getElementById('qrCanvas');
  var meta = document.getElementById('qrMeta');
  var retry = document.getElementById('qrRetry');

  function draw(text) {
    var q = window.qrcode(0, 'M');
    q.addData(text);
    q.make();
    var n = q.getModuleCount(), cell = 6, margin = 4, size = (n + margin * 2) * cell;
    canvas.width = size;
    canvas.height = size;
    var c = canvas.getContext('2d');
    c.fillStyle = '#ffffff';
    c.fillRect(0, 0, size, size);
    c.fillStyle = '#49364f';
    for (var r = 0; r < n; r++) for (var col = 0; col < n; col++) if (q.isDark(r, col)) c.fillRect((col + margin) * cell, (r + margin) * cell, cell, cell);
  }

  /* Uses the existing QR check-in system, so the admin QR Check-In page recognises this pass. */
  function generate() {
    retry.hidden = true;
    meta.textContent = 'Preparing your QR pass…';
    var body = new URLSearchParams();
    body.set('appointment_id', box.getAttribute('data-id'));
    body.set('csrf', box.getAttribute('data-csrf'));
    fetch(box.getAttribute('data-endpoint'), { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected response.' }; }); })
      .then(function (j) {
        if (!j.ok) { canvas.hidden = true; meta.textContent = j.error || 'Unable to create your QR pass.'; return; }
        draw(j.payload);
        canvas.hidden = false;
        meta.textContent = j.pass.code + ' · valid until ' + j.expires_label;
      })
      .catch(function () { canvas.hidden = true; meta.textContent = 'Network error. Please try again.'; retry.hidden = false; });
  }
  retry.addEventListener('click', generate);
  generate();
})();