<?php use App\Helpers\Channel; use App\Models\Interaction; use App\Services\{Auth, InteractionService}; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0 text-brand">Interactions<?= $own ? ' <span class="fw-normal text-secondary fs-6">(' . e(Channel::LABELS[$own]) . ' only)</span>' : '' ?></h1>
  <?php if ($own): ?><a href="<?= e(Channel::formPath($own)) ?>" class="btn btn-primary">Record interaction</a><?php endif; ?>
</div>
<?php if (!$own): ?>
<form method="get" action="/interactions" class="row g-2 mb-3"><div class="col-auto"><label class="visually-hidden" for="channel">Channel</label>
  <select class="form-select" id="channel" name="channel"><option value="">All channels</option><?php foreach (Channel::LABELS as $k => $l): ?><option value="<?= e($k) ?>"<?= $filter === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-primary" type="submit">Filter</button></div></form>
<?php endif; ?>
<p class="small text-secondary"><?= e($total) ?> interaction(s) &middot; page <?= e($page) ?> of <?= e($pages) ?></p>
<?php if (!$rows): ?><div class="card"><div class="card-body text-center text-secondary py-4">No interactions recorded yet.</div></div><?php else: ?>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>When</th><?php if (!$own): ?><th>Channel</th><?php endif; ?><th>Customer</th><th>Reason</th><th>Outcome</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td class="small text-nowrap"><?= e(substr((string) $r['interaction_at'], 0, 16)) ?></td>
  <?php if (!$own): ?><td><?= e(Channel::LABELS[$r['channel']]) ?></td><?php endif; ?>
  <td><a href="/customers/<?= (int) $r['customer_id'] ?>" class="text-decoration-none"><?= e($r['customer_name'] ?? '(no name)') ?></a> <code class="small"><?= e($r['customer_code']) ?></code></td>
  <td class="small"><?= e(Interaction::REASON_LABELS[$r['interaction_reason']]) ?></td>
  <td class="small"><?= e(Interaction::outcomeLabel($r)) ?></td>
  <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/interactions/<?= (int) $r['id'] ?>">Open</a></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if ($pages > 1): ?><nav class="mt-3" aria-label="Pages"><ul class="pagination pagination-sm flex-wrap">
<?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?><li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="/interactions?page=<?= $p ?><?= $filter ? '&amp;channel=' . e($filter) : '' ?>"><?= $p ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
<?php endif; ?>
