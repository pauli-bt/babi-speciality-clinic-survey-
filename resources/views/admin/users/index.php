<?php use App\Helpers\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0 text-brand">Users</h1>
  <a href="/admin/users/new" class="btn btn-primary">New user</a>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last sign-in</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['name']) ?></td>
      <td><code><?= e($u['username']) ?></code></td>
      <td><?= e($u['role_name']) ?></td>
      <td><?= (int) $u['is_active'] === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
      <td class="small text-secondary"><?= e($u['last_login_at'] ?? 'Never') ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-primary" href="/admin/users/<?= (int) $u['id'] ?>/edit">Edit</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
