<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ?? 'Report') ?> | Babi Dental Analytics</title>
<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="print-body">
<div class="container-xl py-3">
  <div class="d-flex align-items-center mb-3">
    <img src="/assets/images/babi-logo.jpg" alt="Babi Dental" width="48" height="48" class="logo-round me-2">
    <div><div class="text-brand fw-semibold">Babi Speciality Dental Clinic</div><div class="small text-secondary">Customer acquisition &amp; marketing analytics</div></div>
    <div class="ms-auto no-print"><button type="button" class="btn btn-primary btn-sm" data-print>Print / Save as PDF</button></div>
  </div>
  <?= $content ?>
</div>
<script src="/assets/js/print.js"></script>
</body>
</html>
