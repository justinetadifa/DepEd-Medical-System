<?php if ($page === 'clinic'): ?>
    <?php
    $monthStart = new DateTimeImmutable($from);
    $monthLabel = $monthStart->format('F Y');
    $previousMonth = $monthStart->modify('-1 month')->format('Y-m');
    $nextMonth = substr($before, 0, 7);
    $today = date('Y-m-d');
    $daysInMonth = (int)$monthStart->format('t');
    $firstWeekday = (int)$monthStart->format('N');
    $dailyVisits = array_fill(1, $daysInMonth, 0);
    foreach ($clinicVisitCounts as $item) {
        $dailyVisits[(int)substr($item['visit_date'], 8, 2)] = (int)$item['count'];
    }
    $visitPeriods = array_chunk($dailyVisits, 7, true);
    $visitPeriodMaximum = max(1, ...array_map('array_sum', $visitPeriods));
    $rosterTotal = count($apeRoster);
    ?>
    <div class="page-heading">
        <div><h1>Clinic overview</h1><p>Review lab submissions, track annual exams, and record clinic visits.</p></div>
        <div class="actions"><?php button('intake', 'Upload lab report'); button('visit_new', '+ Record visit', [], true); ?></div>
    </div>
    <div class="overview-metrics section-block">
        <a class="card overview-metric" href="<?= h(url('employees')) ?>">
            <span class="overview-metric-top"><span class="overview-icon"><?php ui_icon('users'); ?></span><?php ui_icon('arrow-up-right'); ?></span>
            <span class="overview-metric-label">Active employees</span>
            <strong><?= number_format($rosterTotal) ?></strong>
            <small>Employee directory</small>
        </a>
        <a class="card overview-metric" href="<?= h(url('reports', ['status' => 'needs_review'])) ?>">
            <span class="overview-metric-top"><span class="overview-icon rose"><?php ui_icon('report'); ?></span><?php ui_icon('arrow-up-right'); ?></span>
            <span class="overview-metric-label">Reports pending</span>
            <strong><?= number_format($pendingCount) ?></strong>
            <small>Lab review queue</small>
        </a>
        <a class="card overview-metric" href="<?= h(url('ape', ['year' => $year])) ?>">
            <span class="overview-metric-top"><span class="overview-icon peach"><?php ui_icon('check'); ?></span><?php ui_icon('arrow-up-right'); ?></span>
            <span class="overview-metric-label">APE assessments</span>
            <strong><?= number_format($assessed) ?> <small>/ <?= number_format($rosterTotal) ?></small></strong>
            <small><?= $year ?> annual exams</small>
        </a>
        <a class="card overview-metric" href="<?= h(url('visits', ['month' => $month])) ?>">
            <span class="overview-metric-top"><span class="overview-icon green"><?php ui_icon('visit'); ?></span><?php ui_icon('arrow-up-right'); ?></span>
            <span class="overview-metric-label">Clinic visits</span>
            <strong><?= number_format($monthVisits) ?></strong>
            <small><?= h($monthLabel) ?></small>
        </a>
    </div>
    <div class="overview-grid section-block">
        <section class="card panel overview-activity" aria-labelledby="visit-activity-title">
            <div class="overview-panel-heading">
                <div><h2 id="visit-activity-title">Clinic visit activity</h2><p>Recorded visits in <?= h($monthLabel) ?></p></div>
                <a class="text-link" href="<?= h(url('visits', ['month' => $month])) ?>">View visits</a>
            </div>
            <ul class="overview-chart" aria-label="Visits grouped by day of the month">
                <?php foreach ($visitPeriods as $days):
                    $visitCount = array_sum($days);
                    $firstDay = array_key_first($days);
                    $lastDay = array_key_last($days);
                    $periodLabel = $firstDay === $lastDay ? (string)$firstDay : $firstDay . '–' . $lastDay;
                ?>
                    <li>
                        <span class="sr-only">Days <?= h($periodLabel) ?>:</span>
                        <strong><?= number_format($visitCount) ?></strong><span class="sr-only"> visits</span>
                        <div class="overview-plot" aria-hidden="true"><meter min="0" max="<?= $visitPeriodMaximum ?>" value="<?= $visitCount ?>"></meter></div>
                        <small aria-hidden="true"><?= h($monthStart->format('M') . ' ' . $periodLabel) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <section class="card panel overview-calendar" aria-labelledby="clinic-calendar-title">
            <div class="overview-panel-heading"><h2 id="clinic-calendar-title">Clinic calendar</h2><?php ui_icon('calendar'); ?></div>
            <div class="overview-calendar-controls">
                <?php if ($previousMonth >= '1900-01'): ?>
                    <a href="<?= h(url('clinic', ['month' => $previousMonth, 'year' => $year])) ?>" class="overview-calendar-arrow" aria-label="Previous month"><?php ui_icon('chevron-left'); ?></a>
                <?php endif; ?>
                <strong><?= h($monthLabel) ?></strong>
                <?php if ($nextMonth <= '2099-12'): ?>
                    <a href="<?= h(url('clinic', ['month' => $nextMonth, 'year' => $year])) ?>" class="overview-calendar-arrow" aria-label="Next month"><?php ui_icon('chevron-right'); ?></a>
                <?php endif; ?>
            </div>
            <div class="overview-weekdays" aria-hidden="true">
                <?php foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $weekday): ?><span><?= h($weekday) ?></span><?php endforeach; ?>
            </div>
            <ol class="overview-calendar-days" aria-label="<?= h($monthLabel . ' clinic visits by date') ?>">
                <?php for ($slot = 1; $slot < $firstWeekday; $slot++): ?><li aria-hidden="true"></li><?php endfor; ?>
                <?php foreach ($dailyVisits as $day => $count):
                    $calendarDate = $month . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
                ?>
                    <li><time datetime="<?= h($calendarDate) ?>"<?= $calendarDate === $today ? ' aria-current="date"' : '' ?> class="<?= $count ? 'has-visits' : '' ?>">
                        <?= $day ?><?php if ($count): ?><span class="sr-only">, <?= number_format($count) ?> <?= $count === 1 ? 'recorded visit' : 'recorded visits' ?></span><?php endif; ?>
                    </time></li>
                <?php endforeach; ?>
            </ol>
            <p class="overview-calendar-legend">One or more visits recorded</p>
        </section>
    </div>
    <section class="card">
        <div class="panel-title"><div><h2>Lab reports awaiting review</h2><small>Check the source and confirm the fields before approval.</small></div>
            <a class="text-link" href="<?= h(url('reports', ['status' => 'needs_review'])) ?>">Open queue</a></div>
        <?php reports_table(array_slice($reports, 0, 8), true); ?>
    </section>
