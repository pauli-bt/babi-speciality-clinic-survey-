<?php
/** @var string $label @var int|string $value @var string $note @var ?int $prev */
$delta = null;
if (isset($prev)) {
    $ch = \App\Services\Analytics::change((int) $value, (int) $prev);
    $delta = $ch === null ? 'No previous-period data to compare' : (($ch > 0 ? '+' : '') . number_format($ch, 1) . '% vs previous period (' . (int) $prev . ')');
    $cls = $ch === null || $ch == 0 ? 'delta-flat' : ($ch > 0 ? 'delta-up' : 'delta-down');
}
?>
<div class="col-6 col-lg-3"><div class="card h-100 kpi"><div class="card-body py-2">
  <div class="kpi-label"><?= e($label) ?></div><div class="kpi-value"><?= is_int($value) ? e(number_format($value)) : e($value) ?></div>
  <?php if ($delta !== null): ?><div class="kpi-note <?= $cls ?>"><?= e($delta) ?></div><?php endif; ?>
  <div class="kpi-note"><?= e($note) ?></div>
</div></div></div>
