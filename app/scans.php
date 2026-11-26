<?php
declare(strict_types=1);

/**
 * Laboratory scan intake, local storage outside htdocs, Python OCR bridge,
 * and authenticated file/preview delivery.
 *
 * Troubleshooting quick check:
 * - "storage folder outside htdocs": check storage_dir in app/config.php or app/config.local.php.
 * - "Python OCR service is unavailable": start `python ocr/server.py` on 127.0.0.1:8765.
 * - Large upload rejected before reaching PHP: check upload_max_filesize / post_max_size in php.ini.
 */

const MAX_SCAN_BYTES = 8 * 1024 * 1024;
const SCAN_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

/** Resolve and validate the private report storage root (must never live inside Apache htdocs). */
function scan_root(array $config): string
{
    $folder = $config['storage_dir'];
    if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
        throw new RuntimeException('The report storage folder could not be created.');
    }
    $root = realpath($folder);
    $public = realpath($_SERVER['DOCUMENT_ROOT']);
    if ($root === false || $public === false) {
        throw new RuntimeException('The report storage folder could not be opened.');
    }
    $normalized = static fn(string $path): string => strtolower(str_replace('\\', '/', $path));
    if (str_starts_with($normalized($root) . '/', rtrim($normalized($public), '/') . '/')) {
        throw new RuntimeException('Choose a report storage folder outside htdocs in app/config.local.php.');
    }
    return $root;
}

/** Build an absolute storage path after strictly validating the generated storage key format. */
function scan_path(array $config, string $key): string
{
    if (!preg_match('/\A[a-f0-9]{36}\.(pdf|jpg|png)\z/', $key)) {
        throw new RuntimeException('The report file could not be located.');
    }
    return scan_root($config) . DIRECTORY_SEPARATOR . $key;
}

/**
 * Validate an uploaded file (or bundled demo sample) and move it into private storage.
 * Checks PHP upload errors, 8 MB size limit, real MIME type via finfo, and image pixel bounds.
 */
