<?php use App\Services\Analytics as A; require __DIR__ . '/_head.php'; $total = array_sum($byChannel); ?>
<div class="alert alert-light border small">
  <strong>What is counted:</strong> customers whose <em>first-ever contact</em> (acquisition date) falls in the period, attributed to their <em>original</em> acquisition source. A returning customer who answers a campaign later is a re-engagement (see Campaigns), never a new customer here. Original sources are write-once and only change through the audited administrator correction.
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-5"><div class="card h-100"><div class="card-header bg-white fw-semibold">Source distribution</div><div class="card-body"><?= chart_canvas(['type' => 'doughnut', 'labels' => array_column($bySource, 'name'), 'datasets' => [['label' => 'New customers', 'data' => array_map('intval', array_column($bySource, 'n'))]]], 'Distribution of new customers by original source', 280) ?></div></div></div>
  <div class="col-lg-7"><div class="card h-100"><div class="card-header bg-white fw-semibold">New customers over time</div><div class="card-body"><?= chart_canvas(['type' => 'bar', 'labels' => $series['labels'], 'datasets' => [['label' => 'New customers', 'data' => $series['values']]]], 'New customers over time', 280) ?></div></div></div>
</div>
<div class="card mb-3"><div class="card-header bg-white fw-semibold">Source performance (<?= (int) $total ?> new customers)</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Original source</th><th class="text-end">New customers</th><th class="text-end">Share</th><th class="text-end">With &ge;1 appointment</th><th class="text-end">With &ge;1 completed appointment</th></tr></thead><tbody>
  <?php foreach ($bySource as $s): ?><tr><td><?= e($s['name']) ?></td><td class="text-end"><?= (int) $s['n'] ?></td><td class="text-end"><?= e(pct(A::rate((int) $s['n'], $total))) ?></td>
    <td class="text-end"><?= (int) $s['with_appt'] ?> (<?= e(pct(A::rate((int) $s['with_appt'], (int) $s['n']))) ?>)</td><td class="text-end"><?= (int) $s['with_completed'] ?> (<?= e(pct(A::rate((int) $s['with_completed'], (int) $s['n']))) ?>)</td></tr><?php endforeach; ?>
  <?php if (!$bySource): ?><tr><td colspan="5" class="text-center text-secondary py-3">No new customers in this period.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card-footer bg-white small text-secondary">The last two columns count each customer once, using appointments they have to date (not only those in the period), so recent customers have had less time to book.</div></div>
<div class="row g-3">
  <div class="col-md-6"><div class="card h-100"><div class="card-header bg-white fw-semibold">By acquisition channel</div><div class="card-body"><?= chart_canvas(['type' => 'bar', 'horizontal' => true, 'labels' => array_values($channelLabels), 'datasets' => [['label' => 'New customers', 'data' => array_values($byChannel)]]], 'New customers by acquisition channel', 220) ?></div></div></div>
  <div class="col-md-6"><div class="card h-100"><div class="card-header bg-white fw-semibold">Original social platform</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Platform</th><th class="text-end">New customers</th></tr></thead><tbody>
    <?php foreach ($byPlatform as $p): ?><tr><td><?= e($p['name']) ?></td><td class="text-end"><?= (int) $p['n'] ?></td></tr><?php endforeach; ?>
    <?php if (!$byPlatform): ?><tr><td colspan="2" class="text-center text-secondary py-3">No social-media acquisitions in this period.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div>