<?php elseif ($page === 'accounts'): ?>
    <div class="page-heading"><div><h1>User directory</h1><p>Look up account names, DepEd emails, and assigned roles.</p></div></div>
    <div class="card">
        <div class="directory-search"><?php filter_field('accounts', $search, [], 'Search name or email'); ?></div>
        <div class="table-scroll" tabindex="0" role="region" aria-label="User accounts"><table><thead><tr><th>Name</th><th>Email</th><th>Workspace role</th><th>Access</th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $account): ?>
            <tr><td><strong><?= h($account['display_name']) ?></strong></td><td><?= h($account['email']) ?></td>
                <td><?= h(ROLE_LABELS[$account['role']]) ?></td><td><?php checkpoint((bool)$account['enabled'], 'Active', 'Disabled'); ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$accounts): ?><tr><td colspan="4" class="empty">No matching accounts.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
<?php else: ?>
    <div class="page-heading">
        <div><h1>My health</h1><p><?= h($employee['full_name']) ?> · <?= h($employee['office']) ?></p></div>
        <div class="actions"><?php button('profile', 'Update APE profile', ['year' => $year]); button('intake', '+ Upload lab report', ['year' => $year], true); ?></div>
    </div>
    <div class="overview-grid section-block">
        <section class="card panel overview-personnel">
            <div class="overview-panel-heading">
                <div class="overview-section-title"><span class="overview-icon"><?php ui_icon('calendar'); ?></span><h2><?= $year ?> annual physical exam</h2></div>
                <form method="get" class="inline-form"><input type="hidden" name="page" value="health">
                    <label class="sr-only" for="health-year">APE year</label><input id="health-year" type="number" name="year" min="1900" max="2099" value="<?= $year ?>">
                    <button class="button secondary">Go</button>
                </form>
            </div>
            <dl class="check-list overview-checks">
                <div><dt>Health profile</dt><dd><?php checkpoint(!empty($profile['profile_received_at']), 'Received', 'Complete your profile'); ?></dd></div>
                <div><dt>Lab results</dt><dd><?php checkpoint((int)$labCounts['received'] > 0, 'Received', 'Upload needed'); ?></dd></div>
                <div><dt>Clinician assessment</dt><dd><?php checkpoint(!empty($profile['assessed_at']), 'Assessed', 'Awaiting assessment'); ?></dd></div>
            </dl>
        </section>
        <section class="card panel overview-personnel overview-consultation">
            <div class="overview-section-title"><span class="overview-icon green"><?php ui_icon('visit'); ?></span><h2>Last consultation</h2></div>
            <?php if ($lastVisit): ?>
                <p class="large-value"><?= h(display_date($lastVisit['visit_date'])) ?></p>
            <?php else: ?><p class="overview-consultation-empty">No clinic visit on record yet.</p><?php endif; ?>
            <?php if (!empty($lastVisit['share_guidance'])): ?><div class="guidance"><strong>Clinic guidance</strong><p><?= nl2br(h($lastVisit['share_guidance'])) ?></p></div><?php endif; ?>
            <?php if (!empty($lastVisit['followup_date'])): ?><p class="overview-followup"><?php ui_icon('clock'); ?> Follow-up on <?= h(display_date($lastVisit['followup_date'])) ?></p><?php endif; ?>
        </section>
    </div>
    <section class="card section-block">
        <div class="panel-title"><div><h2>Latest and previous readings</h2><small>Changes use readings on different dates with the same test and unit.</small></div></div>
        <?php if ($datedReports): ?>
            <div class="dated-results">
                <?php foreach ($datedReports as $index => $item): ?>
                    <div><small><?= $index === 0 ? 'Latest approved lab report' : 'Previous approved lab report' ?></small>
                        <a class="text-link" href="<?= h(url('report', ['id' => $item['id']])) ?>"><?= h(display_date($item['report_date'])) ?></a></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($trends || ($bp && $priorBp)): ?>
            <div class="table-scroll" tabindex="0" role="region" aria-label="Previous and latest measurements"><table class="trend-table"><thead><tr><th>Measurement</th><th>Latest</th><th>Previous</th><th>Change</th></tr></thead><tbody>
            <?php if ($bp && $priorBp): ?>
                <?php foreach (['systolic' => 'Systolic blood pressure', 'diastolic' => 'Diastolic blood pressure'] as $field => $label): ?>
                    <tr><td><?= h($label) ?></td>
                        <td class="numeric"><?= h($bp[0][$field]) ?> mmHg<small><?= h(display_date($bp[0]['visit_date'])) ?></small></td>
                        <td class="numeric"><?= h($priorBp[$field]) ?> mmHg<small><?= h(display_date($priorBp['visit_date'])) ?></small></td>
                        <td><?= h(compare_label((float)$bp[0][$field] - (float)$priorBp[$field], 'mmHg')) ?></td></tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php foreach ($trends as $trend): ?>
                <tr><td><?= h($trend['name']) ?></td>
                <?php foreach (['current', 'prior'] as $key): $reading = $trend[$key]; ?>
                    <td class="numeric"><a class="text-link" href="<?= h(url('report', ['id' => $reading['id']])) ?>">
                        <?= h($reading['result_value'] . ' ' . $reading['unit']) ?></a><small><?= h(display_date($reading['report_date'])) ?></small></td>
                <?php endforeach; ?>
                <td><?= h(compare_label($trend['delta'], $trend['unit'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="empty">A comparison will appear after a second comparable result is available.</p><?php endif; ?>
    </section>
    <section class="card">
        <div class="panel-title"><h2>Recent lab reports</h2><a class="text-link" href="<?= h(url('reports')) ?>">All reports</a></div>
        <?php reports_table(array_slice($reports, 0, 5), false); ?>
    </section>
<?php endif; ?>
