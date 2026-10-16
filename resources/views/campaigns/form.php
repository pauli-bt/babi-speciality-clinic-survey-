<?php
use App\Helpers\Csrf;
use App\Services\CampaignManager;
$isEdit = $campaign !== null;
$val = static fn (string $k, string $d = '') => (string) ($old[$k] ?? ($campaign[$k] ?? $d));
?>
<div class="row justify-content-center"><div class="col-lg-7">
<h1 class="h4 text-brand mb-3"><?= $isEdit ? 'Edit campaign' : 'New campaign' ?></h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card"><div class="card-body">
<form method="post" action="<?= $isEdit ? '/campaigns/' . (int) $campaign['id'] : '/campaigns' ?>" novalidate>
  <?= Csrf::field() ?>
  <div class="mb-3"><label for="name" class="form-label">Campaign name</label>
    <input type="text" class="form-control" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="150" required></div>
  <div class="row g-3 mb-3">
    <div class="col-md-6"><label for="campaign_code" class="form-label">Code (optional)</label>
      <input type="text" class="form-control" id="campaign_code" name="campaign_code" value="<?= e($val('campaign_code')) ?>" maxlength="60" autocapitalize="none"></div>
    <div class="col-md-6"><label for="platform_id" class="form-label">Platform</label>
      <select class="form-select" id="platform_id" name="platform_id"><option value="">Offline / none</option>
      <?php foreach ($platforms as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $val('platform_id') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
      <?php if ($isEdit && $campaign['platform_id'] && !array_filter($platforms, static fn ($p) => (int) $p['id'] === (int) $campaign['platform_id'])): ?><option value="<?= (int) $campaign['platform_id'] ?>" selected><?= e($campaign['platform_name']) ?> (inactive)</option><?php endif; ?>
      </select></div>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-md-4"><label for="start_date" class="form-label">Start date</label><input type="date" class="form-control" id="start_date" name="start_date" value="<?= e($val('start_date')) ?>"></div>
    <div class="col-md-4"><label for="end_date" class="form-label">End date</label><input type="date" class="form-control" id="end_date" name="end_date" value="<?= e($val('end_date')) ?>"></div>
    <div class="col-md-4"><label for="status" class="form-label">Status</label>
      <select class="form-select" id="status" name="status"><?php foreach (CampaignManager::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $val('status', 'draft') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="form-text mb-3">Draft campaigns are not offered in the questionnaires. <?= $isEdit ? 'This campaign has ' . (int) $campaign['response_count'] . ' recorded response(s).' : '' ?></div>
  <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create campaign' ?></button>
  <a href="/campaigns" class="btn btn-link">Cancel</a>
</form></div></div>
</div></div>
