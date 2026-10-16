<?php use App\Services\AnalyticsRange; ?>
<h1 class="h4 text-brand mb-2">Reports</h1>
<p class="text-secondary small">Aggregate reports for a chosen period. Download as CSV (opens in Excel) or open the print view and use your browser's &ldquo;Save as PDF&rdquo;. Reports contain no customer names, codes, phone numbers or notes.</p>
<?php if ($errors): ?><div class="alert alert-warning py-2" role="alert"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="get" action="/reports" class="card card-body py-2 mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small mb-0" for="range">Period</label>
      <select class="form-select form-select-sm" id="range" name="range"><?php foreach (AnalyticsRange::PRESETS as $k => $l): ?><option value="<?= e($k) ?>"<?= $range->preset === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($range->from->format('Y-m-d')) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($range->to->format('Y-m-d')) ?>"></div>
    <div class="col-6 col-md-2"><button class="btn btn-primary btn-sm w-100" type="submit">Apply</button></div>
    <div class="col-12 col-md-3 small text-secondary">Reporting period: <strong><?= e($range->label()) ?></strong> (<?= (int) $range->days() ?> day<?= $range->days() === 1 ? '' : 's' ?>, clinic local time, both days included). From/To apply only to &ldquo;Custom range&rdquo;.</div>
  </div>
</form>
<div class="card"><ul class="list-group list-group-flush">
<?php foreach ($types as $k => [$label, $desc]): ?>
  <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div><div class="fw-semibold"><?= e($label) ?></div><div class="small text-secondary"><?= e($desc) ?></div></div>
    <div class="btn-group btn-group-sm" role="group" aria-label="<?= e($label) ?>">
      <a class="btn btn-outline-primary" href="/reports/<?= e($k) ?>?<?= e($qs) ?>">View</a>
      <a class="btn btn-outline-primary" href="/reports/<?= e($k) ?>/csv?<?= e($qs) ?>">CSV</a>
      <a class="btn btn-outline-primary" href="/reports/<?= e($k) ?>/print?<?= e($qs) ?>" target="_blank" rel="noopener">Print / PDF</a>
    </div>
  </li>
<?php endforeach; ?>
</ul></div>
