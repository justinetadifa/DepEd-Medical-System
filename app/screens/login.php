<?php if ($page === 'problem' && $user): ?>
    <div class="page-heading">
        <div><h1><?= h($title) ?></h1><p><?= h($detail) ?></p></div>
        <div class="actions"><?php button(home_page($user), 'Back to workspace'); ?></div>
    </div>
<?php elseif ($page === 'setup'): ?>
    <div class="auth-heading">
        <h1><?= h($title) ?></h1>
        <p>Start Apache and MySQL in XAMPP. Import <code>database/install.sql</code> and then <code>database/demo.sql</code> in phpMyAdmin to try the app.</p>
        <p>Check the PDO MySQL extension and <code>app/config.local.php</code> if you use a custom database password or port.</p>
    </div>
    <div class="actions auth-actions">
        <a class="button primary" href="/phpmyadmin/">Open phpMyAdmin</a>
        <?php button('login', 'Try again'); ?>
    </div>
<?php elseif ($page === 'problem'): ?>
    <div class="auth-heading">
        <h1><?= h($title) ?></h1>
        <p><?= h($detail) ?></p>
    </div>
    <div class="actions auth-actions"><?php button('login', 'Back to sign in'); ?></div>
<?php elseif ($page === 'verify'): ?>
    <?php $practiceCode = $config['demo_mode'] ? ($_SESSION['demo_code'] ?? null) : null; ?>
    <div class="auth-heading">
        <h1>Enter your sign-in code</h1>
        <p><?= $practiceCode !== null
            ? 'Enter the six-digit practice code to open your workspace.'
            : 'If your account is active, check your inbox for a six-digit code. It expires after five minutes.' ?></p>
    </div>
    <?php if ($practiceCode !== null): ?>
        <p class="demo-code"><span>Local practice code</span><strong><?= h($practiceCode) ?></strong></p>
    <?php endif; ?>
    <form method="post" class="stack auth-form">
        <?php form_action('verify_code'); ?>
        <label>One-time code
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
        </label>
        <button type="submit" class="button primary">Verify and sign in</button>
    </form>
    <div class="actions auth-links">
        <form method="post">
            <?php form_action('send_code', ['email' => $_SESSION['otp_email']]); ?>
            <button type="submit" class="quiet">Request a new code</button>
        </form>
        <a class="quiet" href="<?= h(url('login')) ?>"><?= $config['demo_mode'] ? 'Choose another role' : 'Use another email' ?></a>
    </div>
<?php else: ?>
    <div class="auth-heading">
        <h1>Sign in</h1>
        <p><?= $config['demo_mode'] ? 'Choose a practice account to open its workspace.' : 'Use your assigned DepEd email to get a one-time code.' ?></p>
    </div>
    <?php if ($config['demo_mode']): ?>
        <?php
        $practiceAccounts = [
            'nurse' => ['Nurse', 'Review lab reports', 'report'],
            'doctor' => ['Doctor', 'Record clinic visits', 'visit'],
            'personnel' => ['Personnel', 'View your health record', 'health'],
            'ict' => ['ICT staff', 'Browse staff accounts', 'users'],
        ];
        ?>
        <div class="demo-accounts">
            <?php foreach ($practiceAccounts as $role => [$label, $description, $icon]): ?>
                <form method="post">
                    <?php form_action('send_code', ['email' => $role . '@example.test']); ?>
                    <button type="submit" class="demo-account">
                        <span class="demo-account-icon <?= h($role) ?>" aria-hidden="true"><?php ui_icon($icon); ?></span>
                        <span class="demo-account-label"><strong><?= h($label) ?></strong><small><?= h($description) ?></small></span>
                        <?php ui_icon('arrow-right', 'demo-account-arrow'); ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <form method="post" class="stack auth-form">
            <?php form_action('send_code'); ?>
            <label>DepEd email<input type="email" name="email" autocomplete="email" maxlength="190" required autofocus></label>
            <button type="submit" class="button primary">Send a code</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
