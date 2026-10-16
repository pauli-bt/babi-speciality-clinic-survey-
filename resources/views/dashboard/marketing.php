<h1 class="h4 text-brand">Marketing dashboard</h1>
<p class="text-secondary">Aggregate analytics only. This role never sees customer names, phone numbers or individual records.</p>
<div class="card"><div class="card-body">
  <span class="badge text-bg-secondary mb-2">Not built yet</span>
  <p class="mb-0">Charts and metrics are not built yet. No figures are shown until real data exists.</p>
</div></div>
<?php if (\App\Services\Auth::can('campaigns.view')): ?><p class="mt-3"><a class="btn btn-outline-primary" href="/campaigns">View campaigns</a></p><?php endif; ?>
