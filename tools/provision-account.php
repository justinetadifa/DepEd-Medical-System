<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = dirname(__DIR__) . '/app';
require $app . '/Database.php';
require $app . '/records.php';
require $app . '/auth.php';
$config = require $app . '/config.php';
if (is_file($app . '/config.local.php')) {
    $config = array_replace($config, require $app . '/config.local.php');
}

$email = strtolower($argv[1] ?? '');
$role = $argv[2] ?? '';
$name = trim($argv[3] ?? '');
$employeeNo = strtoupper(trim($argv[4] ?? ''));
if ($config['demo_mode'] || !allowed_email($config, $email) || !isset(ROLE_LABELS[$role]) || $name === '' || strlen($name) > 120
    || ($role === 'personnel' && $employeeNo === '')) {
    fwrite(STDERR, "Set demo_mode to false and use an allowed DepEd email.\n");
    fwrite(STDERR, "Usage: php tools/provision-account.php email nurse|doctor|personnel|ict \"Display Name\" [employee-id]\n");
    exit(1);
}

try {
    $db = Database::getInstance($config);
    $employee = $role === 'personnel' ? row($db, 'SELECT id, full_name FROM employees WHERE employee_no = ? AND active = 1', [$employeeNo]) : null;
    if ($role === 'personnel' && !$employee) {
        fwrite(STDERR, "Add the employee to the clinic roster before linking the account.\n");
        exit(1);
    }
    execute($db, 'INSERT INTO accounts (email, display_name, role, employee_id) VALUES (?, ?, ?, ?)',
        [$email, $employee['full_name'] ?? $name, $role, $employee['id'] ?? null]);
    echo "Account created. It can request a sign-in code from the web app.\n";
} catch (PDOException $error) {
    fwrite(STDERR, $error->getCode() === '23000'
        ? "That email or employee ID is already linked to an account.\n"
        : "Could not create the account. Check the database and connection settings.\n");
    exit(1);
}
