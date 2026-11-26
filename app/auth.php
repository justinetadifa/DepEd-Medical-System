<?php
declare(strict_types=1);

const DEMO_DOMAIN = 'example.test';
const ROLE_LABELS = ['personnel' => 'Personnel', 'nurse' => 'Nurse', 'doctor' => 'Doctor', 'ict' => 'ICT'];

function allowed_email(array $config, string $email): bool
{
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $domain = substr(strrchr($email, '@'), 1);
    return $config['demo_mode'] ? $domain === DEMO_DOMAIN
        : ($domain !== DEMO_DOMAIN && in_array($domain, $config['email_domains'], true));
}

function is_clinic(array $account): bool
{
    return in_array($account['role'], ['doctor', 'nurse'], true);
}

function home_page(array $account): string
{
    return match ($account['role']) {
        'personnel' => 'health',
        'ict' => 'accounts',
        default => 'clinic',
    };
}

function request_code(PDO $db, array $config, string $email): void
{
    if (($_SESSION['otp_email'] ?? '') !== $email) {
        unset($_SESSION['demo_code']);
    }
    $_SESSION['otp_email'] = $email;
    if (!allowed_email($config, $email)) {
        return;
    }
    $now = time();
    $db->beginTransaction();
    try {
        $account = row($db, 'SELECT id FROM accounts WHERE email = ? AND enabled = 1 FOR UPDATE', [$email]);
        if (!$account) {
            $db->commit();
            return;
        }
        $active = row($db, 'SELECT requested_at FROM login_codes WHERE account_id = ?', [$account['id']]);
        if ($active && (int)$active['requested_at'] + ($config['demo_mode'] ? 3 : 45) > $now) {
            $db->commit();
            return;
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        if ($active) {
            execute($db, 'UPDATE login_codes SET code_hash = ?, requested_at = ?, expires_at = ?, attempts = 0 WHERE account_id = ?',
                [$hash, $now, $now + 300, $account['id']]);
        } else {
            execute($db, 'INSERT INTO login_codes (account_id, code_hash, requested_at, expires_at) VALUES (?, ?, ?, ?)',
                [$account['id'], $hash, $now, $now + 300]);
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    if ($config['demo_mode']) {
        $_SESSION['demo_code'] = $code;
        return;
    }
    $sender = $config['mail_from'];
    $accepted = filter_var($sender, FILTER_VALIDATE_EMAIL) && @mail(
        $email,
        'DepEd Employee Health sign-in code',
        "Your sign-in code is {$code}.\r\nThis code expires in 5 minutes.\r\n",
        ['From' => $sender, 'Content-Type' => 'text/plain; charset=UTF-8']
    );
    if (!$accepted) {
        execute($db, 'UPDATE login_codes SET expires_at = 0 WHERE account_id = ? AND code_hash = ?', [$account['id'], $hash]);
        error_log('DepEd sign-in mail was not accepted. Check the local mail transport configuration.');
    }
}

function verify_code(PDO $db, array $config, string $code): ?array
{
    $email = $_SESSION['otp_email'] ?? '';
    if (!allowed_email($config, $email) || !preg_match('/\A[0-9]{6}\z/', $code)) {
        return null;
    }
    $db->beginTransaction();
    try {
        $account = row($db, 'SELECT * FROM accounts WHERE email = ? AND enabled = 1 FOR UPDATE', [$email]);
        $saved = $account ? row($db, 'SELECT * FROM login_codes WHERE account_id = ?', [$account['id']]) : null;
        if (!$saved || (int)$saved['expires_at'] <= time() || (int)$saved['attempts'] >= 5) {
            $db->commit();
            return null;
        }
        if (!password_verify($code, $saved['code_hash'])) {
            execute($db, 'UPDATE login_codes SET attempts = attempts + 1 WHERE account_id = ?', [$account['id']]);
            $db->commit();
            return null;
        }
        execute($db, 'UPDATE login_codes SET expires_at = 0 WHERE account_id = ?', [$account['id']]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    sign_in($account);
    return $account;
}
