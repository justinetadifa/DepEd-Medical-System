<?php
/**
 * Laboratory workflow screens rendered inside app/view.php:
 * - 'reports': Searchable list of reports with All / Pending / Submitted filters.
 * - 'intake':  File upload (scanner PDF/JPG/PNG) + live camera capture/preview/Retake + clinic manual entry.
 * - 'review':  Clinic side-by-side original scan & editable draft (save draft or approve -> Submitted).
 * - 'report':  Report details, printable view link (after approval), and separate paper-original receipt.
 */
if ($page === 'reports'): ?>
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
    <?php
    // Restore employee & APE year after a failed/retake upload (keeps blank APE year intact for clinic non-APE reports).
    $intakeOld = ($old['form'] ?? '') === 'intake' ? ($old['data'] ?? []) : null;
    $selectedEmployee = $intakeOld !== null ? (int)($intakeOld['employee_id'] ?? 0) : (int)query('employee');
    if ($intakeOld !== null && array_key_exists('ape_year', $intakeOld)) {
        $intakeYear = (string)$intakeOld['ape_year'];
    } elseif (isset($_GET['year'])) {
        $intakeYear = query('year');
    } else {
        $intakeYear = (string)$year;
    }
    if (!$clinic && $intakeYear === '') {
        $intakeYear = (string)$year;
    }
    ?>
    <a class="text-link" href="<?= h(url('reports')) ?>">← Laboratory reports</a>
    <div class="page-heading"><div><h1>Upload laboratory report</h1><p>Choose a PDF, JPG, or PNG from a flatbed scanner or capture the full page with a built-in or USB document camera.</p></div></div>
    <div class="intake-layout">
        <section class="card panel">
            <h2>Attach a report</h2>
            <form method="post" enctype="multipart/form-data" class="stack" data-upload-form>
                <?= form_action('upload') ?>
                <?php if ($clinic) employee_select($employees, $selectedEmployee); ?>
                <label>APE year <?= $clinic ? '(leave blank for a non-APE report)' : '' ?>
                    <input type="number" name="ape_year" min="1900" max="2099" value="<?= h($intakeYear) ?>" <?= $clinic ? '' : 'required' ?>>
                </label>
                <div class="actions">
                    <button type="button" class="button secondary" data-upload-trigger>Upload file</button>
                    <button type="button" class="button secondary" data-camera-open>Use camera</button>
                </div>
                <label class="upload-field">Laboratory file
                    <input type="file" name="report_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                    <small>PDF, JPG, or PNG · up to 8 MB · up to 5 pages for OCR</small>
                    <span class="file-summary" data-file-summary hidden></span>
                </label>
                <p data-camera-message class="notice" role="status" aria-live="polite" hidden></p>
                <div class="camera-box" data-camera-panel hidden>
                    <label>Camera or USB document camera<select data-camera-list></select></label>
                    <video data-camera-view autoplay playsinline muted></video>
                    <small data-camera-hint>Keep the full page flat, well-lit, and in focus before capturing.</small>
                    <div class="actions">
                        <button type="button" class="button primary" data-capture disabled>Capture page</button>
                        <button type="button" class="quiet" data-camera-close>Close camera</button>
                    </div>
                </div>
                <div class="capture-preview" data-capture-preview hidden>
                    <div class="capture-preview-head">
                        <div>
                            <strong data-capture-title>Captured page preview</strong>
                            <small data-capture-meta>Check that text, numbers, and units are sharp before submitting.</small>
                        </div>
                        <div class="actions">
                            <button type="button" class="button secondary" data-retake>Retake</button>
                            <button type="button" class="quiet" data-clear-capture>Remove</button>
                        </div>
                    </div>
                    <div class="capture-frame"><img data-capture-image alt="Preview of the captured laboratory report"></div>
                </div>
                <button class="button primary" type="submit" data-submit-report><?= $clinic ? 'Read report' : 'Submit for review' ?></button>
            </form>
        </section>
        <aside class="stack">
            <?php if ($clinic): ?>
                <section class="card panel"><h2>Enter a report manually</h2><p>Use this for a readable report with an unfamiliar layout, when OCR is unavailable, or when you already have the verified values.</p>
                    <form method="post" class="stack" data-once-form>
                        <?= form_action('new_manual') ?>
                        <?php employee_select($employees, $selectedEmployee); ?>
                        <label>APE year (optional)<input type="number" name="ape_year" min="1900" max="2099" value="<?= h($intakeYear) ?>"></label>
                        <button class="button secondary" type="submit">Open manual entry</button>
                    </form>
                </section>
            <?php else: ?>
                <section class="card panel"><h2>After uploading</h2><p>Your upload stays Pending until a nurse or doctor reviews and approves it. Bring the paper original to the clinic so staff can record its receipt.</p></section>
            <?php endif; ?>
            <?php if ($config['demo_mode'] && ($clinic || $ownId === 1)): ?>
                <section class="card panel sample-panel"><h2>Try a sample report</h2><p>Use the fictional lab report for Mara Santos to test upload and review.</p>
                    <form method="post" data-once-form>
                        <?= form_action('sample', ['employee_id' => 1, 'ape_year' => 2026]) ?>
                        <button type="submit" class="button secondary">Load sample report</button>
                    </form>
                </section>
            <?php endif; ?>
        </aside>
    </div>
