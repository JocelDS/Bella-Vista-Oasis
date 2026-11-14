(function () {
  'use strict';
  var V = window.CuValidate = {
    email: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v); },
    strongPassword: function (v) { return v.length >= 8 && /[A-Z]/.test(v) && /[a-z]/.test(v) && /\d/.test(v); },
    phone: function (v) { return /^[0-9+()\-\s]{7,30}$/.test(v); },
    setError: function (input, msg) {
      var field = input.closest('.cu-field') || input.parentNode;
      var out = field.querySelector('.field-error');
      if (field.classList) field.classList.toggle('invalid', !!msg);
      if (out) out.textContent = msg || '';
      input.setAttribute('aria-invalid', msg ? 'true' : 'false');
    },
    busy: function (form) {
      var btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.classList.add('loading'); btn.setAttribute('aria-busy', 'true'); }
    }
  };

  var toast = document.getElementById('cuToast'), timer;
  function showToast(msg) {
    if (!toast) return;
    toast.innerHTML = msg;
    toast.classList.add('show');
    clearTimeout(timer);
    timer = setTimeout(function () { toast.classList.remove('show'); }, 3200);
  }
  document.addEventListener('click', function (e) {
    var soon = e.target.closest('[data-soon]');
    if (soon) { e.preventDefault(); showToast(soon.getAttribute('data-soon')); return; }
    var pw = e.target.closest('.cu-toggle-pw');
    if (pw) {
      var t = document.getElementById(pw.getAttribute('data-target'));
      if (!t) return;
      var show = t.type === 'password';
      t.type = show ? 'text' : 'password';
      pw.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    }
  });

  var toggle = document.querySelector('.cu-nav-toggle'), nav = document.getElementById('cuNav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  var nl = document.getElementById('newsletterForm');
  if (nl) {
    nl.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = document.getElementById('newsletterEmail'), err = document.getElementById('newsletterError');
      var v = input.value.trim();
      if (!V.email(v)) { err.textContent = 'Enter a valid email address.'; input.setAttribute('aria-invalid', 'true'); return; }
      err.textContent = '';
      input.setAttribute('aria-invalid', 'false');
      V.busy(nl);
      fetch(nl.action, { method: 'POST', body: new FormData(nl), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          nl.querySelector('button').classList.remove('loading');
          if (d.ok) { input.value = ''; showToast(d.message); } else { err.textContent = d.message; }
        })
        .catch(function () { nl.querySelector('button').classList.remove('loading'); err.textContent = 'Something went wrong. Please try again.'; });
    });
  }
})();
