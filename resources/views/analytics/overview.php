<?php
use App\Services\Analytics as A;
require __DIR__ . '/_head.php';
$kpi = static function (string $label, $value, string $note, ?int $prev = null): void { require __DIR__ . '/_kpi.php'; };
?>

<div class="row g-3 mb-4">
  <?php $kpi('Total customers', $totalCustomers, 'All customers on record today (merged duplicates excluded). Not period-based.'); ?>
  <?php $kpi('New customers acquired', $new, 'First-ever contact falls in the period (acquisition date).', $newPrev); ?>
  <?php $kpi('Interactions recorded', $int['total'], $int['customers'] . ' distinct customers. Inquiries/visits, not appointments.', $intPrev['total']); ?>
  <?php $kpi('Appointment bookings', $bookings, 'Appointments booked in the period (counted once each).', $bookingsPrev); ?>
</div>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <p class="small text-secondary mb-0">
      The four cards below show the <strong>current status</strong> of the appointments booked in this period. A booking is not a completed visit, and a confirmed appointment is not a completed one.
    </p>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php $kpi('Confirmed', $status['confirmed'], 'Booked in period, currently confirmed (not yet closed).'); ?>
  <?php $kpi('Completed', $status['completed'], 'Booked in period, marked completed.'); ?>
  <?php $kpi('Cancelled', $status['cancelled'], 'Booked in period, cancelled.'); ?>
  <?php $kpi('No-show', $status['no_show'], 'Booked in period, marked no-show.'); ?>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white py-3 border-bottom fw-semibold">New customers over time</div>
      <div class="card-body"><?= chart_canvas(['type' => 'bar', 'labels' => $newSeries['labels'], 'datasets' => [['label' => 'New customers', 'data' => $newSeries['values']]]], 'New customers over time', 240) ?></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white py-3 border-bottom fw-semibold">Interactions and bookings over time</div>
      <div class="card-body"><?= chart_canvas(['type' => 'line', 'labels' => $intSeries['labels'], 'datasets' => [['label' => 'Interactions', 'data' => $intSeries['values'], 'color' => '#173b77'], ['label' => 'Appointment bookings', 'data' => $bookSeries['values'], 'color' => '#6c9be0']]], 'Interactions and bookings over time', 240) ?></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white py-3 border-bottom fw-semibold">What stands out</div>
  <ul class="list-group list-group-flush small">
    <li class="list-group-item py-3"><?= $topSource ? 'Top acquisition source: <strong>' . e($topSource['name']) . '</strong> with ' . (int) $topSource['n'] . ' of ' . (int) $new . ' new customers (' . e(pct(A::rate((int) $topSource['n'], $new))) . ').' : 'No new customers were acquired in this period.' ?></li>
    <li class="list-group-item py-3"><?= $busiest ? 'Busiest channel: <strong>' . e($channelLabels[$busiest]) . '</strong> with ' . (int) $busiestN . ' of ' . (int) $int['total'] . ' interactions (see Channels for the split).' : 'No interactions were recorded in this period.' ?></li>
    <li class="list-group-item py-3">Of closed appointments booked in the period (<?= (int) $rates['closed'] ?>): completion <?= e(pct($rates['completion'])) ?>, cancellation <?= e(pct($rates['cancellation'])) ?>, no-show <?= e(pct($rates['no_show'])) ?>. <?= (int) $rates['open'] ?> still open. Rates are n/a when nothing has closed yet.</li>
    <li class="list-group-item py-3 text-secondary">Comparison uses the equally long period before this one (<?= e($prevLabel) ?>). Status cards are not compared: older bookings have had longer to close, so the comparison would mislead.</li>
  </ul>
</div>

<?php if ($quality !== null): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white py-3 border-bottom fw-semibold">Data quality (administrators)</div>
  <ul class="list-group list-group-flush small">
    <li class="list-group-item py-3"><?= (int) $quality['needs_review'] ?> interaction(s) in the period are flagged "needs review".</li>
    <li class="list-group-item py-3"><?= (int) $quality['campaign_without_id'] ?> campaign-response interaction(s) in the period have no campaign selected, so they cannot appear in campaign analytics.</li>
    <li class="list-group-item py-3"><?= (int) $quality['confirmed_past'] ?> confirmed appointment(s) have a time in the past and have not been closed (<a href="/appointments?status=confirmed">review</a>).</li>
    <li class="list-group-item py-3"><?= (int) $quality['stale_requested'] ?> requested appointment(s) older than 7 days still have no scheduled time.</li>
    <li class="list-group-item py-3"><?= (int) $quality['no_origin'] ?> appointment(s) have no originating interaction (booking date falls back to their creation time, channel shown as "none").</li>
  </ul>
</div>
<?php endif; ?>

</div><!-- Close container from _head.php -->