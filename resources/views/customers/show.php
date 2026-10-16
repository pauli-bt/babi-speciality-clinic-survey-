<?php
use App\Helpers\{Channel, Csrf, Phone};
$ch = Channel::LABELS;
$reasons = ['first_contact' => 'First contact', 'appointment_followup' => 'Appointment / follow-up', 'another_service' => 'Another service', 'campaign_response' => 'Advertisement / campaign'];
$phoneShown = \App\Services\Auth::can('customers.view_contact') ? ($c['phone'] ?? '') : Phone::mask($c['phone_normalized']);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-0 text-brand"><?= e($c['name'] ?? '(no name recorded)') ?></h1>
    <code><?= e($c['customer_code']) ?></code>
    <?php if ($c['status'] !== 'active'): ?><span class="badge text-bg-secondary ms-1"><?= e(ucfirst($c['status'])) ?></span><?php endif; ?>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($c['status'] === 'active'): ?><form method="post" action="/customers/<?= (int) $c['id'] ?>/select" class="m-0"><?= Csrf::field() ?><button class="btn btn-primary" type="submit">Select this customer</button></form><?php endif; ?>
    <a href="/customers" class="btn btn-outline-secondary">Back to search</a>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white fw-semibold">Contact</div><div class="card-body">
    <dl class="row mb-0">
      <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= e($c['name'] ?? 'not recorded') ?></dd>
      <dt class="col-sm-4">Telephone</dt><dd class="col-sm-8"><?= e($phoneShown !== '' ? $phoneShown : 'not recorded') ?></dd>
      <dt class="col-sm-4">Registered</dt><dd class="col-sm-8"><?= e($c['first_acquisition_at']) ?> by <?= e($c['created_by_name']) ?></dd>
    </dl></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white fw-semibold">Original acquisition <span class="fw-normal text-secondary small">(never overwritten by later contacts)</span></div><div class="card-body">
    <dl class="row mb-0">
      <dt class="col-sm-5">First heard via</dt><dd class="col-sm-7"><?= e($c['source_name']) ?><?= $c['platform_name'] ? ' &middot; ' . e($c['platform_name']) : '' ?></dd>
      <?php if ($c['original_source_other']): ?><dt class="col-sm-5">Description</dt><dd class="col-sm-7"><?= e($c['original_source_other']) ?></dd><?php endif; ?>
      <dt class="col-sm-5">Campaign</dt><dd class="col-sm-7"><?= e($c['campaign_name'] ?? 'not identified') ?></dd>
      <dt class="col-sm-5">First contact</dt><dd class="col-sm-7"><?= e($ch[$c['first_acquisition_channel']] ?? '') ?></dd>
    </dl></div></div></div>
</div>

<div class="card mt-3"><div class="card-header bg-white fw-semibold">Interactions<?= $ownChannel ? ' <span class="fw-normal text-secondary small">(' . e($ch[$ownChannel]) . ' only)</span>' : '' ?></div>
<?php if (!$interactions): ?><div class="card-body text-secondary">No interactions recorded yet.</div><?php else: ?>
<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Date</th><?php if (!$ownChannel): ?><th>Channel</th><?php endif; ?><th>Reason</th><th>Service</th><th>Outcome</th></tr></thead><tbody>
<?php foreach ($interactions as $i): ?><tr><td class="text-nowrap small"><a href="/interactions/<?= (int) $i['id'] ?>"><?= e($i['interaction_at']) ?></a></td><?php if (!$ownChannel): ?><td><?= e($ch[$i['channel']] ?? '') ?></td><?php endif; ?>
<td><?= e($reasons[$i['interaction_reason']] ?? $i['interaction_reason']) ?></td><td><?= e($i['service_name'] ?? '-') ?></td>
<td class="small"><?php $oc = $i['channel'] === 'reception' ? ['Service use', $i['service_usage_outcome']] : ['Booking', $i['booking_outcome']]; echo e($oc[0] . ': ' . ($oc[1] === 'not_applicable' ? 'not recorded' : str_replace('_', ' ', $oc[1]))); ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>

<?php if ($canManage): ?>
<div class="card mt-3"><div class="card-header bg-white fw-semibold">Administration</div><div class="card-body d-flex flex-wrap gap-2">
  <a class="btn btn-outline-primary" href="/customers/<?= (int) $c['id'] ?>/edit">Correct name / phone</a>
  <?php if ($canCorrect): ?><a class="btn btn-outline-primary" href="/customers/<?= (int) $c['id'] ?>/source">Correct original acquisition source</a><?php endif; ?>
  <form method="post" action="/customers/<?= (int) $c['id'] ?>/archive" class="m-0" data-confirm="<?= $c['status'] === 'active' ? 'Archive this customer? Staff will no longer be able to find or select them.' : 'Restore this customer?' ?>"><?= Csrf::field() ?>
    <button class="btn <?= $c['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= $c['status'] === 'active' ? 'Archive customer' : 'Restore customer' ?></button></form>
</div>
<?php if ($corrections): ?><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Source correction</th><th>By</th><th>Reason</th></tr></thead><tbody>
<?php foreach ($corrections as $k): ?><tr><td class="small text-nowrap"><?= e($k['corrected_at']) ?><br><?= e($k['old_name']) ?> &rarr; <?= e($k['new_name']) ?></td><td><?= e($k['username']) ?></td><td class="small"><?= e($k['reason']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
<?php endif; ?>
