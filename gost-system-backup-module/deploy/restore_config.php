<?php
/**
 * Помощник после распаковки бэкапа на новом хостинге.
 * Откройте restore_config.php в браузере, укажите данные MySQL — запишет config.php.
 * Удалите этот файл после настройки.
 */
declare(strict_types=1);

$done = null;
$error = null;
$configPath = is_dir(__DIR__ . '/public') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
if (!is_dir(dirname($configPath))) {
    $configPath = __DIR__ . '/config.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
    $name = trim((string) ($_POST['db_name'] ?? ''));
    $user = trim((string) ($_POST['db_user'] ?? ''));
    $pass = (string) ($_POST['db_pass'] ?? '');
    if ($name === '' || $user === '') {
        $error = 'Укажите имя БД и пользователя.';
    } else {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $export = "<?php\nreturn [\n"
                . "  'db_host' => " . var_export($host, true) . ",\n"
                . "  'db_name' => " . var_export($name, true) . ",\n"
                . "  'db_user' => " . var_export($user, true) . ",\n"
                . "  'db_pass' => " . var_export($pass, true) . ",\n"
                . "];\n";
            if (file_put_contents($configPath, $export) === false) {
                throw new RuntimeException('Не удалось записать config.php');
            }
            $done = 'config.php записан. Импортируйте database_full.sql и удалите restore_config.php.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><title>Восстановление config</title>
<style>body{font:15px/1.45 system-ui;max-width:480px;margin:40px auto;padding:0 16px}label{display:block;margin:12px 0 4px}input{width:100%;padding:8px}button{margin-top:16px;padding:10px 16px}err{color:#b00}.ok{color:#060}</style>
</head><body>
<h1>Настройка после бэкапа</h1>
<p>1) Импортируйте <code>database_full.sql</code> в MySQL.<br>2) Укажите доступы ниже.</p>
<?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if ($done): ?><p class="ok"><?= htmlspecialchars($done) ?></p><?php else: ?>
<form method="post">
<label>Хост БД<input name="db_host" value="localhost" required></label>
<label>Имя БД<input name="db_name" required></label>
<label>Пользователь<input name="db_user" required></label>
<label>Пароль<input type="password" name="db_pass"></label>
<button type="submit">Сохранить config.php</button>
</form>
<?php endif; ?>
</body></html>
