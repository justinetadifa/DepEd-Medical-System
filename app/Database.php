<?php
declare(strict_types=1);

// Adapted from config/database.php in Ahmed-Al-Aini/Hospital-Management-System.
// Copyright (c) 2026 Ahmed Senan Al-Aini. MIT, see reference/UPSTREAM-LICENSE.txt.
final class Database
{
    private static ?self $instance = null;
    private PDO $pdo;

    private function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            $config['db_port'],
            $config['db_name']
        );
        $this->pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function getInstance(array $config): PDO
    {
        self::$instance ??= new self($config);
        return self::$instance->pdo;
    }
}
