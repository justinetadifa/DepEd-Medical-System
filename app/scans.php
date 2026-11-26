<?php
declare(strict_types=1);

const MAX_SCAN_BYTES = 8 * 1024 * 1024;
const SCAN_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

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

function scan_path(array $config, string $key): string
{
    if (!preg_match('/\A[a-f0-9]{36}\.(pdf|jpg|png)\z/', $key)) {
        throw new RuntimeException('The report file could not be located.');
    }
    return scan_root($config) . DIRECTORY_SEPARATOR . $key;
}

function stage_scan(array $config, bool $sample): array
{
    if ($sample) {
        $original = __DIR__ . '/../samples/northstar-report.pdf';
        $name = 'northstar-report.pdf';
    } else {
        $file = $_FILES['report_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Choose a PDF, JPG, or PNG file up to 8 MB.');
        }
        $original = $file['tmp_name'];
        $name = basename(str_replace('\\', '/', (string)$file['name']));
        $name = substr(preg_replace('/[^a-zA-Z0-9. _-]/', '_', $name) ?? '', 0, 140) ?: 'report';
    }
    $size = filesize($original);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($original);
    if ($size === false || $size < 1 || $size > MAX_SCAN_BYTES || !isset(SCAN_TYPES[$mime])) {
        throw new RuntimeException('Choose a valid PDF, JPG, or PNG file up to 8 MB.');
    }
    if ($mime !== 'application/pdf') {
        $dims = @getimagesize($original);
        if ($dims === false || min($dims[0], $dims[1]) < 350 || $dims[0] * $dims[1] > 30_000_000) {
            throw new RuntimeException('Try again with a clear, full-page image between 350 pixels per side and 30 megapixels.');
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
        CURLOPT_TIMEOUT => 40,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $result = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if (!is_string($result)) {
        $fallback['warnings'][] = 'The Python service is offline. Run python ocr/server.py, or enter the results manually.';
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

function deliver_scan(array $config, array $report, bool $preview): never
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
    header('Content-Type: ' . $mime);
    $disposition = $mime === 'application/pdf' ? 'attachment' : 'inline';
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
