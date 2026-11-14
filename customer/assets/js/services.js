(function () {
  'use strict';

  document.querySelectorAll('[data-book]').forEach(function (a) {
    a.addEventListener('click', function () {
      a.classList.add('loading');
      a.setAttribute('aria-busy', 'true');
      var card = a.closest('.sv-card');
      if (card) card.classList.add('picked');
      setTimeout(function () { a.classList.remove('loading'); a.removeAttribute('aria-busy'); }, 4000);
    });
  });

  var cards = document.querySelectorAll('.sv-card');
  var rows = document.querySelectorAll('.sv-table tbody tr');
  cards.forEach(function (card) {
    var id = card.getAttribute('data-service');
    card.addEventListener('mouseenter', function () { rows.forEach(function (r, i) { r.classList.toggle('hl', cards[i] === card); }); });
    card.addEventListener('mouseleave', function () { rows.forEach(function (r) { r.classList.remove('hl'); }); });
  });

  document.querySelectorAll('a[href*="#"]').forEach(function (a) {
    var u = a.getAttribute('href');
    if (u.charAt(0) !== '#' || u.length < 2) return;
    a.addEventListener('click', function (e) {
      var t = document.querySelector(u);
      if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth' }); }
    });
  });
})();