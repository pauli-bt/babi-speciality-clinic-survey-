// Reusable customer picker. Builds all DOM with textContent (never innerHTML) so customer data cannot inject markup.
(function () {
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }

  document.querySelectorAll('[data-picker]').forEach(function (root) {
    var input = root.querySelector('[data-picker-input]');
    var results = root.querySelector('[data-picker-results]');
    var hint = root.querySelector('[data-picker-hint]');
    var valueField = root.querySelector('[data-picker-value]');
    var chosen = root.querySelector('[data-picker-chosen]');
    var mode = root.getAttribute('data-mode');
    var timer = null, seq = 0;

    function submitSelect(id) {
      var f = document.createElement('form');
      f.method = 'post'; f.action = '/customers/' + encodeURIComponent(id) + '/select';
      var t = document.createElement('input'); t.type = 'hidden'; t.name = '_csrf'; t.value = root.getAttribute('data-csrf');
      f.appendChild(t); document.body.appendChild(f); f.submit();
    }

    function choose(r) {
      if (mode === 'field' && valueField) {
        valueField.value = r.id;
        chosen.classList.remove('d-none');
        chosen.textContent = 'Selected: ' + (r.name || '(no name)') + ' (' + r.code + ')';
        results.textContent = '';
        input.value = '';
        root.dispatchEvent(new CustomEvent('picker:chosen', { bubbles: true, detail: { id: r.id } }));
      } else {
        submitSelect(r.id);
      }
    }

    function render(data) {
      results.textContent = '';
      hint.textContent = data.message || (data.results.length ? '' : 'No matching customers.');
      data.results.forEach(function (r) {
        var b = el('button', 'list-group-item list-group-item-action d-flex flex-wrap justify-content-between gap-1');
        b.type = 'button';
        var left = el('span');
        left.appendChild(el('strong', '', r.name || '(no name recorded)'));
        left.appendChild(document.createTextNode(' '));
        left.appendChild(el('code', 'small', r.code));
        var sub = el('span', 'small text-secondary d-block', (r.phone || 'no phone') + ' \u00B7 registered ' + r.registered + ' \u00B7 ' + r.source);
        left.appendChild(sub);
        b.appendChild(left);
        if (r.shared_phone > 0) b.appendChild(el('span', 'badge text-bg-info align-self-center', 'Shares phone with ' + r.shared_phone + ' other'));
        b.appendChild(el('span', 'btn btn-sm btn-primary align-self-center', 'Select'));
        b.addEventListener('click', function () { choose(r); });
        results.appendChild(b);
      });
      if (data.more) results.appendChild(el('div', 'list-group-item small text-secondary', 'More matches exist. Type more of the name or number to narrow down.'));
    }

    function search() {
      var q = input.value.trim();
      var my = ++seq;
      if (q === '') { results.textContent = ''; hint.textContent = 'Type at least 2 letters, 4 digits or a customer ID.'; return; }
      fetch(root.getAttribute('data-lookup-url') + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { if (!r.ok || (r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('bad'); return r.json(); })
        .then(function (data) { if (my === seq) render(data); })
        .catch(function () { if (my === seq) { results.textContent = ''; hint.textContent = 'Search failed. Reload the page; your session may have expired.'; } });
    }

    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 300); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); search(); } });
  });
})();
