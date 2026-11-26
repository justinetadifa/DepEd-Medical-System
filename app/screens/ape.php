<?php if ($page === 'ape'): ?>
    <div class="page-heading"><div><h1>Annual physical exams</h1>
        <p>Track health profiles, lab results, and clinical assessments for <?= $year ?>.</p></div></div>
    <section class="card">
        <form method="get" class="directory-search search-bar">
            <input type="hidden" name="page" value="ape">
            <label>APE year<input type="number" class="year-input" name="year" min="1900" max="2099" value="<?= $year ?>" required></label>
            <label class="search-field">Find employee or office<input name="q" maxlength="100" value="<?= h($search) ?>" placeholder="Name, employee ID, or office"></label>
            <button class="button secondary align-end">Apply</button>
        </form>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Annual physical exam roster"><table><thead><tr><th>Employee</th><th>Office</th><th>Health profile</th><th>Lab results</th><th>Assessment</th><th></th></tr></thead><tbody>
        <?php foreach ($apeRoster as $item): ?>
            <tr><td><strong><?= h($item['full_name']) ?></strong><small><?= h($item['employee_no']) ?></small></td>
                <td><?= h($item['office']) ?></td>
                <td><?php checkpoint($item['profile_received_at'] !== null, 'Received', 'Awaiting profile'); ?></td>
                <td><?php checkpoint((int)$item['labs_received'] > 0, (int)$item['labs_received'] . ' received', 'Awaiting labs'); ?>
                    <?php if ($item['labs_received']): ?><small><?= (int)$item['labs_approved'] ?> approved</small><?php endif; ?></td>
                <td><?php checkpoint($item['assessed_at'] !== null, 'Assessed', 'Awaiting assessment'); ?></td>
                <td><a class="table-link" href="<?= h(url('profile', ['id' => $item['id'], 'year' => $year])) ?>">Open</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$apeRoster): ?><tr><td class="empty" colspan="6">No employees match the search.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
<?php elseif ($page === 'employees'): ?>
    <div class="page-heading"><div><h1>Employees</h1><p>The clinic roster connects profiles, lab reports, and visits.</p></div></div>
    <div class="intake-layout">
        <section class="card">
            <div class="directory-search"><?php filter_field('employees', $search, [], 'Search name, employee ID, or office'); ?></div>
            <div class="table-scroll" tabindex="0" role="region" aria-label="Employee roster"><table><thead><tr><th>Employee</th><th>Office</th><th></th></tr></thead><tbody>
            <?php foreach ($employees as $entry): ?>
                <tr><td><strong><?= h($entry['full_name']) ?></strong><small><?= h($entry['employee_no']) ?></small></td>
                    <td><?= h($entry['office']) ?></td>
                    <td><a class="table-link" href="<?= h(url('profile', ['id' => $entry['id']])) ?>">APE profile</a></td></tr>
            <?php endforeach; ?>
            <?php if (!$employees): ?><tr><td class="empty" colspan="3">No employees found.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
        <section class="card panel">
            <h2>Add an employee</h2>
            <form method="post" class="stack">
                <?= form_action('add_employee') ?>
                <?php input_field('Employee ID', 'employee_no', '', 'text', 24, true);
                input_field('Full name', 'full_name', '', 'text', 120, true);
                input_field('Office or unit', 'office', '', 'text', 120, true);
                input_field('Position', 'position_title'); input_field('Employment status', 'employment_status', '', 'text', 40); ?>
                <button class="button primary">Save employee</button>
            </form>
        </section>
    </div>
