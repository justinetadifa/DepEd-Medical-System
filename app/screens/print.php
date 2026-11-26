<?php
declare(strict_types=1);

$isRegister = $page === 'print_register';
$printTitle = match ($page) {
    'print_result' => 'Laboratory results',
    'print_visit' => 'Health examination record',
    default => 'Daily treatment register',
};
$back = match ($page) {
    'print_result' => url('report', ['id' => $report['id']]),
    'print_visit' => url('visit', ['id' => $visit['id']]),
    default => url('visits', ['month' => substr($from, 0, 7)]),
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($printTitle) ?></title>
    <link rel="stylesheet" href="assets/font.css">
    <link rel="stylesheet" href="assets/print.css">
    <script src="assets/app.js" defer></script>
</head>
<body class="<?= $isRegister ? 'register' : '' ?>">
    <nav class="print-toolbar" aria-label="Print controls">
        <a href="<?= h($back) ?>">← Back to record</a>
        <button type="button" data-print>Print / Save as PDF</button>
    </nav>
    <main class="print-sheet">
        <header class="print-header">
            <div><strong>Department of Education · Regional Office I</strong><span>Employee health records</span></div>
            <p>Confidential</p>
        </header>
        <h1><?= h($printTitle) ?></h1>
        <?php if ($page === 'print_result'): ?>
            <p class="subtitle">Clinic-reviewed report <?= h($report['report_no'] ?: '#' . $report['id']) ?></p>
            <dl class="print-details">
                <div><dt>Employee</dt><dd><?= h($report['full_name']) ?></dd></div>
                <div><dt>Employee ID</dt><dd><?= h($report['employee_no']) ?></dd></div>
                <div><dt>Office</dt><dd><?= h($report['office']) ?></dd></div>
                <div><dt>Result date</dt><dd><?= h(date_label($report['report_date'])) ?></dd></div>
                <div><dt>Laboratory</dt><dd><?= h($report['laboratory'] ?: '—') ?></dd></div>
                <div><dt>Reviewed by</dt><dd><?= h($report['reviewer'] ?: '—') ?></dd></div>
            </dl>
            <?php result_table($report['results']); ?>
            <p class="print-note">This copy contains the results reviewed by the clinic. Refer to the original laboratory document for its full findings.</p>
        <?php elseif ($page === 'print_visit'): ?>
            <p class="subtitle">F023-style clinic examination printout</p>
            <dl class="print-details">
                <div><dt>Employee</dt><dd><?= h($visit['full_name'] . ' · ' . $visit['employee_no']) ?></dd></div>
                <div><dt>Office / unit</dt><dd><?= h($visit['office']) ?></dd></div>
                <div><dt>Position</dt><dd><?= h($visit['position_title'] ?: '—') ?></dd></div>
                <div><dt>Age / sex</dt><dd><?= h(($visit['age_at_visit'] ?? '—') . ' / ' . ($visit['sex_at_visit'] ?: '—')) ?></dd></div>
                <div><dt>Date examined</dt><dd><?= h(display_date($visit['visit_date'])) ?></dd></div>
                <div><dt>Attending staff</dt><dd><?= h($visit['provider_name']) ?></dd></div>
            </dl>
            <dl class="print-notes">
                <div><dt>Vital signs</dt><dd><?= h(vitals_label($visit)) ?></dd></div>
                <?php foreach (['chief_complaint' => 'Chief complaint', 'subjective_findings' => 'Subjective findings',
                    'objective_findings' => 'Objective findings', 'assessment' => 'Assessment', 'plan' => 'Plan',
                    'treatment' => 'Treatment', 'prescription' => 'Prescribed medicines', 'disposition' => 'Disposition'] as $key => $label): ?>
                    <div><dt><?= h($label) ?></dt><dd><?= nl2br(h($visit[$key] ?: '—')) ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <div class="signatures"><div>Physician name and signature</div><div>License / PTR <?= h(trim($visit['license_no'] . ' ' . $visit['ptr_no'])) ?></div></div>
        <?php else: ?>
            <p class="subtitle">F010-style clinic treatment printout · <?= h(date_label($from)) ?>
                <?= $registerDay && valid_date($registerDay) ? '' : ' to ' . h(date_label((new DateTimeImmutable($before))->modify('-1 day')->format('Y-m-d'))) ?></p>
            <div class="table-scroll" tabindex="0" role="region" aria-label="Daily treatment register"><table class="register-table">
                <thead><tr><th>Date</th><th>Employee</th><th>Age / sex</th><th>Office / designation</th><th>Vital signs</th><th>Chief complaint</th><th>Assessment / treatment</th><th>Prescribed medicines</th><th>Employee signature</th><th>Attending staff / signature</th></tr></thead>
                <tbody>
                <?php foreach ($visits as $entry): ?>
                    <tr><td><?= h(date_label($entry['visit_date'])) ?></td><td><?= h($entry['full_name']) ?></td>
                        <td><?= h(($entry['age_at_visit'] ?? '—') . ' / ' . ($entry['sex_at_visit'] ?: '—')) ?></td>
                        <td><?= h($entry['office']) ?><br><?= h($entry['position_title']) ?></td>
                        <td><?= h(vitals_label($entry)) ?></td><td><?= nl2br(h($entry['chief_complaint'] ?: $entry['reason'])) ?></td>
                        <td><?= nl2br(h(implode("\n", array_filter([$entry['assessment'], $entry['treatment']])) ?: '—')) ?></td>
                        <td><?= nl2br(h($entry['prescription'] ?: '—')) ?></td><td></td><td><?= h($entry['provider_name']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$visits): ?><tr><td colspan="10">No visits in this period.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
            <div class="signatures"><div>Nurse on duty name and signature</div></div>
        <?php endif; ?>
    </main>
</body>
</html>
