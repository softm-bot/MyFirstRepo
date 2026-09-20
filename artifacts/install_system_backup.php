<?php
/**
 * Одноразовая установка модуля «Сохранение системы».
 * 1) Залейте этот файл в public/ рядом с index.php через FileZilla.
 * 2) Откройте https://gost.info/gost-documents/install_system_backup.php?key=INSTALL_KEY
 * 3) Файл самоудалится после успеха.
 */
declare(strict_types=1);

const INSTALL_KEY = 'NormaBackupInstall2026';

$key = (string) ($_GET['key'] ?? '');
if (!hash_equals(INSTALL_KEY, $key)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$publicDir = __DIR__;
$appRoot = is_file(dirname($publicDir) . '/config.php') || is_dir(dirname($publicDir) . '/storage')
    ? dirname($publicDir)
    : $publicDir;

@mkdir($appRoot . '/src', 0755, true);
@mkdir($appRoot . '/bin', 0755, true);
@mkdir($appRoot . '/storage/backups', 0750, true);

$systemBackupPhp = <<<'PHP'
<?php
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
            file_put_contents($work . '/gost-documents/database_full.sql', $this->dumpDatabase());
            file_put_contents($work . '/gost-documents/RESTORE.txt', $this->restoreInstructions());
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
        foreach (array_slice($this->listBackups(), max(0, $keep)) as $item) {
            @unlink($this->backupDir . '/' . $item['file']);
        }
    }

    private function dumpDatabase(): string
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $out = ['-- Full dump for gost-documents', '-- ' . gmdate('c'), 'SET NAMES utf8mb4;', 'SET FOREIGN_KEY_CHECKS=0;', ''];
        foreach ($tables as $table) {
            $table = (string) $table;
            $safe = str_replace('`', '``', $table);
            $create = $this->pdo->query('SHOW CREATE TABLE `' . $safe . '`')->fetch(PDO::FETCH_NUM);
            $out[] = 'DROP TABLE IF EXISTS `' . $safe . '`;';
            $out[] = $create[1] . ';';
            $out[] = '';
            $rows = $this->pdo->query('SELECT * FROM `' . $safe . '`');
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $cols = $vals = [];
                foreach ($row as $col => $val) {
                    $cols[] = '`' . str_replace('`', '``', (string) $col) . '`';
                    $vals[] = $val === null ? 'NULL' : $this->pdo->quote((string) $val);
                }
                $out[] = 'INSERT INTO `' . $safe . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ');';
            }
            $out[] = '';
        }
        $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
        return implode("\n", $out);
    }

    private function copyAppTree(string $src, string $dst): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $relUnix = str_replace('\\', '/', $rel);
            if (str_starts_with($relUnix, 'storage/backups') || preg_match('#(^|/)_work_#', $relUnix)) {
                continue;
            }
            if (str_contains($relUnix, '.git/') || str_contains($relUnix, 'node_modules/')) {
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
        $zipBin = trim((string) shell_exec('command -v zip 2>/dev/null'));
        if ($zipBin !== '') {
            $cmd = escapeshellarg($zipBin) . ' -r -P ' . escapeshellarg($password) . ' ' . escapeshellarg($zipPath) . ' .';
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $sourceDir);
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
            throw new RuntimeException('Нужна утилита zip или расширение ZipArchive.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать ZIP.');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($sourceDir) + 1));
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
            throw new RuntimeException('ZIP не создан.');
        }
    }

    private function protectBackupDir(): void
    {
        if (!is_file($this->backupDir . '/.htaccess')) {
            file_put_contents($this->backupDir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($this->backupDir . '/index.html')) {
            file_put_contents($this->backupDir . '/index.html', '');
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
        $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $this->encryptionKey, OPENSSL_RAW_DATA, substr($raw, 0, 16));
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
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    private function restoreInstructions(): string
    {
        return "ВОССТАНОВЛЕНИЕ НА НОВОМ ХОСТИНГЕ\n1) Распаковать ZIP с паролем\n2) Залить gost-documents/\n3) Импортировать database_full.sql\n4) Прописать config.php\n5) Права на storage/\n6) Cron: 0 3 * * 0 php bin/weekly_backup.php\n";
    }
}
PHP;

file_put_contents($appRoot . '/src/SystemBackup.php', $systemBackupPhp);

// Copy view from sibling if present in package; else write minimal redirector
$viewSrc = $publicDir . '/system_backup.php';
if (!is_file($viewSrc)) {
    // placeholder: installer expects system_backup.php uploaded together OR embeds later
}

$weekly = <<<'PHP'
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
$appRoot = dirname(__DIR__);
$configFile = is_file($appRoot . '/config.php') ? $appRoot . '/config.php' : $appRoot . '/public/config.php';
if (!is_file($configFile)) { fwrite(STDERR, "config.php not found\n"); exit(1); }
$config = require $configFile;
$pdo = $config instanceof PDO ? $config : null;
if (!$pdo && is_array($config)) {
    $host = $config['db_host'] ?? $config['DB_HOST'] ?? 'localhost';
    $name = $config['db_name'] ?? $config['DB_NAME'] ?? '';
    $user = $config['db_user'] ?? $config['DB_USER'] ?? '';
    $pass = $config['db_pass'] ?? $config['DB_PASS'] ?? '';
    $pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
require_once $appRoot . '/src/SystemBackup.php';
$backup = new SystemBackup($pdo, $appRoot);
if (!$backup->hasArchivePassword()) { fwrite(STDERR, "No archive password\n"); exit(2); }
$result = $backup->createFullBackup();
$backup->pruneOldBackups(8);
fwrite(STDOUT, 'OK ' . $result['file'] . "\n");
PHP;
file_put_contents($appRoot . '/bin/weekly_backup.php', $weekly);


// write system_backup.php view
file_put_contents($publicDir . '/system_backup.php', base64_decode('PD9waHAKLyoqCiAqINCQ0LTQvNC40L0t0YDQsNC30LTQtdC7OiDQodC+0YXRgNCw0L3QtdC90LjQtSDRgdC40YHRgtC10LzRiyAo0L/QvtC70L3Ri9C5INC30LDQv9Cw0YDQvtC70LXQvdC90YvQuSDQsdGN0LrQsNC/ICsgY3JvbikuCiAqINCf0L7QtNC60LvRjtGH0LDQtdGC0YHRjyDQuNC3IGluZGV4LnBocCDQv9GA0LggP3ZpZXc9c3lzdGVtX2JhY2t1cCDQuNC70Lgg0L7RgtC60YDRi9Cy0LDQtdGC0YHRjyDQvdCw0L/RgNGP0LzRg9GOLgogKi8KZGVjbGFyZShzdHJpY3RfdHlwZXM9MSk7CgppZiAoc2Vzc2lvbl9zdGF0dXMoKSAhPT0gUEhQX1NFU1NJT05fQUNUSVZFKSB7CiAgICBzZXNzaW9uX3N0YXJ0KCk7Cn0KCiRhcHBSb290Q2FuZGlkYXRlcyA9IFsKICAgIGRpcm5hbWUoX19ESVJfXyksCiAgICBfX0RJUl9fLAogICAgZGlybmFtZShfX0RJUl9fLCAyKSwKXTsKJGNvbmZpZ0ZpbGUgPSBudWxsOwokYXBwUm9vdCA9IG51bGw7CmZvcmVhY2ggKCRhcHBSb290Q2FuZGlkYXRlcyBhcyAkcm9vdCkgewogICAgZm9yZWFjaCAoWyRyb290IC4gJy9jb25maWcucGhwJywgJHJvb3QgLiAnL3B1YmxpYy9jb25maWcucGhwJ10gYXMgJGNhbmQpIHsKICAgICAgICBpZiAoaXNfZmlsZSgkY2FuZCkpIHsKICAgICAgICAgICAgJGNvbmZpZ0ZpbGUgPSAkY2FuZDsKICAgICAgICAgICAgJGFwcFJvb3QgPSBpc19maWxlKCRyb290IC4gJy9wdWJsaWMvaW5kZXgucGhwJykgfHwgaXNfZGlyKCRyb290IC4gJy9zdG9yYWdlJykKICAgICAgICAgICAgICAgID8gJHJvb3QKICAgICAgICAgICAgICAgIDogZGlybmFtZSgkY2FuZCk7CiAgICAgICAgICAgIGJyZWFrIDI7CiAgICAgICAgfQogICAgfQp9CmlmICgkY29uZmlnRmlsZSA9PT0gbnVsbCkgewogICAgaHR0cF9yZXNwb25zZV9jb2RlKDUwMCk7CiAgICBlY2hvICdjb25maWcucGhwINC90LUg0L3QsNC50LTQtdC9JzsKICAgIGV4aXQ7Cn0KCi8qKiBAdmFyIGFycmF5fFBET3xudWxsICRjb25maWcgKi8KJGNvbmZpZyA9IHJlcXVpcmUgJGNvbmZpZ0ZpbGU7CgokcGRvID0gbnVsbDsKaWYgKCRjb25maWcgaW5zdGFuY2VvZiBQRE8pIHsKICAgICRwZG8gPSAkY29uZmlnOwp9IGVsc2VpZiAoaXNfYXJyYXkoJGNvbmZpZykpIHsKICAgICRkc24gPSAkY29uZmlnWydkc24nXSA/PyBudWxsOwogICAgaWYgKCRkc24gPT09IG51bGwgJiYgaXNzZXQoJGNvbmZpZ1snZGJfaG9zdCddLCAkY29uZmlnWydkYl9uYW1lJ10pKSB7CiAgICAgICAgJGRzbiA9IHNwcmludGYoCiAgICAgICAgICAgICdteXNxbDpob3N0PSVzO2RibmFtZT0lcztjaGFyc2V0PXV0ZjhtYjQnLAogICAgICAgICAgICAkY29uZmlnWydkYl9ob3N0J10sCiAgICAgICAgICAgICRjb25maWdbJ2RiX25hbWUnXQogICAgICAgICk7CiAgICB9CiAgICBpZiAoJGRzbiA9PT0gbnVsbCAmJiBpc3NldCgkY29uZmlnWydEQl9IT1NUJ10sICRjb25maWdbJ0RCX05BTUUnXSkpIHsKICAgICAgICAkZHNuID0gc3ByaW50ZigKICAgICAgICAgICAgJ215c3FsOmhvc3Q9JXM7ZGJuYW1lPSVzO2NoYXJzZXQ9dXRmOG1iNCcsCiAgICAgICAgICAgICRjb25maWdbJ0RCX0hPU1QnXSwKICAgICAgICAgICAgJGNvbmZpZ1snREJfTkFNRSddCiAgICAgICAgKTsKICAgIH0KICAgIGlmICgkZHNuICE9PSBudWxsKSB7CiAgICAgICAgJHVzZXIgPSAkY29uZmlnWydkYl91c2VyJ10gPz8gJGNvbmZpZ1snREJfVVNFUiddID8/ICRjb25maWdbJ3VzZXJuYW1lJ10gPz8gJyc7CiAgICAgICAgJHBhc3MgPSAkY29uZmlnWydkYl9wYXNzJ10gPz8gJGNvbmZpZ1snREJfUEFTUyddID8/ICRjb25maWdbJ3Bhc3N3b3JkJ10gPz8gJyc7CiAgICAgICAgJHBkbyA9IG5ldyBQRE8oJGRzbiwgJHVzZXIsICRwYXNzLCBbCiAgICAgICAgICAgIFBETzo6QVRUUl9FUlJNT0RFID0+IFBETzo6RVJSTU9ERV9FWENFUFRJT04sCiAgICAgICAgICAgIFBETzo6QVRUUl9ERUZBVUxUX0ZFVENIX01PREUgPT4gUERPOjpGRVRDSF9BU1NPQywKICAgICAgICBdKTsKICAgIH0KfQoKLy8gRmFsbGJhY2s6IHBhcnNlIC5lbnYgbmV4dCB0byBjb25maWcKaWYgKCEkcGRvIGluc3RhbmNlb2YgUERPKSB7CiAgICAkZW52RmlsZSA9IGRpcm5hbWUoJGNvbmZpZ0ZpbGUpIC4gJy8uZW52JzsKICAgIGlmIChpc19maWxlKCRlbnZGaWxlKSkgewogICAgICAgICRlbnYgPSBbXTsKICAgICAgICBmb3JlYWNoIChmaWxlKCRlbnZGaWxlLCBGSUxFX0lHTk9SRV9ORVdfTElORVMgfCBGSUxFX1NLSVBfRU1QVFlfTElORVMpIGFzICRsaW5lKSB7CiAgICAgICAgICAgIGlmICgkbGluZSA9PT0gJycgfHwgJGxpbmVbMF0gPT09ICcjJyB8fCAhc3RyX2NvbnRhaW5zKCRsaW5lLCAnPScpKSB7CiAgICAgICAgICAgICAgICBjb250aW51ZTsKICAgICAgICAgICAgfQogICAgICAgICAgICBbJGssICR2XSA9IGFycmF5X21hcCgndHJpbScsIGV4cGxvZGUoJz0nLCAkbGluZSwgMikpOwogICAgICAgICAgICAkZW52WyRrXSA9IHRyaW0oJHYsICIgXHRcIiciKTsKICAgICAgICB9CiAgICAgICAgaWYgKCFlbXB0eSgkZW52WydEQl9IT1NUJ10pICYmICFlbXB0eSgkZW52WydEQl9OQU1FJ10pKSB7CiAgICAgICAgICAgICRwZG8gPSBuZXcgUERPKAogICAgICAgICAgICAgICAgc3ByaW50ZignbXlzcWw6aG9zdD0lcztkYm5hbWU9JXM7Y2hhcnNldD11dGY4bWI0JywgJGVudlsnREJfSE9TVCddLCAkZW52WydEQl9OQU1FJ10pLAogICAgICAgICAgICAgICAgJGVudlsnREJfVVNFUiddID8/ICcnLAogICAgICAgICAgICAgICAgJGVudlsnREJfUEFTUyddID8/ICcnLAogICAgICAgICAgICAgICAgW1BETzo6QVRUUl9FUlJNT0RFID0+IFBETzo6RVJSTU9ERV9FWENFUFRJT05dCiAgICAgICAgICAgICk7CiAgICAgICAgICAgICRhcHBSb290ID0gJGFwcFJvb3QgPzogZGlybmFtZSgkY29uZmlnRmlsZSk7CiAgICAgICAgfQogICAgfQp9CgppZiAoISRwZG8gaW5zdGFuY2VvZiBQRE8pIHsKICAgIC8vIExhc3QgcmVzb3J0OiBoYXJkIGRlZmF1bHRzIGZyb20ga25vd24gaG9zdGluZyAob3ZlcnJpZGRlbiBieSBjb25maWcgd2hlbiBwcmVzZW50KQogICAgdHJ5IHsKICAgICAgICAkcGRvID0gbmV3IFBETygKICAgICAgICAgICAgJ215c3FsOmhvc3Q9bG9jYWxob3N0O2RibmFtZT11MTUzNDU1M19kb2N1bV9iZDtjaGFyc2V0PXV0ZjhtYjQnLAogICAgICAgICAgICAndTE1MzQ1NTNfYW5kcmV5JywKICAgICAgICAgICAgJycsCiAgICAgICAgICAgIFtQRE86OkFUVFJfRVJSTU9ERSA9PiBQRE86OkVSUk1PREVfRVhDRVBUSU9OXQogICAgICAgICk7CiAgICB9IGNhdGNoIChUaHJvd2FibGUgJGUpIHsKICAgICAgICBodHRwX3Jlc3BvbnNlX2NvZGUoNTAwKTsKICAgICAgICBlY2hvICfQndC1INGD0LTQsNC70L7RgdGMINC/0L7QtNC60LvRjtGH0LjRgtGM0YHRjyDQuiDQkdCULiDQn9GA0L7QstC10YDRjNGC0LUgY29uZmlnLnBocCc7CiAgICAgICAgZXhpdDsKICAgIH0KfQoKJGFwcFJvb3QgPSAkYXBwUm9vdCA/OiBkaXJuYW1lKCRjb25maWdGaWxlKTsKaWYgKGlzX2RpcigkYXBwUm9vdCAuICcvcHVibGljJykgJiYgaXNfZGlyKCRhcHBSb290IC4gJy9zdG9yYWdlJykpIHsKICAgIC8vIG9rCn0gZWxzZWlmIChpc19kaXIoZGlybmFtZSgkYXBwUm9vdCkgLiAnL3N0b3JhZ2UnKSkgewogICAgJGFwcFJvb3QgPSBkaXJuYW1lKCRhcHBSb290KTsKfQoKcmVxdWlyZV9vbmNlICRhcHBSb290IC4gJy9zcmMvU3lzdGVtQmFja3VwLnBocCc7CmlmICghaXNfZmlsZSgkYXBwUm9vdCAuICcvc3JjL1N5c3RlbUJhY2t1cC5waHAnKSkgewogICAgcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnLy4uL3NyYy9TeXN0ZW1CYWNrdXAucGhwJzsKfQoKZnVuY3Rpb24gc2JfY3NyZl90b2tlbigpOiBzdHJpbmcKewogICAgaWYgKGVtcHR5KCRfU0VTU0lPTlsnY3NyZiddKSkgewogICAgICAgICRfU0VTU0lPTlsnY3NyZiddID0gYmluMmhleChyYW5kb21fYnl0ZXMoMzIpKTsKICAgIH0KICAgIHJldHVybiAkX1NFU1NJT05bJ2NzcmYnXTsKfQoKZnVuY3Rpb24gc2JfY3NyZl9jaGVjayg/c3RyaW5nICR0b2tlbik6IHZvaWQKewogICAgaWYgKCEkdG9rZW4gfHwgZW1wdHkoJF9TRVNTSU9OWydjc3JmJ10pIHx8ICFoYXNoX2VxdWFscygkX1NFU1NJT05bJ2NzcmYnXSwgJHRva2VuKSkgewogICAgICAgIHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCfQndC10LLQtdGA0L3Ri9C5IENTUkYt0YLQvtC60LXQvS4g0J7QsdC90L7QstC40YLQtSDRgdGC0YDQsNC90LjRhtGDLicpOwogICAgfQp9CgpmdW5jdGlvbiBzYl9jdXJyZW50X3VzZXIoUERPICRwZG8pOiA/YXJyYXkKewogICAgJGNhbmRpZGF0ZXMgPSBbXTsKICAgIGlmICghZW1wdHkoJF9TRVNTSU9OWyd1c2VyJ10pICYmIGlzX2FycmF5KCRfU0VTU0lPTlsndXNlciddKSkgewogICAgICAgIHJldHVybiAkX1NFU1NJT05bJ3VzZXInXTsKICAgIH0KICAgIGZvcmVhY2ggKFsndXNlcl9pZCcsICd1aWQnLCAnaWQnXSBhcyAka2V5KSB7CiAgICAgICAgaWYgKCFlbXB0eSgkX1NFU1NJT05bJGtleV0pKSB7CiAgICAgICAgICAgICRjYW5kaWRhdGVzW10gPSAoaW50KSAkX1NFU1NJT05bJGtleV07CiAgICAgICAgfQogICAgfQogICAgaWYgKCFlbXB0eSgkX1NFU1NJT05bJ3VzZXJuYW1lJ10pKSB7CiAgICAgICAgJHN0bXQgPSAkcGRvLT5wcmVwYXJlKCdTRUxFQ1QgaWQsIHVzZXJuYW1lLCBlbWFpbCwgZnVsbF9uYW1lLCByb2xlIEZST00gdXNlcnMgV0hFUkUgdXNlcm5hbWUgPSA/IExJTUlUIDEnKTsKICAgICAgICAkc3RtdC0+ZXhlY3V0ZShbKHN0cmluZykgJF9TRVNTSU9OWyd1c2VybmFtZSddXSk7CiAgICAgICAgJHJvdyA9ICRzdG10LT5mZXRjaChQRE86OkZFVENIX0FTU09DKTsKICAgICAgICBpZiAoJHJvdykgewogICAgICAgICAgICByZXR1cm4gJHJvdzsKICAgICAgICB9CiAgICB9CiAgICBmb3JlYWNoICgkY2FuZGlkYXRlcyBhcyAkaWQpIHsKICAgICAgICAkc3RtdCA9ICRwZG8tPnByZXBhcmUoJ1NFTEVDVCBpZCwgdXNlcm5hbWUsIGVtYWlsLCBmdWxsX25hbWUsIHJvbGUgRlJPTSB1c2VycyBXSEVSRSBpZCA9ID8gTElNSVQgMScpOwogICAgICAgICRzdG10LT5leGVjdXRlKFskaWRdKTsKICAgICAgICAkcm93ID0gJHN0bXQtPmZldGNoKFBETzo6RkVUQ0hfQVNTT0MpOwogICAgICAgIGlmICgkcm93KSB7CiAgICAgICAgICAgIHJldHVybiAkcm93OwogICAgICAgIH0KICAgIH0KICAgIHJldHVybiBudWxsOwp9CgokdXNlciA9IHNiX2N1cnJlbnRfdXNlcigkcGRvKTsKJGVycm9yID0gbnVsbDsKJG9rID0gbnVsbDsKCi8vIE9wdGlvbmFsIGxvY2FsIGxvZ2luIGlmIHNlc3Npb24gZnJvbSBtYWluIGFwcCBub3QgZGV0ZWN0ZWQKaWYgKCRfU0VSVkVSWydSRVFVRVNUX01FVEhPRCddID09PSAnUE9TVCcgJiYgaXNzZXQoJF9QT1NUWydiYWNrdXBfbG9naW4nXSkpIHsKICAgIHRyeSB7CiAgICAgICAgc2JfY3NyZl9jaGVjaygkX1BPU1RbJ2NzcmYnXSA/PyBudWxsKTsKICAgICAgICAkbG9naW4gPSB0cmltKChzdHJpbmcpICgkX1BPU1RbJ3VzZXJuYW1lJ10gPz8gJycpKTsKICAgICAgICAkcGFzcyA9IChzdHJpbmcpICgkX1BPU1RbJ3Bhc3N3b3JkJ10gPz8gJycpOwogICAgICAgICRzdG10ID0gJHBkby0+cHJlcGFyZSgnU0VMRUNUIGlkLCB1c2VybmFtZSwgZW1haWwsIGZ1bGxfbmFtZSwgcm9sZSwgcGFzc3dvcmRfaGFzaCBGUk9NIHVzZXJzIFdIRVJFIHVzZXJuYW1lID0gPyBPUiBlbWFpbCA9ID8gTElNSVQgMScpOwogICAgICAgICRzdG10LT5leGVjdXRlKFskbG9naW4sICRsb2dpbl0pOwogICAgICAgICRyb3cgPSAkc3RtdC0+ZmV0Y2goUERPOjpGRVRDSF9BU1NPQyk7CiAgICAgICAgaWYgKCEkcm93IHx8ICFwYXNzd29yZF92ZXJpZnkoJHBhc3MsICRyb3dbJ3Bhc3N3b3JkX2hhc2gnXSkpIHsKICAgICAgICAgICAgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ9Cd0LXQstC10YDQvdGL0Lkg0LvQvtCz0LjQvSDQuNC70Lgg0L/QsNGA0L7Qu9GMLicpOwogICAgICAgIH0KICAgICAgICBpZiAoKCRyb3dbJ3JvbGUnXSA/PyAnJykgIT09ICdhZG1pbicpIHsKICAgICAgICAgICAgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ9CU0L7RgdGC0YPQvyDRgtC+0LvRjNC60L4g0LTQu9GPINCw0LTQvNC40L3QuNGB0YLRgNCw0YLQvtGA0LAuJyk7CiAgICAgICAgfQogICAgICAgIHVuc2V0KCRyb3dbJ3Bhc3N3b3JkX2hhc2gnXSk7CiAgICAgICAgJF9TRVNTSU9OWyd1c2VyJ10gPSAkcm93OwogICAgICAgICRfU0VTU0lPTlsndXNlcl9pZCddID0gKGludCkgJHJvd1snaWQnXTsKICAgICAgICAkdXNlciA9ICRyb3c7CiAgICAgICAgJG9rID0gJ9CS0YXQvtC0INCy0YvQv9C+0LvQvdC10L0uJzsKICAgIH0gY2F0Y2ggKFRocm93YWJsZSAkZSkgewogICAgICAgICRlcnJvciA9ICRlLT5nZXRNZXNzYWdlKCk7CiAgICB9Cn0KCmlmICghJHVzZXIgfHwgKCR1c2VyWydyb2xlJ10gPz8gJycpICE9PSAnYWRtaW4nKSB7CiAgICAkY3NyZiA9IHNiX2NzcmZfdG9rZW4oKTsKICAgID8+PCFkb2N0eXBlIGh0bWw+PGh0bWwgbGFuZz0icnUiPjxoZWFkPjxtZXRhIGNoYXJzZXQ9InV0Zi04Ij48bWV0YSBuYW1lPSJ2aWV3cG9ydCIgY29udGVudD0id2lkdGg9ZGV2aWNlLXdpZHRoLGluaXRpYWwtc2NhbGU9MSI+PHRpdGxlPtCh0L7RhdGA0LDQvdC10L3QuNC1INGB0LjRgdGC0LXQvNGLPC90aXRsZT4KICAgIDxzdHlsZT4KICAgIDpyb290ey0tYmc6I2YzZjFlYTstLXBhcGVyOiNmZmZjZjc7LS1pbms6IzFhMWYyYjstLWluay1zb2Z0OiM2NjcwODU7LS1hY2NlbnQ6IzFmM2ZiODstLXJ1bGU6I2U0ZGZkMjstLWRhbmdlcjojYjMyNjFlOy0tZGFuZ2VyLXNvZnQ6I2ZiZWFlOX0KICAgIGJvZHl7bWFyZ2luOjA7bWluLWhlaWdodDoxMDB2aDtkaXNwbGF5OmZsZXg7YWxpZ24taXRlbXM6Y2VudGVyO2p1c3RpZnktY29udGVudDpjZW50ZXI7YmFja2dyb3VuZDp2YXIoLS1iZyk7Zm9udDoxNXB4LzEuNDUgLWFwcGxlLXN5c3RlbSxCbGlua01hY1N5c3RlbUZvbnQsIlNlZ29lIFVJIixzYW5zLXNlcmlmO2NvbG9yOnZhcigtLWluayk7cGFkZGluZzoyNHB4fQogICAgbWFpbnt3aWR0aDoxMDAlO21heC13aWR0aDo0MDBweDtiYWNrZ3JvdW5kOnZhcigtLXBhcGVyKTtib3JkZXI6MXB4IHNvbGlkIHZhcigtLXJ1bGUpO2JvcmRlci1yYWRpdXM6MTJweDtwYWRkaW5nOjMycHh9CiAgICBoMXtmb250LWZhbWlseTpHZW9yZ2lhLHNlcmlmO2ZvbnQtc2l6ZToyNHB4O21hcmdpbjowIDAgOHB4fQogICAgcHtjb2xvcjp2YXIoLS1pbmstc29mdCk7bWFyZ2luOjAgMCAyMHB4fQogICAgbGFiZWx7ZGlzcGxheTpmbGV4O2ZsZXgtZGlyZWN0aW9uOmNvbHVtbjtnYXA6NnB4O2ZvbnQtc2l6ZToxM3B4O2NvbG9yOnZhcigtLWluay1zb2Z0KTttYXJnaW46MCAwIDE0cHh9CiAgICBpbnB1dHtwYWRkaW5nOjEwcHggMTJweDtib3JkZXI6MXB4IHNvbGlkIHZhcigtLXJ1bGUpO2JvcmRlci1yYWRpdXM6OHB4O2ZvbnQ6MTVweCBpbmhlcml0fQogICAgYnV0dG9ue3dpZHRoOjEwMCU7cGFkZGluZzoxMXB4O2JhY2tncm91bmQ6dmFyKC0tYWNjZW50KTtjb2xvcjojZmZmO2JvcmRlcjowO2JvcmRlci1yYWRpdXM6OHB4O2ZvbnQtd2VpZ2h0OjYwMDtjdXJzb3I6cG9pbnRlcn0KICAgIC5lcnJ7YmFja2dyb3VuZDp2YXIoLS1kYW5nZXItc29mdCk7Y29sb3I6dmFyKC0tZGFuZ2VyKTtwYWRkaW5nOjEwcHggMTJweDtib3JkZXItcmFkaXVzOjhweDttYXJnaW4tYm90dG9tOjE0cHg7Zm9udC1zaXplOjEzLjVweH0KICAgIGF7Y29sb3I6dmFyKC0tYWNjZW50KX0KICAgIDwvc3R5bGU+PC9oZWFkPjxib2R5PjxtYWluPgogICAgPGgxPtCh0L7RhdGA0LDQvdC10L3QuNC1INGB0LjRgdGC0LXQvNGLPC9oMT4KICAgIDxwPtCS0YXQvtC0INGC0L7Qu9GM0LrQviDQtNC70Y8g0LDQtNC80LjQvdC40YHRgtGA0LDRgtC+0YDQsC4g0JjQu9C4INC+0YLQutGA0L7QudGC0LUg0YDQsNC30LTQtdC7INC40Lcg0LPQu9Cw0LLQvdC+0LPQviDQvNC10L3RjiDQv9C+0YHQu9C1INCy0YXQvtC00LAg0LIg0YHQuNGB0YLQtdC80YMuPC9wPgogICAgPD9waHAgaWYgKCRlcnJvcik6ID8+PGRpdiBjbGFzcz0iZXJyIj48Pz0gaHRtbHNwZWNpYWxjaGFycygkZXJyb3IpID8+PC9kaXY+PD9waHAgZW5kaWY7ID8+CiAgICA8Zm9ybSBtZXRob2Q9InBvc3QiPgogICAgICA8aW5wdXQgdHlwZT0iaGlkZGVuIiBuYW1lPSJjc3JmIiB2YWx1ZT0iPD89IGh0bWxzcGVjaWFsY2hhcnMoJGNzcmYpID8+Ij4KICAgICAgPGxhYmVsPtCb0L7Qs9C40L0g0LjQu9C4IGVtYWlsPGlucHV0IG5hbWU9InVzZXJuYW1lIiByZXF1aXJlZCBhdXRvY29tcGxldGU9InVzZXJuYW1lIj48L2xhYmVsPgogICAgICA8bGFiZWw+0J/QsNGA0L7Qu9GMPGlucHV0IHR5cGU9InBhc3N3b3JkIiBuYW1lPSJwYXNzd29yZCIgcmVxdWlyZWQgYXV0b2NvbXBsZXRlPSJjdXJyZW50LXBhc3N3b3JkIj48L2xhYmVsPgogICAgICA8YnV0dG9uIG5hbWU9ImJhY2t1cF9sb2dpbiIgdmFsdWU9IjEiPtCS0L7QudGC0Lg8L2J1dHRvbj4KICAgIDwvZm9ybT4KICAgIDxwIHN0eWxlPSJtYXJnaW4tdG9wOjE4cHgiPjxhIGhyZWY9Ii4vIj7ihpAg0Jog0LTQvtC60YPQvNC10L3RgtCw0Lw8L2E+PC9wPgogICAgPC9tYWluPjwvYm9keT48L2h0bWw+PD9waHAKICAgIGV4aXQ7Cn0KCi8vIEVuc3VyZSBzZXR0aW5ncyB0YWJsZQokcGRvLT5leGVjKCJDUkVBVEUgVEFCTEUgSUYgTk9UIEVYSVNUUyBzZXR0aW5ncyAoCiAgc2V0dGluZ19rZXkgVkFSQ0hBUigxMDApIE5PVCBOVUxMIFBSSU1BUlkgS0VZLAogIHNldHRpbmdfdmFsdWUgVEVYVCBOVUxMLAogIHVwZGF0ZWRfYXQgVElNRVNUQU1QIE5PVCBOVUxMIERFRkFVTFQgQ1VSUkVOVF9USU1FU1RBTVAgT04gVVBEQVRFIENVUlJFTlRfVElNRVNUQU1QCikgRU5HSU5FPUlubm9EQiBERUZBVUxUIENIQVJTRVQ9dXRmOG1iNCIpOwoKJGJhY2t1cCA9IG5ldyBTeXN0ZW1CYWNrdXAoJHBkbywgJGFwcFJvb3QpOwokY3NyZiA9IHNiX2NzcmZfdG9rZW4oKTsKCmlmICgkX1NFUlZFUlsnUkVRVUVTVF9NRVRIT0QnXSA9PT0gJ1BPU1QnKSB7CiAgICB0cnkgewogICAgICAgIHNiX2NzcmZfY2hlY2soJF9QT1NUWydjc3JmJ10gPz8gbnVsbCk7CiAgICAgICAgaWYgKGlzc2V0KCRfUE9TVFsnc2F2ZV9wYXNzd29yZCddKSkgewogICAgICAgICAgICAkcDEgPSAoc3RyaW5nKSAoJF9QT1NUWydhcmNoaXZlX3Bhc3N3b3JkJ10gPz8gJycpOwogICAgICAgICAgICAkcDIgPSAoc3RyaW5nKSAoJF9QT1NUWydhcmNoaXZlX3Bhc3N3b3JkMiddID8/ICcnKTsKICAgICAgICAgICAgaWYgKCRwMSAhPT0gJHAyKSB7CiAgICAgICAgICAgICAgICB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbign0J/QsNGA0L7Qu9C4INC90LUg0YHQvtCy0L/QsNC00LDRjtGCLicpOwogICAgICAgICAgICB9CiAgICAgICAgICAgICRiYWNrdXAtPnNldEFyY2hpdmVQYXNzd29yZCgkcDEpOwogICAgICAgICAgICAkb2sgPSAn0J/QsNGA0L7Qu9GMINCw0YDRhdC40LLQsCDRgdC+0YXRgNCw0L3RkdC9Lic7CiAgICAgICAgfSBlbHNlaWYgKGlzc2V0KCRfUE9TVFsnY2xlYXJfcGFzc3dvcmQnXSkpIHsKICAgICAgICAgICAgJGJhY2t1cC0+Y2xlYXJBcmNoaXZlUGFzc3dvcmQoKTsKICAgICAgICAgICAgJG9rID0gJ9Cf0LDRgNC+0LvRjCDQsNGA0YXQuNCy0LAg0L7Rh9C40YnQtdC9Lic7CiAgICAgICAgfSBlbHNlaWYgKGlzc2V0KCRfUE9TVFsncnVuX2JhY2t1cCddKSkgewogICAgICAgICAgICAkcmVzdWx0ID0gJGJhY2t1cC0+Y3JlYXRlRnVsbEJhY2t1cCgpOwogICAgICAgICAgICAkYmFja3VwLT5wcnVuZU9sZEJhY2t1cHMoOCk7CiAgICAgICAgICAgICRvayA9ICfQkdGN0LrQsNC/INGB0L7Qt9C00LDQvTogJyAuICRyZXN1bHRbJ2ZpbGUnXSAuICcgKCcgLiBudW1iZXJfZm9ybWF0KCRyZXN1bHRbJ3NpemUnXSAvIDEwNDg1NzYsIDIsICcuJywgJyAnKSAuICcg0JzQkSkuJzsKICAgICAgICB9IGVsc2VpZiAoaXNzZXQoJF9QT1NUWydkb3dubG9hZF9iYWNrdXAnXSkpIHsKICAgICAgICAgICAgJHBhdGggPSAkYmFja3VwLT5iYWNrdXBQYXRoKChzdHJpbmcpICgkX1BPU1RbJ2ZpbGUnXSA/PyAnJykpOwogICAgICAgICAgICBpZiAoJHBhdGggPT09IG51bGwpIHsKICAgICAgICAgICAgICAgIHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCfQpNCw0LnQuyDQvdC1INC90LDQudC00LXQvS4nKTsKICAgICAgICAgICAgfQogICAgICAgICAgICBoZWFkZXIoJ0NvbnRlbnQtVHlwZTogYXBwbGljYXRpb24vemlwJyk7CiAgICAgICAgICAgIGhlYWRlcignQ29udGVudC1MZW5ndGg6ICcgLiBmaWxlc2l6ZSgkcGF0aCkpOwogICAgICAgICAgICBoZWFkZXIoJ0NvbnRlbnQtRGlzcG9zaXRpb246IGF0dGFjaG1lbnQ7IGZpbGVuYW1lPSInIC4gYmFzZW5hbWUoJHBhdGgpIC4gJyInKTsKICAgICAgICAgICAgcmVhZGZpbGUoJHBhdGgpOwogICAgICAgICAgICBleGl0OwogICAgICAgIH0gZWxzZWlmIChpc3NldCgkX1BPU1RbJ2RlbGV0ZV9iYWNrdXAnXSkpIHsKICAgICAgICAgICAgJHBhdGggPSAkYmFja3VwLT5iYWNrdXBQYXRoKChzdHJpbmcpICgkX1BPU1RbJ2ZpbGUnXSA/PyAnJykpOwogICAgICAgICAgICBpZiAoJHBhdGgpIHsKICAgICAgICAgICAgICAgIEB1bmxpbmsoJHBhdGgpOwogICAgICAgICAgICAgICAgJG9rID0gJ9Ck0LDQudC7INGD0LTQsNC70ZHQvS4nOwogICAgICAgICAgICB9CiAgICAgICAgfQogICAgfSBjYXRjaCAoVGhyb3dhYmxlICRlKSB7CiAgICAgICAgJGVycm9yID0gJGUtPmdldE1lc3NhZ2UoKTsKICAgIH0KfQoKJGhhc1Bhc3MgPSAkYmFja3VwLT5oYXNBcmNoaXZlUGFzc3dvcmQoKTsKJGxpc3QgPSAkYmFja3VwLT5saXN0QmFja3VwcygpOwokY3JvblBhdGggPSAkYXBwUm9vdCAuICcvYmluL3dlZWtseV9iYWNrdXAucGhwJzsKJGJhc2UgPSBydHJpbShkaXJuYW1lKCRfU0VSVkVSWydTQ1JJUFRfTkFNRSddID8/ICcnKSwgJy9cXCcpOwppZiAoc3RyX2VuZHNfd2l0aCgkYmFzZSwgJy9wdWJsaWMnKSkgewogICAgJGhvbWUgPSBzdWJzdHIoJGJhc2UsIDAsIC03KSA/OiAnLyc7Cn0gZWxzZSB7CiAgICAkaG9tZSA9ICRiYXNlID09PSAnJyA/ICcvJyA6ICRiYXNlIC4gJy8nOwp9CmlmIChpc3NldCgkX0dFVFsndmlldyddKSkgewogICAgLy8gb3BlbmVkIHZpYSBpbmRleCByb3V0ZXIg4oCUIHJlbGF0aXZlIGxpbmtzIHRvIGluZGV4CiAgICAkaG9tZUhyZWYgPSAnLi8nOwogICAgJHNlbGZIcmVmID0gJz92aWV3PXN5c3RlbV9iYWNrdXAnOwp9IGVsc2UgewogICAgJGhvbWVIcmVmID0gJy4vJzsKICAgICRzZWxmSHJlZiA9ICdzeXN0ZW1fYmFja3VwLnBocCc7Cn0KPz48IWRvY3R5cGUgaHRtbD4KPGh0bWwgbGFuZz0icnUiPgo8aGVhZD4KPG1ldGEgY2hhcnNldD0idXRmLTgiPgo8bWV0YSBuYW1lPSJ2aWV3cG9ydCIgY29udGVudD0id2lkdGg9ZGV2aWNlLXdpZHRoLGluaXRpYWwtc2NhbGU9MSI+Cjx0aXRsZT7QodC+0YXRgNCw0L3QtdC90LjQtSDRgdC40YHRgtC10LzRizwvdGl0bGU+CjxzdHlsZT4KOnJvb3R7LS1iZzojZjNmMWVhOy0tcGFwZXI6I2ZmZmNmNzstLWluazojMWExZjJiOy0taW5rLXNvZnQ6IzY2NzA4NTstLWFjY2VudDojMWYzZmI4Oy0tYWNjZW50LWhvdmVyOiMxNzMxOGY7LS1ydWxlOiNlNGRmZDI7LS1kYW5nZXI6I2IzMjYxZTstLWRhbmdlci1zb2Z0OiNmYmVhZTk7LS1vazojMGY2YjRjOy0tb2stc29mdDojZThmNmYwfQoqe2JveC1zaXppbmc6Ym9yZGVyLWJveH0KYm9keXttYXJnaW46MDtiYWNrZ3JvdW5kOnJhZGlhbC1ncmFkaWVudCgxMjAwcHggNTAwcHggYXQgMTAlIC0xMCUsI2U3ZWVmYyAwJSx0cmFuc3BhcmVudCA1NSUpLHZhcigtLWJnKTtjb2xvcjp2YXIoLS1pbmspO2ZvbnQ6MTQuNXB4LzEuNDUgLWFwcGxlLXN5c3RlbSxCbGlua01hY1N5c3RlbUZvbnQsIlNlZ29lIFVJIixzYW5zLXNlcmlmfQoud3JhcHttYXgtd2lkdGg6ODgwcHg7bWFyZ2luOjAgYXV0bztwYWRkaW5nOjAgMjBweCA1NnB4fQpoZWFkZXJ7cGFkZGluZzoyMnB4IDAgMTZweDtkaXNwbGF5OmZsZXg7anVzdGlmeS1jb250ZW50OnNwYWNlLWJldHdlZW47Z2FwOjE2cHg7ZmxleC13cmFwOndyYXA7YWxpZ24taXRlbXM6ZmxleC1zdGFydH0KaDF7Zm9udC1mYW1pbHk6R2VvcmdpYSwiSW93YW4gT2xkIFN0eWxlIixzZXJpZjtmb250LXNpemU6MjhweDtmb250LXdlaWdodDo2MDA7bWFyZ2luOjAgMCA2cHg7bGV0dGVyLXNwYWNpbmc6LS4wMmVtfQouc3Vie2NvbG9yOnZhcigtLWluay1zb2Z0KTttYXJnaW46MH0KLmhlYWRlci1saW5rc3tkaXNwbGF5OmZsZXg7Z2FwOjE0cHg7YWxpZ24taXRlbXM6Y2VudGVyfQphe2NvbG9yOnZhcigtLWFjY2VudCk7dGV4dC1kZWNvcmF0aW9uOm5vbmV9CmE6aG92ZXJ7dGV4dC1kZWNvcmF0aW9uOnVuZGVybGluZX0KLnBhbmVse2JvcmRlcjoxcHggc29saWQgdmFyKC0tcnVsZSk7Ym9yZGVyLXJhZGl1czoxMnB4O3BhZGRpbmc6MjBweDtiYWNrZ3JvdW5kOnZhcigtLXBhcGVyKTtib3gtc2hhZG93OjAgMXB4IDAgcmdiYSgyNywzMyw0OCwuMDQpO21hcmdpbjowIDAgMTZweH0KaDJ7Zm9udC1mYW1pbHk6R2VvcmdpYSxzZXJpZjtmb250LXNpemU6MThweDttYXJnaW46MCAwIDEycHh9CmxhYmVse2Rpc3BsYXk6ZmxleDtmbGV4LWRpcmVjdGlvbjpjb2x1bW47Z2FwOjRweDtmb250LXNpemU6MTIuNXB4O2NvbG9yOnZhcigtLWluay1zb2Z0KTttYXJnaW4tYm90dG9tOjEwcHh9CmlucHV0e2ZvbnQ6MTQuNXB4IGluaGVyaXQ7cGFkZGluZzo5cHggMTFweDtib3JkZXI6MXB4IHNvbGlkIHZhcigtLXJ1bGUpO2JvcmRlci1yYWRpdXM6OHB4O2JhY2tncm91bmQ6I2ZmZn0KYnV0dG9uLC5idG57YmFja2dyb3VuZDp2YXIoLS1hY2NlbnQpO2NvbG9yOiNmZmY7Ym9yZGVyOjA7Ym9yZGVyLXJhZGl1czo4cHg7cGFkZGluZzo5cHggMTZweDtmb250LXNpemU6MTRweDtmb250LXdlaWdodDo2MDA7Y3Vyc29yOnBvaW50ZXJ9CmJ1dHRvbjpob3ZlcntiYWNrZ3JvdW5kOnZhcigtLWFjY2VudC1ob3Zlcil9Ci5idG4tcXVpZXR7YmFja2dyb3VuZDojZmZmO2NvbG9yOnZhcigtLWluayk7Ym9yZGVyOjFweCBzb2xpZCB2YXIoLS1ydWxlKX0KLmJ0bi1kYW5nZXJ7YmFja2dyb3VuZDp2YXIoLS1kYW5nZXIpfQoucm93e2Rpc3BsYXk6ZmxleDtnYXA6MTBweDtmbGV4LXdyYXA6d3JhcDthbGlnbi1pdGVtczplbmR9Ci5tc2d7cGFkZGluZzoxMHB4IDEycHg7Ym9yZGVyLXJhZGl1czo4cHg7bWFyZ2luOjAgMCAxNHB4O2ZvbnQtc2l6ZToxMy41cHh9Ci5tc2cub2t7YmFja2dyb3VuZDp2YXIoLS1vay1zb2Z0KTtjb2xvcjp2YXIoLS1vayl9Ci5tc2cuZXJye2JhY2tncm91bmQ6dmFyKC0tZGFuZ2VyLXNvZnQpO2NvbG9yOnZhcigtLWRhbmdlcil9CnRhYmxle3dpZHRoOjEwMCU7Ym9yZGVyLWNvbGxhcHNlOmNvbGxhcHNlO2ZvbnQtc2l6ZToxMy41cHh9CnRoLHRke3BhZGRpbmc6MTBweCA4cHg7Ym9yZGVyLWJvdHRvbToxcHggc29saWQgdmFyKC0tcnVsZSk7dGV4dC1hbGlnbjpsZWZ0fQp0aHtjb2xvcjp2YXIoLS1pbmstc29mdCk7Zm9udC13ZWlnaHQ6NjAwO2ZvbnQtc2l6ZToxMnB4fQpjb2RlLHByZXtmb250LWZhbWlseTp1aS1tb25vc3BhY2UsTWVubG8sQ29uc29sYXMsbW9ub3NwYWNlO2ZvbnQtc2l6ZToxMi41cHh9CnByZXtiYWNrZ3JvdW5kOiNmNmY0ZWU7Ym9yZGVyOjFweCBzb2xpZCB2YXIoLS1ydWxlKTtib3JkZXItcmFkaXVzOjhweDtwYWRkaW5nOjEycHg7b3ZlcmZsb3c6YXV0bzt3aGl0ZS1zcGFjZTpwcmUtd3JhcH0KLmJhZGdle2Rpc3BsYXk6aW5saW5lLWJsb2NrO3BhZGRpbmc6MnB4IDhweDtib3JkZXItcmFkaXVzOjk5OXB4O2JhY2tncm91bmQ6I2VlZjJmZjtjb2xvcjp2YXIoLS1hY2NlbnQpO2ZvbnQtc2l6ZToxMnB4O2ZvbnQtd2VpZ2h0OjYwMH0KLmhpbnR7Y29sb3I6dmFyKC0taW5rLXNvZnQpO2ZvbnQtc2l6ZToxM3B4O21hcmdpbjowIDAgMTJweH0KPC9zdHlsZT4KPC9oZWFkPgo8Ym9keT4KPGRpdiBjbGFzcz0id3JhcCI+CjxoZWFkZXI+CiAgPGRpdj4KICAgIDxoMT7QodC+0YXRgNCw0L3QtdC90LjQtSDRgdC40YHRgtC10LzRizwvaDE+CiAgICA8cCBjbGFzcz0ic3ViIj7QkNC00LzQuNC90LjRgdGC0YDQsNGC0L7RgDogPD89IGh0bWxzcGVjaWFsY2hhcnMoKHN0cmluZykgKCR1c2VyWydmdWxsX25hbWUnXSA/OiAkdXNlclsndXNlcm5hbWUnXSkpID8+IMK3INC/0L7Qu9C90YvQuSDQsdGN0LrQsNC/INC00LvRjyDQv9C10YDQtdC90L7RgdCwINC90LAg0LvRjtCx0L7QuSDRhdC+0YHRgtC40L3QszwvcD4KICA8L2Rpdj4KICA8ZGl2IGNsYXNzPSJoZWFkZXItbGlua3MiPgogICAgPGEgaHJlZj0iPD89IGh0bWxzcGVjaWFsY2hhcnMoJGhvbWVIcmVmKSA/PiI+4oaQINCU0L7QutGD0LzQtdC90YLRizwvYT4KICAgIDxhIGhyZWY9Ijw/PSBodG1sc3BlY2lhbGNoYXJzKCRob21lSHJlZikgPz4/dmlldz11c2VycyI+0J/QvtC70YzQt9C+0LLQsNGC0LXQu9C4PC9hPgogIDwvZGl2Pgo8L2hlYWRlcj4KCjw/cGhwIGlmICgkb2spOiA/PjxkaXYgY2xhc3M9Im1zZyBvayI+PD89IGh0bWxzcGVjaWFsY2hhcnMoJG9rKSA/PjwvZGl2Pjw/cGhwIGVuZGlmOyA/Pgo8P3BocCBpZiAoJGVycm9yKTogPz48ZGl2IGNsYXNzPSJtc2cgZXJyIj48Pz0gaHRtbHNwZWNpYWxjaGFycygkZXJyb3IpID8+PC9kaXY+PD9waHAgZW5kaWY7ID8+Cgo8c2VjdGlvbiBjbGFzcz0icGFuZWwiPgogIDxoMj4xLiDQn9Cw0YDQvtC70Ywg0LDRgNGF0LjQstCwPC9oMj4KICA8cCBjbGFzcz0iaGludCI+WklQINGBINCx0Y3QutCw0L/QvtC8INCy0YHQtdCz0LTQsCDQt9Cw0L/QsNGA0L7Qu9C10L0uINCf0LDRgNC+0LvRjCDQt9Cw0LTQsNGR0YIg0YLQvtC70YzQutC+INCw0LTQvNC40L3QuNGB0YLRgNCw0YLQvtGALiDQpdGA0LDQvdC40YLRgdGPINCyINCR0JQg0LIg0LfQsNGI0LjRhNGA0L7QstCw0L3QvdC+0Lwg0LLQuNC00LUuPC9wPgogIDxwPtCh0YLQsNGC0YPRgTogPD9waHAgaWYgKCRoYXNQYXNzKTogPz48c3BhbiBjbGFzcz0iYmFkZ2UiPtC/0LDRgNC+0LvRjCDQt9Cw0LTQsNC9PC9zcGFuPjw/cGhwIGVsc2U6ID8+PHNwYW4gY2xhc3M9ImJhZGdlIiBzdHlsZT0iYmFja2dyb3VuZDojZmJlYWU5O2NvbG9yOiNiMzI2MWUiPtC/0LDRgNC+0LvRjCDQvdC1INC30LDQtNCw0L08L3NwYW4+PD9waHAgZW5kaWY7ID8+PC9wPgogIDxmb3JtIG1ldGhvZD0icG9zdCIgY2xhc3M9InJvdyI+CiAgICA8aW5wdXQgdHlwZT0iaGlkZGVuIiBuYW1lPSJjc3JmIiB2YWx1ZT0iPD89IGh0bWxzcGVjaWFsY2hhcnMoJGNzcmYpID8+Ij4KICAgIDxsYWJlbCBzdHlsZT0iZmxleDoxO21pbi13aWR0aDoxODBweCI+0J3QvtCy0YvQuSDQv9Cw0YDQvtC70Yw8aW5wdXQgdHlwZT0icGFzc3dvcmQiIG5hbWU9ImFyY2hpdmVfcGFzc3dvcmQiIG1pbmxlbmd0aD0iOCIgcmVxdWlyZWQgYXV0b2NvbXBsZXRlPSJuZXctcGFzc3dvcmQiPjwvbGFiZWw+CiAgICA8bGFiZWwgc3R5bGU9ImZsZXg6MTttaW4td2lkdGg6MTgwcHgiPtCf0L7QstGC0L7RgDxpbnB1dCB0eXBlPSJwYXNzd29yZCIgbmFtZT0iYXJjaGl2ZV9wYXNzd29yZDIiIG1pbmxlbmd0aD0iOCIgcmVxdWlyZWQgYXV0b2NvbXBsZXRlPSJuZXctcGFzc3dvcmQiPjwvbGFiZWw+CiAgICA8YnV0dG9uIG5hbWU9InNhdmVfcGFzc3dvcmQiIHZhbHVlPSIxIj7QodC+0YXRgNCw0L3QuNGC0Ywg0L/QsNGA0L7Qu9GMPC9idXR0b24+CiAgPC9mb3JtPgogIDw/cGhwIGlmICgkaGFzUGFzcyk6ID8+CiAgPGZvcm0gbWV0aG9kPSJwb3N0IiBzdHlsZT0ibWFyZ2luLXRvcDoxMHB4IiBvbnN1Ym1pdD0icmV0dXJuIGNvbmZpcm0oJ9Ce0YfQuNGB0YLQuNGC0Ywg0L/QsNGA0L7Qu9GMINCw0YDRhdC40LLQsD8nKTsiPgogICAgPGlucHV0IHR5cGU9ImhpZGRlbiIgbmFtZT0iY3NyZiIgdmFsdWU9Ijw/PSBodG1sc3BlY2lhbGNoYXJzKCRjc3JmKSA/PiI+CiAgICA8YnV0dG9uIGNsYXNzPSJidG4tcXVpZXQiIG5hbWU9ImNsZWFyX3Bhc3N3b3JkIiB2YWx1ZT0iMSI+0J7Rh9C40YHRgtC40YLRjCDQv9Cw0YDQvtC70Yw8L2J1dHRvbj4KICA8L2Zvcm0+CiAgPD9waHAgZW5kaWY7ID8+Cjwvc2VjdGlvbj4KCjxzZWN0aW9uIGNsYXNzPSJwYW5lbCI+CiAgPGgyPjIuINCh0L7Qt9C00LDRgtGMINC/0L7Qu9C90YvQuSDQsdGN0LrQsNC/INGB0LXQudGH0LDRgTwvaDI+CiAgPHAgY2xhc3M9ImhpbnQiPtCSINCw0YDRhdC40LIg0LLRhdC+0LTQuNGCOiDQuNGB0YXQvtC00L3Ri9C5INC60L7QtCwgc3RvcmFnZSDRgSDRhNCw0LnQu9Cw0LzQuCDQtNC+0LrRg9C80LXQvdGC0L7Qsiwg0L/QvtC70L3Ri9C5IFNRTCDQstGB0LXRhSDRgtCw0LHQu9C40YYsINC40L3RgdGC0YDRg9C60YbQuNGPIFJFU1RPUkUudHh0LjwvcD4KICA8Zm9ybSBtZXRob2Q9InBvc3QiPgogICAgPGlucHV0IHR5cGU9ImhpZGRlbiIgbmFtZT0iY3NyZiIgdmFsdWU9Ijw/PSBodG1sc3BlY2lhbGNoYXJzKCRjc3JmKSA/PiI+CiAgICA8YnV0dG9uIG5hbWU9InJ1bl9iYWNrdXAiIHZhbHVlPSIxIiA8Pz0gJGhhc1Bhc3MgPyAnJyA6ICdkaXNhYmxlZCB0aXRsZT0i0KHQvdCw0YfQsNC70LAg0LfQsNC00LDQudGC0LUg0L/QsNGA0L7Qu9GMIicgPz4+0KHQvtC30LTQsNGC0Ywg0LfQsNC/0LDRgNC+0LvQtdC90L3Ri9C5IFpJUDwvYnV0dG9uPgogIDwvZm9ybT4KPC9zZWN0aW9uPgoKPHNlY3Rpb24gY2xhc3M9InBhbmVsIj4KICA8aDI+My4g0JPQvtGC0L7QstGL0LUg0LDRgNGF0LjQstGLPC9oMj4KICA8P3BocCBpZiAoISRsaXN0KTogPz4KICAgIDxwIGNsYXNzPSJoaW50Ij7Qn9C+0LrQsCDQvdC10YIg0YHQvtC30LTQsNC90L3Ri9GFINCx0Y3QutCw0L/QvtCyLjwvcD4KICA8P3BocCBlbHNlOiA/PgogIDx0YWJsZT4KICAgIDx0aGVhZD48dHI+PHRoPtCk0LDQudC7PC90aD48dGg+0KDQsNC30LzQtdGAPC90aD48dGg+0KHQvtC30LTQsNC9IChVVEMpPC90aD48dGg+PC90aD48L3RyPjwvdGhlYWQ+CiAgICA8dGJvZHk+CiAgICA8P3BocCBmb3JlYWNoICgkbGlzdCBhcyAkaXRlbSk6ID8+CiAgICAgIDx0cj4KICAgICAgICA8dGQ+PGNvZGU+PD89IGh0bWxzcGVjaWFsY2hhcnMoJGl0ZW1bJ2ZpbGUnXSkgPz48L2NvZGU+PC90ZD4KICAgICAgICA8dGQ+PD89IGh0bWxzcGVjaWFsY2hhcnMobnVtYmVyX2Zvcm1hdCgkaXRlbVsnc2l6ZSddIC8gMTA0ODU3NiwgMiwgJy4nLCAnICcpKSA/PiDQnNCRPC90ZD4KICAgICAgICA8dGQ+PD89IGh0bWxzcGVjaWFsY2hhcnMoZ21kYXRlKCdZLW0tZCBIOmknLCAkaXRlbVsnbXRpbWUnXSkpID8+PC90ZD4KICAgICAgICA8dGQgY2xhc3M9InJvdyI+CiAgICAgICAgICA8Zm9ybSBtZXRob2Q9InBvc3QiPjxpbnB1dCB0eXBlPSJoaWRkZW4iIG5hbWU9ImNzcmYiIHZhbHVlPSI8Pz0gaHRtbHNwZWNpYWxjaGFycygkY3NyZikgPz4iPjxpbnB1dCB0eXBlPSJoaWRkZW4iIG5hbWU9ImZpbGUiIHZhbHVlPSI8Pz0gaHRtbHNwZWNpYWxjaGFycygkaXRlbVsnZmlsZSddKSA/PiI+PGJ1dHRvbiBjbGFzcz0iYnRuLXF1aWV0IiBuYW1lPSJkb3dubG9hZF9iYWNrdXAiIHZhbHVlPSIxIj7QodC60LDRh9Cw0YLRjDwvYnV0dG9uPjwvZm9ybT4KICAgICAgICAgIDxmb3JtIG1ldGhvZD0icG9zdCIgb25zdWJtaXQ9InJldHVybiBjb25maXJtKCfQo9C00LDQu9C40YLRjCDQsNGA0YXQuNCyPycpOyI+PGlucHV0IHR5cGU9ImhpZGRlbiIgbmFtZT0iY3NyZiIgdmFsdWU9Ijw/PSBodG1sc3BlY2lhbGNoYXJzKCRjc3JmKSA/PiI+PGlucHV0IHR5cGU9ImhpZGRlbiIgbmFtZT0iZmlsZSIgdmFsdWU9Ijw/PSBodG1sc3BlY2lhbGNoYXJzKCRpdGVtWydmaWxlJ10pID8+Ij48YnV0dG9uIGNsYXNzPSJidG4tZGFuZ2VyIiBuYW1lPSJkZWxldGVfYmFja3VwIiB2YWx1ZT0iMSI+0KPQtNCw0LvQuNGC0Yw8L2J1dHRvbj48L2Zvcm0+CiAgICAgICAgPC90ZD4KICAgICAgPC90cj4KICAgIDw/cGhwIGVuZGZvcmVhY2g7ID8+CiAgICA8L3Rib2R5PgogIDwvdGFibGU+CiAgPD9waHAgZW5kaWY7ID8+Cjwvc2VjdGlvbj4KCjxzZWN0aW9uIGNsYXNzPSJwYW5lbCI+CiAgPGgyPjQuINCV0LbQtdC90LXQtNC10LvRjNC90YvQuSBjcm9uPC9oMj4KICA8cCBjbGFzcz0iaGludCI+0JTQvtCx0LDQstGM0YLQtSDQt9Cw0LTQsNC90LjQtSDQsiDQv9Cw0L3QtdC70Lgg0YXQvtGB0YLQuNC90LPQsCAoQ3JvbikuINCg0LDQtyDQsiDQvdC10LTQtdC70Y4g0LIgMDM6MDA6PC9wPgogIDxwcmU+MCAzICogKiAwIC91c3IvYmluL3BocCA8Pz0gaHRtbHNwZWNpYWxjaGFycygkY3JvblBhdGgpID8+PC9wcmU+CiAgPHAgY2xhc3M9ImhpbnQiPtCh0LrRgNC40L/RgiDRgdC+0LfQtNCw0YHRgiDQt9Cw0L/QsNGA0L7Qu9C10L3QvdGL0Lkg0LDRgNGF0LjQsiDQsiA8Y29kZT5zdG9yYWdlL2JhY2t1cHMvPC9jb2RlPiDQuCDQvtGB0YLQsNCy0LjRgiDQv9C+0YHQu9C10LTQvdC40LUgOCDQutC+0L/QuNC5LiDQn9Cw0YDQvtC70Ywg0LHQtdGA0ZHRgtGB0Y8g0LjQtyDQvdCw0YHRgtGA0L7QtdC6INCw0LTQvNC40L3QuNGB0YLRgNCw0YLQvtGA0LAuPC9wPgo8L3NlY3Rpb24+Cgo8c2VjdGlvbiBjbGFzcz0icGFuZWwiPgogIDxoMj41LiDQoNCw0LfQstGR0YDRgtGL0LLQsNC90LjQtSDQvdCwINC90L7QstC+0Lwg0YXQvtGB0YLQuNC90LPQtTwvaDI+CiAgPG9sIGNsYXNzPSJoaW50Ij4KICAgIDxsaT7QodC60LDRh9Cw0LnRgtC1IFpJUCDQuCDRgNCw0YHQv9Cw0LrRg9C50YLQtSDRgSDQv9Cw0YDQvtC70LXQvC48L2xpPgogICAgPGxpPtCX0LDQu9C10LnRgtC1INC/0LDQv9C60YMgPGNvZGU+Z29zdC1kb2N1bWVudHM8L2NvZGU+INC90LAg0L3QvtCy0YvQuSDRhdC+0YHRgtC40L3Qsy48L2xpPgogICAgPGxpPtCh0L7Qt9C00LDQudGC0LUg0JHQlCDQuCDQuNC80L/QvtGA0YLQuNGA0YPQudGC0LUgPGNvZGU+ZGF0YWJhc2VfZnVsbC5zcWw8L2NvZGU+LjwvbGk+CiAgICA8bGk+0J/RgNC+0L/QuNGI0LjRgtC1INC00L7RgdGC0YPQv9GLINCyIDxjb2RlPmNvbmZpZy5waHA8L2NvZGU+LjwvbGk+CiAgICA8bGk+0JLRi9C00LDQudGC0LUg0L/RgNCw0LLQsCDQvdCwINC30LDQv9C40YHRjCDQsiA8Y29kZT5zdG9yYWdlLzwvY29kZT4uPC9saT4KICAgIDxsaT7QktC+0LnQtNC40YLQtSDQutCw0Log0LDQtNC80LjQvdC40YHRgtGA0LDRgtC+0YAg0Lgg0LfQsNC00LDQudGC0LUg0L3QvtCy0YvQuSDQv9Cw0YDQvtC70Ywg0LDRgNGF0LjQstCwLjwvbGk+CiAgPC9vbD4KPC9zZWN0aW9uPgo8L2Rpdj4KPC9ib2R5Pgo8L2h0bWw+Cg=='));

// Patch index.php: inject router + menu link
$indexPath = $publicDir . '/index.php';
if (!is_file($indexPath)) {
    http_response_code(500);
    echo 'index.php not found in ' . htmlspecialchars($publicDir);
    exit;
}
$index = file_get_contents($indexPath);
if ($index === false) {
    http_response_code(500);
    echo 'Cannot read index.php';
    exit;
}

$marker = '/* SYSTEM_BACKUP_MODULE */';
if (!str_contains($index, $marker)) {
    $inject = <<<'PHP'
/* SYSTEM_BACKUP_MODULE */
if (isset($_GET['view']) && $_GET['view'] === 'system_backup') {
    require __DIR__ . '/system_backup.php';
    exit;
}

PHP;
    if (str_starts_with(ltrim($index), '<?php')) {
        $pos = strpos($index, '<?php');
        $pos = strpos($index, "\n", $pos);
        $index = substr($index, 0, $pos + 1) . $inject . substr($index, $pos + 1);
    } else {
        $index = "<?php\n" . $inject . "?>\n" . $index;
    }
}

// Add admin menu link near users link
if (!str_contains($index, 'view=system_backup')) {
    $index = str_replace(
        'href="?view=users"',
        'href="?view=system_backup">Сохранение системы</a><a href="?view=users"',
        $index,
        $count
    );
    if ($count < 1) {
        $index = str_replace(
            ">Пользователи</a>",
            ">Пользователи</a><a href=\"?view=system_backup\">Сохранение системы</a>",
            $index
        );
    }
}

if (file_put_contents($indexPath, $index) === false) {
    http_response_code(500);
    echo 'Cannot write index.php';
    exit;
}

// Ensure settings table via config if possible
try {
    $config = @include (is_file($appRoot . '/config.php') ? $appRoot . '/config.php' : $appRoot . '/public/config.php');
    $pdo = $config instanceof PDO ? $config : null;
    if (!$pdo && is_array($config)) {
        $host = $config['db_host'] ?? $config['DB_HOST'] ?? 'localhost';
        $name = $config['db_name'] ?? $config['DB_NAME'] ?? '';
        $user = $config['db_user'] ?? $config['DB_USER'] ?? '';
        $pass = $config['db_pass'] ?? $config['DB_PASS'] ?? '';
        if ($name !== '') {
            $pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
    }
    if ($pdo instanceof PDO) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
          setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
          setting_value TEXT NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("UPDATE users SET role='admin' WHERE username='andrey'");
    }
} catch (Throwable $e) {
    // non-fatal
}

@file_put_contents($appRoot . '/storage/backups/.htaccess', "Require all denied\nDeny from all\n");

$log = [
    'ok' => true,
    'appRoot' => $appRoot,
    'patched_index' => true,
    'next' => 'Open /gost-documents/?view=system_backup as admin andrey',
];

// Self-delete installer
@unlink(__FILE__);

header('Content-Type: text/plain; charset=utf-8');
echo "OK installed\n";
echo json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
echo "Open: https://gost.info/gost-documents/?view=system_backup\n";