<?php else: ?>
    <a class="text-link" href="<?= h(url($clinic ? 'ape' : 'health', ['year' => $year])) ?>">← <?= $clinic ? 'Annual physical exams' : 'My health' ?></a>
    <div class="page-heading">
        <div><h1>APE health profile</h1><p><?= h($employee['full_name']) ?> · <?= h($employee['employee_no']) ?> · <?= h($employee['office']) ?></p></div>
        <form method="get" class="inline-form">
            <input type="hidden" name="page" value="profile"><input type="hidden" name="id" value="<?= h($employee['id']) ?>">
            <label>APE year<input type="number" name="year" min="1900" max="2099" value="<?= $year ?>"></label>
            <button class="button secondary align-end">Go</button>
        </form>
    </div>
    <div class="intake-layout">
        <section class="card panel">
            <h2><?= $year ?> health profile</h2>
            <p>The clinic uses these details for health tracking and assessment. Enter the information you can confirm.</p>
            <form method="post" class="stack">
                <?= form_action('save_profile', ['employee_id' => $employee['id'], 'ape_year' => $year]) ?>
                <div class="field-grid">
                    <?php input_field('Contact number', 'contact_no', $employee['contact_no'], 'text', 40);
                    input_field('Date of birth', 'birth_date', $employee['birth_date'], 'date');
                    input_field('Sex', 'sex', $employee['sex'], 'text', 30);
                    input_field('Assessment date', 'assessment_date', $profile['assessment_date'] ?? '', 'date'); ?>
                </div>
                <fieldset><legend>Measurements</legend><div class="field-grid three">
                    <?php measurement('Systolic BP (mmHg)', 'systolic', $profile); measurement('Diastolic BP (mmHg)', 'diastolic', $profile);
                    measurement('Pulse (bpm)', 'pulse', $profile); measurement('Weight (kg)', 'weight_kg', $profile, '0.01');
                    measurement('Height (m)', 'height_m', $profile, '0.01');
                    input_field('Blood type', 'blood_type', $profile['blood_type'] ?? '', 'text', 12); ?>
                </div></fieldset>
                <div class="field-grid">
                <?php foreach (APE_FIELDS as $key => $label) textarea_field($label, $key, $profile); ?>
                </div>
                <button class="button primary">Save health profile</button>
            </form>
        </section>
        <aside class="stack">
            <section class="card panel"><h2><?= $year ?> checklist</h2>
                <dl class="check-list">
                    <div><dt>Health profile</dt><dd><?php checkpoint(!empty($profile['profile_received_at']), 'Received', 'Awaiting profile'); ?></dd></div>
                    <div><dt>Lab results</dt><dd><?php checkpoint((int)$labCounts['received'] > 0, (int)$labCounts['received'] . ' received', 'Awaiting labs'); ?></dd></div>
                    <div><dt>Clinician</dt><dd><?php checkpoint(!empty($profile['assessed_at']), 'Assessed', 'Awaiting assessment'); ?></dd></div>
                </dl>
                <?php button('intake', 'Upload lab report', ['employee' => $employee['id'], 'year' => $year]); ?>
            </section>
            <?php if ($clinic): ?>
                <section class="card panel">
                    <h2>Employee history</h2>
                    <ul class="record-links">
                        <?php foreach ($recentReports as $recent): ?>
                            <li><a class="text-link" href="<?= h(url('report', ['id' => $recent['id']])) ?>"><?= h($recent['report_no'] ?: 'Report #' . $recent['id']) ?></a>
                                <small><?= h(date_label($recent['report_date'])) ?> · <?= $recent['review_status'] === 'reviewed' ? 'Submitted' : 'Pending' ?></small></li>
                        <?php endforeach; ?>
                        <?php foreach ($recentVisits as $recent): ?>
                            <li><a class="text-link" href="<?= h(url('visit', ['id' => $recent['id']])) ?>"><?= h($recent['reason']) ?></a>
                                <small><?= h(date_label($recent['visit_date'])) ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!$recentReports && !$recentVisits): ?><p>No reports or visits recorded yet.</p><?php endif; ?>
                    <?php button('visit_new', 'Record a visit', ['employee' => $employee['id']]); ?>
                </section>
                <section class="card panel">
                    <h2>Clinician assessment</h2>
                    <?php if (!empty($profile['assessed_at'])): ?><p>Last assessed <?= h(date_label($profile['assessed_at'])) ?>.</p><?php endif; ?>
                    <form method="post" class="stack">
                        <?= form_action('assess_ape', ['employee_id' => $employee['id'], 'ape_year' => $year]) ?>
                        <label>Assessment notes<textarea required name="clinical_assessment" rows="5" maxlength="3000"><?= h($profile['clinical_assessment'] ?? '') ?></textarea></label>
                        <button class="button primary">Record assessment</button>
                    </form>
                </section>
            <?php endif; ?>
        </aside>
    </div>
<?php endif; ?>
