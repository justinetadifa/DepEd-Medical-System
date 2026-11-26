<?php
declare(strict_types=1);

const APE_FIELDS = [
    'allergies' => 'Allergies',
    'conditions_reported' => 'Current medical conditions (self-reported)',
    'medications' => 'Current medications',
    'family_history' => 'Family history',
    'surgery_hospitalization' => 'Surgeries and recent hospitalizations',
    'lifestyle' => 'Smoking, alcohol, and other history',
    'screening_history' => 'Screening history (optional)',
];
const VISIT_FIELDS = [
    'chief_complaint' => 'Chief complaint',
    'subjective_findings' => 'Subjective findings',
    'objective_findings' => 'Objective findings',
    'assessment' => 'Clinical assessment',
    'plan' => 'Plan',
    'treatment' => 'Treatment / medicine administered',
    'share_guidance' => 'Guidance to share with the employee',
];
const VISIT_REASONS = [
    'Acute illness consultation', 'Medical certification', 'Chronic disease monitoring',
    'Laboratory interpretation', 'Minor injury management', 'Medication refill', 'Follow-up checkup', 'Preventive care',
];
const DISPOSITIONS = ['Treated', 'Advised', 'Referred to OPD', 'Referred elsewhere', 'Follow-up scheduled', 'Other'];

function field(string $key, int $max, bool $required = false): string
{
    $value = post($key);
    if (strlen($value) > $max || ($required && $value === '')) {
        throw new DomainException('Check the length and completeness of the marked fields.');
    }
    return $value;
}

function date_field(string $key, bool $required = false): ?string
{
    $value = post($key);
    if ($value === '' && !$required) {
        return null;
    }
    if (!valid_date($value) || (int)substr($value, 0, 4) < 1900 || (int)substr($value, 0, 4) > 2099) {
        throw new DomainException('Enter a date between 1900 and 2099.');
    }
    return $value;
}

function number_field(string $key, float $max, bool $integer = false): int|float|null
{
    $value = post($key);
    if ($value === '') {
        return null;
    }
    $format = $integer ? '/\A\d{1,4}\z/' : '/\A\d{1,4}(?:\.\d{1,2})?\z/';
    if (!preg_match($format, $value) || (float)$value > $max) {
        throw new DomainException('Check the measurement format and units.');
    }
    return $integer ? (int)$value : (float)$value;
}

function ape_year(string $value, bool $optional = false): ?int
{
    if ($value === '' && $optional) {
        return null;
    }
    if (!preg_match('/\A(?:19|20)\d{2}\z/', $value)) {
        throw new DomainException('Enter an APE year from 1900 to 2099.');
    }
    return (int)$value;
}

function view_year(): int
{
    $default = date('Y');
    $year = query('year', $default);
    return preg_match('/\A(?:19|20)\d{2}\z/', $year) ? (int)$year : (int)$default;
}

function employee_by_id(PDO $db, int $id): ?array
{
    return row($db, 'SELECT * FROM employees WHERE id = ?', [$id]);
}

function clinical_employee(PDO $db, int $id): array
{
    $employee = employee_by_id($db, $id);
    if (!$employee) {
        throw new DomainException('Choose an employee from the directory.');
    }
    return $employee;
}

function employee_choices(PDO $db): array
{
    return rows($db, 'SELECT id, full_name, employee_no, office FROM employees WHERE active = 1 ORDER BY full_name, id');
}

