<?php
/** Shared by registration and the audited source correction. Expects $sources, $platforms, $campaigns, $old. */
$selSource = (int) ($old['source_id'] ?? 0);
$needPlatform = false; $allowOther = false;
foreach ($sources as $s) { if ((int) $s['id'] === $selSource) { $needPlatform = (int) $s['requires_platform'] === 1; $allowOther = (int) $s['allows_other_text'] === 1; } }
?>
<div class="mb-3">
  <label for="source_id" class="form-label">How did the customer first hear about the clinic? <span class="text-danger" aria-hidden="true">*</span></label>
  <select class="form-select" id="source_id" name="source_id" required data-attr-source>
    <option value="">Choose...</option>
    <?php foreach ($sources as $s): ?>
      <option value="<?= (int) $s['id'] ?>" data-platform="<?= (int) $s['requires_platform'] ?>" data-other="<?= (int) $s['allows_other_text'] ?>"<?= $selSource === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <div class="form-text">If the customer does not remember, choose "Unknown". Please do not guess.</div>
</div>
<div class="mb-3<?= $needPlatform ? '' : ' d-none' ?>" data-attr-platform-group>
  <label for="platform_id" class="form-label">Which social media platform?</label>
  <select class="form-select" id="platform_id" name="platform_id" data-attr-platform>
    <option value="">Choose...</option>
    <?php foreach ($platforms as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) ($old['platform_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
  </select>
</div>
<div class="mb-3<?= $allowOther ? '' : ' d-none' ?>" data-attr-other-group>
  <label for="source_other" class="form-label">Please describe (optional)</label>
  <input type="text" class="form-control" id="source_other" name="source_other" maxlength="150" value="<?= e($old['source_other'] ?? '') ?>" data-attr-other>
</div>
<?php if ($campaigns): ?>
<div class="mb-3">
  <label for="campaign_id" class="form-label">Campaign (only if you can tell which one)</label>
  <select class="form-select" id="campaign_id" name="campaign_id">
    <option value="">Not known</option>
    <?php foreach ($campaigns as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) ($old['campaign_id'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
  </select>
  <div class="form-text">Leave as "Not known" rather than guessing; it is never filled in automatically.</div>
</div>
<?php endif; ?>
