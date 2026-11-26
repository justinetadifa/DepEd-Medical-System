<?php if ($page === 'visit_new'): ?>
    <?php $form = ($old['form'] ?? '') === 'visit' ? $old['data'] : []; ?>
    <a class="text-link" href="<?= h(url('visits')) ?>">← Clinic visits</a>
    <div class="page-heading"><div><h1>Record clinic visit</h1><p>One entry for the visit history, treatment register, and examination record.</p></div></div>
    <form method="post" class="stack">
        <?= form_action('save_visit') ?>
        <section class="card panel stack">
            <h2>Visit details</h2>
            <div class="field-grid">
                <?php employee_select($employees, (int)($form['employee_id'] ?? query('employee')));
                input_field('Visit date', 'visit_date', $form['visit_date'] ?? date('Y-m-d'), 'date', 10, true); ?>
                <label>Reason for visit<input name="reason" list="visit-reasons" maxlength="120" required value="<?= h($form['reason'] ?? '') ?>"></label>
                <datalist id="visit-reasons"><?php foreach (VISIT_REASONS as $reason): ?><option value="<?= h($reason) ?>"><?php endforeach; ?></datalist>
                <?php input_field('Nature of case (optional)', 'case_category', $form['case_category'] ?? ''); ?>
            </div>
            <?php textarea_field('Chief complaint', 'chief_complaint', $form); ?>
            <fieldset><legend>Vital signs</legend><div class="field-grid four">
                <?php foreach (['Systolic BP (mmHg)' => 'systolic', 'Diastolic BP (mmHg)' => 'diastolic', 'Pulse (bpm)' => 'pulse', 'Respirations (/min)' => 'respiration',
                    'SpO₂ (%)' => 'spo2'] as $label => $key) measurement($label, $key, $form);
                measurement('Temperature (°C)', 'temperature', $form, '0.01');
                measurement('BMI (kg/m²)', 'bmi', $form, '0.01'); ?>
            </div></fieldset>
        </section>
        <section class="card panel stack">
            <h2>Clinical notes and disposition</h2>
            <div class="field-grid">
            <?php foreach (['subjective_findings', 'objective_findings', 'assessment', 'plan', 'treatment'] as $key) textarea_field(VISIT_FIELDS[$key], $key, $form, 2000); ?>
            <?php if ($user['role'] === 'doctor') textarea_field('Prescribed medicines', 'prescription', $form); ?>
            </div>
            <div class="field-grid">
                <label>Disposition<select name="disposition" required><option value="">Select an outcome</option>
                    <?php foreach (DISPOSITIONS as $name): ?><option <?= ($form['disposition'] ?? '') === $name ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?>
                </select></label>
                <?php input_field('Follow-up date (visible to employee)', 'followup_date', $form['followup_date'] ?? '', 'date'); ?>
                <?php if ($user['role'] === 'doctor'):
                    input_field('Physician license no. (for printed record)', 'license_no', $form['license_no'] ?? '', 'text', 40);
                    input_field('PTR no. (for printed record)', 'ptr_no', $form['ptr_no'] ?? '', 'text', 40);
                endif; ?>
            </div>
            <?php textarea_field(VISIT_FIELDS['share_guidance'], 'share_guidance', $form); ?>
            <button class="button primary" type="submit">Save visit</button>
        </section>
    </form>
<?php elseif ($page === 'visit'): ?>
    <a class="text-link" href="<?= h(url('visits', ['month' => substr($visit['visit_date'], 0, 7)])) ?>">← Clinic visits</a>
    <div class="page-heading">
        <div><h1><?= h($visit['full_name']) ?></h1><p><?= h($visit['employee_no']) ?> · <?= h(display_date($visit['visit_date'])) ?> · <?= h($visit['reason']) ?></p></div>
        <div class="actions"><?php button('print_visit', 'Print F023-style record', ['id' => $visit['id']]); button('print_register', 'Print F010-style register', ['day' => $visit['visit_date']]); ?></div>
    </div>
    <section class="card panel stack">
        <div class="split-title"><h2>Recorded visit</h2><?php checkpoint(true, $visit['disposition'], ''); ?></div>
        <p><?= h(vitals_label($visit)) ?></p>
        <dl class="note-grid">
            <?php foreach (VISIT_FIELDS + ['prescription' => 'Prescribed medicines'] as $key => $label): if (!$visit[$key]) continue; ?>
                <div><dt><?= h($label) ?></dt><dd><?= nl2br(h($visit[$key])) ?></dd></div>
            <?php endforeach; ?>
            <div><dt>Attending staff</dt><dd><?= h($visit['provider_name']) ?> · <?= h(ROLE_LABELS[$visit['provider_role']]) ?></dd></div>
            <?php if ($visit['followup_date']): ?><div><dt>Follow-up</dt><dd><?= h(date_label($visit['followup_date'])) ?></dd></div><?php endif; ?>
        </dl>
    </section>
