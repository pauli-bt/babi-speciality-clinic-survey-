<?php
use App\Helpers\{Csrf, Phone};
use App\Services\Auth;
$query = $res['query'];
$base = '/customers?q=' . urlencode($q) . ($archived ? '&archived=1' : '');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0 text-brand">Find a customer</h1>
  <?php if (Auth::can('customers.create')): ?><a href="/customers/new" class="btn btn-primary">Register new customer</a><?php endif; ?>
</div>
<form method="get" action="/customers" class="card card-body mb-3" role="search">
  <label for="q" class="form-label">Search by name, phone number or customer ID</label>
  <div class="input-group">
    <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="80" placeholder="e.g. Selam, 0911 23, BDC-000123" autocomplete="off" autofocus>
    <button class="btn btn-primary" type="submit">Search</button>
  </div>
  <?php if (Auth::can('customers.manage')): ?>
  <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="archived" name="archived" value="1"<?= $archived ? ' checked' : '' ?>><label class="form-check-label" for="archived">Include archived customers</label></div>
  <?php endif; ?>
</form>

<?php if ($query['kind'] === 'none'): ?>
  <p class="text-secondary">Type part of a name, at least 4 digits of a phone number, or a customer ID.</p>
<?php elseif ($query['kind'] === 'invalid'): ?>
  <div class="alert alert-warning"><?= e($query['error']) ?></div>
<?php elseif ($res['total'] === 0): ?>
  <div class="card"><div class="card-body text-center py-4">
    <p class="mb-2">No customers match <strong><?= e($q) ?></strong>.</p>
    <?php if (Auth::can('customers.create')): ?><a href="/customers/new" class="btn btn-outline-primary">Register as a new customer</a><?php endif; ?>
  </div></div>
<?php else: ?>
  <p class="small text-secondary mb-2"><?= e($res['total']) ?> match<?= $res['total'] === 1 ? '' : 'es' ?> &middot; page <?= e($res['page']) ?> of <?= e($res['pages']) ?></p>
  <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Customer</th><th>Phone</th><th>First heard via</th><th>Registered</th><th class="text-end">Actions</th></tr></thead><tbody>
    <?php foreach ($res['rows'] as $r): ?>
    <tr>
      <td><a href="/customers/<?= (int) $r['id'] ?>" class="fw-semibold text-decoration-none"><?= e($r['name'] ?? '(no name recorded)') ?></a><br><code class="small"><?= e($r['customer_code']) ?></code>
        <?php if ($r['status'] !== 'active'): ?><span class="badge text-bg-secondary">Archived</span><?php endif; ?></td>
      <td><?= e(Phone::mask($r['phone_normalized']) ?: 'not recorded') ?>
        <?php if ((int) $r['shared_phone_count'] > 0): ?><br><span class="badge text-bg-info">Shared with <?= (int) $r['shared_phone_count'] ?> other customer<?= (int) $r['shared_phone_count'] === 1 ? '' : 's' ?></span><?php endif; ?></td>
      <td><?= e($r['source_name']) ?></td>
      <td class="small text-nowrap"><?= e(substr((string) $r['first_acquisition_at'], 0, 10)) ?></td>
      <td class="text-end text-nowrap">
        <a href="/customers/<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
        <?php if ($r['status'] === 'active'): ?>
        <form method="post" action="/customers/<?= (int) $r['id'] ?>/select" class="d-inline"><?= Csrf::field() ?><button class="btn btn-sm btn-primary" type="submit">Select</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody></table></div></div>
  <?php if ($res['pages'] > 1): ?>
  <nav class="mt-3" aria-label="Search result pages"><ul class="pagination pagination-sm flex-wrap">
    <li class="page-item<?= $res['page'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($base . '&page=' . max(1, $res['page'] - 1)) ?>">Previous</a></li>
    <?php for ($p = max(1, $res['page'] - 2); $p <= min($res['pages'], $res['page'] + 2); $p++): ?>
    <li class="page-item<?= $p === $res['page'] ? ' active' : '' ?>"><a class="page-link" href="<?= e($base . '&page=' . $p) ?>"><?= $p ?></a></li>
    <?php endfor; ?>
    <li class="page-item<?= $res['page'] >= $res['pages'] ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($base . '&page=' . min($res['pages'], $res['page'] + 1)) ?>">Next</a></li>
  </ul></nav>
  <?php endif; ?>
<?php endif; ?>
