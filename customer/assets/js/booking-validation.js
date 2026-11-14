(function () {
  'use strict';
  var BV = window.BV = window.BV || {};

  function val(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }

  BV.isEmail = function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v); };
  BV.isPhPhone = function (v) { return /^(\+?63|0)9\d{9}$/.test(String(v).replace(/[\s\-()]/g, '')); };

  /* Each validator returns '' when the step is complete, otherwise a message for the guest. */
  BV.validators = {
    1: function () {
      if (!val('gName') || !val('gEmail') || !val('gPhone')) return 'Your account is missing contact details. Please contact the spa.';
      if (!BV.isEmail(val('gEmail'))) return 'The email on your account does not look valid. Please contact the spa.';
      return '';
    },
    2: function () {
      var svc = BV.selectedService();
      if (!svc) return 'Choose a treatment to continue.';
      var g = parseInt(val('bkGuests'), 10);
      if (!g || g < +svc.dataset.min || g > +svc.dataset.max) return 'Choose a valid number of guests for this treatment.';
      return '';
    },
    3: function () {
      if (!val('bkDate')) return 'Choose a date.';
      if (!val('bkStart')) return 'Choose an available start time.';
      return '';
    },
    4: function () {
      if (!val('hc') || !val('hi')) return 'Please complete the health declaration (type None if none).';
      if (!document.querySelector('input[name="is_pregnant"]:checked')) return 'Please tell us whether any guest is pregnant.';
      var boxes = document.querySelectorAll('.bk-checks input[type="checkbox"]');
      for (var i = 0; i < boxes.length; i++) { if (!boxes[i].checked) return 'Please accept every declaration to confirm your appointment.'; }
      return '';
    }
  };

  BV.firstInvalid = function () {
    for (var s = 1; s <= 4; s++) {
      var m = BV.validators[s]();
      if (m) return { step: s, message: m };
    }
    return null;
  };

  BV.selectedService = function () {
    return document.querySelector('input[name="service_id"]:checked');
  };
})();