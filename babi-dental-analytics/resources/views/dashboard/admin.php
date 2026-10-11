<h1 class="h4 mb-3 text-brand">Administrator dashboard</h1>
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-secondary small">Customers recorded</div><div class="h3 mb-0"><?= e($customers) ?></div></div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-secondary small">Interactions recorded</div><div class="h3 mb-0"><?= e($interactions) ?></div></div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-secondary small">Audit events (24 h)</div><div class="h3 mb-0"><?= e($auditToday) ?></div></div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card"><div class="card-header bg-white fw-semibold">User accounts by role</div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Role</th><th class="text-end">Active</th><th class="text-end">Inactive</th></tr></thead><tbody>
    <?php foreach ($roleCounts as $r): ?><tr><td><?= e($r['name']) ?></td><td class="text-end"><?= e($r['active'] ?? 0) ?></td><td class="text-end"><?= e($r['inactive'] ?? 0) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="card-footer bg-white"><a href="/admin/users" class="btn btn-sm btn-primary">Manage users</a> <a href="/admin/audit" class="btn btn-sm btn-outline-primary">Audit log</a></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header bg-white fw-semibold">Build status</div><div class="card-body small">
    <p class="mb-2"><span class="badge text-bg-success">Available</span> Sign-in, roles and permissions, user accounts, audit log.</p>
    <p class="mb-0"><span class="badge text-bg-secondary">Not built yet</span> Customers (Phase 3), questionnaires (Phase 4), campaigns, services and appointments (Phase 5), analytics (Phase 6), reports (Phase 7). The counts above are real database counts and will stay at zero until then.</p>
  </div></div></div>
</div>
