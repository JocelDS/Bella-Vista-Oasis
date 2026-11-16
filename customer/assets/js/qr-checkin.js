(function () {
  'use strict';

  function toast(text) {
    var t = document.createElement('div');
    t.className = 'qp-toast';
    t.setAttribute('role', 'status');
    t.textContent = text;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 2200);
  }

  /* Copy the booking reference */
  var copy = document.getElementById('qpCopy');
  if (copy) {
    copy.addEventListener('click', function () {
      var ref = copy.getAttribute('data-ref');
      function ok() { toast('Reference ' + ref + ' copied'); }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ref).then(ok, function () { toast('Reference: ' + ref); });
      } else {
        var ta = document.createElement('textarea');
        ta.value = ref;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); ok(); } catch (e) { toast('Reference: ' + ref); }
        ta.remove();
      }
    });
  }

  /* Dialogs */
  document.querySelectorAll('[data-open]').forEach(function (b) {
    b.addEventListener('click', function () {
      var m = document.getElementById(b.getAttribute('data-open'));
      if (m) { m.hidden = false; var f = m.querySelector('a,button'); if (f) f.focus(); }
    });
  });
  document.querySelectorAll('.qp-modal').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) m.hidden = true; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.qp-modal').forEach(function (m) { m.hidden = true; });
  });

  /* Loading state while a download starts */
  document.querySelectorAll('a[data-busy]').forEach(function (a) {
    a.addEventListener('click', function () {
      a.setAttribute('aria-busy', 'true');
      setTimeout(function () { a.removeAttribute('aria-busy'); }, 2000);
    });
  });
})();