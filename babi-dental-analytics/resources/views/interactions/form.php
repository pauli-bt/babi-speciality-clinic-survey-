<?php
use App\Helpers\{Csrf, Phone};
use App\Models\Interaction;
$val = static fn (string $k, string $d = '') => (string) ($old[$k] ?? $d);
$vis = static fn (string $k, array $vs) => in_array((string) ($old[$k] ?? ''), $vs, true) ? '' : ' d-none';
$isRec = $channel === 'reception'; $isDm = $channel === 'social_dm';
$mode = $val('customer_mode', 'new'); $reason = $val('reason');
$oldPhoneNorm = Phone::normalize($val('phone'));
$ackIds = implode(',', array_map(static fn ($d) => (int) $d['id'], $duplicates));
$back = \App\Helpers\Channel::formPath($channel);
$followup = $reason === 'appointment_followup';
?>
<div class="row justify-content-center"><div class="col-lg-9 col-xl-8">
<h1 class="h4 text-brand mb-1"><?= e($channelLabel) ?>: record an interaction</h1>
<p class="text-secondary small mb-3"><?= $isRec ? 'Walk-in visit. The key question is whether the customer used the service.' : ($isDm ? 'Social media direct message. The key question is whether an appointment was booked.' : 'Telephone call. The key question is whether an appointment was booked.') ?></p>

<?php if ($errors): ?><div class="alert alert-danger" role="alert"><strong>Please fix the following:</strong><ul class="mb-0 mt-1"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($duplicates): ?>
<div class="card border-warning mb-3" role="alert">
  <div class="card-header bg-warning-subtle fw-semibold">Possible existing customer<?= count($duplicates) > 1 ? 's' : '' ?> found</div>
  <div class="card-body">
    <p class="mb-3">Check whether this person is already registered. Sharing a phone number does not mean they are the same person.</p>
    <?php foreach ($duplicates as $d):
        $byPhone = $oldPhoneNorm !== '' && $d['phone_normalized'] === $oldPhoneNorm;
        $byName = $val('name') !== '' && mb_strtolower(trim((string) $d['name'])) === mb_strtolower(trim($val('name'))); ?>
    <div class="border rounded p-3 mb-2"><div class="d-flex flex-wrap justify-content-between gap-2"><div>
      <div class="fw-semibold"><?= e($d['name'] ?? '(no name recorded)') ?> <code><?= e($d['customer_code']) ?></code></div>
      <div class="small text-secondary">Phone <?= e(Phone::mask($d['phone_normalized']) ?: 'not recorded') ?> &middot; registered <?= e(substr((string) $d['first_acquisition_at'], 0, 10)) ?> &middot; first heard via <?= e($d['source_name']) ?></div>
      <div class="mt-1"><?php if ($byPhone): ?><span class="badge text-bg-warning">Same phone number</span> <?php endif; ?><?php if ($byName): ?><span class="badge text-bg-warning">Same name</span><?php endif; ?></div>
    </div>
    <form method="post" action="/customers/<?= (int) $d['id'] ?>/select" class="m-0 align-self-center"><?= Csrf::field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button type="submit" class="btn btn-primary btn-sm">Same person: record against this customer</button></form></div></div>
    <?php endforeach; ?>
    <p class="small text-secondary mb-0">If they are a different person, use the button at the bottom of the form.</p>
  </div>
