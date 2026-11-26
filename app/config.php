<?php
declare(strict_types=1);

return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'deped_medical_v2',
    'db_user' => 'root',
    'db_password' => '',
    'storage_dir' => dirname(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: __DIR__) . DIRECTORY_SEPARATOR . 'deped-medical-v2-data',
    'ocr_url' => 'http://127.0.0.1:8765',
    'demo_mode' => true,
    'email_domains' => ['deped.gov.ph'],
    'mail_from' => '',
    'timezone' => 'Asia/Manila',
];
