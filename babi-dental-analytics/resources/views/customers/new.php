<?php
use App\Helpers\{Csrf, Phone};
$oldPhoneNorm = Phone::normalize((string) ($old['phone'] ?? ''));
$ackIds = implode(',', array_map(static fn ($d) => (int) $d['id'], $duplicates));
$chLabels = \App\Helpers\Channel::LABELS;
?>
<div class="row justify-content-center"><div class="col-lg-8">
<h1 class="h4 text-brand mb-3">Register a new customer</h1>

<?php if ($errors): ?><div class="alert alert-danger" role="alert"><strong>Please fix the following:</strong><ul class="mb-0 mt-1"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($duplicates): ?>
<div class="card border-warning mb-3" role="alert">
  <div class="card-header bg-warning-subtle fw-semibold">Possible existing customer<?= count($duplicates) > 1 ? 's' : '' ?> found</div>
  <div class="card-body">
    <p class="mb-3">Check whether this person is already registered. Sharing a phone number does not mean they are the same person.</p>
    <?php foreach ($duplicates as $d):
        $byPhone = $oldPhoneNorm !== '' && $d['phone_normalized'] === $oldPhoneNorm;
        $byName = !empty($old['name']) && mb_strtolower(trim((string) $d['name'])) === mb_strtolower(trim((string) $old['name'])); ?>
    <div class="border rounded p-3 mb-2">
      <div class="d-flex flex-wrap justify-content-between gap-2">
        <div>
          <div class="fw-semibold"><?= e($d['name'] ?? '(no name recorded)') ?> <code><?= e($d['customer_code']) ?></code></div>
          <div class="small text-secondary">
            Phone <?= e(Phone::mask($d['phone_normalized']) ?: 'not recorded') ?> &middot; registered <?= e(substr((string) $d['first_acquisition_at'], 0, 10)) ?>
            &middot; first heard via <?= e($d['source_name']) ?> &middot; first contact: <?= e($chLabels[$d['first_acquisition_channel']] ?? '') ?>
          </div>
          <div class="mt-1"><?php if ($byPhone): ?><span class="badge text-bg-warning">Same phone number</span> <?php endif; ?><?php if ($byName): ?><span class="badge text-bg-warning">Same name</span><?php endif; ?></div>
        </div>
        <form method="post" action="/customers/<?= (int) $d['id'] ?>/select" class="m-0 align-self-center"><?= Csrf::field() ?><button type="submit" class="btn btn-primary btn-sm">This is the same person: use this customer</button></form>
      </div>
    </div>
    <?php endforeach; ?>
    <p class="small text-secondary mb-0">If they are a different person, use the button at the bottom of the form to register them as a new customer.</p>
  </div>
</div>
<?php endif; ?>

<div class="card"><div class="card-body">
<form method="post" action="/customers" novalidate>
  <?= Csrf::field() ?>
  <input type="hidden" name="form_token" value="<?= e($formToken) ?>">
  <input type="hidden" name="ack_ids" value="<?= e($ackIds) ?>">
  <p class="text-secondary small">Enter the customer's name or telephone number (at least one). Both are welcome when available.</p>
  <div class="mb-3">
    <label for="name" class="form-label">Customer name</label>
    <input type="text" class="form-control" id="name" name="name" value="<?= e($old['name'] ?? '') ?>" maxlength="150" autocomplete="off">
  </div>
  <div class="mb-3">
    <label for="phone" class="form-label">Telephone number</label>
    <input type="tel" class="form-control" id="phone" name="phone" value="<?= e($old['phone'] ?? '') ?>" maxlength="30" inputmode="tel" autocomplete="off" aria-describedby="phoneHelp">
    <div id="phoneHelp" class="form-text">Family members may share one number; that is fine.</div>
  </div>
  <hr>
  <?php include __DIR__ . '/_attribution.php'; ?>
  <?php if ($channelFixed === null): ?>
  <div class="mb-3">
    <label for="channel" class="form-label">Channel of first contact <span class="text-danger" aria-hidden="true">*</span></label>
    <select class="form-select" id="channel" name="channel" required>
      <option value="">Choose...</option>
      <?php foreach ($channels as $k => $lbl): ?><option value="<?= e($k) ?>"<?= ($old['channel'] ?? '') === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php else: ?>
  <p class="small text-secondary">First contact channel: <strong><?= e($chLabels[$channelFixed]) ?></strong> (set automatically from your role).</p>
  <?php endif; ?>
  <div class="d-flex flex-wrap gap-2">
    <button type="submit" class="btn btn-primary"><?= $duplicates ? 'These are different people: register as a new customer' : 'Register customer' ?></button>
    <a href="/customers" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form></div></div>
</div></div>
