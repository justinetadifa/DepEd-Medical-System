<?php
declare(strict_types=1);

const PAGE_TITLES = [
    'setup' => 'Connect the database', 'login' => 'Sign in', 'verify' => 'Enter your code', 'problem' => 'Page unavailable',
    'clinic' => 'Clinic overview', 'health' => 'My health', 'profile' => 'APE health profile',
    'ape' => 'Annual physical exams', 'reports' => 'Laboratory reports', 'report' => 'Laboratory report',
    'review' => 'Review laboratory report', 'intake' => 'Upload laboratory report', 'employees' => 'Employees',
    'visits' => 'Clinic visits', 'visit_new' => 'Record clinic visit', 'visit' => 'Clinic visit', 'summary' => 'Clinic reports',
    'accounts' => 'User directory',
];

function url(string $page, array $params = []): string
{
    return '?' . http_build_query(['page' => $page] + $params);
}

function ui_icon(string $name, string $class = 'icon'): void
{
    $shape = match ($name) {
        'overview' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'health' => '<path d="M20.8 8.7a4.6 4.6 0 0 0-8.8-1.9 4.6 4.6 0 0 0-8.8 1.9c0 4.4 8.8 10.3 8.8 10.3s8.8-5.9 8.8-10.3Z"/><path d="M7 11h2l1-2 2 4 1-2h4"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18m-12 5 2 2 4-4"/>',
        'report' => '<path d="M8 3h6l5 5v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h2Zm6 0v5h5M8 12h8m-8 4h6"/>',
        'visit' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-3 3v7m-3.5-3.5h7"/>',
        'users' => '<circle cx="9" cy="7" r="3"/><path d="M3 20v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2M16 4a3 3 0 0 1 0 6m5 10v-2a4 4 0 0 0-3-3.9"/>',
        'chart' => '<path d="M4 4v16h16M8 16v-4m5 4V8m5 8v-6"/>',
        'account' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10.5" r="1.7"/><path d="M6.5 15.5v-.4a2.5 2.5 0 0 1 5 0v.4M15 10h3m-3 4h3"/>',
        'logout' => '<path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4m6-4 4-4-4-4m4 4H9"/>',
        'arrow-up-right' => '<path d="M7 17 17 7M8 7h9v9"/>',
        'arrow-right' => '<path d="M4 12h15m-6-6 6 6-6 6"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="5.5"/><path d="m15 15 4 4"/>',
    };
    ?><svg class="<?= h($class) ?>" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor"
        stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $shape ?></svg><?php
}

function button(string $page, string $text, array $params = [], bool $primary = false): void
{
    ?><a class="button <?= $primary ? 'primary' : 'secondary' ?>" href="<?= h(url($page, $params)) ?>"><?= h($text) ?></a><?php
}

function form_action(string $action, array $values = []): void
{
    echo csrf_field();
    $values = ['action' => $action] + $values;
    foreach ($values as $name => $value) {
        ?><input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>"><?php
    }
}

function date_label(?string $date): string
{
    return $date ? display_date($date) : '—';
}

function status_badge(string $status): void
{
    $approved = $status === 'reviewed';
    ?><span class="status <?= $approved ? 'reviewed' : 'needs_review' ?>"><?= $approved ? 'Submitted' : 'Pending' ?></span><?php
}

function checkpoint(bool $done, string $positive, string $waiting): void
{
    ?><span class="status <?= $done ? 'reviewed' : 'neutral' ?>"><?= h($done ? $positive : $waiting) ?></span><?php
}

function employee_select(array $employees, int $selected = 0): void
{
    ?>
    <label>Employee
      <select name="employee_id" required>
        <option value="">Select an employee</option>
        <?php foreach ($employees as $employee): ?>
            <option value="<?= h($employee['id']) ?>" <?= (int)$employee['id'] === $selected ? 'selected' : '' ?>>
                <?= h($employee['full_name'] . ' · ' . $employee['employee_no'] . ' · ' . $employee['office']) ?>
            </option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php
}

function input_field(string $label, string $name, mixed $value = '', string $type = 'text', int $limit = 120, bool $required = false): void
{
    ?><label><?= h($label) ?>
        <input type="<?= h($type) ?>" name="<?= h($name) ?>" value="<?= h($value ?? '') ?>"
            <?= $type === 'text' ? 'maxlength="' . $limit . '"' : '' ?> <?= $required ? 'required' : '' ?>>
    </label><?php
}

function measurement(string $label, string $key, array $data, string $step = '1'): void
{
    ?><label><?= h($label) ?><input type="number" name="<?= h($key) ?>" value="<?= h($data[$key] ?? '') ?>" min="0" step="<?= h($step) ?>"></label><?php
}

