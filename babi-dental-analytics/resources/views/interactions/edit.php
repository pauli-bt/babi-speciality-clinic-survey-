<?php use App\Helpers\Csrf; use App\Models\Interaction;
$v = static fn (string $k, $fallback) => (string) ($old[$k] ?? $fallback);
$isRec = $i['channel'] === 'reception'; $isDm = $i['channel'] === 'social_dm'; $followup = $i['interaction_reason'] === 'appointment_followup';
$hasAppt = $i['origin_appointment_id'] !== null;
$showReasonNC = $isRec ? $v('used', $i['service_usage_outcome']) === 'not_used' : $i['booking_outcome'] === 'not_booked'; ?>
<div class="row justify-content-center"><div class="col-lg-7">
<h1 class="h4 text-brand mb-1">Edit interaction #<?= (int) $i['id'] ?></h1>
<p class="text-secondary small">Only corrections are possible here. The channel, customer, reason<?= $isRec ? '' : ' and booking outcome' ?> cannot be changed. Edits are recorded in the audit trail.</p>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card"><div class="card-body"><form method="post" action="/interactions/<?= (int) $i['id'] ?>/edit" novalidate data-interaction-form>
  <?= Csrf::field() ?>
  <?php if ($isDm): ?><div class="mb-3"><label for="dm_platform_id" class="form-label">Platform of this conversation</label><select class="form-select" id="dm_platform_id" name="dm_platform_id"><?php foreach ($platforms as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $v('dm_platform_id', (string) $i['social_platform_id']) === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="mb-3"><label for="service_id" class="form-label">Service</label>
    <select class="form-select" id="service_id" name="service_id"<?= $hasAppt ? ' aria-describedby="svcHelp"' : '' ?>><option value="">Not specified</option><?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>"<?= $v('service_id', (string) $i['service_id']) === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
    <?php if ($hasAppt): ?><div id="svcHelp" class="form-text">An appointment was booked from this interaction, so the service is fixed.</div><?php endif; ?></div>
  <?php if ($isRec): ?>
  <fieldset class="mb-3"><legend class="form-label fs-6">Did the customer use the service?</legend>
    <?php foreach (['used' => 'Yes, the service was used', 'not_used' => 'No, visit or inquiry only'] + ($followup ? ['unknown' => 'Not known (follow-up)'] : []) as $k => $l): ?>
    <div class="form-check"><input class="form-check-input" type="radio" name="used" id="used_<?= e($k) ?>" value="<?= e($k) ?>"<?= $v('used', $i['service_usage_outcome']) === $k ? ' checked' : '' ?>><label class="form-check-label" for="used_<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?></fieldset>
  <?php endif; ?>
  <?php if ($isRec || $i['booking_outcome'] === 'not_booked'): ?>
  <div class="mb-3<?= $showReasonNC ? '' : ' d-none' ?>"<?= $isRec ? ' data-show-when="used:not_used"' : '' ?>>
    <label for="non_conversion_reason_id" class="form-label">Reason</label>
    <select class="form-select" id="non_conversion_reason_id" name="non_conversion_reason_id"><option value="">Choose...</option><?php foreach ($reasonsNC as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $v('non_conversion_reason_id', (string) $i['non_conversion_reason_id']) === (string) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
    <label for="non_conversion_other_text" class="form-label mt-2">Explanation (optional)</label><input type="text" class="form-control" id="non_conversion_other_text" name="non_conversion_other_text" maxlength="300" value="<?= e($v('non_conversion_other_text', (string) $i['non_conversion_other_text'])) ?>"></div>
  <?php endif; ?>
  <?php if ($campaigns): ?><div class="mb-3"><label for="interaction_campaign_id" class="form-label">Campaign</label><select class="form-select" id="interaction_campaign_id" name="interaction_campaign_id"><option value="">Not known</option><?php foreach ($campaigns as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $v('interaction_campaign_id', (string) $i['campaign_id']) === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="mb-3"><label for="notes" class="form-label">Notes</label><textarea class="form-control" id="notes" name="notes" rows="3" maxlength="1000"><?= e($v('notes', (string) $i['notes'])) ?></textarea></div>
  <button class="btn btn-primary" type="submit">Save changes</button> <a class="btn btn-link" href="/interactions/<?= (int) $i['id'] ?>">Cancel</a>
</form></div></div></div></div>