<?php elseif ($page === 'review'): ?>
    <?php
    // Prepare OCR warnings, extraction badge label, and an automatic source-identity vs. employee mismatch check.
    $warnings = json_decode($report['extraction_note'] ?? '[]', true) ?: [];
    $methodLabel = match ($report['extraction_method'] ?? 'manual') {
        'pdf-text' => 'Digital PDF text',
        'tesseract' => 'Image OCR (Tesseract)',
        'paddleocr' => 'Image OCR (PaddleOCR)',
        'sample' => 'Practice sample',
        'demo' => 'Demo record',
        default => 'Manual entry',
    };
    $identityMismatch = false;
    if (!empty($report['source_identity'])) {
        $identityLower = strtolower($report['source_identity']);
        $empNoMatch = $report['employee_no'] !== '' && str_contains($identityLower, strtolower($report['employee_no']));
        $nameTokens = array_filter(preg_split('/\s+/', strtolower($report['full_name'])) ?: [], static fn(string $t): bool => strlen($t) >= 2);
        $nameMatch = $nameTokens && count(array_filter($nameTokens, static fn(string $t): bool => str_contains($identityLower, $t))) === count($nameTokens);
        $identityMismatch = !$empNoMatch && !$nameMatch;
    }
    $flagMissing = (bool)$report['storage_key'] || (bool)$warnings;
    ?>
    <a class="text-link" href="<?= h(url('reports')) ?>">← Laboratory reports</a>
    <div class="page-heading">
        <div><h1>Review laboratory report</h1><p><?= h($report['full_name']) ?> · <?= h($report['employee_no']) ?> · <?= h($report['office']) ?></p></div>
        <div class="actions"><?php status_badge($report['review_status']); button('report', 'Open details', ['id' => $report['id']]); ?></div>
    </div>
    <div class="review-layout">
        <section class="card original">
            <div class="panel-title">
                <h2>Original report</h2>
                <?php if ($report['storage_key']): ?><a class="text-link" href="<?= h(url('source', ['id' => $report['id'], 'inline' => '1'])) ?>" target="_blank" rel="noopener">Open original</a><?php endif; ?>
            </div>
            <?php
            $hasPreview = $report['storage_key'] && ($report['source_mime'] !== 'application/pdf'
                || is_file(scan_path($config, $report['storage_key']) . '.preview.png'));
            ?>
            <?php if ($hasPreview): ?>
                <div class="image-preview"><img src="<?= h(url('source', ['id' => $report['id'], 'preview' => '1'])) ?>" alt="Scan of the laboratory report"></div>
                <?php if ($report['source_mime'] === 'application/pdf'): ?>
                    <p class="card-foot">Page 1 preview shown. Use <strong>Open original</strong> to inspect multi-page PDFs.</p>
                <?php endif; ?>
            <?php elseif ($report['storage_key'] && $report['source_mime'] === 'application/pdf'): ?>
                <iframe src="<?= h(url('source', ['id' => $report['id'], 'inline' => '1'])) ?>" title="Original laboratory report PDF"></iframe>
            <?php elseif ($report['storage_key']): ?>
                <p class="empty">Open the original file to compare pages and confirm the results.</p>
            <?php else: ?><p class="empty">No source file is attached. Compare your entries with the paper report.</p><?php endif; ?>
            <?php if ($report['source_identity'] || $report['storage_key']): ?>
                <div class="card-foot">
                    <?php if ($report['source_identity']): ?><span>Name / ID read from source: <strong><?= h($report['source_identity']) ?></strong></span><?php endif; ?>
                    <?php if ($report['storage_key']): ?><span>Paper original: <strong><?= $report['paper_received_at'] ? 'Received ' . h(date_label($report['paper_received_at'])) : 'Due at clinic' ?></strong></span><?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <section class="card panel">
            <div class="split-title">
                <h2>Editable results</h2>
                <span class="status neutral"><?= h($methodLabel) ?></span>
            </div>
            <?php if ($identityMismatch): ?>
                <p class="notice error">The name or ID read from the source (<?= h($report['source_identity']) ?>) does not match <?= h($report['full_name']) ?> (<?= h($report['employee_no']) ?>). Verify the employee before approving.</p>
            <?php endif; ?>
            <?php foreach ($warnings as $warning): ?>
                <p class="notice warn"><?= h($warning) ?></p>
            <?php endforeach; ?>
            <form method="post" class="review-form" data-once-form>
                <?= form_action('save_report', ['report_id' => $report['id'], 'edit_version' => $report['edit_version']]) ?>
                <div class="field-grid">
                    <label>Result date
                        <input type="date" name="report_date" value="<?= h($report['report_date'] ?? '') ?>" class="<?= $flagMissing && empty($report['report_date']) ? 'needs-check' : '' ?>">
                    </label>
                    <label>Report number
                        <input type="text" name="report_no" value="<?= h($report['report_no'] ?? '') ?>" maxlength="60" class="<?= $flagMissing && empty($report['report_no']) ? 'needs-check' : '' ?>">
                    </label>
                    <label>Laboratory
                        <input type="text" name="laboratory" value="<?= h($report['laboratory'] ?? '') ?>" maxlength="120" class="<?= $flagMissing && empty($report['laboratory']) ? 'needs-check' : '' ?>">
                    </label>
                    <?php input_field('APE year (optional)', 'ape_year', $report['ape_year'], 'number'); ?>
                </div>
                <div aria-label="Laboratory measurements">
                    <div class="result-row row-labels" aria-hidden="true"><span>Test</span><span>Result</span><span>Unit</span><span></span></div>
                    <div data-test-rows>
                    <?php
                    $testRow = static function (array $test, bool $highlightUnit = false): void {
                        $val = (string)($test['result_value'] ?? '');
                        $unit = (string)($test['unit'] ?? '');
                        $unitMissing = $highlightUnit && $val !== '' && preg_match('/\d/', $val) && $unit === '';
                        ?>
                        <div class="result-row">
                            <input aria-label="Test name" name="test_name[]" value="<?= h($test['test_name'] ?? '') ?>" maxlength="120" placeholder="Test">
                            <input aria-label="Result value" name="result_value[]" value="<?= h($val) ?>" maxlength="200" placeholder="Result">
                            <input aria-label="Unit" name="unit[]" value="<?= h($unit) ?>" maxlength="40" placeholder="Unit" class="<?= $unitMissing ? 'needs-check' : '' ?>">
                            <button type="button" class="remove" aria-label="Remove test result" data-remove-row>×</button>
                        </div>
                        <?php
                    };
                    foreach ($report['results'] ?: [[]] as $test) $testRow($test, $flagMissing);
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
        <div><h1><?= h(($clinic || $approved) && $report['report_no'] ? $report['report_no'] : 'Lab report #' . $report['id']) ?></h1>
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
                <p>
                    <?php if ($report['paper_received_at']): ?>
                        Received on <?= h(date_label($report['paper_received_at'])) ?><?= $report['receiver'] ? ' (' . h($report['receiver']) . ')' : '' ?>.
                    <?php else: ?>
                        The paper original is due at the clinic.
                    <?php endif; ?>
                </p>
                <div class="actions section-top">
                    <a class="button secondary" href="<?= h(url('source', ['id' => $report['id'], 'inline' => '1'])) ?>" target="_blank" rel="noopener">Open attached scan</a>
                    <?php if ($clinic && !$report['paper_received_at']): ?>
                        <form method="post" data-once-form><?= form_action('receive_paper', ['report_id' => $report['id']]) ?>
                            <button type="submit" class="button primary">Mark original received</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?><p>No uploaded original is attached.</p><?php endif; ?>
        </aside>
    </div>
<?php endif; ?>
