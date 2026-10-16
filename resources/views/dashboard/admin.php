<div class="container-fluid px-4 px-xl-5 py-4">
  <!-- Dashboard Header -->
  <div class="mb-4">
    <h1 class="h4 fw-bold text-brand mb-1">Administrator dashboard</h1>
    <p class="text-muted small mb-0">System metrics, user roles, and operational status.</p>
  </div>

  <!-- Top Metric Cards Row 1 -->
  <div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-4">
      <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
        <div class="card-body">
          <div class="text-secondary small fw-semibold text-uppercase mb-1">Customers recorded</div>
          <div class="h3 mb-0 fw-bold text-dark"><?= e($customers) ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-4">
      <div class="card border-0 shadow-sm h-100 border-start border-info border-4">
        <div class="card-body">
          <div class="text-secondary small fw-semibold text-uppercase mb-1">Interactions recorded</div>
          <div class="h3 mb-0 fw-bold text-dark"><?= e($interactions) ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-4">
      <div class="card border-0 shadow-sm h-100 border-start border-warning border-4">
        <div class="card-body">
          <div class="text-secondary small fw-semibold text-uppercase mb-1">Audit events (24 h)</div>
          <div class="h3 mb-0 fw-bold text-dark"><?= e($auditToday) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Top Metric Cards Row 2 (With exact original clickable links) -->
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
      <a class="text-decoration-none" href="/appointments?status=requested">
        <div class="card border-0 shadow-sm h-100 hover-card">
          <div class="card-body">
            <div class="text-secondary small fw-semibold text-uppercase mb-1">Open appointments</div>
            <div class="h3 mb-0 fw-bold text-dark"><?= e($apptOpen) ?></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-sm-6 col-lg-3">
      <a class="text-decoration-none" href="/appointments?status=confirmed">
        <div class="card border-0 shadow-sm h-100 hover-card">
          <div class="card-body">
            <div class="text-secondary small fw-semibold text-uppercase mb-1">Confirmed, time passed (needs closing)</div>
            <div class="h3 mb-0 fw-bold text-dark"><?= e($apptDue) ?></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-sm-6 col-lg-3">
      <a class="text-decoration-none" href="/campaigns?status=active">
        <div class="card border-0 shadow-sm h-100 hover-card">
          <div class="card-body">
            <div class="text-secondary small fw-semibold text-uppercase mb-1">Active campaigns</div>
            <div class="h3 mb-0 fw-bold text-dark"><?= e($campaignsActive) ?></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-sm-6 col-lg-3">
      <a class="text-decoration-none" href="/admin/services">
        <div class="card border-0 shadow-sm h-100 hover-card">
          <div class="card-body">
            <div class="text-secondary small fw-semibold text-uppercase mb-1">Active services</div>
            <div class="h3 mb-0 fw-bold text-dark"><?= e($servicesActive) ?></div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <!-- Lower Section: Tables & Build Status -->
  <div class="row g-4">
    <!-- User Accounts by Role (Exact original $roleCounts loop and routes) -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white py-3 border-bottom fw-semibold">User accounts by role</div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light text-muted small">
              <tr>
                <th class="ps-3">Role</th>
                <th class="text-end">Active</th>
                <th class="text-end pe-3">Inactive</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($roleCounts as $r): ?>
                <tr>
                  <td class="ps-3 fw-medium"><?= e($r['name']) ?></td>
                  <td class="text-end">
                    <span class="badge bg-success-subtle text-success px-2 py-1"><?= e($r['active'] ?? 0) ?></span>
                  </td>
                  <td class="text-end pe-3 text-muted"><?= e($r['inactive'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer bg-white py-3 border-top d-flex gap-2">
          <a href="/admin/users" class="btn btn-sm btn-primary px-3">Manage users</a> 
          <a href="/admin/audit" class="btn btn-sm btn-outline-primary px-3">Audit log</a>
        </div>
      </div>
    </div>

    <!-- Build Status (Exact original content & links) -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white py-3 border-bottom fw-semibold">Build status</div>
        <div class="card-body small p-4">
          <p class="mb-3">
            <span class="badge text-bg-success px-2 py-1">Available</span> Sign-in, roles, users, audit log, customers, questionnaires, services, campaigns, appointments, <a href="/analytics" class="text-decoration-none fw-medium">analytics</a>.
          </p>
          <p class="mb-0">
            <span class="badge text-bg-secondary px-2 py-1">Not built yet</span> Exports and reports.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>