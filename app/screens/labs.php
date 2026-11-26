<?php if ($page === 'reports'): ?>
    <div class="page-heading">
        <div><h1><?= $clinic ? 'Laboratory reports' : 'My lab reports' ?></h1>
            <p><?= $clinic ? 'Find employee reports by name, report number, test, or value.' : 'Track uploads and open your clinic-reviewed results.' ?></p></div>
        <?php button('intake', '+ Upload lab report', [], true); ?>
    </div>
    <section class="card">
        <div class="report-tools">
            <?php filter_field('reports', $search, ['status' => $status], $clinic ? 'Search employee, report, or test' : 'Search your reports'); ?>
            <nav class="filters" aria-label="Digital report status">
                <?php foreach (['all' => 'All reports', 'needs_review' => 'Pending', 'reviewed' => 'Submitted'] as $key => $name): ?>
                    <a class="filter <?= $status === $key ? 'selected' : '' ?>" href="<?= h(url('reports', ['status' => $key, 'q' => $search])) ?>"><?= h($name) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php reports_table($reports, $clinic); ?>
    </section>
<?php elseif ($page === 'intake'): ?>
    <a class="text-link" href="<?= h(url('reports')) ?>">← Laboratory reports</a>
    <div class="page-heading"><div><h1>Upload laboratory report</h1><p>Choose a file from your scanner or take a clear photo of the full page.</p></div></div>
    <div class="intake-layout">
        <section class="card panel">
            <h2>Attach a report</h2>
            <form method="post" enctype="multipart/form-data" class="stack" data-upload-form>
                <?= form_action('upload') ?>
                <?php if ($clinic) employee_select($employees, (int)query('employee')); ?>
                <label>APE year <?= $clinic ? '(leave blank for a non-APE report)' : '' ?>
                    <input type="number" name="ape_year" min="1900" max="2099" value="<?= $year ?>" <?= $clinic ? '' : 'required' ?>>
                </label>
                <label class="upload-field">Laboratory file
                    <input type="file" name="report_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                    <small>PDF, JPG, or PNG · up to 8 MB</small>
                </label>
                <p data-camera-message class="notice" role="status" hidden></p>
                <div class="actions"><button type="button" class="button secondary" data-camera-open>Use camera</button></div>
                <div class="camera-box" data-camera-panel hidden>
                    <label>Camera or USB document camera<select data-camera-list></select></label>
                    <video data-camera-view autoplay playsinline muted></video>
                    <div class="actions">
                        <button type="button" class="button secondary" data-capture>Capture page</button>
                        <button type="button" class="quiet" data-camera-close>Close camera</button>
                    </div>
                </div>
                <button class="button primary" type="submit"><?= $clinic ? 'Read report' : 'Submit for review' ?></button>
            </form>
        </section>
        <aside class="stack">
            <?php if ($clinic): ?>
                <section class="card panel"><h2>Enter a report manually</h2><p>Use this when you already have the verified values.</p>
                    <form method="post" class="stack">
                        <?= form_action('new_manual') ?>
                        <?php employee_select($employees, (int)query('employee')); ?>
                        <label>APE year (optional)<input type="number" name="ape_year" min="1900" max="2099" value="<?= $year ?>"></label>
                        <button class="button secondary" type="submit">Open manual entry</button>
                    </form>
                </section>
            <?php else: ?>
                <section class="card panel"><h2>After uploading</h2><p>Your upload stays Pending until the clinic approves it. Bring the original to the clinic so staff can record its receipt.</p></section>
            <?php endif; ?>
            <?php if ($config['demo_mode'] && ($clinic || $ownId === 1)): ?>
                <section class="card panel sample-panel"><h2>Try a sample report</h2><p>Use the fictional lab report for Mara Santos to test upload and review.</p>
                    <form method="post">
                        <?= form_action('sample', ['employee_id' => 1, 'ape_year' => 2026]) ?>
                        <button type="submit" class="button secondary">Load sample report</button>
                    </form>
                </section>
            <?php endif; ?>
        </aside>
    </div>
