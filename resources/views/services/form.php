<?php
use App\Helpers\Csrf;
$isEdit = $service !== null;
$val = static fn (string $k) => $old[$k] ?? ($service[$k] ?? '');
?>
<div class="row justify-content-center"><div class="col-lg-7">
<h1 class="h4 text-brand mb-3"><?= $isEdit ? 'Edit service' : 'New service' ?></h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card"><div class="card-body">
<form method="post" action="<?= $isEdit ? '/admin/services/' . (int) $service['id'] : '/admin/services' ?>" novalidate>
  <?= Csrf::field() ?>
  <div class="mb-3"><label for="name" class="form-label">Service name</label>
    <input type="text" class="form-control" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="120" required></div>
  <div class="mb-3"><label for="description" class="form-label">Description (optional)</label>
    <input type="text" class="form-control" id="description" name="description" value="<?= e($val('description')) ?>" maxlength="255"></div>
  <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create service' ?></button>
  <a href="/admin/services" class="btn btn-link">Cancel</a>
</form></div></div>
</div></div>