</div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" novalidate data-interaction-form data-appointments-url="/customers/__ID__/appointments">
  <?= Csrf::field() ?>
  <input type="hidden" name="form_token" value="<?= e($formToken) ?>">
  <input type="hidden" name="ack_ids" value="<?= e($ackIds) ?>">

  <div class="card mb-3"><div class="card-header bg-white fw-semibold">1. Customer</div><div class="card-body">
    <fieldset class="mb-3"><legend class="form-label fs-6">Is this a new or an existing customer? <span class="text-danger" aria-hidden="true">*</span></legend>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="customer_mode" id="mode_new" value="new"<?= $mode === 'new' ? ' checked' : '' ?>><label class="form-check-label" for="mode_new">New customer</label></div>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="customer_mode" id="mode_existing" value="existing"<?= $mode === 'existing' ? ' checked' : '' ?>><label class="form-check-label" for="mode_existing">Existing customer</label></div>
    </fieldset>

    <div data-show-when="customer_mode:new" class="<?= $mode === 'new' ? '' : 'd-none' ?>">
      <p class="text-secondary small">Enter the customer's name or telephone number (at least one).</p>
      <div class="mb-3"><label for="name" class="form-label">Customer name</label><input type="text" class="form-control" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="150" autocomplete="off"></div>
      <div class="mb-3"><label for="phone" class="form-label">Telephone number</label><input type="tel" class="form-control" id="phone" name="phone" value="<?= e($val('phone')) ?>" maxlength="30" inputmode="tel" autocomplete="off"><div class="form-text">Family members may share one number; that is fine.</div></div>
      <?php include __DIR__ . '/../customers/_attribution.php'; ?>
      <p class="small text-secondary mb-0">This is how they <em>first</em> heard about the clinic. It is stored permanently and is separate from the channel of this conversation.</p>
    </div>

    <div data-show-when="customer_mode:existing" class="<?= $mode === 'existing' ? '' : 'd-none' ?>">
      <?php $pickerId = 'ix-picker'; $pickerMode = 'field'; $pickerField = 'customer_id'; $pickerValue = $customer ? (string) $customer['id'] : ''; $pickerLabel = $customer ? 'Selected: ' . ($customer['name'] ?? '(no name)') . ' (' . $customer['customer_code'] . ')' : ''; include __DIR__ . '/../components/customer_picker.php'; ?>

      <fieldset class="mt-3"><legend class="form-label fs-6">Why is the customer in touch? <span class="text-danger" aria-hidden="true">*</span></legend>
        <?php foreach (['appointment_followup' => 'Existing appointment or follow-up', 'another_service' => 'Another service', 'campaign_response' => 'Saw an advertisement or new campaign'] as $k => $lbl): ?>
        <div class="form-check"><input class="form-check-input" type="radio" name="reason" id="reason_<?= e($k) ?>" value="<?= e($k) ?>"<?= $reason === $k ? ' checked' : '' ?>><label class="form-check-label" for="reason_<?= e($k) ?>"><?= e($lbl) ?></label></div>
        <?php endforeach; ?>
      </fieldset>

      <div class="mt-3<?= $followup ? '' : ' d-none' ?>" data-show-when="reason:appointment_followup">
        <label for="followup_appointment_id" class="form-label">Which appointment is this about? (optional)</label>
        <select class="form-select" id="followup_appointment_id" name="followup_appointment_id" data-followup-select>
          <option value="">Not linked / not sure</option>
          <?php foreach ($appointments as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $val('followup_appointment_id') === (string) $a['id'] ? ' selected' : '' ?>><?= e($a['service_name'] . ' - ' . ($a['appointment_at'] ? substr((string) $a['appointment_at'], 0, 16) : 'no time set') . ' - ' . $a['status']) ?></option><?php endforeach; ?>
        </select>
        <div class="form-text">Linking does not create a new appointment.</div>
      </div>

      <?php if (!$isDm): ?>
      <div class="mt-3<?= $reason === 'campaign_response' ? '' : ' d-none' ?>" data-show-when="reason:campaign_response">
        <div class="mb-3"><label for="ad_source_id" class="form-label">Where did they see the advertisement? <span class="text-danger" aria-hidden="true">*</span></label>
          <select class="form-select" id="ad_source_id" name="ad_source_id" data-ad-source><option value="">Choose...</option>
            <?php foreach ($sources as $s): if ($s['category'] === 'unknown') { continue; } ?><option value="<?= (int) $s['id'] ?>" data-platform="<?= (int) $s['requires_platform'] ?>"<?= $val('ad_source_id') === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-3<?= in_array($val('ad_source_id'), array_map(static fn ($s) => (string) $s['id'], array_filter($sources, static fn ($s) => (int) $s['requires_platform'] === 1)), true) ? '' : ' d-none' ?>" data-ad-platform-group>
          <label for="ad_platform_id" class="form-label">Which platform?</label>
          <select class="form-select" id="ad_platform_id" name="ad_platform_id"><option value="">Choose...</option><?php foreach ($platforms as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $val('ad_platform_id') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <?php endif; ?>
      <?php if ($campaigns): ?>
      <div class="mt-3"><label for="interaction_campaign_id" class="form-label">Campaign (only if you can tell which one)</label>
        <select class="form-select" id="interaction_campaign_id" name="interaction_campaign_id"><option value="">Not known</option>
          <?php foreach ($campaigns as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $val('interaction_campaign_id') === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <div class="form-text">Never filled in automatically. Leave as "Not known" rather than guessing.</div></div>
      <?php endif; ?>
    </div>
  </div></div>

  <div class="card mb-3"><div class="card-header bg-white fw-semibold">2. This <?= e(strtolower($channelLabel)) ?> interaction</div><div class="card-body">
    <div class="mb-3"><label for="interaction_at" class="form-label">Date and time <span class="text-danger" aria-hidden="true">*</span></label>
      <input type="datetime-local" class="form-control" id="interaction_at" name="interaction_at" value="<?= e($val('interaction_at')) ?>" required></div>
    <?php if ($isDm): ?>
    <div class="mb-3"><label for="dm_platform_id" class="form-label">Platform of this conversation <span class="text-danger" aria-hidden="true">*</span></label>
      <select class="form-select" id="dm_platform_id" name="dm_platform_id" required><option value="">Choose...</option><?php foreach ($platforms as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $val('dm_platform_id') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
      <div class="form-text">Recorded separately from how the customer first heard about the clinic.</div></div>
    <?php endif; ?>
    <div class="mb-3"><label for="service_id" class="form-label"><?= $isRec ? 'Service of interest / service used' : 'Service of interest' ?></label>
      <select class="form-select" id="service_id" name="service_id"><option value="">Not specified</option><?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>"<?= $val('service_id') === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>

    <?php if ($isRec): ?>
    <fieldset class="mb-3"><legend class="form-label fs-6">Did the customer use the service? <span class="text-danger" aria-hidden="true">*</span></legend>
      <div class="form-check"><input class="form-check-input" type="radio" name="used" id="used_yes" value="used"<?= $val('used') === 'used' ? ' checked' : '' ?>><label class="form-check-label" for="used_yes">Yes, the service was used</label></div>
      <div class="form-check"><input class="form-check-input" type="radio" name="used" id="used_no" value="not_used"<?= $val('used') === 'not_used' ? ' checked' : '' ?>><label class="form-check-label" for="used_no">No, visit or inquiry only</label></div>
      <div class="form-check<?= $followup ? '' : ' d-none' ?>" data-show-when="reason:appointment_followup"><input class="form-check-input" type="radio" name="used" id="used_unknown" value="unknown"<?= $val('used') === 'unknown' ? ' checked' : '' ?>><label class="form-check-label" for="used_unknown">Not known (follow-up)</label></div>
      <div class="form-text">A booking alone is not service use.</div>
    </fieldset>
    <div class="mb-3<?= $val('used') === 'not_used' ? '' : ' d-none' ?>" data-show-when="used:not_used">
      <label for="non_conversion_reason_id" class="form-label">Why did they not use the service?</label>
      <select class="form-select" id="non_conversion_reason_id" name="non_conversion_reason_id"><option value="">Choose...</option><?php foreach ($reasonsNC as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $val('non_conversion_reason_id') === (string) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
      <label for="non_conversion_other_text" class="form-label mt-2">Explanation (optional, used with "Other")</label>
      <input type="text" class="form-control" id="non_conversion_other_text" name="non_conversion_other_text" maxlength="300" value="<?= e($val('non_conversion_other_text')) ?>">
    </div>
    <?php else: ?>
    <fieldset class="mb-3"><legend class="form-label fs-6"><?= $isDm ? 'Did the customer book an appointment?' : 'Did the caller book an appointment?' ?> <span class="text-danger" aria-hidden="true">*</span></legend>
      <div class="form-check"><input class="form-check-input" type="radio" name="booking" id="b_conf" value="confirmed"<?= $val('booking') === 'confirmed' ? ' checked' : '' ?>><label class="form-check-label" for="b_conf">Yes: appointment confirmed (date and time agreed)</label></div>
      <div class="form-check"><input class="form-check-input" type="radio" name="booking" id="b_req" value="requested"<?= $val('booking') === 'requested' ? ' checked' : '' ?>><label class="form-check-label" for="b_req">Yes: appointment requested (not confirmed yet)</label></div>
      <div class="form-check"><input class="form-check-input" type="radio" name="booking" id="b_no" value="not_booked"<?= $val('booking') === 'not_booked' ? ' checked' : '' ?>><label class="form-check-label" for="b_no">No: inquiry only</label></div>
      <div class="form-check<?= $followup ? '' : ' d-none' ?>" data-show-when="reason:appointment_followup"><input class="form-check-input" type="radio" name="booking" id="b_na" value="not_applicable"<?= $val('booking') === 'not_applicable' ? ' checked' : '' ?>><label class="form-check-label" for="b_na">Not applicable (follow-up only)</label></div>
    </fieldset>
    <div class="mb-3<?= in_array($val('booking'), ['confirmed', 'requested'], true) ? '' : ' d-none' ?>" data-show-when="booking:confirmed|requested">
      <label for="appointment_at" class="form-label">Appointment date and time <span class="text-secondary small">(required when confirmed)</span></label>
      <input type="datetime-local" class="form-control" id="appointment_at" name="appointment_at" value="<?= e($val('appointment_at')) ?>">
      <div class="form-text">The service above is used for the appointment. Booking creates one appointment with the status "<?= $val('booking') === 'requested' ? 'requested' : 'confirmed' ?>"; it is not counted as completed.</div>
    </div>
    <div class="mb-3<?= $val('booking') === 'not_booked' ? '' : ' d-none' ?>" data-show-when="booking:not_booked">
      <label for="non_conversion_reason_id" class="form-label">Why was no appointment booked?</label>
      <select class="form-select" id="non_conversion_reason_id" name="non_conversion_reason_id"><option value="">Choose...</option><?php foreach ($reasonsNC as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $val('non_conversion_reason_id') === (string) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
      <label for="non_conversion_other_text" class="form-label mt-2">Explanation (optional, used with "Other")</label>
      <input type="text" class="form-control" id="non_conversion_other_text" name="non_conversion_other_text" maxlength="300" value="<?= e($val('non_conversion_other_text')) ?>">
    </div>
    <?php endif; ?>

    <div class="mb-0"><label for="notes" class="form-label">Notes (optional)</label>
      <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="1000" aria-describedby="notesHelp"><?= e($val('notes')) ?></textarea>
      <div id="notesHelp" class="form-text">Do not enter medical details. Notes are visible only to staff of this channel and administrators.</div></div>
  </div></div>

  <div class="d-flex flex-wrap gap-2 mb-4">
    <button type="submit" class="btn btn-primary"><?= $duplicates ? 'These are different people: save as a new customer' : 'Save interaction' ?></button>
    <a href="/" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>
</div></div>
