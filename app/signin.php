<?php
declare(strict_types=1);

if ($action === 'send_code') {
    $email = strtolower(substr(post('email'), 0, 190));
    if (($_SESSION['last_code_request'] ?? 0) + 3 <= time() && $email !== '') {
        $_SESSION['last_code_request'] = time();
        try {
            request_code($db, $config, $email);
        } catch (Throwable $error) {
            error_log('DepEd failed to prepare a sign-in code.');
        }
    }
    redirect('verify');
}
if ($action === 'verify_code') {
    $verified = verify_code($db, $config, post('code'));
    if ($verified) {
        redirect(home_page($verified));
    }
    flash('The code is incorrect, expired, or has reached its attempt limit. Check it or request a new code.', 'error');
    redirect('verify');
}
$page = query('page') === 'verify' && isset($_SESSION['otp_email']) ? 'verify' : 'login';
