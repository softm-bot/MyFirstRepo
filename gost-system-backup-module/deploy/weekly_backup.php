<?php
/**
 * CLI: еженедельный полный бэкап (для cron).
 * Пример: 0 3 * * 0 /usr/bin/php /path/to/gost-documents/bin/weekly_backup.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$appRoot = dirname(__DIR__);
$configFile = null;
foreach ([$appRoot . '/config.php', $appRoot . '/public/config.php'] as $cand) {
    if (is_file($cand)) {
        $configFile = $cand;
        break;
    }
}
if ($configFile === null) {
    fwrite(STDERR, "config.php not found\n");
    exit(1);
}

$config = require $configFile;
$pdo = null;
if ($config instanceof PDO) {
    $pdo = $config;
} elseif (is_array($config)) {
    $host = $config['db_host'] ?? $config['DB_HOST'] ?? 'localhost';
    $name = $config['db_name'] ?? $config['DB_NAME'] ?? '';
    $user = $config['db_user'] ?? $config['DB_USER'] ?? '';
    $pass = $config['db_pass'] ?? $config['DB_PASS'] ?? '';
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "PDO not configured\n");
    exit(1);
}

require_once $appRoot . '/src/SystemBackup.php';

try {
    $backup = new SystemBackup($pdo, $appRoot);
    if (!$backup->hasArchivePassword()) {
        fwrite(STDERR, "Archive password is not set by admin. Skip.\n");
        exit(2);
    }
    $result = $backup->createFullBackup();
    $backup->pruneOldBackups(8);
    fwrite(STDOUT, 'OK ' . $result['file'] . ' ' . $result['size'] . " bytes\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR ' . $e->getMessage() . "\n");
    exit(1);
}
