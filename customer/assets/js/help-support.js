(function () {
  'use strict';

  var tabs = document.querySelectorAll('.hs-tab');
  var items = document.querySelectorAll('.hs-item');

  function closeItem(item) {
    item.classList.remove('open');
    item.querySelector('.hs-q').setAttribute('aria-expanded', 'false');
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (t) { t.classList.toggle('active', t === tab); t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
      items.forEach(function (it) {
        closeItem(it);
        it.hidden = it.getAttribute('data-group') !== tab.getAttribute('data-tab');
      });
    });
  });

  items.forEach(function (item) {
    var btn = item.querySelector('.hs-q');
    btn.addEventListener('click', function () {
      var willOpen = !item.classList.contains('open');
      items.forEach(closeItem);
      if (willOpen) {
        item.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  });

  document.querySelectorAll('[data-scroll]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var t = document.querySelector(a.getAttribute('href'));
      if (!t) return;
      e.preventDefault();
      t.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  var form = document.getElementById('inquiryForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      var ok = true, f = form.elements;
      function set(input, msg) {
        var field = input.closest('.cu-field');
        var out = field.querySelector('.field-error');
        if (out) out.textContent = msg || '';
        field.classList.toggle('invalid', !!msg);
        if (msg) ok = false;
      }
      set(f.category, f.category.value ? '' : 'Choose an inquiry category.');
      var ref = f.appointment_ref.value.trim();
      set(f.appointment_ref, ref && !/^(BVO-)?0*\d{1,9}$/i.test(ref) ? 'Use a reference like BVO-0001, or leave this blank.' : '');
      var s = f.subject.value.trim();
      set(f.subject, s.length < 3 ? 'Enter a subject (at least 3 characters).' : '');
      var m = f.message.value.trim();
      set(f.message, m.length < 10 ? 'Enter a message (at least 10 characters).' : '');
      if (!ok) { e.preventDefault(); return; }
      var btn = form.querySelector('.hs-submit');
      btn.classList.add('loading');
      btn.setAttribute('aria-busy', 'true');
    });
  }
})();