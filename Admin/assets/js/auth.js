(function () {
  'use strict';

  var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.9 10.9 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4M6.5 6.6A16.5 16.5 0 0 0 2 12s3.5 7 10 7c1.7 0 3.2-.4 4.5-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

  // Show / hide password
  document.querySelectorAll('.toggle-pw').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!btn || !btn.dataset || !btn.dataset.target) { return; }
      var input = document.getElementById(btn.dataset.target);
      if (!input) { return; }
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? EYE_OFF : EYE;
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  // Validation
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  function check(input, form) {
    var rule = input.dataset.rule || '';
    var v = input.value;
    if (rule === 'email') {
      if (!v.trim()) return 'Enter your email address.';
      if (!EMAIL_RE.test(v.trim())) return 'Enter a valid email address.';
    } else if (rule === 'required') {
      if (!v) return 'Enter your password.';
    } else if (rule === 'strong') {
      if (!v) return 'Enter a new password.';
      if (v.length < 8 || !/[A-Z]/.test(v) || !/[a-z]/.test(v) || !/\d/.test(v))
        return 'Use 8+ characters with upper-case, lower-case and a number.';
    } else if (rule.indexOf('match:') === 0) {
      if (!v) return 'Confirm your new password.';
      var matchTarget = form.querySelector('#' + rule.slice(6));
      if (!matchTarget) return 'Passwords do not match.';
      if (v !== matchTarget.value) return 'Passwords do not match.';
    }
    return '';
  }

  function show(input, msg) {
    var field = input.closest('.field');
    field.classList.toggle('invalid', !!msg);
    field.querySelector('.field-error').textContent = msg;
  }

  document.querySelectorAll('form[data-validate]').forEach(function (form) {
    var inputs = form.querySelectorAll('[data-rule]');
    var btn = form.querySelector('.btn-primary');
    var submitting = false;

    inputs.forEach(function (input) {
      input.addEventListener('blur', function () { if (input.value) show(input, check(input, form)); });
      input.addEventListener('input', function () {
        if (input.closest('.field').classList.contains('invalid')) show(input, check(input, form));
      });
    });

    form.addEventListener('submit', function (e) {
      if (submitting) { e.preventDefault(); return; }
      var firstBad = null;
      inputs.forEach(function (input) {
        var msg = check(input, form);
        show(input, msg);
        if (msg && !firstBad) firstBad = input;
      });
      if (firstBad) { e.preventDefault(); firstBad.focus(); return; }
      submitting = true; // block double submits
      if (btn) {
        btn.classList.add('loading');
        btn.disabled = true;
        var btnText = btn.querySelector('.btn-text');
        if (btnText) { btnText.textContent = 'Please wait…'; }
      }
    });
  });

  // Reset button state when returning via browser back (bfcache)
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) window.location.reload();
  });
})();
