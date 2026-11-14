(function () {
  'use strict';
  var V = window.CuValidate;

  var login = document.getElementById('loginForm');
  if (login) {
    login.addEventListener('submit', function (e) {
      var id = login.elements.identifier, pw = login.elements.password, ok = true;
      var idv = id.value.trim();
      if (!idv) { V.setError(id, 'Enter your email or Membership ID.'); ok = false; }
      else if (idv.indexOf('@') !== -1 && !V.email(idv)) { V.setError(id, 'Enter a valid email address.'); ok = false; }
      else V.setError(id, '');
      if (!pw.value) { V.setError(pw, 'Enter your passcode.'); ok = false; } else V.setError(pw, '');
      if (!ok) { e.preventDefault(); return; }
      V.busy(login);
    });
  }

  var toggle = document.getElementById('magicToggle'), magic = document.getElementById('magicForm');
  if (toggle && magic) {
    toggle.addEventListener('click', function () {
      magic.hidden = !magic.hidden;
      if (!magic.hidden) {
        var main = login.elements.identifier.value.trim();
        if (V.email(main)) magic.elements.email.value = main;
        magic.elements.email.focus();
      }
    });
    magic.addEventListener('submit', function (e) {
      var em = magic.elements.email;
      if (!V.email(em.value.trim())) { e.preventDefault(); magic.querySelector('.field-error').textContent = 'Enter a valid email address.'; return; }
      magic.querySelector('.field-error').textContent = '';
      V.busy(magic);
    });
  }

  var forgot = document.getElementById('forgotForm');
  if (forgot) {
    forgot.addEventListener('submit', function (e) {
      var em = forgot.elements.email;
      if (!V.email(em.value.trim())) { e.preventDefault(); V.setError(em, 'Enter a valid email address.'); return; }
      V.setError(em, '');
      V.busy(forgot);
    });
  }
})();
