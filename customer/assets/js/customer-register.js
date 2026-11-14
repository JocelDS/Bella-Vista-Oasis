(function () {
  'use strict';
  var V = window.CuValidate;

  function check(form, withProfile) {
    var ok = true, f = form.elements;
    function fail(input, msg) { V.setError(input, msg); ok = false; }
    if (withProfile) {
      if (f.full_name.value.trim().length < 2) fail(f.full_name, 'Enter your full name.'); else V.setError(f.full_name, '');
      if (!V.email(f.email.value.trim())) fail(f.email, 'Enter a valid email address.'); else V.setError(f.email, '');
      if (!V.phone(f.phone.value.trim())) fail(f.phone, 'Enter a valid phone number.'); else V.setError(f.phone, '');
    }
    if (!V.strongPassword(f.password.value)) fail(f.password, 'Use at least 8 characters with upper case, lower case and a number.'); else V.setError(f.password, '');
    if (f.confirm_password.value !== f.password.value || !f.confirm_password.value) fail(f.confirm_password, 'Passcodes do not match.'); else V.setError(f.confirm_password, '');
    return ok;
  }

  [['registerForm', true], ['resetForm', false]].forEach(function (pair) {
    var form = document.getElementById(pair[0]);
    if (!form) return;
    form.addEventListener('submit', function (e) {
      if (!check(form, pair[1])) { e.preventDefault(); return; }
      V.busy(form);
    });
    form.addEventListener('blur', function (e) {
      var c = form.elements.confirm_password;
      if (e.target === c && c.value) { if (c.value !== form.elements.password.value) V.setError(c, 'Passcodes do not match.'); else V.setError(c, ''); }
    }, true);
  });
})();
