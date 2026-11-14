(function () {
  'use strict';

  var modal = document.getElementById('lrModal');
  var text = document.getElementById('lrModalText');
  var confirmBtn = document.getElementById('lrConfirm');
  var cancelBtn = document.getElementById('lrCancel');
  var pending = null;

  function close() { modal.hidden = true; pending = null; document.body.classList.remove('lr-lock'); }

  document.querySelectorAll('.lr-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (form.dataset.confirmed === '1') return;
      e.preventDefault();
      pending = form;
      text.textContent = 'Redeem ' + form.dataset.name + ' for ' + Number(form.dataset.points).toLocaleString() + ' tranquility points? Your balance is checked again by the spa before points are deducted.';
      modal.hidden = false;
      document.body.classList.add('lr-lock');
      confirmBtn.focus();
    });
  });

  confirmBtn.addEventListener('click', function () {
    if (!pending) return;
    var form = pending;
    confirmBtn.classList.add('loading');
    document.querySelectorAll('.lr-redeem').forEach(function (b) { b.disabled = true; });
    form.dataset.confirmed = '1';
    form.submit();
  });
  cancelBtn.addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

  document.querySelectorAll('[data-scroll]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var t = document.querySelector(a.getAttribute('href'));
      if (!t) return;
      e.preventDefault();
      t.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
})();