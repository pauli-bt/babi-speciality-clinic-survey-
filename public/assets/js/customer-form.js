// Shows the platform / "other" fields only when the chosen acquisition source needs them.
(function () {
  var source = document.querySelector('[data-attr-source]');
  if (!source) return;
  var pGroup = document.querySelector('[data-attr-platform-group]');
  var oGroup = document.querySelector('[data-attr-other-group]');
  var platform = document.querySelector('[data-attr-platform]');
  var other = document.querySelector('[data-attr-other]');
  function sync() {
    var opt = source.options[source.selectedIndex];
    var needP = opt && opt.getAttribute('data-platform') === '1';
    var needO = opt && opt.getAttribute('data-other') === '1';
    pGroup.classList.toggle('d-none', !needP);
    oGroup.classList.toggle('d-none', !needO);
    if (!needP) platform.value = '';
    if (!needO) other.value = '';
  }
  source.addEventListener('change', sync);
})();
