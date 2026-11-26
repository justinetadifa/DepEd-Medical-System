<?php
declare(strict_types=1);

function execute(PDO $db, string $sql, array $params = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function rows(PDO $db, string $sql, array $params = []): array
{
    return execute($db, $sql, $params)->fetchAll();
}

function row(PDO $db, string $sql, array $params = []): ?array
{
    return execute($db, $sql, $params)->fetch() ?: null;
}

function record_event(PDO $db, int $actor, string $kind, int $id, string $event): void
{
    execute($db, 'INSERT INTO record_events (account_id, record_kind, record_id, event_name) VALUES (?, ?, ?, ?)',
        [$actor, $kind, $id, $event]);
}

function report_list(PDO $db, string $search = '', string $status = 'all', ?int $employee = null): array
{
    $where = [];
    $params = [];
    if ($employee !== null) {
        $where[] = 'r.employee_id = ?';
        $params[] = $employee;
    }
    if (in_array($status, ['needs_review', 'reviewed'], true)) {
        $where[] = 'r.review_status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $matches = '(r.report_no LIKE ? OR r.laboratory LIKE ?
            OR EXISTS (SELECT 1 FROM report_results t WHERE t.report_id = r.id AND (t.test_name LIKE ? OR t.result_value LIKE ?)))';
        $where[] = $employee === null ? '(e.full_name LIKE ? OR e.employee_no LIKE ? OR ' . $matches . ')'
            : "(r.review_status = 'reviewed' AND {$matches})";
        $params = array_merge($params, array_fill(0, $employee === null ? 6 : 4, '%' . $search . '%'));
    }
    $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    return rows($db, 'SELECT r.*, e.full_name, e.office, e.employee_no,
        (SELECT COUNT(*) FROM report_results t WHERE t.report_id = r.id) AS test_count
        FROM reports r JOIN employees e ON e.id = r.employee_id' . $filter .
        ' ORDER BY r.created_at DESC, r.id DESC LIMIT 100', $params);
}

function report_by_id(PDO $db, int $id): ?array
{
    $record = row($db, 'SELECT r.*, e.full_name, e.employee_no, e.office,
        COALESCE(u.display_name, r.legacy_reviewer) AS reviewer, p.display_name AS receiver
        FROM reports r JOIN employees e ON e.id = r.employee_id
        LEFT JOIN accounts u ON u.id = r.reviewed_by LEFT JOIN accounts p ON p.id = r.received_by
        WHERE r.id = ?', [$id]);
    if ($record) {
        $record['results'] = rows($db, 'SELECT test_name, result_value, unit FROM report_results
            WHERE report_id = ? ORDER BY row_order', [$id]);
    }
    return $record;
}

function replace_result_rows(PDO $db, int $id, array $tests): void
{
    execute($db, 'DELETE FROM report_results WHERE report_id = ?', [$id]);
    $insert = $db->prepare('INSERT INTO report_results (report_id, row_order, test_name, result_value, unit) VALUES (?, ?, ?, ?, ?)');
    foreach (array_values($tests) as $position => $test) {
        $insert->execute([$id, $position + 1, $test['test_name'], $test['result_value'], $test['unit']]);
    }
}

function create_report(PDO $db, int $employeeId, ?int $year, int $actor, ?array $scan, array $extraction): int
{
    $fields = $extraction['fields'];
    $resultDate = $fields['report_date'] ?? '';
    $db->beginTransaction();
    try {
        execute($db, 'INSERT INTO reports (employee_id, ape_year, report_no, report_date, laboratory,
            storage_key, source_mime, source_name, source_hash, extraction_method, extraction_note, source_identity, submitted_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $employeeId, $year, substr($fields['report_no'] ?? '', 0, 60) ?: null,
            valid_date($resultDate) ? $resultDate : null, substr($fields['laboratory'] ?? '', 0, 120),
            $scan['key'] ?? null, $scan['mime'] ?? null, $scan['name'] ?? null, $scan['hash'] ?? null,
            substr($extraction['engine'], 0, 24), json_encode($extraction['warnings'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            trim(($fields['name'] ?? '') . ' ' . ($fields['employee_no'] ?? '')), $actor,
        ]);
        $id = (int)$db->lastInsertId();
        replace_result_rows($db, $id, $extraction['results']);
        record_event($db, $actor, 'report', $id, 'created');
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function save_report(PDO $db, int $id, int $revision, array $details, array $tests, int $actor, bool $approve): void
{
    $db->beginTransaction();
    try {
        $status = $approve ? 'reviewed' : 'needs_review';
        $change = execute($db, 'UPDATE reports SET ape_year = ?, report_no = ?, report_date = ?, laboratory = ?, review_message = ?,
            review_status = ?, reviewed_by = ?, reviewed_at = ?, edit_version = edit_version + 1
            WHERE id = ? AND review_status = ? AND edit_version = ?', [
            $details['ape_year'], $details['report_no'] ?: null, $details['report_date'] ?: null, $details['laboratory'], $approve ? '' : $details['review_message'],
            $status, $approve ? $actor : null, $approve ? date('Y-m-d H:i:s') : null, $id, 'needs_review', $revision,
        ]);
        if ($change->rowCount() !== 1) {
            throw new LogicException('Another reviewer changed this report. Reopen it to see the latest version.');
        }
        replace_result_rows($db, $id, $tests);
        record_event($db, $actor, 'report', $id, $approve ? 'approved' : 'draft_saved');
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function receive_original(PDO $db, int $id, int $actor): void
{
    $db->beginTransaction();
    try {
        $changed = execute($db, 'UPDATE reports SET paper_received_at = CURRENT_TIMESTAMP, received_by = ?
            WHERE id = ? AND paper_received_at IS NULL', [$actor, $id]);
        if ($changed->rowCount()) {
            record_event($db, $actor, 'report', $id, 'paper_received');
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function lab_test_key(string $label): string
{
    $label = strtolower(trim(preg_replace('/\s+/', ' ', $label) ?? ''));
    return match ($label) {
        'fbs', 'fbg', 'fasting glucose', 'fasting blood sugar' => 'fasting blood glucose',
        'tc' => 'total cholesterol',
        'tg' => 'triglycerides',
        default => $label,
    };
}

function numeric_result(string $value): ?float
{
    return preg_match('/\A[+-]?[0-9]+(?:\.[0-9]+)?\z/', $value) ? (float)$value : null;
}

function lab_trends(PDO $db, int $employee): array
{
    $results = rows($db, "SELECT r.id, r.report_date, t.test_name, t.result_value, t.unit
        FROM reports r JOIN report_results t ON t.report_id = r.id
        WHERE r.employee_id = ? AND r.review_status = 'reviewed' AND r.report_date IS NOT NULL
        ORDER BY r.report_date DESC, r.id DESC, t.row_order LIMIT 500", [$employee]);
    $latest = [];
    $trends = [];
    foreach ($results as $result) {
        $number = numeric_result($result['result_value']);
        if ($number === null || $result['unit'] === '') {
            continue;
        }
        $key = lab_test_key($result['test_name']) . '|' . strtolower(str_replace(' ', '', $result['unit']));
        if (!isset($latest[$key])) {
            $latest[$key] = $result;
        } elseif (!isset($trends[$key]) && $result['report_date'] < $latest[$key]['report_date']) {
            $trends[$key] = [
                'name' => $latest[$key]['test_name'], 'unit' => $latest[$key]['unit'],
                'current' => $latest[$key], 'prior' => $result,
                'delta' => numeric_result($latest[$key]['result_value']) - $number,
            ];
        }
    }
    return array_values(array_slice($trends, 0, 8));
}
