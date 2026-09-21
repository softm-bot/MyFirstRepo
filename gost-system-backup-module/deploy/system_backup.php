<?php
/**
 * Админ-раздел: Сохранение системы (полный запароленный бэкап + cron).
 * Подключается из index.php при ?view=system_backup или открывается напрямую.
 */
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$appRootCandidates = [
    dirname(__DIR__),
    __DIR__,
    dirname(__DIR__, 2),
];
$configFile = null;
$appRoot = null;
foreach ($appRootCandidates as $root) {
    foreach ([$root . '/config.php', $root . '/public/config.php'] as $cand) {
        if (is_file($cand)) {
            $configFile = $cand;
            $appRoot = is_file($root . '/public/index.php') || is_dir($root . '/storage')
                ? $root
                : dirname($cand);
            break 2;
        }
    }
}
if ($configFile === null) {
    http_response_code(500);
    echo 'config.php не найден';
    exit;
}

/** @var array|PDO|null $config */
$config = require $configFile;

$pdo = null;
if ($config instanceof PDO) {
    $pdo = $config;
} elseif (is_array($config)) {
    $dsn = $config['dsn'] ?? null;
    if ($dsn === null && isset($config['db_host'], $config['db_name'])) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            $config['db_name']
        );
    }
    if ($dsn === null && isset($config['DB_HOST'], $config['DB_NAME'])) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['DB_HOST'],
            $config['DB_NAME']
        );
    }
    if ($dsn !== null) {
        $user = $config['db_user'] ?? $config['DB_USER'] ?? $config['username'] ?? '';
        $pass = $config['db_pass'] ?? $config['DB_PASS'] ?? $config['password'] ?? '';
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}

// Fallback: parse .env next to config
if (!$pdo instanceof PDO) {
    $envFile = dirname($configFile) . '/.env';
    if (is_file($envFile)) {
        $env = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $env[$k] = trim($v, " \t\"'");
        }
        if (!empty($env['DB_HOST']) && !empty($env['DB_NAME'])) {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'], $env['DB_NAME']),
                $env['DB_USER'] ?? '',
                $env['DB_PASS'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $appRoot = $appRoot ?: dirname($configFile);
        }
    }
}

