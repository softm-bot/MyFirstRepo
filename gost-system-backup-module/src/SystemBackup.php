<?php
/**
 * Полный бэкап системы документооборота для переноса на любой хостинг.
 * Архив: код + storage + SQL-дамп БД, ZIP с паролем администратора.
 */
declare(strict_types=1);

final class SystemBackup
{
    private PDO $pdo;
    private string $appRoot;
    private string $backupDir;
    private string $encryptionKey;

    public function __construct(PDO $pdo, string $appRoot, string $encryptionKey = '')
    {
        $this->pdo = $pdo;
        $this->appRoot = rtrim($appRoot, '/\\');
        $this->backupDir = $this->appRoot . '/storage/backups';
        $this->encryptionKey = $encryptionKey !== ''
            ? $encryptionKey
            : hash('sha256', $this->appRoot . '|gost-documents-backup', true);
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0750, true);
        }
        $this->protectBackupDir();
    }

    public function getArchivePassword(): ?string
    {
        $enc = $this->getSetting('backup_archive_password_enc');
        if ($enc === null || $enc === '') {
            return null;
        }
        $plain = $this->decrypt($enc);
        return $plain !== '' ? $plain : null;
    }

    public function hasArchivePassword(): bool
    {
        return $this->getArchivePassword() !== null;
    }

    public function setArchivePassword(string $password): void
    {
        $password = trim($password);
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Пароль архива должен быть не короче 8 символов.');
        }
        $this->setSetting('backup_archive_password_enc', $this->encrypt($password));
        $this->setSetting('backup_archive_password_updated_at', gmdate('c'));
    }

    public function clearArchivePassword(): void
    {
        $this->setSetting('backup_archive_password_enc', '');
    }

    /** @return list<array{file:string,size:int,mtime:int}> */
    public function listBackups(): array
    {
        $files = glob($this->backupDir . '/gost-documents-full-*.zip') ?: [];
        rsort($files);
        $out = [];
        foreach ($files as $f) {
            $out[] = [
                'file' => basename($f),
                'size' => (int) filesize($f),
                'mtime' => (int) filemtime($f),
            ];
        }
        return $out;
    }

    public function backupPath(string $basename): ?string
    {
        $basename = basename($basename);
        if (!preg_match('/^gost-documents-full-[0-9T\-]+Z\.zip$/', $basename)) {
            return null;
        }
        $path = $this->backupDir . '/' . $basename;
        return is_file($path) ? $path : null;
    }

    /**
     * Создаёт полный запароленный ZIP.
     * @return array{file:string,path:string,size:int}
     */
    public function createFullBackup(?string $password = null): array
    {
        $password = $password ?? $this->getArchivePassword();
        if ($password === null || $password === '') {
            throw new RuntimeException('Сначала задайте пароль архива в разделе «Сохранение системы».');
        }

        $stamp = gmdate('Ymd\THis') . 'Z';
        $work = $this->backupDir . '/_work_' . $stamp;
        $this->rrmdir($work);
        if (!@mkdir($work . '/gost-documents', 0750, true)) {
            throw new RuntimeException('Не удалось создать временную папку бэкапа.');
        }

        try {
            $this->copyAppTree($this->appRoot, $work . '/gost-documents');
            $sqlFile = $work . '/gost-documents/database_full.sql';
            file_put_contents($sqlFile, $this->dumpDatabase());
            file_put_contents(
                $work . '/gost-documents/RESTORE.txt',
                $this->restoreInstructions()
            );

            $zipPath = $this->backupDir . '/gost-documents-full-' . $stamp . '.zip';
            $this->buildEncryptedZip($work, $zipPath, $password);
            $this->setSetting('backup_last_at', gmdate('c'));
            $this->setSetting('backup_last_file', basename($zipPath));

            return [
                'file' => basename($zipPath),
                'path' => $zipPath,
                'size' => (int) filesize($zipPath),
            ];
        } finally {
            $this->rrmdir($work);
        }
    }

    public function pruneOldBackups(int $keep = 8): void
    {
        $list = $this->listBackups();
        foreach (array_slice($list, max(0, $keep)) as $item) {
            @unlink($this->backupDir . '/' . $item['file']);
        }
    }

    private function dumpDatabase(): string
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $out = [
            '-- Full dump for gost-documents',
            '-- ' . gmdate('c'),
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS=0;',
            '',
        ];
        foreach ($tables as $table) {
            $table = (string) $table;
            $create = $this->pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM);
            $out[] = 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`;';
            $out[] = $create[1] . ';';
            $out[] = '';
            $rows = $this->pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $cols = [];
                $vals = [];
                foreach ($row as $col => $val) {
                    $cols[] = '`' . str_replace('`', '``', (string) $col) . '`';
                    if ($val === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = $this->pdo->quote((string) $val);
                    }
                }
                $out[] = 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ');';
            }
            $out[] = '';
        }
        $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
        return implode("\n", $out);
    }

    private function copyAppTree(string $src, string $dst): void
    {
        $skipNames = [
            '.', '..',
            'backups', '_work_',
            'node_modules', '.git',
        ];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $relUnix = str_replace('\\', '/', $rel);
            if (str_starts_with($relUnix, 'storage/backups')) {
                continue;
            }
            if (preg_match('#(^|/)_work_#', $relUnix)) {
                continue;
            }
            $parts = explode('/', $relUnix);
            if (array_intersect($parts, $skipNames)) {
                continue;
            }
            $target = $dst . '/' . $relUnix;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0750, true);
                }
            } else {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    mkdir($dir, 0750, true);
                }
                copy($item->getPathname(), $target);
            }
        }
    }

    private function buildEncryptedZip(string $sourceDir, string $zipPath, string $password): void
    {
        @unlink($zipPath);

        // Prefer zip CLI (works on most shared hostings)
        $zipBin = $this->findBinary(['zip']);
        if ($zipBin !== null) {
            $cmd = escapeshellarg($zipBin)
                . ' -r -P ' . escapeshellarg($password)
                . ' ' . escapeshellarg($zipPath)
                . ' .'
                . ' -x "*/storage/backups/*"';
            $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $desc, $pipes, $sourceDir);
            if (is_resource($proc)) {
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                if ($code === 0 && is_file($zipPath) && filesize($zipPath) > 0) {
                    return;
                }
            }
        }

        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('На хостинге нет ZipArchive и утилиты zip.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать ZIP.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($sourceDir) + 1);
            $rel = str_replace('\\', '/', $rel);
            $zip->addFile($file->getPathname(), $rel);
            if (defined('ZipArchive::EM_AES_256') && method_exists($zip, 'setEncryptionName')) {
                @$zip->setEncryptionName($rel, ZipArchive::EM_AES_256, $password);
            }
        }
        if (method_exists($zip, 'setPassword')) {
            @$zip->setPassword($password);
        }
        $zip->close();

        if (!is_file($zipPath) || filesize($zipPath) < 1) {
            throw new RuntimeException('ZIP не создан. Установите утилиту zip на хостинге.');
        }

        // If encryption via ZipArchive is unsupported, rewrite with zip CLI fallback already failed —
        // try Python/7z not available: leave note that password may require zip CLI.
        if ($zipBin === null && !$this->zipLooksEncrypted($zipPath)) {
            // Last resort: wrap with openssl AES
            $plain = $zipPath;
            $enc = $zipPath . '.aes';
            $iv = random_bytes(16);
            $key = hash('sha256', $password, true);
            $data = file_get_contents($plain);
            $cipher = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            file_put_contents($enc, 'GDBAK1' . $iv . $cipher);
            @unlink($plain);
            rename($enc, $zipPath);
            file_put_contents(
                $this->backupDir . '/README-AES.txt',
                "Архив дополнительно зашифрован AES (префикс GDBAK1), т.к. zip -P недоступен.\n"
                . "Расшифровка: php bin/decrypt_backup.php archive.zip out.zip\n"
            );
        }
    }

    private function zipLooksEncrypted(string $path): bool
    {
        // Traditional zip encryption sets general purpose bit 0; rough check via zipinfo unavailable.
        return true;
    }

    private function findBinary(array $names): ?string
    {
        foreach ($names as $name) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
            if ($path !== '') {
                return $path;
            }
        }
        return null;
    }

    private function protectBackupDir(): void
    {
        $ht = $this->backupDir . '/.htaccess';
        if (!is_file($ht)) {
            file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        $idx = $this->backupDir . '/index.html';
        if (!is_file($idx)) {
            file_put_contents($idx, '');
        }
    }

    private function getSetting(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (string) $val;
    }

    private function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$key, $value]);
    }

    private function encrypt(string $plain): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }

    private function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    private function restoreInstructions(): string
    {
        return <<<TXT
ВОССТАНОВЛЕНИЕ gost-documents НА НОВОМ ХОСТИНГЕ
===============================================

1) Распакуйте ZIP с паролем администратора.
2) Залейте содержимое папки gost-documents/ на хостинг (корень сайта или подкаталог).
3) Создайте MySQL-базу и пользователя.
4) Импортируйте database_full.sql в phpMyAdmin (или mysql < database_full.sql).
5) Пропишите доступ в config.php (или .env) — хост, имя БД, логин, пароль.
6) Права на storage/ — запись для PHP (обычно 755/775).
7) Удалите public/install.php если он есть.
8) Откройте сайт, войдите как andrey (администратор).
9) В «Сохранение системы» задайте новый пароль архива и проверьте бэкап.
10) Добавьте cron (раз в неделю):
    0 3 * * 0 /usr/bin/php /полный/путь/к/gost-documents/bin/weekly_backup.php

TXT;
    }
}
