<?php use App\Helpers\Channel; use App\Models\Interaction; ?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div><h1 class="h4 mb-0 text-brand">Interaction #<?= (int) $i['id'] ?></h1><span class="text-secondary"><?= e(Channel::LABELS[$i['channel']]) ?> &middot; <?= e($i['interaction_at']) ?></span>
    <?php if ((int) $i['is_first_contact'] === 1): ?> <span class="badge text-bg-info">First contact: new customer</span><?php endif; ?></div>
  <div class="d-flex gap-2"><?php if ($edit['allowed']): ?><a class="btn btn-primary" href="/interactions/<?= (int) $i['id'] ?>/edit">Edit</a><?php endif; ?><a href="/interactions" class="btn btn-outline-secondary">Back to list</a></div>
</div>
<?php if (!$edit['allowed'] && $edit['message']): ?><div class="alert alert-secondary small"><?= e($edit['message']) ?></div><?php endif; ?>
<div class="card"><div class="card-body"><dl class="row mb-0">
  <dt class="col-sm-4">Customer</dt><dd class="col-sm-8"><a href="/customers/<?= (int) $i['customer_id'] ?>"><?= e($i['customer_name'] ?? '(no name recorded)') ?></a> <code><?= e($i['customer_code']) ?></code></dd>
  <dt class="col-sm-4">Reason</dt><dd class="col-sm-8"><?= e(Interaction::REASON_LABELS[$i['interaction_reason']]) ?></dd>
  <dt class="col-sm-4">Outcome</dt><dd class="col-sm-8"><?= e(Interaction::outcomeLabel($i)) ?></dd>
  <?php if ($i['reason_name']): ?><dt class="col-sm-4">Reason for no <?= $i['channel'] === 'reception' ? 'service use' : 'booking' ?></dt><dd class="col-sm-8"><?= e($i['reason_name']) ?><?= $i['non_conversion_other_text'] ? ': ' . e($i['non_conversion_other_text']) : '' ?></dd><?php endif; ?>
  <dt class="col-sm-4">Service</dt><dd class="col-sm-8"><?= e($i['service_name'] ?? 'not specified') ?></dd>
  <?php if ($i['platform_name']): ?><dt class="col-sm-4">Platform</dt><dd class="col-sm-8"><?= e($i['platform_name']) ?></dd><?php endif; ?>
  <?php if ($i['ad_source_name']): ?><dt class="col-sm-4">Advertisement seen via</dt><dd class="col-sm-8"><?= e($i['ad_source_name']) ?></dd><?php endif; ?>
  <dt class="col-sm-4">Campaign</dt><dd class="col-sm-8"><?= e($i['campaign_name'] ?? 'not identified') ?></dd>
  <dt class="col-sm-4">Recorded by</dt><dd class="col-sm-8"><?= e($i['recorded_by_name']) ?> on <?= e($i['created_at']) ?></dd>
  <dt class="col-sm-4">Notes</dt><dd class="col-sm-8 text-break"><?= $i['notes'] ? nl2br(e($i['notes'])) : '<span class="text-secondary">none</span>' ?></dd>
</dl></div></div>
<?php if ($appointments): ?><div class="card mt-3"><div class="card-header bg-white fw-semibold">Appointments</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Service</th><th>When</th><th>Status</th><th>Relation</th></tr></thead><tbody>
<?php foreach ($appointments as $a): ?><tr><td><?= e($a['service_name']) ?></td><td><?= e($a['appointment_at'] ? substr((string) $a['appointment_at'], 0, 16) : 'not set') ?></td><td><span class="badge text-bg-secondary"><?= e($a['status']) ?></span></td>
<td class="small"><?= e(['booked' => 'Created by this interaction', 'followup' => 'Follow-up on', 'fulfilled' => 'Fulfilled', 'reference' => 'Reference'][$a['link_type']] ?? $a['link_type']) ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