if (!$pdo instanceof PDO) {
    // Last resort: hard defaults from known hosting (overridden by config when present)
    try {
        $pdo = new PDO(
            'mysql:host=localhost;dbname=u1534553_docum_bd;charset=utf8mb4',
            'u1534553_andrey',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Не удалось подключиться к БД. Проверьте config.php';
        exit;
    }
}

$appRoot = $appRoot ?: dirname($configFile);
if (is_dir($appRoot . '/public') && is_dir($appRoot . '/storage')) {
    // ok
} elseif (is_dir(dirname($appRoot) . '/storage')) {
    $appRoot = dirname($appRoot);
}

require_once $appRoot . '/src/SystemBackup.php';
if (!is_file($appRoot . '/src/SystemBackup.php')) {
    require_once __DIR__ . '/../src/SystemBackup.php';
}

function sb_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function sb_csrf_check(?string $token): void
{
    if (!$token || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        throw new RuntimeException('Неверный CSRF-токен. Обновите страницу.');
    }
}

function sb_current_user(PDO $pdo): ?array
{
    $candidates = [];
    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    foreach (['user_id', 'uid', 'id'] as $key) {
        if (!empty($_SESSION[$key])) {
            $candidates[] = (int) $_SESSION[$key];
        }
    }
    if (!empty($_SESSION['username'])) {
        $stmt = $pdo->prepare('SELECT id, username, email, full_name, role FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([(string) $_SESSION['username']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }
    foreach ($candidates as $id) {
        $stmt = $pdo->prepare('SELECT id, username, email, full_name, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }
    return null;
}

$user = sb_current_user($pdo);
$error = null;
$ok = null;

// Optional local login if session from main app not detected
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup_login'])) {
    try {
        sb_csrf_check($_POST['csrf'] ?? null);
        $login = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id, username, email, full_name, role, password_hash FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$login, $login]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($pass, $row['password_hash'])) {
            throw new RuntimeException('Неверный логин или пароль.');
        }
        if (($row['role'] ?? '') !== 'admin') {
            throw new RuntimeException('Доступ только для администратора.');
        }
        unset($row['password_hash']);
        $_SESSION['user'] = $row;
        $_SESSION['user_id'] = (int) $row['id'];
        $user = $row;
        $ok = 'Вход выполнен.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (!$user || ($user['role'] ?? '') !== 'admin') {
    $csrf = sb_csrf_token();
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Сохранение системы</title>
    <style>
    :root{--bg:#f3f1ea;--paper:#fffcf7;--ink:#1a1f2b;--ink-soft:#667085;--accent:#1f3fb8;--rule:#e4dfd2;--danger:#b3261e;--danger-soft:#fbeae9}
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);font:15px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);padding:24px}
    main{width:100%;max-width:400px;background:var(--paper);border:1px solid var(--rule);border-radius:12px;padding:32px}
    h1{font-family:Georgia,serif;font-size:24px;margin:0 0 8px}
    p{color:var(--ink-soft);margin:0 0 20px}
    label{display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--ink-soft);margin:0 0 14px}
    input{padding:10px 12px;border:1px solid var(--rule);border-radius:8px;font:15px inherit}
    button{width:100%;padding:11px;background:var(--accent);color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer}
    .err{background:var(--danger-soft);color:var(--danger);padding:10px 12px;border-radius:8px;margin-bottom:14px;font-size:13.5px}
    a{color:var(--accent)}
    </style></head><body><main>
    <h1>Сохранение системы</h1>
    <p>Вход только для администратора. Или откройте раздел из главного меню после входа в систему.</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <label>Логин или email<input name="username" required autocomplete="username"></label>
      <label>Пароль<input type="password" name="password" required autocomplete="current-password"></label>
      <button name="backup_login" value="1">Войти</button>
    </form>
    <p style="margin-top:18px"><a href="./">← К документам</a></p>
    </main></body></html><?php
    exit;
}

