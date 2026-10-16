<?php use App\Helpers\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0 text-brand">Services</h1>
  <a href="/admin/services/new" class="btn btn-primary">New service</a>
</div>
<p class="small text-secondary">Deactivating a service hides it from new interactions only. Past interactions and appointments keep it. Services are never deleted.</p>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Name</th><th>Description</th><th class="text-end">Interactions</th><th class="text-end">Appointments</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($services as $s): ?>
    <tr>
      <td><?= e($s['name']) ?></td>
      <td class="small text-secondary"><?= e($s['description'] ?? '') ?></td>
      <td class="text-end"><?= e($s['interaction_count']) ?></td>
      <td class="text-end"><?= e($s['appointment_count']) ?></td>
      <td><?= (int) $s['is_active'] === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-primary" href="/admin/services/<?= (int) $s['id'] ?>/edit">Edit</a>
        <form method="post" action="/admin/services/<?= (int) $s['id'] ?>/active" class="d-inline" data-confirm="<?= (int) $s['is_active'] === 1 ? 'Deactivate this service? Existing records keep it.' : 'Reactivate this service?' ?>">
          <?= Csrf::field() ?><button class="btn btn-sm <?= (int) $s['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= (int) $s['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$services): ?><tr><td colspan="6" class="text-center text-secondary py-4">No services yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
