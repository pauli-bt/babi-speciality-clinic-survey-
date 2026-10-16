<div class="row justify-content-center"><div class="col-lg-8">
<div class="alert alert-success">Reset link issued for <strong><?= e($target['name']) ?></strong>.</div>
<div class="card"><div class="card-body">
  <p class="mb-2">Give this single-use link to the user. It expires in one hour and is shown only once.</p>
  <div class="form-control user-select-all text-break mb-3"><?= e($link) ?></div>
  <p class="small text-secondary">Any existing sessions for this user have been ended.</p>
  <a href="/admin/users" class="btn btn-primary">Back to users</a>
</div></div></div></div>