function textarea_field(string $label, string $name, array $data, int $max = 1000): void
{
    ?><label><?= h($label) ?><textarea name="<?= h($name) ?>" maxlength="<?= $max ?>" rows="2"><?= h($data[$name] ?? '') ?></textarea></label><?php
}

function filter_field(string $page, string $search, array $hidden = [], string $placeholder = 'Search'): void
{
    ?><form method="get" class="search-bar">
        <?php foreach (['page' => $page] + $hidden as $key => $value): ?>
            <input type="hidden" name="<?= h($key) ?>" value="<?= h($value) ?>">
        <?php endforeach; ?>
        <div class="search-input">
            <?php ui_icon('search'); ?>
            <input class="search-field" type="search" aria-label="<?= h($placeholder) ?>" name="q"
                maxlength="100" value="<?= h($search) ?>" placeholder="<?= h($placeholder) ?>">
        </div>
        <button type="submit" class="button secondary">Search</button>
    </form><?php
}

function result_table(array $tests): void
{
    if (!$tests) {
        echo '<p class="empty">No results entered yet.</p>';
        return;
    }
    ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Laboratory measurements"><table class="measurements-table"><thead><tr><th>Test</th><th>Result</th><th>Unit</th></tr></thead><tbody>
    <?php foreach ($tests as $test): ?>
        <tr><td><?= h($test['test_name']) ?></td><td class="numeric"><?= h($test['result_value']) ?></td><td><?= h($test['unit'] ?: '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function reports_table(array $reports, bool $clinic): void
{
    if (!$reports) {
        echo '<p class="empty">No reports match this view.</p>';
        return;
    }
    ?>
    <div class="table-scroll"><table class="report-table"><thead><tr>
        <th><?= $clinic ? 'Employee / report' : 'Report' ?></th><th>Result date</th><th>Digital status</th><th>Paper original</th><th></th>
    </tr></thead><tbody>
    <?php foreach ($reports as $report): ?>
        <?php $label = $report['report_no'] ?: 'Report #' . $report['id']; ?>
        <tr>
            <td>
                <?php if ($clinic): ?><strong><?= h($report['full_name']) ?></strong><small><?= h($label) ?></small>
                <?php elseif ($report['review_status'] === 'reviewed'): ?><strong><?= h($label) ?></strong><small><?= h($report['laboratory']) ?></small>
                <?php else: ?><strong>Report #<?= h($report['id']) ?></strong><small>Uploaded <?= h(date_label($report['created_at'])) ?></small><?php endif; ?>
            </td>
            <td class="date" data-label="Result date"><?= $clinic || $report['review_status'] === 'reviewed' ? h(date_label($report['report_date'])) : '—' ?></td>
            <td data-label="Digital status"><?php status_badge($report['review_status']); ?></td>
            <td data-label="Paper original"><?= !$report['storage_key'] ? '—' : ($report['paper_received_at'] ? 'Received' : 'To submit') ?></td>
            <td><a class="table-link" href="<?= h(url($clinic && $report['review_status'] === 'needs_review' ? 'review' : 'report', ['id' => $report['id']])) ?>">
                <?= $clinic && $report['review_status'] === 'needs_review' ? 'Review' : 'Open' ?><span class="sr-only"> <?= h($label) ?></span>
            </a></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php
}

function problem(int $status, string $detail): never
{
    global $app, $user, $config;
    http_response_code($status);
    $page = 'problem';
    $message = null;
    require $app . '/view.php';
    exit;
}

function compare_label(float $delta, string $unit): string
{
    $number = rtrim(rtrim(number_format(abs($delta), 3, '.', ''), '0'), '.');
    return !$delta || $number === '0' ? 'No change' : ($delta > 0 ? 'Increased by ' : 'Decreased by ') . $number . ' ' . $unit;
}

function vitals_label(array $visit): string
{
    $values = [];
    if ($visit['systolic'] !== null && $visit['diastolic'] !== null) {
        $values[] = 'BP ' . $visit['systolic'] . '/' . $visit['diastolic'] . ' mmHg';
    }
    foreach (['pulse' => ['PR', ' bpm'], 'respiration' => ['RR', '/min'], 'temperature' => ['Temp', ' °C'],
        'spo2' => ['SpO₂', '%'], 'bmi' => ['BMI', ' kg/m²']] as $key => [$label, $unit]) {
        if ($visit[$key] !== null) {
            $values[] = $label . ' ' . $visit[$key] . $unit;
        }
    }
    return $values ? implode(' · ', $values) : '—';
}
