// Conditional fields for the interaction questionnaires. Hidden fields are also disabled so they are never submitted;
// the server validates everything again and ignores/rejects whatever does not apply.
(function () {
  var form = document.querySelector('[data-interaction-form]');
  if (!form) return;

  function value(name) {
    var els = form.querySelectorAll('[name="' + name + '"]');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el.type === 'radio') { if (el.checked) return el.value; } else if (!el.disabled) { return el.value; }
    }
    return '';
  }

  function sync() {
    form.querySelectorAll('[data-show-when]').forEach(function (el) {
      var spec = el.getAttribute('data-show-when').split(':');
      var on = spec[1].split('|').indexOf(value(spec[0])) >= 0;
      var parent = el.parentElement ? el.parentElement.closest('[data-show-when]') : null;
      if (parent && parent.classList.contains('d-none')) on = false;
      el.classList.toggle('d-none', !on);
      el.querySelectorAll('input, select, textarea').forEach(function (c) { c.disabled = !on; });
    });
    var adSource = form.querySelector('[data-ad-source]');
    var adGroup = form.querySelector('[data-ad-platform-group]');
    if (adSource && adGroup) {
      var opt = adSource.options[adSource.selectedIndex];
      var need = opt && opt.getAttribute('data-platform') === '1';
      var visible = !adSource.disabled;
      adGroup.classList.toggle('d-none', !(need && visible));
      adGroup.querySelectorAll('select').forEach(function (c) { c.disabled = !(need && visible); });
    }
  }

  form.addEventListener('change', sync);
  sync();

  // After picking an existing customer, load that customer's appointments for the follow-up selector.
  form.addEventListener('picker:chosen', function (e) {
    var select = form.querySelector('[data-followup-select]');
    if (!select) return;
    var url = form.getAttribute('data-appointments-url').replace('__ID__', encodeURIComponent(e.detail.id));
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('bad'); return r.json(); })
      .then(function (data) {
        select.textContent = '';
        var none = document.createElement('option'); none.value = ''; none.textContent = 'Not linked / not sure'; select.appendChild(none);
        data.appointments.forEach(function (a) {
          var o = document.createElement('option'); o.value = a.id;
          o.textContent = a.service + ' - ' + (a.at || 'no time set') + ' - ' + a.status;
          select.appendChild(o);
        });
      })
      .catch(function () { /* leave the selector as it is */ });
  });
})();
