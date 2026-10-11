// Confirmation for destructive actions + protection against accidental double submits.
document.addEventListener('submit', function (event) {
  var form = event.target;
  var message = form.getAttribute('data-confirm');
  if (message && !window.confirm(message)) {
    event.preventDefault();
    return;
  }
  var button = form.querySelector('[type="submit"]');
  if (button) {
    setTimeout(function () { button.disabled = true; }, 0);
  }
});
// Re-enable buttons when the page is restored from the back/forward cache.
window.addEventListener('pageshow', function (e) {
  if (e.persisted) {
    document.querySelectorAll('[type="submit"]').forEach(function (b) { b.disabled = false; });
  }
});
