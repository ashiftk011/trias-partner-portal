<?php
// Determine active module from URL
$currentPath = $_SERVER['REQUEST_URI'] ?? '';
$activeModule = '';
if (strpos($currentPath, '/modules/') !== false) {
    preg_match('/\/modules\/([^\/]+)/', $currentPath, $m);
    $activeModule = $m[1] ?? '';
}

$navItems = [
    ['module'=>'dashboard', 'icon'=>'speedometer2',     'label'=>'Dashboard',  'url'=>BASE_URL.'/modules/dashboard/index.php'],
    ['module'=>'leads',     'icon'=>'funnel-fill',      'label'=>'Leads',      'url'=>BASE_URL.'/modules/leads/index.php'],
    ['module'=>'clients',   'icon'=>'people-fill',      'label'=>'Clients',    'url'=>BASE_URL.'/modules/clients/index.php'],
    ['module'=>'renewals',  'icon'=>'arrow-repeat',     'label'=>'Renewals',   'url'=>BASE_URL.'/modules/renewals/index.php'],
    ['module'=>'invoices',  'icon'=>'receipt',          'label'=>'Invoices',   'url'=>BASE_URL.'/modules/invoices/index.php'],
    ['module'=>'projects',  'icon'=>'folder2-open',     'label'=>'Projects',   'url'=>BASE_URL.'/modules/projects/index.php'],
    ['module'=>'plans',     'icon'=>'clipboard-check',  'label'=>'Plans',      'url'=>BASE_URL.'/modules/plans/index.php'],
    ['module'=>'users',     'icon'=>'person-gear',      'label'=>'Users',      'url'=>BASE_URL.'/modules/users/index.php'],
    ['module'=>'settings',  'icon'=>'gear-fill',        'label'=>'Settings',   'url'=>BASE_URL.'/modules/settings/index.php'],
];

?>
<nav id="sidebar" class="sidebar">
  <div class="sidebar-brand">
    <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="Logo" class="sidebar-brand-logo">
    <span class="sidebar-brand-text"><?= APP_NAME ?></span>
  </div>

  <div class="sidebar-nav-section">
    <span class="sidebar-section-label">MENU</span>
  </div>

  <ul class="nav flex-column sidebar-menu">
    <?php foreach ($navItems as $item): ?>
      <?php if (hasAccess($item['module'])): ?>
        <?php $isActive = $activeModule === $item['module']; ?>
        <li class="nav-item">
          <a href="<?= $item['url'] ?>"
             class="sidebar-link <?= $isActive ? 'active' : '' ?>">
            <i class="bi bi-<?= $item['icon'] ?> sidebar-link-icon"></i>
            <span class="sidebar-link-text"><?= $item['label'] ?></span>
            <?php if ($isActive): ?>
            <span class="sidebar-active-dot"></span>
            <?php endif; ?>
          </a>
        </li>
      <?php endif; ?>
    <?php endforeach; ?>
  </ul>

  <div class="sidebar-footer">
    <small><?= APP_NAME ?> v<?= APP_VERSION ?></small>
  </div>
</nav>
