<?php
/** Shared header: tabs + date filter. Needs $tab, $path, $range, $errors, $qs. */
use App\Services\AnalyticsRange;
$tabs = [
    'overview' => ['Overview', '/analytics'], 
    'acquisition' => ['Acquisition', '/analytics/acquisition'], 
    'channels' => ['Channels', '/analytics/channels'], 
    'campaigns' => ['Campaigns', '/analytics/campaigns'], 
    'appointments' => ['Appointments & services', '/analytics/appointments']
];
?>
<div class="container-fluid px-4 px-xl-5 py-4">
  <!-- Page Title -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 fw-bold text-brand mb-0"><?= e($title) ?></h1>
  </div>
  
  <!-- Fancy Navigation Sub-Tabs -->
  <div class="card border-0 shadow-sm p-2 mb-4 bg-white rounded-3">
    <ul class="nav nav-pills analytics-tabs flex-wrap gap-2">
      <?php foreach ($tabs as $k => [$label, $href]): ?>
        <li class="nav-item">
          <a class="nav-link px-3 py-2 rounded-2 fw-medium text-decoration-none <?= $tab === $k ? 'bg-primary text-white shadow-sm' : 'text-dark bg-light' ?>" 
             style="<?= $tab === $k ? 'background-color: #173b77 !important; color: #ffffff !important;' : '' ?>"
             href="<?= e($href . '?' . $qs) ?>">
            <?= e($label) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-warning py-2 shadow-sm rounded-3" role="alert">
      <?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Date Filter Card -->
  <form method="get" action="<?= e($path) ?>" class="card card-body border-0 shadow-sm p-3 mb-4 rounded-3">
    <div class="row g-3 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label small fw-semibold text-secondary mb-1" for="range">Period</label>
        <select class="form-select form-select-sm shadow-none" id="range" name="range">
          <?php foreach (AnalyticsRange::PRESETS as $k => $l): ?>
            <option value="<?= e($k) ?>"<?= $range->preset === $k ? ' selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold text-secondary mb-1" for="from">From</label>
        <input type="date" class="form-control form-control-sm shadow-none" id="from" name="from" value="<?= e($range->from->format('Y-m-d')) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold text-secondary mb-1" for="to">To</label>
        <input type="date" class="form-control form-control-sm shadow-none" id="to" name="to" value="<?= e($range->to->format('Y-m-d')) ?>">
      </div>
      <div class="col-6 col-md-2">
        <button class="btn btn-primary btn-sm w-100 shadow-sm py-1.5" type="submit">Apply</button>
      </div>
      <div class="col-12 col-md-3 small text-secondary">
        Showing <strong><?= e($range->label()) ?></strong> (<?= (int) $range->days() ?> day<?= $range->days() === 1 ? '' : 's' ?>, clinic local time).
      </div>
    </div>
  </form>