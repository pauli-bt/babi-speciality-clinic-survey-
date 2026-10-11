<?php
use App\Helpers\Csrf;
$isEdit = $target !== null;
$val = static fn (string $k, string $fallback = '') => $old[$k] ?? ($isEdit ? ($target[$k] ?? $fallback) : $fallback);
$selRole = (int) ($old['role_id'] ?? ($target['role_id'] ?? 0));
?>
<div class="row justify-content-center"><div class="col-lg-7">
<h1 class="h4 text-brand mb-3"><?= $isEdit ? 'Edit user' : 'New user' ?></h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card mb-3"><div class="card-body">
<form method="post" action="<?= $isEdit ? '/admin/users/' . (int) $target['id'] : '/admin/users' ?>" novalidate>
  <?= Csrf::field() ?>
  <div class="mb-3"><label for="name" class="form-label">Full name</label>
    <input type="text" class="form-control" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="120" required></div>
  <div class="mb-3"><label for="username" class="form-label">Username</label>
    <?php if ($isEdit): ?><input type="text" class="form-control" id="username" value="<?= e($target['username']) ?>" disabled aria-describedby="unhelp"><div id="unhelp" class="form-text">Usernames cannot be changed.</div>
    <?php else: ?><input type="text" class="form-control" id="username" name="username" value="<?= e($val('username')) ?>" maxlength="40" autocapitalize="none" required><div class="form-text">Lowercase letters, numbers, dot, dash, underscore.</div><?php endif; ?></div>
  <div class="mb-3"><label for="email" class="form-label">Email (optional)</label>
    <input type="email" class="form-control" id="email" name="email" value="<?= e($val('email')) ?>" maxlength="190"></div>
  <div class="mb-3"><label for="role_id" class="form-label">Role</label>
    <select class="form-select" id="role_id" name="role_id" required>
      <option value="">Choose a role</option>
      <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $selRole === (int) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
    </select>
    <?php if ($isEdit): ?><div class="form-text">Changing the role signs the user out everywhere so the new permissions apply at once.</div><?php endif; ?></div>
  <?php if (!$isEdit): ?>
  <div class="mb-3"><label for="password" class="form-label">Temporary password (optional)</label>
    <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" aria-describedby="pwh">
    <div id="pwh" class="form-text">Leave blank to generate one. The user must change it at first sign-in.</div></div>
  <?php endif; ?>
  <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
  <a href="/admin/users" class="btn btn-link">Cancel</a>
</form></div></div>
<?php if ($isEdit): ?>
<div class="card"><div class="card-header bg-white fw-semibold">Account actions</div><div class="card-body d-flex flex-wrap gap-2">
  <form method="post" action="/admin/users/<?= (int) $target['id'] ?>/active" data-confirm="<?= (int) $target['is_active'] === 1 ? 'Deactivate this account? The user will be signed out immediately.' : 'Reactivate this account?' ?>">
    <?= Csrf::field() ?>
    <button class="btn <?= (int) $target['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= (int) $target['is_active'] === 1 ? 'Deactivate account' : 'Reactivate account' ?></button>
  </form>
  <?php if ((int) $target['is_active'] === 1): ?>
  <form method="post" action="/admin/users/<?= (int) $target['id'] ?>/reset" data-confirm="Issue a password reset link? The user will be signed out and their old password stays valid only until the link is used.">
    <?= Csrf::field() ?><button class="btn btn-outline-primary" type="submit">Issue password reset link</button>
  </form>
  <?php endif; ?>
</div></div>
<?php endif; ?>
</div></div>
