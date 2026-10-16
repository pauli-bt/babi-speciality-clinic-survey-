<?php use App\Helpers\Channel; use App\Services\AppointmentManager as AM;
$badge = ['requested' => 'info', 'confirmed' => 'primary', 'completed' => 'success', 'cancelled' => 'secondary', 'no_show' => 'danger'];
$qs = static fn (int $p) => '/appointments?' . http_build_query(array_filter($filters, static fn ($v) => $v !== '') + ['page' => $p]);
?>
<h1 class="h4 text-brand mb-3">Appointments</h1>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="get" action="/appointments" class="row g-2 mb-3 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label small mb-0" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All</option>
    <?php foreach (AM::STATUS_LABELS as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0" for="service_id">Service</label><select class="form-select" id="service_id" name="service_id"><option value="">All</option>
    <?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>"<?= $filters['service_id'] === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0" for="channel">Booked via</label><select class="form-select" id="channel" name="channel"><option value="">All</option>
    <?php foreach (Channel::LABELS as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['channel'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">From</label><input type="date" class="form-control" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">To</label><input type="date" class="form-control" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0" for="q"><?= $canNames ? 'Name or code' : 'Customer code' ?></label><input type="text" class="form-control" id="q" name="q" value="<?= e($filters['q']) ?>" maxlength="60"></div>
  <div class="col-12"><button class="btn btn-primary" type="submit">Filter</button> <a class="btn btn-link" href="/appointments">Reset</a></div>
</form>
<p class="small text-secondary"><?= e($total) ?> appointment(s) &middot; page <?= e($page) ?> of <?= e($pages) ?> &middot; times are clinic local time</p>
<?php if (!$rows): ?><div class="card"><div class="card-body text-center text-secondary py-4">No appointments match.</div></div><?php else: ?>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>When</th><th>Customer</th><th>Service</th><th>Booked via</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td class="small text-nowrap"><?= $r['appointment_at'] ? e(substr((string) $r['appointment_at'], 0, 16)) : '<span class="text-secondary">Not scheduled</span>' ?></td>
  <td><?= $canNames ? e($r['customer_name'] ?? '(no name)') . ' ' : '' ?><code class="small"><?= e($r['customer_code']) ?></code></td>
  <td><?= e($r['service_name']) ?></td>
  <td class="small"><?= e($r['origin_channel'] ? Channel::LABELS[$r['origin_channel']] : '-') ?></td>
  <td><span class="badge text-bg-<?= $badge[$r['status']] ?>"><?= e(AM::STATUS_LABELS[$r['status']]) ?></span></td>
  <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/appointments/<?= (int) $r['id'] ?>">Open</a></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if ($pages > 1): ?><nav class="mt-3" aria-label="Pages"><ul class="pagination pagination-sm flex-wrap">
<?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?><li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e($qs($p)) ?>"><?= $p ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
<?php endif; ?>