<?php elseif ($page === 'summary'): ?>
    <?php
    $officeTotals = [];
    foreach ($apeRoster as $item) {
        $office = $item['office'];
        $officeTotals[$office] ??= ['roster' => 0, 'profiles' => 0, 'labs' => 0, 'assessed' => 0];
        $officeTotals[$office]['roster']++;
        $officeTotals[$office]['profiles'] += $item['profile_received_at'] !== null ? 1 : 0;
        $officeTotals[$office]['labs'] += $item['labs_received'] > 0 ? 1 : 0;
        $officeTotals[$office]['assessed'] += $item['assessed_at'] !== null ? 1 : 0;
    }
    $services = $dispositions = [];
    $referrals = 0;
    foreach ($visits as $visit) {
        $services[$visit['reason']] = ($services[$visit['reason']] ?? 0) + 1;
        $dispositions[$visit['disposition']] = ($dispositions[$visit['disposition']] ?? 0) + 1;
        $referrals += str_starts_with($visit['disposition'], 'Referred') ? 1 : 0;
    }
    arsort($services);
    arsort($dispositions);
    ?>
    <div class="page-heading"><div><h1>Clinic reports</h1><p>APE completion and service counts from saved records.</p></div></div>
    <form method="get" class="card panel filter-form section-block">
        <input type="hidden" name="page" value="summary">
        <?php input_field('APE year', 'year', $year, 'number'); input_field('Clinic visit month', 'month', $month, 'month'); ?>
        <button type="submit" class="button secondary align-end">Update view</button>
    </form>
    <section class="card section-block">
        <div class="panel-title"><div><h2>APE completion by office</h2><small><?= $year ?> · Active employee roster</small></div></div>
        <div class="table-scroll" tabindex="0" role="region" aria-label="APE completion by office"><table><thead><tr><th>Office</th><th>Personnel</th><th>Profiles received</th><th>Lab results received</th><th>Assessed</th></tr></thead><tbody>
        <?php foreach ($officeTotals as $office => $total): ?>
            <tr><td><?= h($office) ?></td><td class="numeric"><?= $total['roster'] ?></td><td class="numeric"><?= $total['profiles'] ?></td>
                <td class="numeric"><?= $total['labs'] ?></td><td class="numeric"><?= $total['assessed'] ?> <small><?= (int)round(100 * $total['assessed'] / $total['roster']) ?>%</small></td></tr>
        <?php endforeach; ?>
        <?php if (!$officeTotals): ?><tr><td colspan="5" class="empty">No active employees.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <div class="two-col">
        <section class="card">
            <div class="panel-title"><div><h2>Services provided</h2><small><?= h(date('F Y', strtotime($from))) ?> · <?= count($visits) ?> visits</small></div></div>
            <table><thead><tr><th>Reason for visit</th><th>Visits</th></tr></thead><tbody>
            <?php foreach ($services as $service => $count): ?><tr><td><?= h($service) ?></td><td class="numeric"><?= $count ?></td></tr><?php endforeach; ?>
            <?php if (!$services): ?><tr><td colspan="2" class="empty">No visits for this month.</td></tr><?php endif; ?>
            </tbody></table>
        </section>
        <section class="card">
            <div class="panel-title"><div><h2>Visit dispositions</h2><small><?= $referrals ?> referrals this month</small></div></div>
            <table><thead><tr><th>Outcome</th><th>Visits</th></tr></thead><tbody>
            <?php foreach ($dispositions as $type => $count): ?><tr><td><?= h($type) ?></td><td class="numeric"><?= $count ?></td></tr><?php endforeach; ?>
            <?php if (!$dispositions): ?><tr><td colspan="2" class="empty">No dispositions for this month.</td></tr><?php endif; ?>
            </tbody></table>
        </section>
    </div>
<?php else: ?>
    <div class="page-heading">
        <div><h1>Clinic visits</h1><p>Find consultations, treatments, and referrals.</p></div>
        <?php button('visit_new', '+ Record visit', [], true); ?>
    </div>
    <section class="card">
        <div class="panel-title"><form method="get" class="filter-form">
            <input type="hidden" name="page" value="visits">
            <?php input_field('Visit month', 'month', $month, 'month'); input_field('Find visits', 'q', $search, 'text', 100); ?>
            <button class="button secondary align-end">Apply</button>
        </form></div>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Recorded clinic visits"><table><thead><tr><th>Date</th><th>Employee</th><th>Reason for visit</th><th>Disposition</th><th></th></tr></thead><tbody>
        <?php foreach ($visits as $visit): ?>
            <tr><td class="date"><?= h(display_date($visit['visit_date'])) ?></td><td><strong><?= h($visit['full_name']) ?></strong><small><?= h($visit['office']) ?></small></td>
                <td><?= h($visit['reason']) ?></td><td><?= h($visit['disposition']) ?></td>
                <td><a class="table-link" href="<?= h(url('visit', ['id' => $visit['id']])) ?>">Open</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$visits): ?><tr><td colspan="5" class="empty">No visits match this view.</td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="card-foot"><?php button('print_register', 'Print monthly register', ['month' => $month]); ?></div>
    </section>
<?php endif; ?>
