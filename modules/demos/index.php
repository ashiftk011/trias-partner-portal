<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('demos');

$db = getDB();

$filterStatus  = $_GET['status'] ?? '';
$filterProject = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$filterSearch  = trim($_GET['q'] ?? '');

$sql = "SELECT d.*, pr.name as project_name,
               COALESCE(c.name, l.name) as contact_name,
               u.name as assigned_name,
               cb.name as created_by_name
        FROM demos d
        LEFT JOIN projects pr ON pr.id = d.project_id
        LEFT JOIN clients c ON c.id = d.client_id
        LEFT JOIN leads l ON l.id = d.lead_id
        LEFT JOIN users u ON u.id = d.assigned_to
        LEFT JOIN users cb ON cb.id = d.created_by
        WHERE 1=1";
$params = [];
if ($filterStatus)  { $sql .= " AND d.status=?";     $params[] = $filterStatus; }
if ($filterProject) { $sql .= " AND d.project_id=?"; $params[] = $filterProject; }
if ($filterSearch)  { $sql .= " AND (d.title LIKE ? OR d.demo_no LIKE ? OR COALESCE(c.name,l.name) LIKE ?)";
                      $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; }
$sql .= " ORDER BY d.scheduled_at DESC";

$stmt = $db->prepare($sql); $stmt->execute($params);
$demos = $stmt->fetchAll();

$projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
$counts   = $db->query("SELECT status, COUNT(*) cnt FROM demos GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

// Group upcoming demos for today
$todayDemos = array_filter($demos, fn($d) => date('Y-m-d', strtotime($d['scheduled_at'])) === date('Y-m-d') && $d['status'] === 'scheduled');

$pageTitle = 'Demos';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
  <div><h4 class="mb-0">Demo Scheduling</h4><p class="text-muted small mb-0">Track and schedule product demonstrations</p></div>
  <a href="<?= BASE_URL ?>/modules/demos/save.php" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>Schedule Demo
  </a>
</div>

<?php displayFlash(); ?>

<!-- Today's demos alert -->
<?php if ($todayDemos && !$filterStatus && !$filterProject && !$filterSearch): ?>
<div class="alert alert-info border-0 shadow-sm mb-3 d-flex align-items-center gap-3">
  <i class="bi bi-camera-video-fill fs-4"></i>
  <div>
    <strong><?= count($todayDemos) ?> demo<?= count($todayDemos) > 1 ? 's' : '' ?> scheduled today.</strong>
    <?php foreach ($todayDemos as $td): ?>
    <div class="small"><a href="<?= BASE_URL ?>/modules/demos/save.php?id=<?= $td['id'] ?>" class="text-decoration-none"><?= htmlspecialchars($td['title']) ?></a> at <?= date('h:i A', strtotime($td['scheduled_at'])) ?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Status Pills -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php
  $statusColors = ['scheduled'=>'primary','completed'=>'success','cancelled'=>'danger','rescheduled'=>'warning'];
  foreach ($statusColors as $st => $cls): ?>
  <a href="?status=<?= $st ?><?= $filterProject ? "&project_id=$filterProject" : '' ?>"
     class="badge bg-<?= $filterStatus===$st ? $cls : 'light text-dark' ?> text-decoration-none p-2 fs-6">
    <?= ucfirst($st) ?> <span class="ms-1"><?= $counts[$st] ?? 0 ?></span>
  </a>
  <?php endforeach; ?>
  <a href="?" class="badge <?= !$filterStatus ? 'bg-primary' : 'bg-light text-dark' ?> text-decoration-none p-2 fs-6">
    All <span class="ms-1"><?= array_sum($counts) ?></span>
  </a>
  <?php if ($filterStatus || $filterProject || $filterSearch): ?>
  <a href="?" class="badge bg-light text-dark text-decoration-none p-2 fs-6"><i class="bi bi-x-circle me-1"></i>Clear</a>
  <?php endif; ?>
</div>

<!-- Filter -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search title, demo no, contact..." value="<?= htmlspecialchars($filterSearch) ?>">
      </div>
      <div class="col-md-3">
        <select name="project_id" class="form-select form-select-sm">
          <option value="">All Projects</option>
          <?php foreach ($projects as $pr): ?>
          <option value="<?= $pr['id'] ?>" <?= $filterProject==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable">
        <thead class="table-light">
          <tr><th>No.</th><th>Title</th><th>Contact</th><th>Project</th><th>Type</th><th>Scheduled</th><th>Duration</th><th>Assigned To</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($demos as $d): ?>
          <?php
          $typeIconMap = ['online'=>'camera-video','onsite'=>'geo-alt','phone'=>'telephone'];
          $typeIcon = $typeIconMap[$d['demo_type']] ?? 'camera-video';
          $sc = ['scheduled'=>'primary','completed'=>'success','cancelled'=>'danger','rescheduled'=>'warning'][$d['status']] ?? 'secondary';
          $isPast = strtotime($d['scheduled_at']) < time() && $d['status'] === 'scheduled';
          ?>
          <tr class="<?= $isPast ? 'table-warning' : '' ?>">
            <td><small class="text-muted fw-semibold"><?= htmlspecialchars($d['demo_no'] ?? '') ?></small></td>
            <td>
              <a href="<?= BASE_URL ?>/modules/demos/save.php?id=<?= $d['id'] ?>" class="fw-semibold text-decoration-none">
                <?= htmlspecialchars($d['title']) ?>
              </a>
              <?php if ($isPast): ?><div class="small text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Overdue</div><?php endif; ?>
            </td>
            <td><small><?= htmlspecialchars($d['contact_name'] ?? '-') ?></small></td>
            <td><span class="badge bg-primary"><?= htmlspecialchars($d['project_name'] ?? '') ?></span></td>
            <td><small><i class="bi bi-<?= $typeIcon ?> me-1"></i><?= ucfirst($d['demo_type']) ?></small></td>
            <td>
              <div class="fw-semibold small"><?= date('d M Y', strtotime($d['scheduled_at'])) ?></div>
              <div class="text-muted small"><?= date('h:i A', strtotime($d['scheduled_at'])) ?></div>
            </td>
            <td><small class="text-muted"><?= $d['duration_mins'] ?> min</small></td>
            <td><small><?= htmlspecialchars($d['assigned_name'] ?? '-') ?></small></td>
            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($d['status']) ?></span></td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/modules/demos/save.php?id=<?= $d['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                <?php if ($d['meeting_link'] && $d['status'] === 'scheduled'): ?>
                <a href="<?= htmlspecialchars($d['meeting_link']) ?>" target="_blank" class="btn btn-outline-success" title="Join Meeting"><i class="bi bi-camera-video"></i></a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
