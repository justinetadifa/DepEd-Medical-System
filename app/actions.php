<?php
declare(strict_types=1);

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect('login');
}

$permitted = match ($user['role']) {
    'personnel' => ['upload', 'sample', 'save_profile'],
    'ict' => [],
    default => ['upload', 'sample', 'new_manual', 'save_report', 'receive_paper', 'save_profile',
        'assess_ape', 'save_visit', 'add_employee'],
};
if (!in_array($action, $permitted, true)) {
    problem(403, 'This action is unavailable in your workspace.');
}

$actor = (int)$user['id'];
$targetEmployee = $ownId ?? (int)post('employee_id');

if (in_array($action, ['upload', 'sample', 'new_manual'], true)) {
    $scan = null;
    try {
        clinical_employee($db, $targetEmployee);
        $cycle = ape_year(post('ape_year'), $clinic);
        $extraction = ['fields' => [], 'results' => [], 'warnings' => [], 'engine' => 'manual'];
        if ($action !== 'new_manual') {
            $sample = $action === 'sample';
            if ($sample && !$config['demo_mode']) {
                problem(403, 'Sample reports are available in practice mode.');
            }
            if ($sample && $targetEmployee !== 1) {
                throw new DomainException('The sample belongs to Mara Santos (DEMO-0147).');
            }
            $scan = stage_scan($config, $sample);
            $duplicate = row($db, 'SELECT id FROM reports WHERE employee_id = ? AND source_hash = ?', [$targetEmployee, $scan['hash']]);
            if ($duplicate) {
                delete_scan($config, $scan);
                flash('This file is already attached to a report. Open that report for its current status.');
                redirect('report', ['id' => $duplicate['id']]);
            }
            $extraction = read_scan($config, $scan);
            if ($sample && $extraction['engine'] === 'manual') {
                $extraction = sample_draft();
            }
        }
        $newId = create_report($db, $targetEmployee, $cycle, $actor, $scan, $extraction);
        flash($clinic ? 'Draft saved. Check the source and the extracted fields.'
            : 'Upload received. The clinic will review it. Track its status here.');
        redirect($clinic ? 'review' : 'report', ['id' => $newId]);
    } catch (PDOException $error) {
        if ($scan) {
            delete_scan($config, $scan);
            $duplicate = row($db, 'SELECT id FROM reports WHERE employee_id = ? AND source_hash = ?', [$targetEmployee, $scan['hash']]);
            if ($duplicate) {
                flash('This file is already attached to a report.');
                redirect('report', ['id' => $duplicate['id']]);
            }
        }
        flash('The report could not be saved. Try again.', 'error');
    } catch (DomainException|RuntimeException $error) {
        if ($scan) {
            delete_scan($config, $scan);
        }
        flash($error->getMessage(), 'error');
    }
    redirect('intake', ['employee' => $targetEmployee, 'year' => (int)post('ape_year') ?: date('Y')]);
}

if ($action === 'save_report') {
    $id = (int)post('report_id');
    $revision = (int)post('edit_version');
    $approve = post('approve') === '1';
    $details = [
        'ape_year' => post('ape_year'), 'report_no' => post('report_no'),
        'report_date' => post('report_date'), 'laboratory' => post('laboratory'), 'review_message' => post('review_message'),
    ];
    $tests = submitted_tests();
    try {
        $details['ape_year'] = ape_year($details['ape_year'], true);
        $details['report_date'] = date_field('report_date', $approve);
        $details['report_no'] = field('report_no', 60);
        $details['laboratory'] = field('laboratory', 120);
        $details['review_message'] = field('review_message', 600);
        $count = is_array($_POST['test_name'] ?? null) ? count($_POST['test_name']) : 0;
        if ($count > 40 || ($approve && (post('confirmed') !== 'yes' || !$tests))) {
            throw new DomainException('Enter at least one result and confirm that you checked the source and employee.');
        }
        foreach ($tests as $test) {
            if ($test['test_name'] === '' || $test['result_value'] === ''
                || strlen($test['test_name']) > 120 || strlen($test['result_value']) > 200 || strlen($test['unit']) > 40) {
                throw new DomainException('Every result row needs a test name and a value. Use the unit printed on the source.');
            }
        }
        save_report($db, $id, $revision, $details, $tests, $actor, $approve);
        flash($approve ? 'Report approved. The employee can now view it as Submitted.' : 'Draft saved.');
        redirect($approve ? 'report' : 'review', ['id' => $id]);
    } catch (DomainException $error) {
        $_SESSION['form_input'] = ['form' => 'report', 'id' => $id, 'revision' => $revision,
            'data' => $details + ['results' => $tests]];
        flash($error->getMessage(), 'error');
    } catch (LogicException $error) {
        flash($error->getMessage(), 'error');
    } catch (PDOException) {
        flash('The changes could not be saved. Check the report and try again.', 'error');
    }
    redirect('review', ['id' => $id]);
}

if ($action === 'receive_paper') {
    $id = (int)post('report_id');
    $report = report_by_id($db, $id);
    if (!$report || !$report['storage_key']) {
        problem(404, 'An uploaded report is required to record paper receipt.');
    }
    receive_original($db, $id, $actor);
    flash('Original received. The digital review status is unchanged.');
    redirect('report', ['id' => $id]);
}

if (in_array($action, ['save_profile', 'assess_ape'], true)) {
    $cycle = (int)post('ape_year') ?: (int)date('Y');
    try {
        store_ape($db, $targetEmployee, ape_year(post('ape_year')), $actor, $action === 'assess_ape');
        flash($action === 'assess_ape' ? 'Clinician assessment saved.' : 'APE health profile saved.');
    } catch (DomainException $error) {
        flash($error->getMessage(), 'error');
    } catch (PDOException) {
        flash('The profile could not be saved. Try again.', 'error');
    }
    redirect('profile', ['id' => $targetEmployee, 'year' => $cycle]);
}

if ($action === 'save_visit') {
    try {
        $employee = clinical_employee($db, $targetEmployee);
        $visitId = save_visit($db, $employee, $user);
        flash('Clinic visit recorded.');
        redirect('visit', ['id' => $visitId]);
    } catch (DomainException|PDOException $error) {
        $_SESSION['form_input'] = ['form' => 'visit', 'data' => array_map(
            static fn($input) => is_string($input) ? substr($input, 0, 3000) : '', $_POST
        )];
        flash($error instanceof DomainException ? $error->getMessage() : 'The visit could not be saved. Try again.', 'error');
        redirect('visit_new', ['employee' => $targetEmployee]);
    }
}

if ($action === 'add_employee') {
    try {
        $number = strtoupper(field('employee_no', 24, true));
        if (!preg_match('/\A[A-Z0-9-]{2,24}\z/', $number)) {
            throw new DomainException('Use a unique employee ID with 2 to 24 letters, numbers, or hyphens.');
        }
        execute($db, 'INSERT INTO employees (employee_no, full_name, office, position_title, employment_status)
            VALUES (?, ?, ?, ?, ?)', [$number, field('full_name', 120, true), field('office', 120, true),
                field('position_title', 120), field('employment_status', 40)]);
        flash('Employee added to the clinic directory.');
    } catch (DomainException $error) {
        flash($error->getMessage(), 'error');
    } catch (PDOException $error) {
        flash($error->getCode() === '23000' ? 'That employee ID is already in the directory.' : 'The employee could not be added.', 'error');
    }
    redirect('employees');
}