function stage_scan(array $config, bool $sample): array
{
    if ($sample) {
        $original = __DIR__ . '/../samples/northstar-report.pdf';
        $name = 'northstar-report.pdf';
    } else {
        $file = $_FILES['report_file'] ?? null;
        if (!is_array($file)) {
            throw new RuntimeException('Choose a PDF, JPG, or PNG file, or capture a page with your camera.');
        }
        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('This file exceeds the 8 MB upload limit. Split the report or scan at a lower resolution and try again.');
        }
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Choose a PDF, JPG, or PNG file, or capture a page with your camera.');
        }
        if ($uploadError !== UPLOAD_ERR_OK || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('The file upload could not be completed. Try again with a PDF, JPG, or PNG file up to 8 MB.');
        }
        $original = $file['tmp_name'];
        $name = basename(str_replace('\\', '/', (string)$file['name']));
        $name = substr(preg_replace('/[^a-zA-Z0-9. _-]/', '_', $name) ?? '', 0, 140) ?: 'report';
    }
    $size = filesize($original);
    if ($size === false || $size < 1) {
        throw new RuntimeException('Try again. The selected file is empty.');
    }
    if ($size > MAX_SCAN_BYTES) {
        throw new RuntimeException('This file exceeds the 8 MB upload limit. Split the report or scan at a lower resolution and try again.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($original);
    if (!isset(SCAN_TYPES[$mime])) {
        throw new RuntimeException('Unsupported file type. Upload a PDF, JPG, or PNG laboratory report.');
    }
    if ($mime !== 'application/pdf') {
        $dims = @getimagesize($original);
        if ($dims === false || min($dims[0], $dims[1]) < 350 || $dims[0] * $dims[1] > 30_000_000) {
            throw new RuntimeException('Try again. Capture or scan the full page clearly (at least 350 pixels per side and under 30 megapixels).');
        }
    }
    $key = bin2hex(random_bytes(18)) . '.' . SCAN_TYPES[$mime];
    $target = scan_path($config, $key);
    $moved = $sample ? copy($original, $target) : move_uploaded_file($original, $target);
    if (!$moved) {
        throw new RuntimeException('The scan could not be copied to report storage.');
    }
    if ($sample) {
        copy(__DIR__ . '/../samples/northstar-report.png', $target . '.preview.png');
    }
    return ['key' => $key, 'mime' => $mime, 'name' => $name, 'hash' => hash_file('sha256', $target)];
}

function delete_scan(array $config, array $scan): void
{
    $file = scan_path($config, $scan['key']);
    foreach ([$file, $file . '.preview.png'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

/**
 * Send a staged scan to the local Python OCR service (POST /extract).
 *
 * Contract with ocr/server.py:
 * - HTTP 200: returns {fields, results, warnings, engine, optional preview_png}.
 * - HTTP 422 with retry=true: unreadable/blurry/blank/over-5-page file -> throws RuntimeException
 *   so actions.php deletes the staged scan and prompts the user to retake/re-upload.
 * - Connection failure / non-retry error: falls back to engine='manual' with a warning so
 *   clinic staff can still enter results manually beside the attached original.
 */
function read_scan(array $config, array $scan): array
{
    $fallback = ['fields' => [], 'results' => [], 'warnings' => [], 'engine' => 'manual'];
    if (!function_exists('curl_init')) {
        $fallback['warnings'][] = 'PHP cURL is disabled. Enable it in XAMPP to use Python extraction, or enter the results manually.';
        return $fallback;
    }
    $url = rtrim($config['ocr_url'], '/') . '/extract';
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => file_get_contents(scan_path($config, $scan['key'])),
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $scan['mime'], 'Expect:'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $result = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if (!is_string($result)) {
        $fallback['warnings'][] = 'The local Python OCR service is unavailable. Start python ocr/server.py or compare the original and enter the results manually.';
        return $fallback;
    }
    $decoded = json_decode($result, true);
    if ($status === 422 && ($decoded['retry'] ?? false) && is_string($decoded['message'] ?? null)) {
        throw new RuntimeException($decoded['message']);
    }
    if (!is_array($decoded) || $status !== 200 || !isset($decoded['fields'], $decoded['results'], $decoded['warnings'], $decoded['engine'])) {
        $fallback['warnings'][] = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Python could not read that file. Check the scan and enter results manually.';
        return $fallback;
    }
    if ($scan['mime'] === 'application/pdf' && is_string($decoded['preview_png'] ?? null)) {
        $preview = base64_decode($decoded['preview_png'], true);
        if (is_string($preview) && strlen($preview) <= 2 * 1024 * 1024 && str_starts_with($preview, "\x89PNG\r\n\x1a\n")) {
            file_put_contents(scan_path($config, $scan['key']) . '.preview.png', $preview, LOCK_EX);
        }
    }
    unset($decoded['preview_png']);
    return $decoded;
}

function sample_draft(): array
{
    return [
        'fields' => ['employee_no' => 'DEMO-0147', 'name' => 'Mara Santos', 'report_no' => 'LAB-1126-018',
            'report_date' => '2026-11-26', 'laboratory' => 'Northstar Diagnostic Lab'],
        'results' => [
            ['test_name' => 'Fasting blood glucose', 'result_value' => '96', 'unit' => 'mg/dL'],
            ['test_name' => 'Total cholesterol', 'result_value' => '176', 'unit' => 'mg/dL'],
            ['test_name' => 'Triglycerides', 'result_value' => '104', 'unit' => 'mg/dL'],
        ],
        'engine' => 'sample',
        'warnings' => ['The sample fields were loaded for practice. Start Python and try the sample again to test extraction.'],
    ];
}

/**
 * Stream an attached original scan or rendered PDF page-1 preview after public/index.php
 * has verified session role and record ownership.
 * When $inline is true for a PDF, frame-ancestors 'self' allows the review screen's fallback iframe.
 */
function deliver_scan(array $config, array $report, bool $preview, bool $inline = false): never
{
    if (!$report['storage_key'] || !isset(SCAN_TYPES[$report['source_mime']])) {
        problem(404, 'No source file is attached to this report.');
    }
    $path = scan_path($config, $report['storage_key']);
    $mime = $report['source_mime'];
    if ($preview && $mime === 'application/pdf') {
        $path .= '.preview.png';
        $mime = 'image/png';
    }
    if (!is_file($path)) {
        problem(404, 'The original or preview could not be found.');
    }
    if ($mime === 'application/pdf' && $inline) {
        header("Content-Security-Policy: default-src 'self'; frame-ancestors 'self'; base-uri 'none'");
    }
    header('Content-Type: ' . $mime);
    $disposition = ($mime === 'application/pdf' && !$inline) ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="laboratory-source.' . SCAN_TYPES[$mime] . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function submitted_tests(): array
{
    $tests = [];
    $names = is_array($_POST['test_name'] ?? null) ? $_POST['test_name'] : [];
    foreach (array_slice($names, 0, 40, true) as $key => $name) {
        $test = [];
        foreach (['test_name', 'result_value', 'unit'] as $field) {
            $part = is_array($_POST[$field] ?? null) ? ($_POST[$field][$key] ?? '') : '';
            $test[$field] = is_string($part) ? trim($part) : '';
        }
        if (implode('', $test) !== '') {
            $tests[] = $test;
        }
    }
    return $tests;
}
