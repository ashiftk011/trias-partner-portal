<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('demos');

$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);
$demo = null;

if ($id) {
    $stmt = $db->prepare("SELECT * FROM demos WHERE id=?");
    $stmt->execute([$id]);
    $demo = $stmt->fetch();
    if (!$demo) { setFlash('error','Demo not found.'); redirect(BASE_URL.'/modules/demos/index.php'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title        = trim($_POST['title'] ?? '');
    $project_id   = (int)($_POST['project_id'] ?? 0);
    $lead_id      = $_POST['lead_id']    ? (int)$_POST['lead_id']    : null;
    $client_id    = $_POST['client_id']  ? (int)$_POST['client_id']  : null;
    $assigned_to  = $_POST['assigned_to']? (int)$_POST['assigned_to']: null;
    $demo_type    = $_POST['demo_type']  ?? 'online';
    $scheduled_at = trim($_POST['scheduled_date'] ?? '') . ' ' . trim($_POST['scheduled_time'] ?? '00:00');
    $duration     = (int)($_POST['duration_mins'] ?? 60);
    $meeting_link = trim($_POST['meeting_link'] ?? '');
    $location     = trim($_POST['location'] ?? '');
    $status       = $_POST['status'] ?? 'scheduled';
    $notes        = trim($_POST['notes'] ?? '');

    if (!$title || !$project_id || !$_POST['scheduled_date']) {
        setFlash('error', 'Title, Project, and Scheduled Date are required.');
        redirect(BASE_URL.'/modules/demos/save.php'.($id ? "?id=$id" : ''));
    }

    if ($id) {
        $db->prepare("UPDATE demos SET title=?,project_id=?,lead_id=?,client_id=?,assigned_to=?,
            demo_type=?,scheduled_at=?,duration_mins=?,meeting_link=?,location=?,status=?,notes=? WHERE id=?")
           ->execute([$title,$project_id,$lead_id,$client_id,$assigned_to,
                      $demo_type,$scheduled_at,$duration,$meeting_link,$location,$status,$notes,$id]);
        setFlash('success','Demo updated.');
    } else {
        $last = $db->query("SELECT demo_no FROM demos ORDER BY id DESC LIMIT 1")->fetchColumn();
        $num  = $last && preg_match('/DMO-(\d+)/', $last, $m) ? (int)$m[1]+1 : 1;
        $no   = 'DMO-'.str_pad($num, 4, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO demos (demo_no,title,project_id,lead_id,client_id,assigned_to,demo_type,
            scheduled_at,duration_mins,meeting_link,location,status,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$no,$title,$project_id,$lead_id,$client_id,$assigned_to,$demo_type,
                      $scheduled_at,$duration,$meeting_link,$location,$status,$notes,$user['id']]);
        $id = (int)$db->lastInsertId();
        setFlash('success',"Demo $no scheduled.");
    }
    redirect(BASE_URL.'/modules/demos/save.php?id='.$id);
}

$projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
$leads    = $db->query("SELECT id,name,company FROM leads ORDER BY name")->fetchAll();
$clients  = $db->query("SELECT id,name,company FROM clients ORDER BY name")->fetchAll();
$agents   = $db->query("SELECT id,name FROM users WHERE status='active' ORDER BY name")->fetchAll();

$d = $demo ?? ['title'=>'','project_id'=>'','lead_id'=>'','client_id'=>'','assigned_to'=>'',
     'demo_type'=>'online','scheduled_at'=>'','duration_mins'=>60,'meeting_link'=>'',
     'location'=>'','status'=>'scheduled','notes'=>''];

$scheduledDate = $d['scheduled_at'] ? date('Y-m-d', strtotime($d['scheduled_at'])) : '';
$scheduledTime = $d['scheduled_at'] ? date('H:i',   strtotime($d['scheduled_at'])) : '';

$pageTitle = $id ? 'Edit Demo' : 'Schedule Demo';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/demos/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0"><?= $id ? 'Edit Demo' : 'Schedule a Demo' ?></h4>
    <p class="text-muted small mb-0"><?= $id ? htmlspecialchars($demo['demo_no'] ?? '') : 'Set up a product demonstration' ?></p>
  </div>
</div>

<?php displayFlash(); ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

  <div class="row g-4">
    <div class="col-xl-8">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-camera-video-fill me-2 text-primary"></i>Demo Details</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small fw-semibold">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required value="<?= htmlspecialchars($d['title']) ?>" placeholder="e.g. SEO Platform Demo with ABC Corp">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Project <span class="text-danger">*</span></label>
              <select name="project_id" class="form-select" required>
                <option value="">Select Project</option>
                <?php foreach ($projects as $pr): ?>
                <option value="<?= $pr['id'] ?>" <?= $d['project_id']==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Demo Type</label>
              <select name="demo_type" class="form-select">
                <option value="online"  <?= $d['demo_type']==='online' ?'selected':'' ?>>🌐 Online (Video Call)</option>
                <option value="onsite"  <?= $d['demo_type']==='onsite' ?'selected':'' ?>>📍 On-site Visit</option>
                <option value="phone"   <?= $d['demo_type']==='phone'  ?'selected':'' ?>>📞 Phone Call</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Date <span class="text-danger">*</span></label>
              <input type="date" name="scheduled_date" class="form-control" required value="<?= $scheduledDate ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Time</label>
              <input type="time" name="scheduled_time" class="form-control" value="<?= $scheduledTime ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Duration (minutes)</label>
              <input type="number" name="duration_mins" class="form-control" value="<?= $d['duration_mins'] ?>" min="15" step="15">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Link to Client</label>
              <select name="client_id" class="form-select select2">
                <option value="">Not linked</option>
                <?php foreach ($clients as $cl): ?>
                <option value="<?= $cl['id'] ?>" <?= $d['client_id']==$cl['id']?'selected':'' ?>><?= htmlspecialchars($cl['name'] . ($cl['company'] ? ' — '.$cl['company'] : '')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Link to Lead</label>
              <select name="lead_id" class="form-select select2">
                <option value="">Not linked</option>
                <?php foreach ($leads as $l): ?>
                <option value="<?= $l['id'] ?>" <?= $d['lead_id']==$l['id']?'selected':'' ?>><?= htmlspecialchars($l['name'] . ($l['company'] ? ' — '.$l['company'] : '')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Assigned To</label>
              <select name="assigned_to" class="form-select select2">
                <option value="">Unassigned</option>
                <?php foreach ($agents as $ag): ?>
                <option value="<?= $ag['id'] ?>" <?= $d['assigned_to']==$ag['id']?'selected':'' ?>><?= htmlspecialchars($ag['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Status</label>
              <select name="status" class="form-select">
                <option value="scheduled"   <?= $d['status']==='scheduled'   ?'selected':'' ?>>Scheduled</option>
                <option value="completed"   <?= $d['status']==='completed'   ?'selected':'' ?>>Completed</option>
                <option value="cancelled"   <?= $d['status']==='cancelled'   ?'selected':'' ?>>Cancelled</option>
                <option value="rescheduled" <?= $d['status']==='rescheduled' ?'selected':'' ?>>Rescheduled</option>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-xl-4">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-link-45deg me-2 text-primary"></i>Meeting Info</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Meeting Link</label>
            <input type="url" name="meeting_link" class="form-control" value="<?= htmlspecialchars($d['meeting_link']) ?>" placeholder="https://meet.google.com/...">
            <div class="form-text">For online demos (Zoom, Meet, Teams, etc.)</div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Location / Address</label>
            <textarea name="location" class="form-control" rows="3" placeholder="Office address for on-site visits..."><?= htmlspecialchars($d['location']) ?></textarea>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-chat-left-text me-2 text-primary"></i>Notes</div>
        <div class="card-body">
          <textarea name="notes" class="form-control" rows="5" placeholder="Agenda, topics to cover, special requirements..."><?= htmlspecialchars($d['notes']) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?= $id ? 'Update Demo' : 'Schedule Demo' ?></button>
    <a href="<?= BASE_URL ?>/modules/demos/index.php" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
