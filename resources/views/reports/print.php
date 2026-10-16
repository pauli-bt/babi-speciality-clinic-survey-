<h1 class="h4 text-brand mb-1"><?= e($report['title']) ?></h1>
<dl class="row small mb-2"><?php foreach ($meta as $k => $v): ?><dt class="col-4 col-md-2"><?= e($k) ?></dt><dd class="col-8 col-md-10 mb-0"><?= e($v) ?></dd><?php endforeach; ?></dl>
<div class="border rounded p-2 small mb-3"><?php foreach ($report['notes'] as $n): ?><div><?= e($n) ?></div><?php endforeach; ?></div>
<?php require __DIR__ . '/_tables.php'; ?>
<p class="small text-secondary border-top pt-2">Confidential: for internal clinic use. Aggregate figures only.</p>