// Ensure settings table
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$backup = new SystemBackup($pdo, $appRoot);
$csrf = sb_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        sb_csrf_check($_POST['csrf'] ?? null);
        if (isset($_POST['save_password'])) {
            $p1 = (string) ($_POST['archive_password'] ?? '');
            $p2 = (string) ($_POST['archive_password2'] ?? '');
            if ($p1 !== $p2) {
                throw new RuntimeException('Пароли не совпадают.');
            }
            $backup->setArchivePassword($p1);
            $ok = 'Пароль архива сохранён.';
        } elseif (isset($_POST['clear_password'])) {
            $backup->clearArchivePassword();
            $ok = 'Пароль архива очищен.';
        } elseif (isset($_POST['run_backup'])) {
            $result = $backup->createFullBackup();
            $backup->pruneOldBackups(8);
            $ok = 'Бэкап создан: ' . $result['file'] . ' (' . number_format($result['size'] / 1048576, 2, '.', ' ') . ' МБ).';
        } elseif (isset($_POST['download_backup'])) {
            $path = $backup->backupPath((string) ($_POST['file'] ?? ''));
            if ($path === null) {
                throw new RuntimeException('Файл не найден.');
            }
            header('Content-Type: application/zip');
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            readfile($path);
            exit;
        } elseif (isset($_POST['delete_backup'])) {
            $path = $backup->backupPath((string) ($_POST['file'] ?? ''));
            if ($path) {
                @unlink($path);
                $ok = 'Файл удалён.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$hasPass = $backup->hasArchivePassword();
$list = method_exists($backup, 'listBackupsForAdmin')
    ? $backup->listBackupsForAdmin()
    : $backup->listBackups();
$cronPath = $appRoot . '/bin/weekly_backup.php';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if (str_ends_with($base, '/public')) {
    $home = substr($base, 0, -7) ?: '/';
} else {
    $home = $base === '' ? '/' : $base . '/';
}
if (isset($_GET['view'])) {
    $homeHref = './';
    $selfHref = '?view=system_backup';
} else {
    $homeHref = './';
    $selfHref = 'system_backup.php';
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Сохранение системы</title>
<style>
:root{--bg:#f3f1ea;--paper:#fffcf7;--ink:#1a1f2b;--ink-soft:#667085;--accent:#1f3fb8;--accent-hover:#17318f;--rule:#e4dfd2;--danger:#b3261e;--danger-soft:#fbeae9;--ok:#0f6b4c;--ok-soft:#e8f6f0}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(1200px 500px at 10% -10%,#e7eefc 0%,transparent 55%),var(--bg);color:var(--ink);font:14.5px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:920px;margin:0 auto;padding:0 20px 56px}
header{padding:22px 0 16px;display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start}
h1{font-family:Georgia,"Iowan Old Style",serif;font-size:28px;font-weight:600;margin:0 0 6px;letter-spacing:-.02em}
.sub{color:var(--ink-soft);margin:0}
.header-links{display:flex;gap:14px;align-items:center}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
.panel{border:1px solid var(--rule);border-radius:12px;padding:20px;background:var(--paper);box-shadow:0 1px 0 rgba(27,33,48,.04);margin:0 0 16px}
.panel-primary{border-color:#c9d4f5;box-shadow:0 8px 24px rgba(31,63,184,.06)}
h2{font-family:Georgia,serif;font-size:18px;margin:0 0 12px}
label{display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--ink-soft);margin-bottom:10px}
input{font:14.5px inherit;padding:9px 11px;border:1px solid var(--rule);border-radius:8px;background:#fff}
button,.btn{background:var(--accent);color:#fff;border:0;border-radius:8px;padding:9px 16px;font-size:14px;font-weight:600;cursor:pointer}
button:hover{background:var(--accent-hover)}
button:disabled{opacity:.45;cursor:not-allowed}
.btn-quiet{background:#fff;color:var(--ink);border:1px solid var(--rule)}
.btn-danger{background:var(--danger)}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.msg{padding:10px 12px;border-radius:8px;margin:0 0 14px;font-size:13.5px}
.msg.ok{background:var(--ok-soft);color:var(--ok)}
.msg.err{background:var(--danger-soft);color:var(--danger)}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th,td{padding:12px 8px;border-bottom:1px solid var(--rule);text-align:left;vertical-align:middle}
th{color:var(--ink-soft);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
code,pre{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px}
pre{background:#f6f4ee;border:1px solid var(--rule);border-radius:8px;padding:12px;overflow:auto;white-space:pre-wrap}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef2ff;color:var(--accent);font-size:12px;font-weight:600}
.badge-warn{background:#fbeae9;color:#b3261e}
.hint{color:var(--ink-soft);font-size:13px;margin:0 0 12px}
.empty{padding:28px 12px;text-align:center;color:var(--ink-soft);border:1px dashed var(--rule);border-radius:10px;background:#faf8f3}
.count{font-size:13px;color:var(--ink-soft);margin:0 0 14px}
</style>
</head>
<body>
<div class="wrap">
<header>
  <div>
    <h1>Сохранение системы</h1>
    <p class="sub">Администратор: <?= htmlspecialchars((string) ($user['full_name'] ?: $user['username'])) ?> · полный бэкап для переноса на любой хостинг</p>
  </div>
  <div class="header-links">
    <a href="<?= htmlspecialchars($homeHref) ?>">← Документы</a>
    <a href="<?= htmlspecialchars($homeHref) ?>?view=users">Пользователи</a>
  </div>
</header>

<?php if ($ok): ?><div class="msg ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
<?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<section class="panel panel-primary">
  <h2>Список бэкапов</h2>
  <p class="count">Всего архивов: <strong><?= count($list) ?></strong></p>
  <?php if (!$list): ?>
    <div class="empty">Пока нет созданных бэкапов.<br>Задайте пароль архива ниже и нажмите «Создать запароленный ZIP».</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Файл</th>
        <th>Размер</th>
        <th>Создан (UTC)</th>
        <th>Статус</th>
        <th>Действия</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($list as $item): ?>
      <tr>
        <td><code><?= htmlspecialchars($item['file']) ?></code>
          <?php if (!empty($item['note'])): ?><div class="hint" style="margin:4px 0 0"><?= htmlspecialchars((string)$item['note']) ?></div><?php endif; ?>
        </td>
        <td><?= htmlspecialchars(number_format(($item['size'] ?? 0) / 1048576, 2, '.', ' ')) ?> МБ</td>
        <td><?= htmlspecialchars(gmdate('Y-m-d H:i', (int)($item['mtime'] ?? time()))) ?></td>
        <td><?php if (!empty($item['on_disk']) || !isset($item['on_disk'])): ?><span class="badge">на диске</span><?php else: ?><span class="badge badge-warn">нет файла</span><?php endif; ?></td>
        <td class="row">
          <?php if (!isset($item['on_disk']) || !empty($item['on_disk'])): ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="file" value="<?= htmlspecialchars($item['file']) ?>"><button class="btn-quiet" name="download_backup" value="1">Скачать</button></form>
          <form method="post" onsubmit="return confirm('Удалить архив?');"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="file" value="<?= htmlspecialchars($item['file']) ?>"><button class="btn-danger" name="delete_backup" value="1">Удалить</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Пароль архива</h2>
  <p class="hint">ZIP с бэкапом всегда запаролен. Пароль задаёт только администратор.</p>
  <p>Статус: <?php if ($hasPass): ?><span class="badge">пароль задан</span><?php else: ?><span class="badge badge-warn">пароль не задан</span><?php endif; ?></p>
  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <label style="flex:1;min-width:180px">Новый пароль<input type="password" name="archive_password" minlength="8" required autocomplete="new-password"></label>
    <label style="flex:1;min-width:180px">Повтор<input type="password" name="archive_password2" minlength="8" required autocomplete="new-password"></label>
    <button name="save_password" value="1">Сохранить пароль</button>
  </form>
  <?php if ($hasPass): ?>
  <form method="post" style="margin-top:10px" onsubmit="return confirm('Очистить пароль архива?');">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <button class="btn-quiet" name="clear_password" value="1">Очистить пароль</button>
  </form>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Создать полный бэкап</h2>
  <p class="hint">В архив: код, storage с документами, полный SQL, RESTORE.txt. После создания архив появится в списке выше.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <button name="run_backup" value="1" <?= $hasPass ? '' : 'disabled title="Сначала задайте пароль"' ?>>Создать запароленный ZIP</button>
  </form>
</section>

<section class="panel">
  <h2>Еженедельный cron</h2>
  <p class="hint">Добавьте в панели хостинга (раз в неделю в 03:00 UTC):</p>
  <pre>0 3 * * 0 /usr/bin/php <?= htmlspecialchars($cronPath) ?></pre>
</section>

<section class="panel">
  <h2>Развёртывание на новом хостинге</h2>
  <ol class="hint">
    <li>Скачайте ZIP из списка и распакуйте с паролем.</li>
    <li>Залейте папку <code>gost-documents</code> на новый хостинг.</li>
    <li>Создайте БД и импортируйте <code>database_full.sql</code>.</li>
    <li>Пропишите доступы в <code>config.php</code>.</li>
    <li>Права на запись в <code>storage/</code>.</li>
    <li>Войдите как администратор и задайте новый пароль архива.</li>
  </ol>
</section>
</div>
</body>
</html>
