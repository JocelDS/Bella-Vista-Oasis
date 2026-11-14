(function () {
  'use strict';
  var BV = window.BV = window.BV || {};

  var panels = [].slice.call(document.querySelectorAll('section[data-step]'));
  var items = [].slice.call(document.querySelectorAll('#bkSteps li'));
  var bar = document.getElementById('bkProgress');
  var current = 1;
  var reached = 1;

  function msg(step, text) {
    var el = document.querySelector('[data-msg="' + step + '"]');
    if (el) { el.textContent = text || ''; el.className = 'bk-msg' + (text ? ' err' : ''); }
  }

  function paint() {
    panels.forEach(function (p) { p.hidden = +p.getAttribute('data-step') !== current; });
    items.forEach(function (li, i) {
      var n = i + 1;
      li.classList.toggle('active', n === current);
      li.classList.toggle('done', n < current || (n < reached && n !== current && !BV.validators[n]()));
      var b = li.querySelector('button');
      if (b) b.setAttribute('aria-current', n === current ? 'step' : 'false');
    });
    if (bar) bar.style.width = (current / items.length * 100) + '%';
    var first = document.querySelector('section[data-step="' + current + '"] .bk-card, section[data-step="' + current + '"]');
    if (first && first.scrollIntoView && window.scrollY > 260) first.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  BV.steps = {
    current: function () { return current; },
    message: msg,
    go: function (n) {
      n = Math.max(1, Math.min(4, n));
      /* A guest can go back freely, but cannot skip ahead past an incomplete step. */
      for (var s = 1; s < n; s++) {
        var m = BV.validators[s]();
        if (m) { current = s; msg(s, m); paint(); return false; }
      }
      current = n;
      reached = Math.max(reached, n);
      msg(n, '');
      paint();
      if (BV.onStep) BV.onStep(n);
      return true;
    }
  };

  document.addEventListener('click', function (e) {
    var next = e.target.closest('[data-next]');
    var prev = e.target.closest('[data-prev]');
    var go = e.target.closest('[data-go]');
    if (next) { BV.steps.go(current + 1); }
    else if (prev) { BV.steps.go(current - 1); }
    else if (go) { BV.steps.go(+go.getAttribute('data-go')); }
  });

  document.getElementById('bookingForm').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON') return;
    e.preventDefault();
    if (current < 4) BV.steps.go(current + 1);
  });

  paint();
})();