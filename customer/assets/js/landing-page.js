(function () {
  'use strict';

  var nav = document.querySelector('.cu-nav');
  if (nav) {
    var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 8); };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  document.querySelectorAll('[data-scroll]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var id = a.getAttribute('href');
      var t = id && id.charAt(0) === '#' ? document.querySelector(id) : null;
      if (!t) return;
      e.preventDefault();
      t.scrollIntoView({ behavior: 'smooth', block: 'start' });
      if (history.replaceState) history.replaceState(null, '', id);
    });
  });

  document.querySelectorAll('[data-toggle-details]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card = btn.closest('.lp-card');
      var panel = card.querySelector('.lp-details');
      var open = panel.hidden;
      panel.hidden = !open;
      card.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.textContent = open ? 'Hide Details' : 'View Details';
    });
  });

  var navLinks = document.getElementById('cuNav');
  if (navLinks) {
    navLinks.addEventListener('click', function (e) {
      if (e.target.closest('a') && navLinks.classList.contains('open')) {
        navLinks.classList.remove('open');
        var tg = document.querySelector('.cu-nav-toggle');
        if (tg) tg.setAttribute('aria-expanded', 'false');
      }
    });
  }
})();