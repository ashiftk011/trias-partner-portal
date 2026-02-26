<?php
// header.php — must be included after requireLogin() / requireAccess()
$pageTitle = $pageTitle ?? APP_NAME;
$currentUser = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> | <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<!-- Top Navbar -->
<nav class="top-navbar" id="topNavbar">
  <div class="top-navbar-inner">
    <div class="d-flex align-items-center gap-3">
      <button class="top-navbar-toggle" id="sidebarToggle">
        <i class="bi bi-list"></i>
      </button>
      <div class="top-navbar-breadcrumb">
        <span class="top-navbar-title"><?= htmlspecialchars($pageTitle) ?></span>
      </div>
    </div>
    <div class="d-flex align-items-center gap-3">
      <span class="top-navbar-date d-none d-md-inline">
        <i class="bi bi-calendar3 me-1"></i><?= date('D, d M Y') ?>
      </span>
      <div class="dropdown">
        <button class="top-navbar-user dropdown-toggle" data-bs-toggle="dropdown">
          <div class="top-navbar-avatar"><?= strtoupper(substr($currentUser['name'], 0, 1)) ?></div>
          <div class="d-none d-md-block text-start">
            <div class="top-navbar-username"><?= htmlspecialchars($currentUser['name']) ?></div>
            <div class="top-navbar-role"><?= roleLabel($currentUser['role']) ?></div>
          </div>
          <i class="bi bi-chevron-down ms-1 small"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
          <li><h6 class="dropdown-header"><?= htmlspecialchars($currentUser['email']) ?></h6></li>
          <li><span class="dropdown-item-text small text-muted"><?= roleLabel($currentUser['role']) ?></span></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/modules/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<!-- Sidebar + Content Wrapper -->
<div class="wrapper d-flex">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content flex-grow-1">
    <div class="content-area p-4">
