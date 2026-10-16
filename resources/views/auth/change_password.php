<?php use App\Helpers\Csrf; ?>
<div class="row justify-content-center"><div class="col-md-7 col-lg-5">
<div class="card shadow-sm"><div class="card-body p-4">
  <h1 class="h5 mb-3">Change password</h1>
  <?php if ($forced): ?><div class="alert alert-warning">You must choose a new password before continuing.</div><?php endif; ?>
  <?php if ($errors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="post" action="/password/change" novalidate>
    <?= Csrf::field() ?>
    <div class="mb-3"><label for="current_password" class="form-label">Current password</label>
      <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password" required></div>
    <div class="mb-3"><label for="new_password" class="form-label">New password</label>
      <input type="password" class="form-control" id="new_password" name="new_password" autocomplete="new-password" required aria-describedby="pwhelp">
      <div id="pwhelp" class="form-text">At least 12 characters with letters and numbers. Avoid your username and common words.</div></div>
    <div class="mb-3"><label for="confirm_password" class="form-label">Repeat new password</label>
      <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required></div>
    <button type="submit" class="btn btn-primary">Update password</button>
  </form>
</div></div></div></div>
