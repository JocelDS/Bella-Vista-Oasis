(function () {
  'use strict';
  var BV = window.BV = window.BV || {};

  function $(id) { return document.getElementById(id); }

  BV.peso = function (n) {
    return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  /* Display only. The server recalculates every amount before saving. */
  BV.price = function () {
    var svc = BV.selectedService();
    if (!svc) return null;
    var g = parseInt($('bkGuests').value, 10) || +svc.dataset.base;
    var base = parseFloat(svc.dataset.price) || 0;
    var fee = parseFloat(svc.dataset.extra) || 0;
    var extraGuests = fee > 0 ? Math.max(0, g - (+svc.dataset.base)) : 0;
    var extra = extraGuests * fee;
    var gross = base + extra;
    var pct = (BV.cfg && BV.cfg.discount) || 0;
    var disc = pct > 0 ? Math.round(gross * pct) / 100 : 0;
    return { name: svc.dataset.name, guests: g, base: base, extraGuests: extraGuests, fee: fee, extra: extra, gross: gross, pct: pct, disc: disc, total: gross - disc };
  };

  function show(id, on) { var el = $(id); if (el) el.hidden = !on; }

  BV.summary = function () {
    var p = BV.price();
    var date = $('bkDate').value;
    $('sumDate').textContent = date ? new Date(date + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) : '—';
    $('sumTime').textContent = $('bkStart').value && BV.state.slotLabel ? BV.state.slotLabel : '—';
    var th = $('bkTherapist');
    $('sumTherapist').textContent = th.value ? th.options[th.selectedIndex].text.split(' · ')[0] : 'Any Master Therapist';
    var rm = $('bkRoom');
    $('sumRoom').textContent = rm.value ? rm.options[rm.selectedIndex].text.split(' · ')[0] : 'Best available';
    if (!p) {
      $('sumGuests').textContent = '—';
      $('sumSvc').textContent = 'Select a treatment';
      $('sumBase').textContent = '—';
      $('sumSubtotal').textContent = '—';
      $('sumTotal').textContent = BV.peso(0);
      show('sumExtraRow', false); show('sumDiscRow', false);
      return;
    }
    var svc = BV.selectedService();
    $('sumGuests').textContent = p.guests + (p.guests === 1 ? ' guest' : ' guests');
    $('sumSvc').textContent = p.name + ' (' + svc.dataset.durlabel + ')';
    $('sumBase').textContent = BV.peso(p.base);
    show('sumExtraRow', p.extra > 0);
    if (p.extra > 0) {
      $('sumExtraTxt').textContent = 'Additional guest surcharge (' + p.extraGuests + ' × ' + BV.peso(p.fee) + ')';
      $('sumExtra').textContent = BV.peso(p.extra);
    }
    show('sumDiscRow', p.disc > 0);
    if (p.disc > 0) {
      $('sumDiscTxt').textContent = (BV.cfg.tier ? BV.cfg.tier + ' privilege' : 'Membership privilege') + ' (' + p.pct + '%)';
      $('sumDisc').textContent = '−' + BV.peso(p.disc);
    }
    $('sumSubtotal').textContent = BV.peso(p.gross);
    $('sumTotal').textContent = BV.peso(p.total);
  };
})();