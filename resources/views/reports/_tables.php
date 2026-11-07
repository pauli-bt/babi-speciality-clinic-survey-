<?php
/** @var array $report  @var bool|null $printMode  (set by the print view: plain sections instead of cards) */
$printMode = !empty($printMode);
$fmt = static function ($v): string {
    if ($v === null) { return 'n/a'; }
    if (is_float($v)) { return number_format($v, 1); }
    return (string) $v;
};
foreach ($report['sections'] as $sec): ?>
<section class="report-section mb-4<?= $printMode ? '' : ' card border-0 shadow-sm' ?>">
  <?php if ($printMode): ?>
    <h2 class="section-title mb-2"><?= e($sec['title']) ?></h2>
  <?php else: ?>
    <div class="card-header bg-white py-3 border-bottom"><h2 class="section-title"><?= e($sec['title']) ?></h2></div>
  <?php endif; ?>
  <div class="<?= $printMode ? '' : 'card-body p-0' ?>">
    <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0 report-table">
      <thead><tr><?php foreach ($sec['columns'] as $i => $c): ?><th<?= $i > 0 ? ' class="num"' : '' ?>><?= e($c) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($sec['rows'] as $row): ?><tr><?php foreach ($row as $i => $v): ?><td<?= is_int($v) || is_float($v) ? ' class="num"' : '' ?>><?= e($fmt($v)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      <?php if (!$sec['rows']): ?><tr><td colspan="<?= count($sec['columns']) ?>" class="text-center text-secondary py-2">No data in this period.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div>
  <?php if (!empty($sec['note'])): ?><p class="small text-secondary mb-0 <?= $printMode ? 'mt-1' : 'px-3 py-2 border-top' ?>"><?= e($sec['note']) ?></p><?php endif; ?>
</section>
<?php endforeach; ?>
