<?php use App\Helpers\Csrf;
if (!$old) { $old = ['source_id' => $c['original_source_id'], 'platform_id' => $c['original_social_platform_id'], 'source_other' => $c['original_source_other'], 'campaign_id' => $c['original_campaign_id']]; } ?>
<div class="row justify-content-center"><div class="col-lg-7">
<h1 class="h4 text-brand mb-1">Correct original acquisition source</h1>
<p class="text-secondary"><code><?= e($c['customer_code']) ?></code>, currently: <strong><?= e($c['source_name']) ?></strong><?= $c['platform_name'] ? ' (' . e($c['platform_name']) . ')' : '' ?>.</p>
<div class="alert alert-warning small">The original source is normally permanent. Use this only to fix a recording mistake. The reason, the old and new values and your name are kept in the audit trail.</div>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card"><div class="card-body"><form method="post" action="/customers/<?= (int) $c['id'] ?>/source" novalidate>
  <?= Csrf::field() ?>
  <?php include __DIR__ . '/_attribution.php'; ?>
  <div class="mb-3"><label for="reason" class="form-label">Reason for the correction <span class="text-danger" aria-hidden="true">*</span></label>
    <textarea class="form-control" id="reason" name="reason" rows="3" maxlength="300" required><?= e($_POST['reason'] ?? '') ?></textarea></div>
  <button class="btn btn-primary" type="submit">Record correction</button> <a class="btn btn-link" href="/customers/<?= (int) $c['id'] ?>">Cancel</a>
</form></div></div></div></div>
