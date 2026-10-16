<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <h1 class="h4 text-brand mb-0"><?= e($report['title']) ?></h1>
  <div class="btn-group btn-group-sm">
    <a class="btn btn-primary" href="/reports/<?= e($type) ?>/csv?<?= e($qs) ?>">Download CSV</a>
    <a class="btn btn-outline-primary" href="/reports/<?= e($type) ?>/print?<?= e($qs) ?>" target="_blank" rel="noopener">Print / PDF</a>
    <a class="btn btn-outline-secondary" href="/reports?<?= e($qs) ?>">All reports</a>
  </div>
</div>
<?php if ($errors): ?><div class="alert alert-warning py-2" role="alert"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
<dl class="row small mb-3"><?php foreach ($meta as $k => $v): ?><dt class="col-sm-3 col-lg-2"><?= e($k) ?></dt><dd class="col-sm-9 col-lg-10 mb-0"><?= e($v) ?></dd><?php endforeach; ?></dl>
<div class="alert alert-light border small"><?php foreach ($report['notes'] as $n): ?><div><?= e($n) ?></div><?php endforeach; ?></div>
<?php require __DIR__ . '/_tables.php'; ?>
