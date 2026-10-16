<?php use App\Helpers\Csrf; ?>
<div class="row justify-content-center"><div class="col-lg-6">
<h1 class="h4 text-brand mb-1">Correct customer details</h1>
<p class="text-secondary"><code><?= e($c['customer_code']) ?></code>. This changes the name and phone only; the original acquisition source cannot be edited here.</p>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card"><div class="card-body"><form method="post" action="/customers/<?= (int) $c['id'] ?>/edit" novalidate>
  <?= Csrf::field() ?>
  <div class="mb-3"><label for="name" class="form-label">Name</label><input type="text" class="form-control" id="name" name="name" maxlength="150" value="<?= e($old['name'] ?? $c['name'] ?? '') ?>"></div>
  <div class="mb-3"><label for="phone" class="form-label">Telephone</label><input type="tel" class="form-control" id="phone" name="phone" maxlength="30" value="<?= e($old['phone'] ?? $c['phone'] ?? '') ?>"></div>
  <button class="btn btn-primary" type="submit">Save</button> <a class="btn btn-link" href="/customers/<?= (int) $c['id'] ?>">Cancel</a>
</form></div></div></div></div>