function store_ape(PDO $db, int $id, int $year, int $actor, bool $assessment): void
{
    $data = $assessment ? [
        'clinical_assessment' => field('clinical_assessment', 3000, true),
        'assessed_at' => date('Y-m-d H:i:s'),
        'assessed_by' => $actor,
    ] : [
        'assessment_date' => date_field('assessment_date'),
        'systolic' => number_field('systolic', 999, true),
        'diastolic' => number_field('diastolic', 999, true),
        'pulse' => number_field('pulse', 999, true),
        'weight_kg' => number_field('weight_kg', 999),
        'height_m' => number_field('height_m', 9.99),
        'blood_type' => field('blood_type', 12),
    ];
    if (!$assessment) {
        foreach (array_keys(APE_FIELDS) as $key) {
            $data[$key] = field($key, 1000);
        }
        $contact = field('contact_no', 40);
        $birth = date_field('birth_date');
        $sex = field('sex', 30);
    }

    $db->beginTransaction();
    try {
        if (!row($db, 'SELECT id FROM employees WHERE id = ? FOR UPDATE', [$id])) {
            throw new DomainException('Choose an employee from the directory.');
        }
        $exists = row($db, 'SELECT profile_received_at FROM ape_profiles WHERE employee_id = ? AND ape_year = ?', [$id, $year]);
        if (!$assessment) {
            $data['profile_received_at'] = $exists['profile_received_at'] ?? date('Y-m-d H:i:s');
            execute($db, 'UPDATE employees SET birth_date = ?, contact_no = ?, sex = ? WHERE id = ?', [$birth, $contact, $sex, $id]);
        }
        $columns = array_keys($data);
        $values = array_values($data);
        if ($exists) {
            $set = implode(' = ?, ', $columns) . ' = ?';
            execute($db, "UPDATE ape_profiles SET {$set} WHERE employee_id = ? AND ape_year = ?", [...$values, $id, $year]);
        } else {
            $questions = implode(', ', array_fill(0, count($columns) + 2, '?'));
            execute($db, 'INSERT INTO ape_profiles (employee_id, ape_year, ' . implode(', ', $columns) .
                ") VALUES ({$questions})", [$id, $year, ...$values]);
        }
        record_event($db, $actor, 'ape', $id, $assessment ? 'assessment_recorded' : 'profile_saved');
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function ape_list(PDO $db, int $year, string $search): array
{
    $pattern = '%' . $search . '%';
    return rows($db, "SELECT e.id, e.full_name, e.employee_no, e.office, p.profile_received_at, p.assessed_at,
        COALESCE(l.received, 0) AS labs_received, COALESCE(l.approved, 0) AS labs_approved
        FROM employees e LEFT JOIN ape_profiles p ON p.employee_id = e.id AND p.ape_year = ?
        LEFT JOIN (SELECT employee_id, COUNT(*) AS received,
                   SUM(CASE WHEN review_status = 'reviewed' THEN 1 ELSE 0 END) AS approved
                   FROM reports WHERE ape_year = ? GROUP BY employee_id) l ON l.employee_id = e.id
        WHERE e.active = 1 AND (e.full_name LIKE ? OR e.office LIKE ? OR e.employee_no LIKE ?)
        ORDER BY e.office, e.full_name, e.id", [$year, $year, $pattern, $pattern, $pattern]);
}

function save_visit(PDO $db, array $employee, array $account): int
{
    $visitDate = date_field('visit_date', true);
    $age = $employee['birth_date'] && $employee['birth_date'] <= $visitDate
        ? (new DateTimeImmutable($employee['birth_date']))->diff(new DateTimeImmutable($visitDate))->y : null;
    $data = [
        'employee_id' => $employee['id'],
        'office' => $employee['office'],
        'visit_date' => $visitDate,
        'age_at_visit' => $age,
        'sex_at_visit' => $employee['sex'],
        'reason' => field('reason', 120, true),
        'case_category' => field('case_category', 120),
        'disposition' => post('disposition'),
        'followup_date' => date_field('followup_date'),
        'systolic' => number_field('systolic', 999, true),
        'diastolic' => number_field('diastolic', 999, true),
        'pulse' => number_field('pulse', 999, true),
        'respiration' => number_field('respiration', 999, true),
        'temperature' => number_field('temperature', 999),
        'spo2' => number_field('spo2', 100, true),
        'bmi' => number_field('bmi', 999),
        'created_by' => $account['id'],
        'provider_name' => $account['display_name'],
        'provider_role' => $account['role'],
    ];
    if (!in_array($data['disposition'], DISPOSITIONS, true)) {
        throw new DomainException('Choose how the visit was resolved.');
    }
    if ($account['role'] === 'doctor') {
        $data['prescription'] = field('prescription', 1000);
        $data['license_no'] = field('license_no', 40);
        $data['ptr_no'] = field('ptr_no', 40);
    }
    foreach (array_keys(VISIT_FIELDS) as $key) {
        $data[$key] = field($key, in_array($key, ['chief_complaint', 'share_guidance'], true) ? 1000 : 2000);
    }

    $db->beginTransaction();
    try {
        $questions = implode(', ', array_fill(0, count($data), '?'));
        execute($db, 'INSERT INTO visits (' . implode(', ', array_keys($data)) . ") VALUES ({$questions})", array_values($data));
        $id = (int)$db->lastInsertId();
        record_event($db, (int)$account['id'], 'visit', $id, 'recorded');
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function visit_list(PDO $db, string $from, string $before, string $search = ''): array
{
    $term = '%' . $search . '%';
    return rows($db, 'SELECT v.*, e.full_name, e.employee_no, e.position_title, e.birth_date
        FROM visits v JOIN employees e ON e.id = v.employee_id
        WHERE v.visit_date >= ? AND v.visit_date < ? AND (e.full_name LIKE ? OR v.reason LIKE ? OR v.office LIKE ? OR e.employee_no LIKE ?)
        ORDER BY v.visit_date DESC, v.id DESC', [$from, $before, $term, $term, $term, $term]);
}

function visit_by_id(PDO $db, int $id): ?array
{
    return row($db, 'SELECT v.*, e.full_name, e.employee_no, e.position_title, e.birth_date
                     FROM visits v JOIN employees e ON e.id = v.employee_id WHERE v.id = ?', [$id]);
}

function month_bounds(): array
{
    $month = query('month', date('Y-m'));
    if (!preg_match('/\A(?:19|20)\d{2}-(?:0[1-9]|1[0-2])\z/', $month)) {
        $month = date('Y-m');
    }
    $start = new DateTimeImmutable($month . '-01');
    return [$month, $start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d')];
}
