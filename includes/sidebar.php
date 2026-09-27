<?php
// Determine active module from URL
$currentPath = $_SERVER['REQUEST_URI'] ?? '';
$activeModule = '';
if (strpos($currentPath, '/modules/') !== false) {
    preg_match('/\/modules\/([^\/]+)/', $currentPath, $m);
    $activeModule = $m[1] ?? '';
}

$navSections = [
    [
        'title' => 'MAIN MENU',
        'items' => [
            ['module'=>'dashboard', 'icon'=>'speedometer2',     'label'=>'Dashboard',   'url'=>BASE_URL.'/modules/dashboard/index.php'],
            ['module'=>'leads',     'icon'=>'funnel-fill',      'label'=>'Leads',       'url'=>BASE_URL.'/modules/leads/index.php'],
            ['module'=>'clients',   'icon'=>'people-fill',      'label'=>'Clients',     'url'=>BASE_URL.'/modules/clients/index.php'],
        ]
    ],
    [
        'title' => 'OPERATIONS & FINANCE',
        'items' => [
            ['module'=>'proposals', 'icon'=>'file-earmark-text','label'=>'Proposals',   'url'=>BASE_URL.'/modules/proposals/index.php'],
            ['module'=>'quotations','icon'=>'calculator',       'label'=>'Quotations',  'url'=>BASE_URL.'/modules/quotations/index.php'],
            ['module'=>'demos',     'icon'=>'camera-video-fill','label'=>'Demos',       'url'=>BASE_URL.'/modules/demos/index.php'],
            ['module'=>'renewals',  'icon'=>'arrow-repeat',     'label'=>'Renewals',    'url'=>BASE_URL.'/modules/renewals/index.php'],
            ['module'=>'invoices',  'icon'=>'receipt',          'label'=>'Invoices',    'url'=>BASE_URL.'/modules/invoices/index.php'],
            ['module'=>'payments',  'icon'=>'cash-stack',       'label'=>'Payments Report','url'=>BASE_URL.'/modules/payments/index.php'],
            ['module'=>'projects',  'icon'=>'folder2-open',     'label'=>'Projects',    'url'=>BASE_URL.'/modules/projects/index.php'],
            ['module'=>'plans',     'icon'=>'clipboard-check',  'label'=>'Plans',       'url'=>BASE_URL.'/modules/plans/index.php'],
            ['module'=>'regions',   'icon'=>'geo-alt-fill',     'label'=>'Regions',     'url'=>BASE_URL.'/modules/regions/index.php'],
        ]
    ],
    [
        'title' => 'HR & PAYROLL',
        'items' => [
            ['module'=>'hr', 'submodule'=>'employees', 'icon'=>'person-badge-fill','label'=>'Employees',   'url'=>BASE_URL.'/modules/hr/employees.php'],
            ['module'=>'hr', 'submodule'=>'payroll',   'icon'=>'wallet2',          'label'=>'Salary & Slips','url'=>BASE_URL.'/modules/hr/payroll.php'],
        ]
    ],
    [
        'title' => 'SYSTEM & CONFIG',
        'items' => [
            ['module'=>'users',     'icon'=>'person-gear',      'label'=>'Users',       'url'=>BASE_URL.'/modules/users/index.php'],
            ['module'=>'settings',  'icon'=>'gear-fill',        'label'=>'Settings',    'url'=>BASE_URL.'/modules/settings/index.php'],
        ]
    ]
];

// Fetch dynamic portal branding & company details from app_settings
$sidebarBrandName = APP_NAME;
$sidebarBrandIcon = '';
try {
    $db = getDB();
    $bStmt = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('app_name', 'app_icon', 'company_name', 'company_logo')");
    $sMap = [];
    while ($bRow = $bStmt->fetch()) {
        $sMap[$bRow['setting_key']] = trim($bRow['setting_value']);
    }

    // App Name precedence: app_name -> company_name -> APP_NAME constant
    if (!empty($sMap['app_name'])) {
        $sidebarBrandName = $sMap['app_name'];
    } elseif (!empty($sMap['company_name'])) {
        $sidebarBrandName = $sMap['company_name'];
    }

    // App Icon precedence: app_icon -> company_logo -> default icon
    if (!empty($sMap['app_icon'])) {
        $sidebarBrandIcon = $sMap['app_icon'];
    } elseif (!empty($sMap['company_logo'])) {
        $sidebarBrandIcon = $sMap['company_logo'];
    }
} catch (Exception $e) {}

?>
<nav id="sidebar" class="sidebar">
  <div class="sidebar-brand">
    <?php if (!empty($sidebarBrandIcon)): ?>
      <img src="<?= BASE_URL . '/' . htmlspecialchars($sidebarBrandIcon) ?>" alt="App Icon" class="sidebar-brand-logo">
    <?php else: ?>
      <div class="sidebar-brand-icon">
        <i class="bi bi-diagram-3-fill"></i>
      </div>
    <?php endif; ?>
    <span class="sidebar-brand-text"><?= htmlspecialchars($sidebarBrandName) ?></span>
  </div>

  <?php foreach ($navSections as $section): ?>
    <?php
      $hasSectionAccess = false;
      foreach ($section['items'] as $item) {
          if (hasAccess($item['module'])) {
              $hasSectionAccess = true;
              break;
          }
      }
    ?>
    <?php if ($hasSectionAccess): ?>
      <div class="sidebar-nav-section">
        <span class="sidebar-section-label"><?= $section['title'] ?></span>
      </div>
        <?php 
          $currentPageScript = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
          foreach ($section['items'] as $item): 
        ?>
          <?php if (hasAccess($item['module'])): ?>
            <?php 
              if (isset($item['submodule'])) {
                  if ($item['submodule'] === 'employees') {
                      $isActive = in_array($currentPageScript, ['employees.php', 'save_employee.php', 'view_employee.php', 'index.php']) && !in_array($currentPageScript, ['payroll.php', 'payslip.php']);
                  } elseif ($item['submodule'] === 'payroll') {
                      $isActive = in_array($currentPageScript, ['payroll.php', 'payslip.php']);
                  } else {
                      $isActive = ($activeModule === $item['module']);
                  }
              } else {
                  $isActive = ($activeModule === $item['module']);
              }
            ?>
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
    <?php endif; ?>
  <?php endforeach; ?>

  <div class="sidebar-footer">
    <small><?= APP_NAME ?> v<?= APP_VERSION ?></small>
  </div>
</nav>
