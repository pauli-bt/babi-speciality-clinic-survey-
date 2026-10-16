<?php
use App\Helpers\Channel;
use App\Services\Auth;
$ownCh = Channel::forRole(Auth::user()['role_code']);
?>
<h1 class="h4 text-brand"><?= e($channel) ?> dashboard</h1>
<p class="text-secondary"><?= e($purpose) ?></p>
<div class="row g-3">
  <div class="col-lg-7"><div class="card h-100"><div class="card-header bg-white fw-semibold">Customers</div><div class="card-body">
    <?php $pickerId = 'dash-picker'; $pickerMode = 'select'; include __DIR__ . '/../components/customer_picker.php'; ?>
    <div class="d-flex flex-wrap gap-2 mt-3">
      <?php if (Auth::can('customers.create')): ?><a href="/customers/new" class="btn btn-outline-primary">Register customer only</a><?php endif; ?>
      <a href="/customers" class="btn btn-outline-primary">Full search</a>
    </div></div></div></div>
  <div class="col-lg-5"><div class="card h-100"><div class="card-header bg-white fw-semibold">Interactions</div><div class="card-body">
    <p class="mb-3">Record a <?= e(strtolower($channel)) ?> interaction for a new or an existing customer.</p>
    <div class="d-flex flex-wrap gap-2">
      <?php if ($ownCh && Auth::can('interactions.create')): ?><a href="<?= e(Channel::formPath($ownCh)) ?>" class="btn btn-primary">Record interaction</a><?php endif; ?>
      <a href="/interactions" class="btn btn-outline-primary">Recent interactions</a>
      <?php if (Auth::can('appointments.manage')): ?><a href="/appointments" class="btn btn-outline-primary">Appointments</a><?php endif; ?>
    </div>
  </div></div></div>
</div>
