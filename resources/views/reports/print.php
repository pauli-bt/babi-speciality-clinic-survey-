<?php
$printMode = true;
$cols = 0;
foreach ($report['sections'] as $sec) { $cols = max($cols, count($sec['columns'])); }
$wide = $cols > 7;   // wide tables get a landscape page
$generated = $meta['Generated'] ?? '';
$period = $meta['Reporting period'] ?? '';
$tz = $meta['Timezone'] ?? '';
?>
<div class="report-doc<?= $wide ? ' is-wide' : '' ?>">
  <header class="doc-header">
    <img src="/assets/images/babi-logo.png" alt="Babi Dental" width="46" height="46" class="logo-round">
    <div class="doc-brand"><div class="doc-clinic">Babi Speciality Dental Clinic</div><div class="doc-sub">Customer acquisition &amp; marketing analytics</div></div>
    <div class="doc-confidential">Confidential<br><span>Internal clinic use</span></div>
  </header>
  <h1 class="report-title"><?= e($report['title']) ?></h1>
  <div class="report-meta">
    <div><span>Reporting period</span><strong><?= e($period) ?></strong></div>
    <div><span>Generated</span><strong><?= e($generated) ?></strong></div>
    <div><span>Timezone</span><strong><?= e($tz) ?></strong></div>
  </div>
  <?php if ($report['notes']): ?><div class="report-notes"><?php foreach ($report['notes'] as $n): ?><div><?= e($n) ?></div><?php endforeach; ?></div><?php endif; ?>
  <?php require __DIR__ . '/_tables.php'; ?>
  <footer class="doc-footer">Confidential &middot; for internal clinic use &middot; aggregate figures only, no customer names, codes, phone numbers or notes.</footer>
</div>
