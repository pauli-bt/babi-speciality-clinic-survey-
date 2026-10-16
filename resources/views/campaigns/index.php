<?php use App\Services\CampaignManager; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0 text-brand">Campaigns</h1>
  <?php if ($canManage): ?><a href="/campaigns/new" class="btn btn-primary">New campaign</a><?php endif; ?>
</div>
<form method="get" action="/campaigns" class="row g-2 mb-3">
  <div class="col-auto"><label class="visually-hidden" for="status">Status</label>
    <select class="form-select" id="status" name="status"><option value="">All statuses</option>
    <?php foreach (CampaignManager::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-primary" type="submit">Filter</button></div>
</form>
<p class="small text-secondary">Responses are interactions where staff identified the campaign. A campaign response never changes a customer's original acquisition source. Campaigns are never deleted.</p>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Name</th><th>Code</th><th>Platform</th><th>Dates</th><th>Status</th><th class="text-end">Responses</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($campaigns as $c): ?>
    <tr>
      <td><?= e($c['name']) ?></td>
      <td><?= $c['campaign_code'] ? '<code>' . e($c['campaign_code']) . '</code>' : '<span class="text-secondary">&ndash;</span>' ?></td>
      <td><?= e($c['platform_name'] ?? 'Offline / none') ?></td>
      <td class="small text-nowrap"><?= e($c['start_date'] ?? '?') ?> &rarr; <?= e($c['end_date'] ?? 'open') ?></td>
      <td><span class="badge text-bg-<?= ['active' => 'success', 'paused' => 'warning', 'draft' => 'secondary', 'ended' => 'dark'][$c['status']] ?>"><?= e(CampaignManager::STATUSES[$c['status']]) ?></span></td>
      <td class="text-end"><?= e($c['response_count']) ?></td>
      <?php if ($canManage): ?><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/campaigns/<?= (int) $c['id'] ?>/edit">Edit</a></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if (!$campaigns): ?><tr><td colspan="7" class="text-center text-secondary py-4">No campaigns found.</td></tr><?php endif; ?>
  </tbody></table></div></div>