<?php elseif ($page === 'review'): ?>
    <a class="text-link" href="<?= h(url('reports')) ?>">← Laboratory reports</a>
    <div class="page-heading">
        <div><h1>Review laboratory report</h1><p><?= h($report['full_name']) ?> · <?= h($report['employee_no']) ?> · <?= h($report['office']) ?></p></div>
        <div class="actions"><?php status_badge($report['review_status']); button('report', 'Open details', ['id' => $report['id']]); ?></div>
    </div>
    <div class="review-layout">
        <section class="card original">
            <div class="panel-title">
                <h2>Original report</h2>
                <?php if ($report['storage_key']): ?><a class="text-link" href="<?= h(url('source', ['id' => $report['id']])) ?>" target="_blank" rel="noopener">Open original</a><?php endif; ?>
            </div>
            <?php
            $hasPreview = $report['storage_key'] && ($report['source_mime'] !== 'application/pdf'
                || is_file(scan_path($config, $report['storage_key']) . '.preview.png'));
            ?>
            <?php if ($hasPreview): ?>
                <div class="image-preview"><img src="<?= h(url('source', ['id' => $report['id'], 'preview' => '1'])) ?>" alt="Scan of the laboratory report"></div>
            <?php elseif ($report['storage_key']): ?>
                <p class="empty">Open the original file to compare pages and confirm the results.</p>
            <?php else: ?><p class="empty">No source file is attached. Compare your entries with the paper report.</p><?php endif; ?>
            <?php if ($report['source_identity']): ?><p class="card-foot">Name / ID read from the source <strong><?= h($report['source_identity']) ?></strong></p><?php endif; ?>
        </section>
        <section class="card panel">
            <h2>Editable results</h2>
            <?php foreach ((json_decode($report['extraction_note'] ?? '[]', true) ?: []) as $warning): ?>
                <p class="notice warn"><?= h($warning) ?></p>
            <?php endforeach; ?>
            <form method="post" class="review-form">
                <?= form_action('save_report', ['report_id' => $report['id'], 'edit_version' => $report['edit_version']]) ?>
                <div class="field-grid">
                    <?php input_field('Result date', 'report_date', $report['report_date'], 'date');
                    input_field('Report number', 'report_no', $report['report_no'], 'text', 60);
                    input_field('Laboratory', 'laboratory', $report['laboratory']);
                    input_field('APE year (optional)', 'ape_year', $report['ape_year'], 'number'); ?>
                </div>
                <div aria-label="Laboratory measurements">
                    <div class="result-row row-labels" aria-hidden="true"><span>Test</span><span>Result</span><span>Unit</span><span></span></div>
                    <div data-test-rows>
                    <?php
                    $testRow = static function (array $test): void { ?>
                        <div class="result-row">
                            <input aria-label="Test name" name="test_name[]" value="<?= h($test['test_name'] ?? '') ?>" maxlength="120" placeholder="Test">
                            <input aria-label="Result value" name="result_value[]" value="<?= h($test['result_value'] ?? '') ?>" maxlength="200" placeholder="Result">
                            <input aria-label="Unit" name="unit[]" value="<?= h($test['unit'] ?? '') ?>" maxlength="40" placeholder="Unit">
                            <button type="button" class="remove" aria-label="Remove test result" data-remove-row>×</button>
                        </div>
                        <?php
                    };
                    foreach ($report['results'] ?: [[]] as $test) $testRow($test);
                    ?>
                    </div>
                    <template id="result-row-template"><?php $testRow([]); ?></template>
                    <button type="button" class="quiet add-result" data-add-row>+ Add result</button>
                </div>
                <?php textarea_field('Message to employee while Pending (optional)', 'review_message', $report, 600); ?>
                <label class="confirm"><input type="checkbox" name="confirmed" value="yes">I checked that the report belongs to this employee and confirmed the date, results, and units against the source.</label>
                <div class="actions">
                    <button type="submit" name="approve" value="1" class="button primary">Approve report</button>
                    <button type="submit" class="button secondary">Save draft</button>
                </div>
            </form>
        </section>
    </div>
<?php else: ?>
    <?php $approved = $report['review_status'] === 'reviewed'; ?>
    <a class="text-link" href="<?= h(url('reports')) ?>">← Laboratory reports</a>
    <div class="page-heading">
        <div><h1><?= h($report['report_no'] ?: 'Lab report #' . $report['id']) ?></h1>
            <p><?= h($report['full_name']) ?><?php if ($clinic || $approved): ?> · <?= h(date_label($report['report_date'])) ?><?php endif; ?></p></div>
        <div class="actions"><?php status_badge($report['review_status']); ?>
            <?php if ($approved) button('print_result', 'Print / Save PDF', ['id' => $report['id']], true);
            elseif ($clinic) button('review', 'Review report', ['id' => $report['id']], true); ?>
        </div>
    </div>
    <div class="intake-layout">
        <section class="card">
            <div class="panel-title"><h2><?= $clinic || $approved ? 'Laboratory results' : 'Digital submission' ?></h2></div>
            <?php if ($clinic || $approved): ?><?php result_table($report['results']); ?>
                <p class="card-foot"><?= h($report['laboratory'] ?: 'Laboratory name not recorded') ?>
                    <?php if ($report['reviewer']): ?> <span>Reviewed by <?= h($report['reviewer']) ?></span><?php endif; ?>
                </p>
            <?php else: ?><p class="empty">The clinic is reviewing this upload. Approved results will appear here.</p><?php endif; ?>
            <?php if (!$approved && $report['review_message']): ?>
                <div class="panel guidance"><strong>Message from the clinic</strong><p><?= nl2br(h($report['review_message'])) ?></p></div>
            <?php endif; ?>
        </section>
        <aside class="card panel">
            <h2>Paper original</h2>
            <?php if ($report['storage_key']): ?>
                <p><?= $report['paper_received_at'] ? 'Received on ' . h(date_label($report['paper_received_at'])) : 'The original is due at the clinic.' ?></p>
                <div class="actions section-top">
                    <a class="button secondary" href="<?= h(url('source', ['id' => $report['id']])) ?>">Open attached scan</a>
                    <?php if ($clinic && !$report['paper_received_at']): ?>
                        <form method="post"><?= form_action('receive_paper', ['report_id' => $report['id']]) ?>
                            <button type="submit" class="button primary">Mark original received</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?><p>No uploaded original is attached.</p><?php endif; ?>
        </aside>
    </div>
<?php endif; ?>
