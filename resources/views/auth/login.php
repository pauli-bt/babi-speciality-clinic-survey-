<?php use App\Helpers\Csrf; ?>
<div class="card shadow-sm"><div class="card-body p-4">
  <h2 class="h5 mb-3">Sign in</h2>
  <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/login" autocomplete="on" novalidate>
    <?= Csrf::field() ?>
    <div class="mb-3">
      <label for="username" class="form-label">Username</label>
      <input type="text" class="form-control" id="username" name="username" value="<?= e($username) ?>" maxlength="80" autocomplete="username" autocapitalize="none" required autofocus>
    </div>
    <div class="mb-3">
      <label for="password" class="form-label">Password</label>
      <input type="password" class="form-control" id="password" name="password" maxlength="200" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn btn-primary w-100">Sign in</button>
  </form>
  <p class="small text-secondary mt-3 mb-0">Forgotten your password? Ask an administrator for a reset link.</p>
</div></div>
