<div class="row justify-content-center"><div class="col-lg-7">
<div class="alert alert-success">User <strong><?= e($created['name']) ?></strong> was created.</div>
<div class="card"><div class="card-body">
  <dl class="row mb-3"><dt class="col-sm-4">Username</dt><dd class="col-sm-8"><code><?= e($created['username']) ?></code></dd>
  <dt class="col-sm-4">Role</dt><dd class="col-sm-8"><?= e($created['role_name']) ?></dd>
  <?php if ($password !== ''): ?><dt class="col-sm-4">Temporary password</dt><dd class="col-sm-8"><code><?= e($password) ?></code></dd><?php endif; ?></dl>
  <?php if ($password !== ''): ?><div class="alert alert-warning small">This password is shown only once. Pass it to the user securely. They must change it at first sign-in.</div>
  <?php else: ?><p class="small text-secondary">The user must change the password you set at first sign-in.</p><?php endif; ?>
  <a href="/admin/users" class="btn btn-primary">Back to users</a>
</div></div></div></div>
