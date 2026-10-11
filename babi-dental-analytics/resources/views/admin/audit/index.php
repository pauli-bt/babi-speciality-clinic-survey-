<h1 class="h4 text-brand mb-3">Audit log</h1>
<form method="get" action="/admin/audit" class="row g-2 mb-3">
  <div class="col-sm-6 col-md-4"><label for="action" class="visually-hidden">Action prefix</label>
    <input type="text" class="form-control" id="action" name="action" value="<?= e($action) ?>" placeholder="Action starts with, e.g. user. or auth." maxlength="60"></div>
  <div class="col-auto"><button class="btn btn-primary" type="submit">Filter</button> <a class="btn btn-link" href="/admin/audit">Clear</a></div>
</form>
<p class="small text-secondary"><?= e($total) ?> event(s)</p>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
  <tr><td class="text-nowrap small"><?= e($r['created_at']) ?></td><td><?= e($r['username'] ?? 'system / unknown') ?></td><td><code><?= e($r['action']) ?></code></td>
    <td class="small"><?= e(trim(($r['entity_type'] ?? '') . ' ' . ($r['entity_id'] ?? ''))) ?></td>
    <td class="small text-break"><?= e($r['new_values'] ?? '') ?></td><td class="small"><?= e($r['ip_address']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-secondary py-4">No events match.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php if ($pages > 1): ?>
<nav class="mt-3" aria-label="Audit pages"><ul class="pagination pagination-sm">
  <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
  <li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="/admin/audit?page=<?= $p ?>&amp;action=<?= e(urlencode($action)) ?>"><?= $p ?></a></li>
  <?php endfor; ?></ul></nav>
<?php endif; ?>
