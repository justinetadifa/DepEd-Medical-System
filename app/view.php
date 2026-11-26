<?php
declare(strict_types=1);

$title = PAGE_TITLES[$page];
$shell = $user !== null && $page !== 'setup';
if ($shell) {
    $workspace = match ($user['role']) {
        'personnel' => 'Personnel workspace',
        'ict' => 'ICT workspace',
        default => 'Clinic workspace',
    };
    $home = home_page($user);
    $nav = match ($user['role']) {
        'personnel' => [
            'health' => ['My health', 'My health', 'health'],
            'profile' => ['APE profile', 'APE profile', 'calendar'],
            'reports' => ['My lab reports', 'Lab reports', 'report'],
        ],
        'ict' => ['accounts' => ['User directory', 'Directory', 'account']],
        default => [
            'clinic' => ['Overview', 'Overview', 'overview'],
            'ape' => ['Annual physical exams', 'Exams', 'calendar'],
            'reports' => ['Lab reports', 'Lab reports', 'report'],
            'visits' => ['Clinic visits', 'Visits', 'visit'],
            'employees' => ['Employee roster', 'Roster', 'users'],
            'summary' => ['Reports & exports', 'Reports', 'chart'],
        ],
    };
    $selected = match ($page) {
        'visit', 'visit_new' => 'visits',
        'intake', 'report', 'review' => 'reports',
        'profile' => $user['role'] === 'personnel' ? 'profile' : 'ape',
        default => $page,
    };
    preg_match('/^\X/u', trim($user['display_name']), $initial);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · Employee Health</title>
    <!-- Global system logo used as favicon and brand mark across all workspaces and print views -->
    <link rel="icon" type="image/png" href="assets/medical-logo.png">
    <link rel="stylesheet" href="assets/font.css">
    <link rel="stylesheet" href="assets/app.css">
    <?php if (!$shell): ?>
        <link rel="stylesheet" href="assets/auth.css">
    <?php elseif ($page === 'clinic' || $page === 'health'): ?>
        <link rel="stylesheet" href="assets/dashboard.css">
    <?php endif; ?>
    <script src="assets/app.js" defer></script>
</head>
<body class="<?= $shell ? 'workspace' : 'auth-page' ?>">
<?php if ($shell): ?>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header class="topbar">
        <a href="<?= h(url($home)) ?>" class="brand">
            <img class="brand-logo" src="assets/medical-logo.png" alt="DepEd Regional Office I Employee Health Portal logo">
            <span><strong>Employee Health</strong><small>DepEd · Regional Office I</small></span>
        </a>
        <span class="workspace-name"><?= h($workspace) ?></span>
        <div class="topbar-tools">
            <?php if ($config['demo_mode']): ?><span class="system-label">Practice data</span><?php endif; ?>
            <div class="topbar-account">
                <span class="avatar" aria-hidden="true"><?= h(strtoupper($initial[0] ?? '?')) ?></span>
                <span><strong><?= h($user['display_name']) ?></strong><small><?= h(ROLE_LABELS[$user['role']]) ?></small></span>
            </div>
            <form method="post">
                <?= form_action('logout') ?>
                <button class="quiet signout" type="submit" aria-label="Sign out" title="Sign out"><?php ui_icon('logout'); ?><span>Sign out</span></button>
            </form>
        </div>
    </header>
    <div class="workspace-frame">
        <aside class="rail">
            <nav aria-label="<?= h($workspace) ?>">
                <?php foreach ($nav as $link => [$label, $short, $icon]): ?>
                    <a href="<?= h(url($link)) ?>" aria-label="<?= h($label) ?>" <?= $selected === $link ? 'aria-current="page"' : '' ?>>
                        <?php ui_icon($icon); ?>
                        <span class="nav-caption" aria-hidden="true"><?= h($short) ?></span>
                        <span class="nav-tooltip" aria-hidden="true"><?= h($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <main class="main-content" id="main-content" tabindex="-1">
<?php else: ?>
    <div class="auth-shell">
    <main class="auth">
        <div class="brand auth-brand">
            <img class="brand-logo" src="assets/medical-logo.png" alt="DepEd Regional Office I Employee Health Portal logo">
            <span><strong>Employee Health</strong><small>DepEd · Regional Office I</small></span>
        </div>
<?php endif; ?>

<?php if ($message): ?>
    <p class="notice <?= h($message[1]) ?>" role="status"><?= h($message[0]) ?></p>
<?php endif; ?>

<?php
$screen = match ($page) {
    'login', 'verify', 'setup', 'problem' => 'login',
    'clinic', 'health', 'accounts' => 'home',
    'reports', 'intake', 'report', 'review' => 'labs',
    'ape', 'profile', 'employees' => 'ape',
    'visits', 'visit', 'visit_new', 'summary' => 'visits',
};
require __DIR__ . '/screens/' . $screen . '.php';
?>
</main>
<?php if (!$shell) require __DIR__ . '/screens/login-art.php'; ?>
</div>
</body>
</html>
