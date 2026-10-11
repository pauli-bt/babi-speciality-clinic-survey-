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
<nav class="navbar navbar-expand-xl bg-white border-bottom">
  <div class="container-xl">
    <a class="navbar-brand d-flex align-items-center" href="/">
      <img src="/assets/images/babi-logo.jpg" alt="Babi Dental" width="40" height="40" class="logo-round me-2">
      <span class="text-brand fw-semibold">Babi Dental Analytics</span>
    </a>
    <?php if ($u): ?>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto">
        <?php foreach ($nav as [$label, $href]): ?>
          <li class="nav-item"><a class="nav-link<?= $path === $href ? ' active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <div class="d-flex align-items-lg-center flex-column flex-lg-row gap-2">
        <span class="small text-secondary" title="<?= e($u['role_name']) ?>"><?= e($u['name']) ?></span>
        <a class="btn btn-sm btn-outline-secondary" href="/password/change">Change password</a>
        <form method="post" action="/logout" class="m-0"><?= Csrf::field() ?><button class="btn btn-sm btn-outline-primary w-100" type="submit">Sign out</button></form>
      </div>
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
<script src="/assets/js/app.js"></script>
<script src="/assets/js/customer-picker.js"></script>
<script src="/assets/js/customer-form.js"></script>
<script src="/assets/js/interaction-form.js"></script>
</body>
</html>
