<?php
use App\Helpers\Csrf;
use App\Services\Auth;
/** @var string $content */
$u = Auth::user();
$path = \App\Helpers\Http::path();
$selected = ($u && Auth::can('customers.search')) ? \App\Services\CustomerSelection::current() : null;
$nav = [];
if ($u) {
    $home = ['admin' => '/admin', 'receptionist' => '/reception', 'call_operator' => '/call-operator', 'social_tracker' => '/social-media', 'marketing' => '/marketing'][$u['role_code']] ?? '/';
    $nav[] = ['Dashboard', $home];
    if (Auth::can('customers.search')) { $nav[] = ['Customers', '/customers']; }
    if (Auth::can('interactions.view_own')) { $nav[] = ['Interactions', '/interactions']; }
    $ownCh = \App\Helpers\Channel::forRole($u['role_code']);
    if ($ownCh && Auth::can('interactions.create')) { $nav[] = ['New interaction', \App\Helpers\Channel::formPath($ownCh)]; }
    if (Auth::can('analytics.marketing')) { $nav[] = ['Analytics', '/analytics']; }
    if (Auth::can('exports.aggregate')) { $nav[] = ['Reports', '/reports']; }
    if (Auth::can('appointments.manage')) { $nav[] = ['Appointments', '/appointments']; }
    if (Auth::can('campaigns.view')) { $nav[] = ['Campaigns', '/campaigns']; }
    if (Auth::can('services.manage')) { $nav[] = ['Services', '/admin/services']; }
    if (Auth::can('users.manage')) { $nav[] = ['Users', '/admin/users']; }
    if (Auth::can('auditlogs.view')) { $nav[] = ['Audit log', '/admin/audit']; }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ?? 'Babi Dental') ?> | Babi Dental Analytics</title>
<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>

<nav class="navbar navbar-expand-xl bg-white border-bottom py-2">
  <div class="container-fluid px-4 px-xl-5">
    <!-- Brand / Logo (Left) -->
    <a class="navbar-brand d-flex align-items-center py-1 me-0" href="/">
      <img src="/assets/images/babi-logo.png" alt="Babi Dental" width="34" height="34" class="logo-round me-2">
      <span class="text-brand fw-semibold">Babi Dental Analytics</span>
    </a>

    <?php if (!empty($u)): ?>
      <!-- Mobile Toggler -->
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <!-- Collapsible Container -->
      <div class="collapse navbar-collapse" id="mainNav">
        <!-- Main Nav Links (Center Aligned using mx-auto) -->
        <ul class="navbar-nav mx-auto mb-2 mb-xl-0 gap-xl-1">
          <?php foreach ($nav as [$label, $href]): ?>
            <li class="nav-item">
              <a class="nav-link px-2 py-1<?= ($path === $href) ? ' active fw-bold text-primary' : '' ?>" href="<?= e($href) ?>">
                <?= e($label) ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>

        <!-- Right Side: User Dropdown Menu (Right) -->
        <ul class="navbar-nav mb-0 align-items-xl-center">
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-1 py-1 px-2 rounded bg-light border text-dark small" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-person-circle text-primary"></i>
              <span class="fw-medium"><?= e($u['name'] ?? 'User') ?></span>
              <span class="text-muted small ms-1">(<?= e($u['role_name'] ?? 'User') ?>)</span>
            </a>
            
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-1" aria-labelledby="userDropdown">
              <li>
                <div class="dropdown-item-text text-muted small pb-1 border-bottom mb-2">
                  Signed in as <strong><?= e($u['name']) ?></strong>
                </div>
              </li>
              <li>
                <a class="dropdown-item py-1.5" href="/password/change">
                  <i class="bi bi-key me-2 text-secondary"></i>Change password
                </a>
              </li>
              <li><hr class="dropdown-divider my-1"></li>
              <li>
                <form method="post" action="/logout" class="m-0 p-0">
                  <?= Csrf::field() ?>
                  <button class="dropdown-item py-1.5 text-danger" type="submit">
                    <i class="bi bi-box-arrow-right me-2"></i>Sign out
                  </button>
                </form>
              </li>
            </ul>
          </li>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</nav>
<main class="container-xl py-4">
  <?php foreach (\App\Helpers\Http::pullFlash() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" role="alert"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?php if ($selected): ?>
    <div class="alert alert-primary d-flex flex-wrap justify-content-between align-items-center gap-2 py-2" role="status">
      <span>Selected customer: <strong><?= e($selected['name'] ?? '(no name recorded)') ?></strong> <code><?= e($selected['customer_code']) ?></code></span>
      <span class="d-flex gap-2"><a class="btn btn-sm btn-outline-primary" href="/customers/<?= (int) $selected['id'] ?>">View</a>
      <form method="post" action="/customers/selection/clear" class="m-0"><?= Csrf::field() ?><button class="btn btn-sm btn-outline-secondary" type="submit">Clear</button></form></span>
    </div>
  <?php endif; ?>
  <?= $content ?>
</main>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<?php if (!empty($useCharts)): ?><script src="/assets/vendor/chartjs/chart.umd.min.js"></script><script src="/assets/js/analytics.js"></script><?php endif; ?>
<script src="/assets/js/app.js"></script>
<script src="/assets/js/customer-picker.js"></script>
<script src="/assets/js/customer-form.js"></script>
<script src="/assets/js/interaction-form.js"></script>
</body>
</html>
