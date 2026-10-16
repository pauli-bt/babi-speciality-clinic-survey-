<?php use App\Helpers\{Channel, Csrf}; use App\Services\AppointmentManager as AM;
$badge = ['requested' => 'info', 'confirmed' => 'primary', 'completed' => 'success', 'cancelled' => 'secondary', 'no_show' => 'danger'];
$next = AM::TRANSITIONS[$a['status']];
$open = in_array($a['status'], ['requested', 'confirmed'], true);
$atVal = $a['appointment_at'] ? str_replace(' ', 'T', substr((string) $a['appointment_at'], 0, 16)) : '';
$btn = ['confirmed' => 'btn-primary', 'completed' => 'btn-success', 'cancelled' => 'btn-outline-danger', 'no_show' => 'btn-outline-danger'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0 text-brand">Appointment #<?= (int) $a['id'] ?> <span class="badge text-bg-<?= $badge[$a['status']] ?> fs-6"><?= e(AM::STATUS_LABELS[$a['status']]) ?></span></h1>
  <a href="/appointments" class="btn btn-outline-secondary btn-sm">Back to list</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white fw-semibold">Details</div><div class="card-body">
    <dl class="row mb-0 small">
      <dt class="col-sm-4">Customer</dt><dd class="col-sm-8"><a href="/customers/<?= (int) $a['customer_id'] ?>" class="text-decoration-none"><?= $canNames ? e($a['customer_name'] ?? '(no name)') . ' ' : '' ?><code><?= e($a['customer_code']) ?></code></a></dd>
      <dt class="col-sm-4">Service</dt><dd class="col-sm-8"><?= e($a['service_name']) ?><?= (int) $a['service_active'] === 1 ? '' : ' <span class="badge text-bg-secondary">inactive service</span>' ?></dd>
      <dt class="col-sm-4">Scheduled for</dt><dd class="col-sm-8"><?= $a['appointment_at'] ? e(substr((string) $a['appointment_at'], 0, 16)) . ' (clinic time)' : 'Not scheduled yet' ?></dd>
      <dt class="col-sm-4">Booked via</dt><dd class="col-sm-8"><?= e($a['origin_channel'] ? Channel::LABELS[$a['origin_channel']] : '-') ?></dd>
      <dt class="col-sm-4">Recorded by</dt><dd class="col-sm-8"><?= e($a['created_by_name']) ?>, <?= e(substr((string) $a['created_at'], 0, 16)) ?></dd>
    </dl></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white fw-semibold">Update</div><div class="card-body">
    <?php if (!$next && !$open): ?><p class="text-secondary mb-0">This appointment is closed and its status can no longer be changed.</p><?php endif; ?>
    <?php foreach ($next as $to): ?>
      <form method="post" action="/appointments/<?= (int) $a['id'] ?>/status" class="mb-2"<?= in_array($to, ['cancelled', 'no_show', 'completed'], true) ? ' data-confirm="Mark this appointment as ' . e(strtolower(AM::STATUS_LABELS[$to])) . '? This cannot be undone."' : '' ?>>
        <?= Csrf::field() ?><input type="hidden" name="status" value="<?= e($to) ?>">
        <?php if ($to === 'confirmed' && $a['appointment_at'] === null): ?>
          <label class="form-label small mb-0" for="c_at">Appointment date and time</label><input type="datetime-local" class="form-control mb-2" id="c_at" name="appointment_at" required>
        <?php endif; ?>
        <button class="btn btn-sm <?= $btn[$to] ?>" type="submit"><?= e(['confirmed' => 'Confirm', 'completed' => 'Mark completed', 'cancelled' => 'Cancel appointment', 'no_show' => 'Mark no-show'][$to]) ?></button>
      </form>
    <?php endforeach; ?>
    <?php if ($open): ?>
      <hr><form method="post" action="/appointments/<?= (int) $a['id'] ?>/reschedule" class="row g-2 align-items-end">
        <?= Csrf::field() ?>
        <div class="col"><label class="form-label small mb-0" for="r_at"><?= $a['appointment_at'] ? 'Reschedule to' : 'Set date and time' ?></label><input type="datetime-local" class="form-control" id="r_at" name="appointment_at" value="<?= e($atVal) ?>" required></div>
        <div class="col-auto"><button class="btn btn-outline-primary" type="submit"><?= $a['appointment_at'] ? 'Reschedule' : 'Save' ?></button></div>
      </form>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header bg-white fw-semibold">Status history</div>
    <ul class="list-group list-group-flush small">
    <?php foreach ($history as $h): ?><li class="list-group-item"><?= e(substr((string) $h['event_at'], 0, 16)) ?> &middot; <?= $h['from_status'] ? e(AM::STATUS_LABELS[$h['from_status']]) . ' &rarr; ' : 'Created as ' ?><strong><?= e(AM::STATUS_LABELS[$h['to_status']]) ?></strong> <span class="text-secondary">by <?= e($h['user_name']) ?><?= $h['interaction_id'] ? ' (interaction #' . (int) $h['interaction_id'] . ')' : '' ?></span></li><?php endforeach; ?>
    <?php foreach ($reschedules as $r): ?><li class="list-group-item"><?= e(substr((string) $r['at'], 0, 16)) ?> &middot; Rescheduled <?= e($r['from'] ? substr((string) $r['from'], 0, 16) : 'unscheduled') ?> &rarr; <strong><?= e(substr((string) $r['to'], 0, 16)) ?></strong> <span class="text-secondary">by <?= e($r['user'] ?? 'unknown') ?></span></li><?php endforeach; ?>
    </ul></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header bg-white fw-semibold">Linked interactions</div>
    <ul class="list-group list-group-flush small">
    <?php foreach ($links as $l): ?><li class="list-group-item">#<?= (int) $l['interaction_id'] ?> &middot; <?= e(Channel::LABELS[$l['channel']]) ?> &middot; <?= e(substr((string) $l['interaction_at'], 0, 16)) ?> &middot; <?= e(['booked' => 'booked here', 'followup' => 'follow-up', 'fulfilled' => 'service received', 'reference' => 'reference'][$l['link_type']]) ?></li><?php endforeach; ?>
    <?php if (!$links): ?><li class="list-group-item text-secondary">No linked interactions.</li><?php endif; ?>
    </ul></div></div>
</div>
