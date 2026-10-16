<?php /** @var string $content */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ?? 'Babi Dental') ?> | Babi Speciality Dental Clinic</title>
<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="auth-body">
<main class="container auth-wrap">
  <div class="text-center mb-4">
    <img src="/assets/images/babi-logo.png" alt="Babi Dental" width="96" height="96" class="logo-round">
    <h1 class="h5 mt-3 mb-0 text-brand">Babi Speciality Dental Clinic</h1>
    <p class="text-secondary small mb-0">Customer acquisition &amp; marketing analytics</p>
  </div>
  <?php foreach (\App\Helpers\Http::pullFlash() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" role="alert"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<script src="/assets/js/app.js"></script>
</body>
</html>
