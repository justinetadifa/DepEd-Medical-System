<?php
declare(strict_types=1);

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
    http_response_code(403);
    exit('Open this local starter with http://localhost on the computer running XAMPP.');
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(self), microphone=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' blob:; media-src 'self' blob:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");
session_name('deped_medical_v2');
session_start([
    'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'use_strict_mode' => true,
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);

$app = dirname(__DIR__) . '/app';
foreach (['Database', 'security', 'records', 'clinical', 'auth', 'scans', 'ui'] as $module) {
    require $app . '/' . $module . '.php';
}
$config = require $app . '/config.php';
if (is_file($app . '/config.local.php')) {
    $config = array_replace($config, require $app . '/config.local.php');
}
date_default_timezone_set($config['timezone']);

$user = null;
$message = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
try {
    $db = Database::getInstance($config);
    row($db, 'SELECT id FROM accounts LIMIT 1');
} catch (PDOException $error) {
    $page = 'setup';
    http_response_code(503);
    require $app . '/view.php';
    exit;
}
if (isset($_SESSION['account_id'])) {
    $user = row($db, 'SELECT * FROM accounts WHERE id = ? AND enabled = 1', [$_SESSION['account_id']]);
    if ($user && !allowed_email($config, $user['email'])) {
        $user = null;
    }
    if (!$user) {
        unset($_SESSION['account_id']);
    }
}

$action = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? post('action') : '';
if ($action !== '') {
    check_csrf();
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    problem(413, 'The upload exceeded the PHP request limit. Choose a smaller file or check max upload size in php.ini.');
}
if (!$user) {
    require $app . '/signin.php';
    require $app . '/view.php';
    exit;
}

$clinic = is_clinic($user);
$ownId = $user['role'] === 'personnel' ? (int)$user['employee_id'] : null;
$page = query('page', home_page($user));
$id = max(0, (int)query('id'));
$year = view_year();
$search = substr(query('q'), 0, 100);
$old = $_SESSION['form_input'] ?? null;
unset($_SESSION['form_input']);
if ($action !== '') {
    require $app . '/actions.php';
}

$available = match ($user['role']) {
    'personnel' => ['health', 'profile', 'reports', 'report', 'intake', 'source', 'print_result'],
    'ict' => ['accounts'],
    default => ['clinic', 'ape', 'profile', 'reports', 'report', 'review', 'intake', 'source', 'employees',
        'visits', 'visit', 'visit_new', 'summary', 'print_result', 'print_visit', 'print_register'],
};
if (!in_array($page, $available, true)) {
    problem(403, 'This page is unavailable in your workspace.');
}

if (in_array($page, ['report', 'review', 'source', 'print_result'], true)) {
    $report = report_by_id($db, $id);
    if (!$report || ($ownId !== null && (int)$report['employee_id'] !== $ownId)) {
        problem(404, 'That report could not be found.');
    }
    if (!$clinic && $report['review_status'] === 'needs_review' && (int)$report['submitted_by'] !== (int)$user['id']) {
        if ($page === 'source') {
            problem(404, 'No source file is available for this report yet.');
        }
        $report['storage_key'] = null;
    }
    if ($page === 'source') {
        deliver_scan($config, $report, query('preview') === '1');
    }
    if ($page === 'review' && $report['review_status'] !== 'needs_review') {
        redirect('report', ['id' => $id]);
    }
    if ($page === 'print_result' && $report['review_status'] !== 'reviewed') {
        problem(404, 'The print copy will be available after clinic approval.');
    }
    if ($page === 'review' && $old && $old['form'] === 'report' && $old['id'] === $id
        && $old['revision'] === (int)$report['edit_version']) {
        $report = array_replace($report, $old['data']);
    }
}
if (in_array($page, ['health', 'profile'], true)) {
    $employee = employee_by_id($db, $ownId ?? $id);
    if (!$employee) {
        problem(404, 'The account needs to be linked to an employee record.');
    }
    $profile = row($db, 'SELECT * FROM ape_profiles WHERE employee_id = ? AND ape_year = ?', [$employee['id'], $year]) ?? [];
    $labCounts = row($db, "SELECT COUNT(*) AS received,
        SUM(CASE WHEN review_status = 'reviewed' THEN 1 ELSE 0 END) AS approved
        FROM reports WHERE employee_id = ? AND ape_year = ?", [$employee['id'], $year]);
    if ($page === 'profile' && $clinic) {
        $recentReports = array_slice(report_list($db, '', 'all', (int)$employee['id']), 0, 4);
        $recentVisits = rows($db, 'SELECT id, visit_date, reason FROM visits WHERE employee_id = ?
            ORDER BY visit_date DESC, id DESC LIMIT 4', [$employee['id']]);
    }
    if ($page === 'health') {
        $reports = report_list($db, '', 'all', (int)$employee['id']);
        $datedReports = rows($db, "SELECT id, report_no, report_date FROM reports WHERE employee_id = ?
            AND review_status = 'reviewed' AND report_date IS NOT NULL ORDER BY report_date DESC, id DESC LIMIT 2", [(int)$employee['id']]);
        $trends = lab_trends($db, (int)$employee['id']);
        $lastVisit = row($db, 'SELECT visit_date, share_guidance, followup_date FROM visits
            WHERE employee_id = ? ORDER BY visit_date DESC, id DESC LIMIT 1', [$employee['id']]);
        $bp = rows($db, 'SELECT visit_date, systolic, diastolic FROM visits WHERE employee_id = ?
            AND systolic IS NOT NULL AND diastolic IS NOT NULL ORDER BY visit_date DESC, id DESC LIMIT 15', [$employee['id']]);
        $priorBp = null;
        foreach ($bp as $reading) {
            if ($reading['visit_date'] < $bp[0]['visit_date']) {
                $priorBp = $reading;
                break;
            }
        }
    }
}
if (in_array($page, ['reports', 'clinic'], true)) {
    $status = $page === 'clinic' ? 'needs_review' : query('status', 'all');
    $reports = report_list($db, $search, $status, $ownId);
    if ($page === 'clinic') {
        [$month, $from, $before] = month_bounds();
        $clinicVisitCounts = rows($db, 'SELECT visit_date, COUNT(*) AS count FROM visits
            WHERE visit_date >= ? AND visit_date < ? GROUP BY visit_date', [$from, $before]);
        $monthVisits = array_sum(array_column($clinicVisitCounts, 'count'));
        $pendingCount = (int)row($db, "SELECT COUNT(*) AS count FROM reports WHERE review_status = 'needs_review'")['count'];
        $apeRoster = ape_list($db, $year, '');
        $assessed = count(array_filter($apeRoster, static fn(array $employee) => $employee['assessed_at'] !== null));
    }
}
if ($page === 'ape' || $page === 'summary') {
    $apeRoster = ape_list($db, $year, $page === 'ape' ? $search : '');
}
if (in_array($page, ['visits', 'summary', 'print_register'], true)) {
    [$month, $from, $before] = month_bounds();
    $registerDay = query('day');
    if ($page === 'print_register' && valid_date($registerDay)) {
        $from = $registerDay;
        $before = (new DateTimeImmutable($registerDay))->modify('+1 day')->format('Y-m-d');
    }
    $visits = visit_list($db, $from, $before, $page === 'visits' ? $search : '');
}
if ($page === 'visit' || $page === 'print_visit') {
    $visit = visit_by_id($db, $id);
    if (!$visit) {
        problem(404, 'That clinic visit could not be found.');
    }
}
if ($page === 'employees') {
    $term = '%' . $search . '%';
    $employees = rows($db, 'SELECT * FROM employees WHERE full_name LIKE ? OR employee_no LIKE ? OR office LIKE ?
        ORDER BY full_name LIMIT 100', [$term, $term, $term]);
}
if ($page === 'accounts') {
    $term = '%' . $search . '%';
    $accounts = rows($db, 'SELECT display_name, email, role, enabled FROM accounts
        WHERE display_name LIKE ? OR email LIKE ? ORDER BY display_name LIMIT 100', [$term, $term]);
}
if (($page === 'intake' && $clinic) || $page === 'visit_new') {
    $employees = employee_choices($db);
}
if (str_starts_with($page, 'print_')) {
    require $app . '/screens/print.php';
    exit;
}
require $app . '/view.php';
