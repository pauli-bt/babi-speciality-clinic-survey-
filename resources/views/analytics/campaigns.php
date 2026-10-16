<?php use App\Services\{Analytics as A, CampaignManager}; require __DIR__ . '/_head.php';
$names = array_map(static fn ($c) => $c['name'], array_slice($campaigns, 0, 10));
?>
<div class="alert alert-info small">
  <strong>No cost or revenue data is stored,</strong> so advertising ROI, cost per acquisition and revenue cannot be calculated. Only responses, customers and appointment bookings are reported.
  A <em>response</em> is an interaction where staff selected the campaign. <em>New customers</em> are customers whose first-ever contact was that campaign (original attribution); the rest are returning customers (re-engagement). Interactions with no campaign selected are unknown, not &ldquo;non-campaign&rdquo;.
</div>
<div class="card mb-3"><div class="card-header bg-white fw-semibold">Responses per campaign (top 10)</div><div class="card-body"><?= chart_canvas(['type' => 'bar', 'horizontal' => true, 'stacked' => true, 'labels' => $names, 'datasets' => [
  ['label' => 'From new customers', 'data' => array_map(static fn ($c) => (int) $c['first_contact'], array_slice($campaigns, 0, 10)), 'color' => '#173b77'],
  ['label' => 'From returning customers', 'data' => array_map(static fn ($c) => (int) $c['returning_resp'], array_slice($campaigns, 0, 10)), 'color' => '#7aa5e6']]], 'Campaign responses split by new and returning customers', 340) ?></div></div>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Campaign</th><th>Platform</th><th>Status</th><th class="text-end">Responses</th><th class="text-end">Distinct customers</th><th class="text-end">New customers (original)</th><th class="text-end">Returning responses</th><th class="text-end">Booking rate</th><th class="text-end">Appointments</th><th class="text-end">Open</th><th class="text-end">Completed</th><th class="text-end">Cancelled</th><th class="text-end">No-show</th></tr></thead><tbody>
  <?php foreach ($campaigns as $c): $o = A::outcomeRates($c['appts']); ?><tr>
    <td><?= e($c['name']) ?></td><td><?= e($c['platform'] ?? 'Offline / none') ?></td><td><?= e(CampaignManager::STATUSES[$c['status']]) ?></td>
    <td class="text-end"><?= (int) $c['responses'] ?></td><td class="text-end"><?= (int) $c['customers'] ?></td><td class="text-end"><?= (int) $c['acquired'] ?></td><td class="text-end"><?= (int) $c['returning_resp'] ?></td>
    <td class="text-end"><?= e(pct($c['booking_rate'])) ?> <span class="text-secondary small">(<?= (int) $c['with_booking'] ?>/<?= (int) $c['booking_decided'] ?>)</span></td>
    <td class="text-end"><?= (int) $c['bookings'] ?></td><td class="text-end"><?= (int) $o['open'] ?></td><td class="text-end"><?= (int) $c['appts']['completed'] ?></td><td class="text-end"><?= (int) $c['appts']['cancelled'] ?></td><td class="text-end"><?= (int) $c['appts']['no_show'] ?></td></tr><?php endforeach; ?>
  <?php if (!$campaigns): ?><tr><td colspan="13" class="text-center text-secondary py-3">No campaign activity in this period.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card-footer bg-white small text-secondary">Booking rate = responses that ended in a requested/confirmed appointment &divide; responses with a recorded booking outcome (telephone and DM only; reception responses have none). Appointments = appointments created by those responses (each counted once) shown by current status. Draft campaigns with no activity are hidden.</div></div>
