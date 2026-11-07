<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ?? 'Report') ?> | Babi Dental Analytics</title>
<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/print.css">
</head>
<body class="print-body">
<div class="print-toolbar no-print">
  <div class="print-toolbar-inner">
    <span class="print-hint">To save a PDF: click the button, choose <strong>Save as PDF</strong>, paper <strong>A4</strong>, and turn off <strong>Headers and footers</strong>.</span>
    <button type="button" class="btn btn-primary btn-sm" data-print>Print / Save as PDF</button>
  </div>
</div>
<div class="sheet"><?= $content ?></div>
<script src="/assets/js/print.js"></script>
</body>
</html>
