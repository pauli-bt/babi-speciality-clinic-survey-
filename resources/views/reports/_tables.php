<?php
/** @var array $report */
$fmt = static function ($v): string {
    if ($v === null) { return 'n/a'; }
    if (is_float($v)) { return number_format($v, 1); }
    return (string) $v;
};
foreach ($report['sections'] as $sec): ?>
<section class="report-section mb-4">
  <h2 class="h6 fw-semibold text-brand"><?= e($sec['title']) ?></h2>
  <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-1">
    <thead class="table-light"><tr><?php foreach ($sec['columns'] as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($sec['rows'] as $row): ?><tr><?php foreach ($row as $i => $v): ?><td<?= is_int($v) || is_float($v) ? ' class="text-end"' : '' ?>><?= e($fmt($v)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    <?php if (!$sec['rows']): ?><tr><td colspan="<?= count($sec['columns']) ?>" class="text-center text-secondary py-2">No data in this period.</td></tr><?php endif; ?>
    </tbody></table></div>
  <?php if (!empty($sec['note'])): ?><p class="small text-secondary mb-0"><?= e($sec['note']) ?></p><?php endif; ?>
</section>
<?php endforeach; ?>
