// Print button for the report print view (no inline handlers, CSP-safe).
document.addEventListener('click', function (e) {
  var t = e.target.closest ? e.target.closest('[data-print]') : null;
  if (t) { window.print(); }
});
