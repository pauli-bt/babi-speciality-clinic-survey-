<?php use App\Helpers\Csrf; ?>
<div class="card shadow-sm"><div class="card-body p-4">
  <h2 class="h5 mb-3">Set a new password</h2>
  <?php if ($errors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($valid): ?>
  <form method="post" action="/reset-password" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="mb-3"><label for="new_password" class="form-label">New password</label>
      <input type="password" class="form-control" id="new_password" name="new_password" autocomplete="new-password" required>
      <div class="form-text">At least 12 characters with letters and numbers.</div></div>
    <div class="mb-3"><label for="confirm_password" class="form-label">Repeat new password</label>
      <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required></div>
    <button type="submit" class="btn btn-primary w-100">Save password</button>
  </form>
  <?php else: ?><a href="/login" class="btn btn-outline-primary">Back to sign in</a><?php endif; ?>
</div></div>
