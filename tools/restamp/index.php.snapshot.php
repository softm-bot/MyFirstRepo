<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Создайте config.php на основе config.example.php.');
}
$config = require $configFile;

try {
    $pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    exit('Не удалось подключиться к базе данных. Проверьте config.php.');
}

function ensureSchema(PDO $pdo): void
{
    $hasRole = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if (!$hasRole) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('admin','user') NOT NULL DEFAULT 'user'");
        $pdo->exec("UPDATE users SET role = 'admin' WHERE username = 'andrey'");
    }
    $hasIndex = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'documents' AND index_name = 'uq_org_type_number'")->fetchColumn();
    if ($hasIndex > 0) {
        try {
            $pdo->exec('ALTER TABLE documents DROP INDEX uq_org_type_number');
        } catch (Throwable $error) {
        }
    }
    if (!$pdo->query("SHOW COLUMNS FROM documents LIKE 'registry_number'")->fetch()) {
        try {
            $pdo->exec("ALTER TABLE documents ADD COLUMN registry_number VARCHAR(100) GENERATED ALWAYS AS (IF(`type` = 'internal', NULL, document_number)) STORED");
        } catch (Throwable $error) {
        }
    }
    $hasRegistry = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'documents' AND index_name = 'uq_org_type_registry'")->fetchColumn();
    if ($hasRegistry === 0) {
        try {
            $pdo->exec('ALTER TABLE documents ADD UNIQUE KEY uq_org_type_registry (organization_id, type, registry_number)');
        } catch (Throwable $error) {
        }
    }
    if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'reset_token'")->fetch()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL UNIQUE');
        $pdo->exec('ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL');
    }
    $pdo->prepare("UPDATE users SET email = 'asu@ns52.ru' WHERE username = 'anna' AND email <> 'asu@ns52.ru'")->execute();
    if (!$pdo->query("SHOW COLUMNS FROM document_files LIKE 'pdf_stored_name'")->fetch()) {
        $pdo->exec('ALTER TABLE document_files ADD COLUMN pdf_stored_name VARCHAR(255) NULL');
        $pdo->exec('ALTER TABLE document_files ADD COLUMN pdf_original_name VARCHAR(255) NULL');
        $pdo->exec('ALTER TABLE document_files ADD COLUMN pdf_file_size INT NULL');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS internal_categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(128) NOT NULL UNIQUE,
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS document_internal_categories (
        document_id INT UNSIGNED NOT NULL,
        category_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (document_id, category_id),
        KEY idx_dic_cat (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ((int)$pdo->query('SELECT COUNT(*) FROM internal_categories')->fetchColumn() === 0) {
        $seed = $pdo->prepare('INSERT INTO internal_categories (name, sort_order) VALUES (?, ?)');
        $seed->execute(['Уставные', 10]);
        $seed->execute(['Сертификаты', 20]);
        $seed->execute(['Информационные', 30]);
    }
    if (!$pdo->query("SHOW COLUMNS FROM organizations LIKE 'inn'")->fetch()) {
        $pdo->exec('ALTER TABLE organizations ADD COLUMN inn VARCHAR(12) NULL');
    }
    $pdo->exec("UPDATE organizations SET inn = '5260269813' WHERE name LIKE '%Норма софт%' AND (inn IS NULL OR inn = '')");
    $pdo->exec("UPDATE organizations SET inn = '5259120833' WHERE name LIKE '%Нормасофт%' AND name NOT LIKE '%Норма софт%' AND (inn IS NULL OR inn = '')");
    if (!$pdo->query("SHOW COLUMNS FROM documents LIKE 'search_code'")->fetch()) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN search_code VARCHAR(20) NULL UNIQUE');
    }
}

function counterColumn(string $type): string
{
    return [
        'outgoing' => 'next_number_outgoing',
        'incoming' => 'next_number_incoming',
        'internal' => 'next_number_internal',
    ][$type];
}

function ownNumberSql(): string
{
    return "document_number REGEXP '^[1-9][0-9]{0,3}$'";
}

function nextDocumentNumber(PDO $pdo, int $orgId, string $type): string
{
    $own = ownNumberSql();
    $statement = $pdo->prepare("SELECT COALESCE(MAX(CAST(document_number AS UNSIGNED)), 0) FROM documents WHERE organization_id = ? AND type = ? AND $own");
    $statement->execute([$orgId, $type]);
    return (string)((int)$statement->fetchColumn() + 1);
}

function syncOrgCounter(PDO $pdo, int $orgId, string $type): void
{
    $column = counterColumn($type);
    $own = ownNumberSql();
    $statement = $pdo->prepare("UPDATE organizations SET `$column` = 1 + COALESCE((SELECT MAX(CAST(document_number AS UNSIGNED)) FROM documents WHERE organization_id = ? AND type = ? AND $own), 0) WHERE id = ?");
    $statement->execute([$orgId, $type, $orgId]);
}

function postedCategoryIds(array $allCategories): array
{
    $valid = [];
    foreach ($allCategories as $category) {
        $valid[(int)$category['id']] = true;
    }
    $ids = [];
    foreach ((array)($_POST['category_ids'] ?? []) as $rawId) {
        $id = (int)$rawId;
        if ($id > 0 && isset($valid[$id])) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function syncDocumentCategories(PDO $pdo, int $documentId, array $categoryIds): void
{
    $pdo->prepare('DELETE FROM document_internal_categories WHERE document_id = ?')->execute([$documentId]);
    if (!$categoryIds) {
        return;
    }
    $statement = $pdo->prepare('INSERT INTO document_internal_categories (document_id, category_id) VALUES (?, ?)');
    foreach ($categoryIds as $categoryId) {
        $statement->execute([$documentId, (int)$categoryId]);
    }
}

function isAdmin(): bool
{
    return ($_SESSION['role'] ?? 'user') === 'admin';
}

function generateSearchCode(PDO $pdo): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    do {
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = 'НД-' . substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        $statement = $pdo->prepare('SELECT id FROM documents WHERE search_code = ? LIMIT 1');
        $statement->execute([$code]);
    } while ($statement->fetchColumn());
    return $code;
}

function searchCodeLabel(string $code): string
{
    return 'Код документа: ' . $code;
}

function stampDocxFile(string $path, string $code): void
{
    if ($code === '' || !is_file($path) || !class_exists('ZipArchive')) {
        return;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return;
    }
    $xml = $zip->getFromName('word/document.xml');
    if (!is_string($xml)) {
        $zip->close();
        return;
    }
    $xml = preg_replace('/<w:p\b[^>]*>(?:(?!<\/w:p>).)*?Код документа:(?:(?!<\/w:p>).)*?<\/w:p>/u', '', $xml) ?? $xml;
    $xml = preg_replace('/<w:p\b[^>]*>(?:(?!<\/w:p>).)*?<w:jc w:val="right"\/>(?:(?!<\/w:p>).)*?НД-[A-Z0-9]{4}-[A-Z0-9]{4}(?:(?!<\/w:p>).)*?<\/w:p>/u', '', $xml) ?? $xml;
    $xml = preg_replace('/\s*Код документа:\s*НД-[A-Z0-9]{4}-[A-Z0-9]{4}/u', '', $xml) ?? $xml;
    $xml = preg_replace('/<w:r>\s*<w:tab\/>\s*<\/w:r>\s*<w:r>(?:(?!<\/w:r>).)*?НД-[A-Z0-9]{4}-[A-Z0-9]{4}(?:(?!<\/w:r>).)*?<\/w:r>/u', '', $xml) ?? $xml;
    $xml = preg_replace('/(?<=>)\s*НД-[A-Z0-9]{4}-[A-Z0-9]{4}\s*(?=<)/u', '', $xml) ?? $xml;

    $safeCode = htmlspecialchars($code, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $stampRuns = '<w:r><w:tab/></w:r><w:r><w:rPr><w:sz w:val="15"/><w:szCs w:val="15"/><w:color w:val="666666"/></w:rPr><w:t xml:space="preserve">' . $safeCode . '</w:t></w:r>';

    if (!preg_match('/<w:body\b[^>]*>([\s\S]*)<\/w:body>/', $xml, $bodyMatch)) {
        $zip->close();
        return;
    }
    $body = $bodyMatch[1];
    $sect = '';
    if (preg_match('/(<w:sectPr[\s\S]*)$/', $body, $sm)) {
        $sect = $sm[1];
        $bodyMain = substr($body, 0, -strlen($sect));
    } else {
        $bodyMain = $body;
    }
    if (!preg_match_all('/<w:p\b[\s\S]*?<\/w:p>/', $bodyMain, $pm)) {
        $zip->close();
        return;
    }
    $paragraphs = $pm[0];
    $targetIdx = -1;
    for ($i = count($paragraphs) - 1; $i >= 0; $i--) {
        $text = preg_replace('/<w:tab\/>/', ' ', $paragraphs[$i]) ?? $paragraphs[$i];
        $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text !== '' && !preg_match('/^НД-[A-Z0-9]{4}-[A-Z0-9]{4}$/u', $text)) {
            $targetIdx = $i;
            break;
        }
    }
    if ($targetIdx < 0) {
        $zip->close();
        return;
    }
    $p = $paragraphs[$targetIdx];
    if (preg_match('/<w:tabs\b[^>]*>[\s\S]*?<\/w:tabs>/', $p)) {
        $p = preg_replace('/<w:tabs\b[^>]*>[\s\S]*?<\/w:tabs>/', '<w:tabs><w:tab w:val="right" w:pos="9781"/></w:tabs>', $p, 1) ?? $p;
    } elseif (preg_match('/<w:pPr\b[^>]*>/', $p)) {
        $p = preg_replace('/(<w:pPr\b[^>]*>)/', '$1<w:tabs><w:tab w:val="right" w:pos="9781"/></w:tabs>', $p, 1) ?? $p;
    } else {
        $p = preg_replace('/(<w:p\b[^>]*>)/', '$1<w:pPr><w:tabs><w:tab w:val="right" w:pos="9781"/></w:tabs></w:pPr>', $p, 1) ?? $p;
    }
    if (preg_match('/<w:spacing\b[^>]*\/>/', $p)) {
        $p = preg_replace('/<w:spacing\b[^>]*\/>/', '<w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/>', $p, 1) ?? $p;
    } elseif (preg_match('/<w:pPr\b[^>]*>/', $p)) {
        $p = preg_replace('/(<w:pPr\b[^>]*>)/', '$1<w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/>', $p, 1) ?? $p;
    }
    $p = preg_replace('/<\/w:p>/', $stampRuns . '</w:p>', $p, 1) ?? $p;
    $paragraphs[$targetIdx] = $p;
    $newBody = implode('', $paragraphs) . $sect;
    $xml = preg_replace('/<w:body\b[^>]*>[\s\S]*<\/w:body>/', '<w:body>' . $newBody . '</w:body>', $xml, 1) ?? $xml;
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
}


function documentSearchCode(PDO $pdo, int $documentId): string
{
    $statement = $pdo->prepare('SELECT search_code FROM documents WHERE id = ?');
    $statement->execute([$documentId]);
    return (string)($statement->fetchColumn() ?: '');
}

const APP_VERSION = '1.2.1';
const APP_VERSION_DATE = '22.09.2026';

function appChangelog(): array
{
    return [
        [
            'version' => '1.2.0',
            'date' => '22.09.2026',
            'items' => [
                'У каждого документа уникальный поисковый код вида НД-XXXX-XXXX.',
                'Word и PDF одного документа получают один код — это один документ в двух форматах.',
                'Код ставится в конец тела документа, колонтитулы не меняются.',
                'По коду можно найти документ в журнале.',
            ],
        ],
        [
            'version' => '1.1.0',
            'date' => '22.09.2026',
            'items' => [
                'В правом верхнем углу показаны номер версии и дата релиза.',
                'По клику открывается журнал истории изменений проекта.',
            ],
        ],
        [
            'version' => '1.0.0',
            'date' => '19.09.2026',
            'items' => [
                'Раздельные журналы ООО «Норма софт» (ИНН 5260269813) и ООО «Нормасофт» (ИНН 5259120833).',
                'Исходящие и внутренние документы: уставные, сертификаты, информационные.',
                'При импорте номер берётся из файла; чужие номера не присваиваются, без номера — б/н.',
                'Рекомендуемый номер рядом с кнопкой «Добавить документ».',
                'Запрос пароля по почте, открытие файлов только скачиванием, иконки по типу файла.',
            ],
        ],
    ];
}

function appVersionBadge(): string
{
    return '<button type="button" class="app-version" onclick="document.getElementById(\'changelog-modal\').showModal()" title="История изменений">'
        . '<span class="app-version-num">v' . htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span class="app-version-date">' . htmlspecialchars(APP_VERSION_DATE, ENT_QUOTES, 'UTF-8') . '</span>'
        . '</button>';
}

function appChangelogModal(): string
{
    $html = '<dialog id="changelog-modal" class="modal changelog-modal"><div class="modal-inner">';
    $html .= '<div class="modal-head"><h2>История изменений</h2><button type="button" class="modal-close" onclick="document.getElementById(\'changelog-modal\').close()" aria-label="Закрыть">×</button></div>';
    $html .= '<p class="changelog-lead">Текущая версия <strong>v' . htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') . '</strong> от ' . htmlspecialchars(APP_VERSION_DATE, ENT_QUOTES, 'UTF-8') . '</p>';
    $html .= '<ol class="changelog">';
    foreach (appChangelog() as $entry) {
        $html .= '<li class="changelog-entry"><div class="changelog-meta"><strong>v' . htmlspecialchars((string)$entry['version'], ENT_QUOTES, 'UTF-8') . '</strong><span>' . htmlspecialchars((string)$entry['date'], ENT_QUOTES, 'UTF-8') . '</span></div><ul>';
        foreach ($entry['items'] as $item) {
            $html .= '<li>' . htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $html .= '</ul></li>';
    }
    $html .= '</ol></div></dialog>';
    return $html;
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        http_response_code(403);
        exit('Недостаточно прав.');
    }
}

function appBaseUrl(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'gost.info') . '/gost-documents/';
}

function smtpRead($fp): string
{
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 2048);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function smtpCmd($fp, string $command, string $expectPrefix): bool
{
    fwrite($fp, $command . "\r\n");
    $response = smtpRead($fp);
    return str_starts_with($response, $expectPrefix);
}

function smtpSend(array $mail, string $to, string $subject, string $body): bool
{
    $host = (string)($mail['host'] ?? '');
    $user = (string)($mail['username'] ?? '');
    $password = (string)($mail['password'] ?? '');
    $from = (string)($mail['from'] ?? $user);
    $fromName = (string)($mail['from_name'] ?? 'Документы gost.info');
    $port = (int)($mail['port'] ?? 465);
    $enc = (string)($mail['encryption'] ?? 'ssl');
    if ($host === '' || $user === '' || $password === '' || $to === '') {
        return false;
    }
    $remote = $enc === 'ssl' ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$fp && $enc === 'ssl') {
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    }
    if (!$fp) {
        $fp = @stream_socket_client("tcp://{$host}:25", $errno, $errstr, 20);
        $enc = 'none';
    }
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 20);
    smtpRead($fp);
    $ehloHost = preg_replace('/:\d+$/', '', (string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'gost.info'), PHP_URL_HOST)) ?: 'gost.info';
    if (!smtpCmd($fp, 'EHLO ' . $ehloHost, '250')) {
        fclose($fp);
        return false;
    }
    if ($enc === 'tls') {
        if (!smtpCmd($fp, 'STARTTLS', '220')) {
            fclose($fp);
            return false;
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        if (!smtpCmd($fp, 'EHLO ' . $ehloHost, '250')) {
            fclose($fp);
            return false;
        }
    }
    if (!smtpCmd($fp, 'AUTH LOGIN', '334')
        || !smtpCmd($fp, base64_encode($user), '334')
        || !smtpCmd($fp, base64_encode($password), '235')) {
        fclose($fp);
        return false;
    }
    $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = [
        'From: ' . $encodedName . ' <' . $from . '>',
        'To: ' . $to,
        'Subject: ' . $subject,
        'Date: ' . date('r'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body) . "\r\n.";
    $ok = smtpCmd($fp, 'MAIL FROM:<' . $from . '>', '250')
        && smtpCmd($fp, 'RCPT TO:<' . $to . '>', '250')
        && smtpCmd($fp, 'DATA', '354');
    if ($ok) {
        fwrite($fp, $message . "\r\n");
        $ok = str_starts_with(smtpRead($fp), '250');
    }
    smtpCmd($fp, 'QUIT', '221');
    fclose($fp);
    return $ok;
}

function sendPasswordResetMail(string $to, string $token): bool
{
    global $config;
    $url = appBaseUrl() . '?reset=' . rawurlencode($token);
    $subject = '=?UTF-8?B?' . base64_encode('Запрос пароля — документы gost.info') . '?=';
    $body = "Здравствуйте.\n\nПолучен запрос на смену пароля в системе учёта документов gost.info.\n\nЧтобы задать новый пароль, откройте ссылку. Она действует 24 часа:\n$url\n\nЕсли вы не запрашивали смену пароля, просто проигнорируйте это письмо.\n";
    return smtpSend($config['mail'] ?? [], $to, $subject, $body);
}

function createPasswordReset(PDO $pdo, int $userId, string $email): bool
{
    $token = bin2hex(random_bytes(32));
    $statement = $pdo->prepare('UPDATE users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ?');
    $statement->execute([$token, $userId]);
    return sendPasswordResetMail($email, $token);
}

ensureSchema($pdo);

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function requireCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Недействительный запрос. Обновите страницу и повторите действие.');
    }
}

function redirectHome(string $qs = ''): never
{
    header('Location: /gost-documents/' . ($qs !== '' ? ('?' . $qs) : ''));
    exit;
}

function currentQs(array $overrides = []): string
{
    $params = [
        'journal' => $_GET['journal'] ?? '',
        'view' => $_GET['view'] ?? '',
        'filter_recipient' => $_GET['filter_recipient'] ?? '',
        'filter_category' => $_GET['filter_category'] ?? '',
        'per_page' => $_GET['per_page'] ?? '',
        'page' => $_GET['page'] ?? '',
        'sort' => $_GET['sort'] ?? '',
        'dir' => $_GET['dir'] ?? '',
        'q' => $_GET['q'] ?? '',
    ];
    $params = array_merge($params, $overrides);
    $params = array_filter($params, function ($value) {
        return $value !== '' && $value !== null && $value !== false;
    });
    if (($params['journal'] ?? '') === 'outgoing') {
        unset($params['journal']);
    }
    if (($params['sort'] ?? 'date') === 'date' && ($params['dir'] ?? 'desc') === 'desc') {
        unset($params['sort'], $params['dir']);
    }
    $journalKey = (string)($params['journal'] ?? 'outgoing');
    if ($journalKey !== 'internal') {
        unset($params['filter_category']);
    }
    return http_build_query($params);
}

function storageDir(): string
{
    global $config;
    return rtrim((string)$config['storage_path'], '/\\');
}

function rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function pdfDownloadName(string $originalName): string
{
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    return ($base !== '' ? $base : 'document') . '.pdf';
}

function allowedUploadMimes(): array
{
    return [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];
}

function fileExtOf(array $file): string
{
    $ext = strtolower(pathinfo((string)($file['original_name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== '') {
        return $ext === 'jpeg' ? 'jpg' : $ext;
    }
    $mime = (string)($file['mime_type'] ?? '');
    if (str_contains($mime, 'jpeg')) {
        return 'jpg';
    }
    if (str_contains($mime, 'png')) {
        return 'png';
    }
    if (str_contains($mime, 'pdf')) {
        return 'pdf';
    }
    if (str_contains($mime, 'word')) {
        return 'docx';
    }
    return '';
}

function isWordExt(string $ext): bool
{
    return in_array($ext, ['doc', 'docx'], true);
}

function isImageExt(string $ext): bool
{
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function sendFileHeaders(string $mime, int $size, string $filename, bool $inline): void
{
    header('Content-Type: ' . $mime);
    if ($size > 0) {
        header('Content-Length: ' . $size);
    }
    $safe = str_replace(['"', "\r", "\n"], '', $filename);
    $disposition = $inline ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($safe) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
}

function findLibreOffice(): ?string
{
    $candidates = [
        '/usr/bin/soffice',
        '/usr/bin/libreoffice',
        '/usr/bin/lowriter',
        '/usr/lib/libreoffice/program/soffice',
        '/opt/libreoffice/program/soffice',
        '/opt/libreoffice24.8/program/soffice',
        '/opt/libreoffice25.2/program/soffice',
    ];
    foreach ($candidates as $bin) {
        if (is_executable($bin)) {
            return $bin;
        }
    }
    if (!function_exists('exec')) {
        return null;
    }
    foreach (['soffice', 'libreoffice', 'lowriter'] as $name) {
        $out = [];
        $code = 1;
        @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out, $code);
        if ($code === 0 && !empty($out[0]) && is_executable($out[0])) {
            return $out[0];
        }
    }
    return null;
}

function convertWordToPdf(string $sourcePath, string $targetPath): bool
{
    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return @copy($sourcePath, $targetPath);
    }
    if (!function_exists('exec')) {
        return false;
    }
    $bin = findLibreOffice();
    if ($bin === null) {
        return false;
    }
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdfconv_' . bin2hex(random_bytes(8));
    if (!@mkdir($tmp, 0700) && !is_dir($tmp)) {
        return false;
    }
    $home = $tmp . DIRECTORY_SEPARATOR . 'home';
    @mkdir($home, 0700);
    $inFile = $tmp . DIRECTORY_SEPARATOR . 'source.' . $ext;
    $ok = false;
    try {
        if (!@copy($sourcePath, $inFile)) {
            return false;
        }
        putenv('HOME=' . $home);
        putenv('TMPDIR=' . $tmp);
        $cmd = escapeshellarg($bin) . ' --headless --norestore --nolockcheck --nologo --nofirststartwizard --convert-to pdf --outdir ' . escapeshellarg($tmp) . ' ' . escapeshellarg($inFile);
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        $generated = $tmp . DIRECTORY_SEPARATOR . 'source.pdf';
        $ok = is_file($generated) && filesize($generated) > 0 && @copy($generated, $targetPath);
    } finally {
        rmtree($tmp);
    }
    return $ok;
}

function documentFileRow(PDO $pdo, int $docId, int $orgId): ?array
{
    $statement = $pdo->prepare('SELECT df.* FROM document_files df JOIN documents d ON d.id = df.document_id WHERE df.document_id = ? AND d.organization_id = ? ORDER BY df.id LIMIT 1');
    $statement->execute([$docId, $orgId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function ensurePdfForDocument(PDO $pdo, int $docId, int $orgId): ?array
{
    $row = documentFileRow($pdo, $docId, $orgId);
    if (!$row) {
        return null;
    }
    $storage = storageDir();
    $source = $storage . DIRECTORY_SEPARATOR . $row['stored_name'];
    if (!is_file($source)) {
        return null;
    }
    $ext = strtolower(pathinfo((string)($row['original_name'] ?: $row['stored_name']), PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return [
            'path' => $source,
            'name' => (string)$row['original_name'],
            'size' => (int)$row['file_size'],
        ];
    }
    if (!isWordExt($ext === 'jpeg' ? 'jpg' : $ext)) {
        return null;
    }
    if (!empty($row['pdf_stored_name'])) {
        $pdfPath = $storage . DIRECTORY_SEPARATOR . $row['pdf_stored_name'];
        if (is_file($pdfPath)) {
            return [
                'path' => $pdfPath,
                'name' => (string)($row['pdf_original_name'] ?: pdfDownloadName((string)$row['original_name'])),
                'size' => (int)($row['pdf_file_size'] ?: filesize($pdfPath)),
            ];
        }
    }
    @set_time_limit(180);
    $pdfStored = bin2hex(random_bytes(24)) . '.pdf';
    $pdfPath = $storage . DIRECTORY_SEPARATOR . $pdfStored;
    if (!convertWordToPdf($source, $pdfPath)) {
        @unlink($pdfPath);
        return null;
    }
    $pdfName = pdfDownloadName((string)$row['original_name']);
    $size = (int)filesize($pdfPath);
    $pdo->prepare('UPDATE document_files SET pdf_stored_name = ?, pdf_original_name = ?, pdf_file_size = ? WHERE id = ?')
        ->execute([$pdfStored, $pdfName, $size, $row['id']]);
    return ['path' => $pdfPath, 'name' => $pdfName, 'size' => $size];
}

function uniqueZipName(string $entryName, array &$usedNames, string $prefix): string
{
    $name = $prefix . $entryName;
    $suffix = 1;
    while (in_array($name, $usedNames, true)) {
        $name = $prefix . $suffix . '_' . $entryName;
        $suffix++;
    }
    $usedNames[] = $name;
    return $name;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    redirectHome();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    requireCsrf();
    $login = trim((string)($_POST['username'] ?? ''));
    $statement = $pdo->prepare('SELECT id, username, email, full_name, password_hash, role FROM users WHERE username = ? OR email = ? LIMIT 1');
    $statement->execute([$login, $login]);
    $user = $statement->fetch();
    if ($user && password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'] ?? '';
        $_SESSION['role'] = $user['role'] ?? 'user';
        redirectHome();
    }
    $loginError = 'Неверное имя пользователя, email или пароль.';
}

$loginInfo = '';
$resetUser = null;
$resetToken = trim((string)($_GET['reset'] ?? $_POST['reset_token'] ?? ''));
if ($resetToken !== '') {
    $statement = $pdo->prepare('SELECT id, username, email FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1');
    $statement->execute([$resetToken]);
    $resetUser = $statement->fetch() ?: null;
    if (!$resetUser) {
        $loginError = 'Ссылка для смены пароля недействительна или истекла. Запросите пароль ещё раз.';
        $resetToken = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_password'])) {
    requireCsrf();
    $login = trim((string)($_POST['username'] ?? ''));
    $statement = $pdo->prepare('SELECT id, email FROM users WHERE username = ? OR email = ? LIMIT 1');
    $statement->execute([$login, $login]);
    $user = $statement->fetch();
    if ($user && !empty($user['email'])) {
        createPasswordReset($pdo, (int)$user['id'], (string)$user['email']);
    }
    $loginInfo = 'Если учётная запись найдена, на почту отправлена ссылка для смены пароля. Проверьте также папку «Спам».';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_new_password'])) {
    requireCsrf();
    $newPassword = (string)($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string)($_POST['new_password_confirm'] ?? '');
    if (!$resetUser) {
        $loginError = 'Ссылка для смены пароля недействительна или истекла.';
    } elseif (mb_strlen($newPassword) < 8) {
        $loginError = 'Пароль должен быть не короче 8 символов.';
    } elseif ($newPassword !== $newPasswordConfirm) {
        $loginError = 'Пароли не совпадают.';
    } else {
        $statement = $pdo->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?');
        $statement->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$resetUser['id']]);
        $loginInfo = 'Пароль обновлён. Войдите с новым паролем.';
        $resetUser = null;
        $resetToken = '';
    }
}

$isAuthenticated = isset($_SESSION['user_id']);
if ($isAuthenticated) {
    $statement = $pdo->prepare('SELECT username, email, full_name, role FROM users WHERE id = ?');
    $statement->execute([(int)$_SESSION['user_id']]);
    $currentUserRow = $statement->fetch();
    if (!$currentUserRow) {
        $_SESSION = [];
        session_destroy();
        redirectHome();
    }
    $_SESSION['username'] = $currentUserRow['username'];
    $_SESSION['email'] = $currentUserRow['email'];
    $_SESSION['full_name'] = $currentUserRow['full_name'] ?? '';
    $_SESSION['role'] = $currentUserRow['role'] ?? 'user';
}
if ($isAuthenticated) {
    static $countersSynced = false;
    if (!$countersSynced) {
        foreach ($pdo->query('SELECT id FROM organizations')->fetchAll() as $orgRow) {
            foreach (['outgoing', 'incoming', 'internal'] as $typeName) {
                syncOrgCounter($pdo, (int)$orgRow['id'], $typeName);
            }
        }
        $countersSynced = true;
    }
}
if (!$isAuthenticated) {
    $forgot = isset($_GET['forgot']) || isset($_POST['request_password']);
    ?>
    <!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Документы</title><style>
:root{--paper:#f3f1ea;--paper-raised:#fffcf7;--ink:#1a1f2b;--ink-soft:#667085;--accent:#1f3fb8;--accent-hover:#17318f;--rule:#e4dfd2;--danger:#b3261e;--danger-soft:#fbeae9;--ok:#1e7a4c;--ok-soft:#eaf6ee}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(900px 420px at 12% -8%,#e7eefc 0%,transparent 55%),var(--paper);color:var(--ink);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:24px}
main{width:100%;max-width:376px;background:var(--paper-raised);border:1px solid var(--rule);border-radius:14px;padding:40px 36px;box-shadow:0 10px 30px rgba(27,33,48,.06)}
h1{font-family:Georgia,"Iowan Old Style","Palatino Linotype",serif;font-size:26px;font-weight:600;margin:0 0 6px;letter-spacing:-.01em}
p{color:var(--ink-soft);margin:0 0 22px;font-size:14px}
label{display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--ink-soft);margin:0 0 16px}
input{font:15px/1.4 inherit;padding:10px 12px;border:1px solid var(--rule);border-radius:8px;background:#fff;color:var(--ink)}
input:focus{outline:2px solid var(--accent);outline-offset:1px;border-color:var(--accent)}
button{margin-top:8px;width:100%;padding:11px;background:var(--accent);color:#fff;border:0;border-radius:8px;font-size:14.5px;font-weight:600;cursor:pointer}
button:hover{background:var(--accent-hover)}
.error{color:var(--danger);background:var(--danger-soft);padding:9px 12px;border-radius:8px;font-size:13.5px;margin:0 0 16px}
.ok{color:var(--ok);background:var(--ok-soft);padding:9px 12px;border-radius:8px;font-size:13.5px;margin:0 0 16px}
.aux{display:block;margin-top:16px;text-align:center;color:var(--accent);font-size:13.5px;text-decoration:none}
.aux:hover{text-decoration:underline}
.brand-login{position:fixed;top:18px;left:22px;z-index:2}
.brand-login img{height:40px;width:auto;display:block}
</style></head><body><a class="brand-login" href="/gost-documents/"><img src="logo-normasoft.png" alt="Норма Софт"></a><main>
<?php if ($resetUser): ?>
<h1>Новый пароль</h1>
<p>Учётная запись <?= htmlspecialchars((string)$resetUser['username'], ENT_QUOTES, 'UTF-8') ?></p>
<?php if (!empty($loginError)): ?><p class="error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="reset_token" value="<?= htmlspecialchars($resetToken, ENT_QUOTES, 'UTF-8') ?>">
<label>Новый пароль<input type="password" name="new_password" autocomplete="new-password" required minlength="8"></label>
<label>Повтор пароля<input type="password" name="new_password_confirm" autocomplete="new-password" required minlength="8"></label>
<button name="set_new_password" value="1">Сохранить пароль</button>
</form>
<a class="aux" href="/gost-documents/">Ко входу</a>
<?php elseif ($forgot): ?>
<h1>Запрос пароля</h1>
<p>Укажите логин или email. Ссылка для смены пароля придёт на почту учётной записи.</p>
<?php if (!empty($loginInfo)): ?><p class="ok"><?= htmlspecialchars($loginInfo, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
<label>Пользователь или email<input name="username" autocomplete="username" required></label>
<button name="request_password" value="1">Отправить ссылку</button>
</form>
<a class="aux" href="/gost-documents/">Ко входу</a>
<?php else: ?>
<h1>Документы</h1>
<p>Вход в защищенное хранилище</p>
<?php if (!empty($loginError)): ?><p class="error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if (!empty($loginInfo)): ?><p class="ok"><?= htmlspecialchars($loginInfo, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
<label>Пользователь или email<input name="username" autocomplete="username" required></label>
<label>Пароль<input type="password" name="password" autocomplete="current-password" required></label>
<button name="login" value="1">Войти</button>
</form>
<a class="aux" href="?forgot=1">Запросить пароль</a>
<?php endif; ?>
</main></body></html>
    <?php
    exit;
}

$organizations = $pdo->query('SELECT id, name, inn FROM organizations ORDER BY id')->fetchAll();
if (!$organizations) {
    http_response_code(500);
    exit('Организации не настроены. Выполните первичную установку.');
}

$activeOrgId = isset($_SESSION['active_org_id']) ? (int)$_SESSION['active_org_id'] : 0;
$activeOrgName = '';
foreach ($organizations as $organization) {
    if ((int)$organization['id'] === $activeOrgId) {
        $activeOrgName = $organization['name'];
        break;
    }
}
if ($activeOrgName === '') {
    $activeOrgId = (int)$organizations[0]['id'];
    $activeOrgName = $organizations[0]['name'];
    $_SESSION['active_org_id'] = $activeOrgId;
}

if (isset($_GET['switch_org'])) {
    $switchOrgId = (int)$_GET['switch_org'];
    foreach ($organizations as $organization) {
        if ((int)$organization['id'] === $switchOrgId) {
            $_SESSION['active_org_id'] = $switchOrgId;
            redirectHome(currentQs(['page' => '']));
        }
    }
}

$documentTypeLabels = ['outgoing' => 'Исходящий', 'incoming' => 'Входящий', 'internal' => 'Внутренний'];
$journalTitles = ['outgoing' => 'Исходящие документы', 'incoming' => 'Входящие документы', 'internal' => 'Внутренние документы'];
$journal = (string)($_GET['journal'] ?? 'outgoing');
if (!isset($documentTypeLabels[$journal])) {
    $journal = 'outgoing';
}
$view = (string)($_GET['view'] ?? '');
if ($view === 'users' && !isAdmin()) {
    $view = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    requireCsrf();
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string)($_POST['new_password_confirm'] ?? '');
    if ($newPassword !== '' || $newPasswordConfirm !== '') {
        if (mb_strlen($newPassword) < 8) {
            $profileError = 'Пароль должен быть не короче 8 символов.';
        } elseif ($newPassword !== $newPasswordConfirm) {
            $profileError = 'Пароли не совпадают.';
        } else {
            $statement = $pdo->prepare('UPDATE users SET full_name = ?, password_hash = ? WHERE id = ?');
            $statement->execute([$fullName, password_hash($newPassword, PASSWORD_DEFAULT), (int)$_SESSION['user_id']]);
            $_SESSION['full_name'] = $fullName;
            redirectHome((string)($_POST['return_qs'] ?? ''));
        }
    } else {
        $statement = $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?');
        $statement->execute([$fullName, (int)$_SESSION['user_id']]);
        $_SESSION['full_name'] = $fullName;
        redirectHome((string)($_POST['return_qs'] ?? ''));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    requireCsrf();
    requireAdmin();
    $newUsername = trim((string)($_POST['username'] ?? ''));
    $newEmail = trim((string)($_POST['email'] ?? ''));
    $newFullName = trim((string)($_POST['full_name'] ?? ''));
    $newRole = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $newPassword = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $newUsername) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($newPassword) < 8) {
        $usersError = 'Логин от 3 символов, корректный email и пароль не короче 8 символов.';
        $view = 'users';
    } else {
        try {
            $statement = $pdo->prepare('INSERT INTO users (username, email, full_name, password_hash, role) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$newUsername, $newEmail, $newFullName !== '' ? $newFullName : null, password_hash($newPassword, PASSWORD_DEFAULT), $newRole]);
            redirectHome(currentQs(['view' => 'users', 'page' => '']));
        } catch (Throwable $error) {
            $usersError = 'Такой логин или email уже занят.';
            $view = 'users';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    requireCsrf();
    requireAdmin();
    $targetId = (int)($_POST['user_id'] ?? 0);
    $newRole = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $newPassword = (string)($_POST['password'] ?? '');
    $newFullName = trim((string)($_POST['full_name'] ?? ''));
    if ($targetId < 1) {
        $usersError = 'Пользователь не найден.';
        $view = 'users';
    } else {
        $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        $statement = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $statement->execute([$targetId]);
        $targetRole = $statement->fetchColumn();
        if ($targetRole === false) {
            $usersError = 'Пользователь не найден.';
            $view = 'users';
        } elseif ($targetRole === 'admin' && $newRole !== 'admin' && $adminCount <= 1) {
            $usersError = 'Нельзя снять роль с последнего администратора.';
            $view = 'users';
        } else {
            $statement = $pdo->prepare('UPDATE users SET full_name = ?, role = ? WHERE id = ?');
            $statement->execute([$newFullName !== '' ? $newFullName : null, $newRole, $targetId]);
            if ($newPassword !== '') {
                if (mb_strlen($newPassword) < 8) {
                    $usersError = 'Пароль должен быть не короче 8 символов.';
                    $view = 'users';
                } else {
                    $statement = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $statement->execute([password_hash($newPassword, PASSWORD_DEFAULT), $targetId]);
                }
            }
            if (empty($usersError)) {
                if ($targetId === (int)$_SESSION['user_id']) {
                    $_SESSION['role'] = $newRole;
                    $_SESSION['full_name'] = $newFullName;
                }
                redirectHome(currentQs(['view' => 'users', 'page' => '']));
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reset'])) {
    requireCsrf();
    requireAdmin();
    $targetId = (int)($_POST['user_id'] ?? 0);
    $statement = $pdo->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$targetId]);
    $target = $statement->fetch();
    $view = 'users';
    if (!$target || empty($target['email'])) {
        $usersError = 'У пользователя нет email для отправки ссылки.';
    } elseif (createPasswordReset($pdo, (int)$target['id'], (string)$target['email'])) {
        $usersError = '';
        $usersInfo = 'Ссылка для смены пароля отправлена на ' . (string)$target['email'];
    } else {
        $usersInfo = 'Ссылка создана, но письмо может не дойти. Пользователь может запросить пароль со страницы входа.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_recipient'])) {
    requireCsrf();
    $newInn = trim((string)($_POST['inn'] ?? ''));
    $newName = trim((string)($_POST['name'] ?? ''));
    if (!preg_match('/^\d{10}$|^\d{12}$/', $newInn) || $newName === '') {
        $recipientError = 'Укажите ИНН (10 или 12 цифр) и наименование получателя.';
    } else {
        try {
            $statement = $pdo->prepare('INSERT INTO recipients (inn, name) VALUES (?, ?)');
            $statement->execute([$newInn, $newName]);
            $returnQs = (string)($_POST['return_qs'] ?? '');
            redirectHome($returnQs !== '' ? $returnQs . '&reopen=doc' : 'reopen=doc');
        } catch (Throwable $error) {
            $recipientError = 'Получатель с таким ИНН уже есть в реестре.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_recipient'])) {
    requireCsrf();
    $editRecipientId = (int)($_POST['recipient_id'] ?? 0);
    $editInn = trim((string)($_POST['inn'] ?? ''));
    $editName = trim((string)($_POST['name'] ?? ''));
    if (!preg_match('/^\d{10}$|^\d{12}$/', $editInn) || $editName === '') {
        $recipientError = 'Укажите ИНН (10 или 12 цифр) и наименование получателя.';
    } else {
        try {
            $statement = $pdo->prepare('UPDATE recipients SET inn = ?, name = ? WHERE id = ?');
            $statement->execute([$editInn, $editName, $editRecipientId]);
            $returnQs = (string)($_POST['return_qs'] ?? '');
            redirectHome($returnQs !== '' ? $returnQs . '&reopen=doc' : 'reopen=doc');
        } catch (Throwable $error) {
            $recipientError = 'Получатель с таким ИНН уже есть в реестре.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_recipient'])) {
    requireCsrf();
    $deleteRecipientId = (int)($_POST['recipient_id'] ?? 0);
    $statement = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE recipient_id = ?');
    $statement->execute([$deleteRecipientId]);
    if ((int)$statement->fetchColumn() > 0) {
        $recipientError = 'Этот получатель привязан к документам, удаление запрещено.';
    } else {
        $pdo->prepare('DELETE FROM recipients WHERE id = ?')->execute([$deleteRecipientId]);
    }
}

$categoryError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_internal_category'])) {
    requireCsrf();
    $newCategoryName = trim((string)($_POST['category_name'] ?? ''));
    if ($newCategoryName === '') {
        $categoryError = 'Укажите название категории.';
    } else {
        try {
            $maxOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM internal_categories')->fetchColumn();
            $pdo->prepare('INSERT INTO internal_categories (name, sort_order) VALUES (?, ?)')->execute([$newCategoryName, $maxOrder + 10]);
            $returnQs = (string)($_POST['return_qs'] ?? '');
            redirectHome($returnQs !== '' ? $returnQs . '&reopen=doc' : 'reopen=doc');
        } catch (Throwable $error) {
            $categoryError = 'Такая категория уже есть.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_internal_category'])) {
    requireCsrf();
    $deleteCategoryId = (int)($_POST['category_id'] ?? 0);
    $used = $pdo->prepare('SELECT COUNT(*) FROM document_internal_categories WHERE category_id = ?');
    $used->execute([$deleteCategoryId]);
    if ((int)$used->fetchColumn() > 0) {
        $categoryError = 'Категория уже привязана к документам, удаление запрещено.';
    } else {
        $pdo->prepare('DELETE FROM internal_categories WHERE id = ?')->execute([$deleteCategoryId]);
    }
}

$internalCategories = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM document_internal_categories m WHERE m.category_id = c.id) AS doc_count FROM internal_categories c ORDER BY c.sort_order, c.id')->fetchAll();
$recipients = $pdo->query('SELECT r.*, (SELECT COUNT(*) FROM documents d WHERE d.recipient_id = r.id) AS doc_count FROM recipients r ORDER BY r.name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    requireCsrf();
    $deleteId = (int)($_POST['document_id'] ?? 0);
    $statement = $pdo->prepare('SELECT type FROM documents WHERE id = ? AND organization_id = ?');
    $statement->execute([$deleteId, $activeOrgId]);
    $deletedType = $statement->fetchColumn();
    if ($deletedType) {
        $statement = $pdo->prepare('SELECT stored_name, pdf_stored_name FROM document_files WHERE document_id = ?');
        $statement->execute([$deleteId]);
        $filesToDelete = $statement->fetchAll();
        $pdo->prepare('DELETE FROM document_files WHERE document_id = ?')->execute([$deleteId]);
        $pdo->prepare('DELETE FROM document_internal_categories WHERE document_id = ?')->execute([$deleteId]);
        $pdo->prepare('DELETE FROM documents WHERE id = ? AND organization_id = ?')->execute([$deleteId, $activeOrgId]);
        foreach ($filesToDelete as $fileToDelete) {
            $path = storageDir() . DIRECTORY_SEPARATOR . $fileToDelete['stored_name'];
            @unlink($path);
            if (!empty($fileToDelete['pdf_stored_name'])) {
                @unlink(storageDir() . DIRECTORY_SEPARATOR . $fileToDelete['pdf_stored_name']);
            }
        }
        syncOrgCounter($pdo, $activeOrgId, (string)$deletedType);
    }
    redirectHome((string)($_POST['return_qs'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_download'])) {
    requireCsrf();
    $items = (array)($_POST['items'] ?? []);
    $wordIds = [];
    $pdfIds = [];
    foreach ($items as $item) {
        if (!is_string($item) || !preg_match('/^(word|file|pdf):(\d+)$/', $item, $match)) {
            continue;
        }
        $id = (int)$match[2];
        if ($id <= 0) {
            continue;
        }
        if ($match[1] === 'word' || $match[1] === 'file') {
            $wordIds[$id] = $id;
        } else {
            $pdfIds[$id] = $id;
        }
    }
    $allIds = array_values(array_unique(array_merge(array_values($wordIds), array_values($pdfIds))));
    if ($allIds && class_exists('ZipArchive')) {
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $statement = $pdo->prepare("SELECT d.id, d.document_number, df.original_name, df.stored_name FROM documents d JOIN document_files df ON df.document_id = d.id WHERE d.organization_id = ? AND d.id IN ($placeholders)");
        $statement->execute(array_merge([$activeOrgId], $allIds));
        $selectedDocs = [];
        foreach ($statement->fetchAll() as $selectedDoc) {
            $selectedDocs[(int)$selectedDoc['id']] = $selectedDoc;
        }
        if ($selectedDocs) {
            $tmpZip = tempnam(sys_get_temp_dir(), 'docs_') . '.zip';
            $zip = new ZipArchive();
            $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $usedNames = [];
            foreach ($wordIds as $docId) {
                if (!isset($selectedDocs[$docId])) {
                    continue;
                }
                $selectedDoc = $selectedDocs[$docId];
                $path = storageDir() . DIRECTORY_SEPARATOR . $selectedDoc['stored_name'];
                if (!is_file($path)) {
                    continue;
                }
                $zip->addFile($path, uniqueZipName($selectedDoc['original_name'], $usedNames, $selectedDoc['document_number'] . '_'));
            }
            foreach ($pdfIds as $docId) {
                $pdf = ensurePdfForDocument($pdo, $docId, $activeOrgId);
                if (!$pdf) {
                    continue;
                }
                $prefix = isset($selectedDocs[$docId]) ? $selectedDocs[$docId]['document_number'] . '_' : '';
                $zip->addFile($pdf['path'], uniqueZipName($pdf['name'], $usedNames, $prefix));
            }
            $zip->close();
            if (is_file($tmpZip) && filesize($tmpZip) > 22) {
                sendFileHeaders('application/zip', (int)filesize($tmpZip), 'documents_' . date('Y-m-d') . '.zip', false);
                readfile($tmpZip);
                @unlink($tmpZip);
                exit;
            }
            @unlink($tmpZip);
        }
    }
    redirectHome((string)($_POST['return_qs'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_edit'])) {
    requireCsrf();
    $editIdPost = (int)($_POST['edit_id'] ?? 0);
    $documentDate = (string)($_POST['document_date'] ?? '');
    $subject = trim((string)($_POST['subject'] ?? ''));
    $file = $_FILES['document'] ?? null;
    $allowed = allowedUploadMimes();
    $statement = $pdo->prepare('SELECT type, organization_id, document_number FROM documents WHERE id = ? AND organization_id = ?');
    $statement->execute([$editIdPost, $activeOrgId]);
    $existing = $editIdPost ? $statement->fetch() : false;
    $fileStatement = $pdo->prepare('SELECT id, stored_name, pdf_stored_name FROM document_files WHERE document_id = ? ORDER BY id LIMIT 1');
    $fileStatement->execute([$editIdPost]);
    $existingFile = $editIdPost ? $fileStatement->fetch() : false;
    $recipientId = (int)($_POST['recipient_id'] ?? 0);
    $recipient = '';
    foreach ($recipients as $recipientRow) {
        if ((int)$recipientRow['id'] === $recipientId) {
            $recipient = $recipientRow['name'];
            break;
        }
    }
    if ($existing && $existing['type'] === 'internal' && $recipient === '') {
        foreach ($organizations as $organization) {
            if ((int)$organization['id'] === (int)$existing['organization_id']) {
                $recipient = $organization['name'];
                break;
            }
        }
        $recipientId = null;
    }
    if (!$editIdPost || !$existing || !$documentDate || !$recipient || !$subject) {
        $uploadError = $existing ? 'Заполните все поля.' : 'Документ не найден.';
        $editId = $editIdPost;
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
        $uploadError = 'Укажите корректную дату документа.';
        $editId = $editIdPost;
    } else {
        $replacingFile = $file && $file['error'] === UPLOAD_ERR_OK;
        $newOriginalName = null;
        $newStoredName = null;
        $newMime = null;
        $newSize = null;
        if ($replacingFile) {
            $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!isset($allowed[$extension]) || $mime !== $allowed[$extension] || $file['size'] > 20 * 1024 * 1024) {
                $uploadError = 'Разрешены PDF, DOC, DOCX, XLS, XLSX, JPG и PNG размером до 20 МБ.';
                $editId = $editIdPost;
            } else {
                $storage = rtrim($config['storage_path'], '/\\');
                if (!is_dir($storage)) {
                    mkdir($storage, 0700, true);
                }
                $newStoredName = bin2hex(random_bytes(24)) . '.' . $extension;
                $storedPath = $storage . DIRECTORY_SEPARATOR . $newStoredName;
                if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
                    $uploadError = 'Не удалось сохранить файл.';
                    $editId = $editIdPost;
                } else {
                    $newOriginalName = basename((string)$file['name']);
                    $newMime = $mime;
                    $newSize = (int)$file['size'];
                    if ($extension === 'docx') {
                        $existingCode = documentSearchCode($pdo, $editIdPost);
                        if ($existingCode !== '') {
                            stampDocxFile($storedPath, $existingCode);
                        }
                    }
                }
            }
        }
        if (empty($uploadError)) {
            $newDocumentNumber = trim((string)($_POST['document_number'] ?? ''));
            if ($newDocumentNumber === '') {
                $newDocumentNumber = $existing['document_number'];
            }
            $statement = $pdo->prepare('UPDATE documents SET document_date = ?, recipient = ?, recipient_id = ?, subject = ?, document_number = ? WHERE id = ?');
            $statement->execute([$documentDate, $recipient, $recipientId, $subject, $newDocumentNumber, $editIdPost]);
            if ($replacingFile) {
                if ($existingFile) {
                    $statement = $pdo->prepare('UPDATE document_files SET original_name = ?, stored_name = ?, mime_type = ?, file_size = ?, pdf_stored_name = NULL, pdf_original_name = NULL, pdf_file_size = NULL WHERE id = ?');
                    $statement->execute([$newOriginalName, $newStoredName, $newMime, $newSize, $existingFile['id']]);
                    $oldPath = storageDir() . DIRECTORY_SEPARATOR . $existingFile['stored_name'];
                    @unlink($oldPath);
                    if (!empty($existingFile['pdf_stored_name'])) {
                        @unlink(storageDir() . DIRECTORY_SEPARATOR . $existingFile['pdf_stored_name']);
                    }
                } else {
                    $statement = $pdo->prepare('INSERT INTO document_files (document_id, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)');
                    $statement->execute([$editIdPost, $newOriginalName, $newStoredName, $newMime, $newSize]);
                }
            }
            syncOrgCounter($pdo, $activeOrgId, (string)$existing['type']);
            if ($existing['type'] === 'internal') {
                syncDocumentCategories($pdo, $editIdPost, postedCategoryIds($internalCategories));
            }
            redirectHome((string)($_POST['return_qs'] ?? ''));
        }
    }
}

$editDocument = null;
if (isset($_GET['edit']) || isset($editId)) {
    $editId = (int)($editId ?? $_GET['edit']);
    $statement = $pdo->prepare('SELECT d.*, o.name AS organization_name, (SELECT original_name FROM document_files WHERE document_id = d.id ORDER BY id LIMIT 1) AS original_name FROM documents d JOIN organizations o ON o.id = d.organization_id WHERE d.id = ? AND d.organization_id = ?');
    $statement->execute([$editId, $activeOrgId]);
    $editDocument = $statement->fetch();
    if (!$editDocument) {
        $editId = null;
    }
}
$editCategoryIds = [];
if ($editDocument && ($editDocument['type'] ?? '') === 'internal') {
    $catStatement = $pdo->prepare('SELECT category_id FROM document_internal_categories WHERE document_id = ?');
    $catStatement->execute([(int)$editDocument['id']]);
    $editCategoryIds = array_map('intval', $catStatement->fetchAll(PDO::FETCH_COLUMN));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['category_ids'])) {
    $editCategoryIds = postedCategoryIds($internalCategories);
}

if (isset($_GET['download'])) {
    $statement = $pdo->prepare('SELECT df.original_name, df.stored_name, df.mime_type, df.file_size FROM document_files df JOIN documents d ON d.id = df.document_id WHERE df.document_id = ? AND d.organization_id = ? ORDER BY df.id LIMIT 1');
    $statement->execute([(int)$_GET['download'], $activeOrgId]);
    $document = $statement->fetch();
    $path = $document ? storageDir() . DIRECTORY_SEPARATOR . $document['stored_name'] : '';
    if (!$document || !is_file($path)) {
        http_response_code(404);
        exit('Файл не найден.');
    }
    sendFileHeaders((string)$document['mime_type'], (int)$document['file_size'], (string)$document['original_name'], false);
    readfile($path);
    exit;
}

if (isset($_GET['pdf'])) {
    $pdf = ensurePdfForDocument($pdo, (int)$_GET['pdf'], $activeOrgId);
    if (!$pdf) {
        http_response_code(500);
        exit('Не удалось сформировать PDF из Word. Исходный файл недоступен или на сервере нет конвертера.');
    }
    sendFileHeaders('application/pdf', (int)$pdf['size'], (string)$pdf['name'], false);
    readfile($pdf['path']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload'])) {
    requireCsrf();
    $organizationId = $activeOrgId;
    $type = in_array($_POST['type'] ?? '', ['outgoing', 'incoming', 'internal'], true) ? $_POST['type'] : $journal;
    if (!isset($documentTypeLabels[$type])) {
        $type = $journal;
    }
    $documentDate = (string)($_POST['document_date'] ?? '');
    $recipientId = (int)($_POST['recipient_id'] ?? 0);
    $recipient = '';
    foreach ($recipients as $recipientRow) {
        if ((int)$recipientRow['id'] === $recipientId) {
            $recipient = $recipientRow['name'];
            break;
        }
    }
    if ($type === 'internal' && $recipient === '' && $organizationId) {
        foreach ($organizations as $organization) {
            if ((int)$organization['id'] === (int)$organizationId) {
                $recipient = $organization['name'];
                break;
            }
        }
        $recipientId = null;
    }
    $subject = trim((string)($_POST['subject'] ?? ''));
    $manualNumber = trim((string)($_POST['document_number'] ?? ''));
    $file = $_FILES['document'] ?? null;
    $allowed = allowedUploadMimes();
    if ($organizationId === false || $organizationId === null || $type === '' || !$documentDate || !$recipient || !$subject || $manualNumber === '' || !$file || $file['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'Укажите номер из документа, заполните все поля и выберите файл.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
        $uploadError = 'Укажите корректную дату документа.';
    } else {
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($allowed[$extension]) || $mime !== $allowed[$extension] || $file['size'] > 20 * 1024 * 1024) {
            $uploadError = 'Разрешены PDF, DOC, DOCX, XLS, XLSX, JPG и PNG размером до 20 МБ.';
        } else {
            $storage = rtrim($config['storage_path'], '/\\');
            if (!is_dir($storage)) {
                mkdir($storage, 0700, true);
            }
            $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
            $storedPath = $storage . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
                $uploadError = 'Не удалось сохранить файл.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $lock = $pdo->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE');
                    $lock->execute([$organizationId]);
                    if (!$lock->fetchColumn()) {
                        throw new RuntimeException('Организация не найдена.');
                    }
                    $documentNumber = $manualNumber;
                    if ($type !== 'internal') {
                        $taken = $pdo->prepare('SELECT id FROM documents WHERE organization_id = ? AND type = ? AND document_number = ? LIMIT 1');
                        $taken->execute([$organizationId, $type, $documentNumber]);
                        if ($taken->fetchColumn()) {
                            throw new RuntimeException('number-taken');
                        }
                    }
                    $searchCode = generateSearchCode($pdo);
                    $statement = $pdo->prepare('INSERT INTO documents (organization_id, type, document_number, document_date, recipient, recipient_id, subject, uploaded_by, search_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $statement->execute([$organizationId, $type, $documentNumber, $documentDate, $recipient, $recipientId, $subject, (int)$_SESSION['user_id'], $searchCode]);
                    $newDocumentId = (int)$pdo->lastInsertId();
                    $statement = $pdo->prepare('INSERT INTO document_files (document_id, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)');
                    $statement->execute([$newDocumentId, basename((string)$file['name']), $storedName, $mime, (int)$file['size']]);
                    if ($extension === 'docx') {
                        stampDocxFile($storedPath, $searchCode);
                    }
                    if ($type === 'internal') {
                        syncDocumentCategories($pdo, $newDocumentId, postedCategoryIds($internalCategories));
                    }
                    syncOrgCounter($pdo, $organizationId, $type);
                    $pdo->commit();
                    redirectHome((string)($_POST['return_qs'] ?? ''));
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    @unlink($storedPath);
                    $uploadError = 'Не удалось зарегистрировать документ. Возможно, такой номер уже занят.';
                }
            }
        }
    }
}

$filterRecipient = isset($_GET['filter_recipient']) ? (int)$_GET['filter_recipient'] : 0;
$searchQuery = trim((string)($_GET['q'] ?? ''));
$perPageRaw = (string)($_GET['per_page'] ?? '10');
if (!in_array($perPageRaw, ['10', '20', '30', '50', 'all'], true)) {
    $perPageRaw = '10';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$allowedSort = ['number', 'date', 'recipient', 'subject'];
$sort = in_array((string)($_GET['sort'] ?? ''), $allowedSort, true) ? (string)$_GET['sort'] : 'date';
$dir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
if (!isset($_GET['sort']) && !isset($_GET['dir'])) {
    $sort = 'date';
    $dir = 'desc';
}
$recommendedNumber = nextDocumentNumber($pdo, $activeOrgId, $journal);
$filterCategory = isset($_GET['filter_category']) ? (int)$_GET['filter_category'] : 0;

$where = ['d.organization_id = ?', 'd.type = ?'];
$queryParams = [$activeOrgId, $journal];
if ($filterRecipient) {
    $where[] = 'd.recipient_id = ?';
    $queryParams[] = $filterRecipient;
}
if ($journal === 'internal' && $filterCategory) {
    $where[] = 'EXISTS (SELECT 1 FROM document_internal_categories m WHERE m.document_id = d.id AND m.category_id = ?)';
    $queryParams[] = $filterCategory;
}
if ($searchQuery !== '') {
    $where[] = '(d.search_code LIKE ? OR d.document_number LIKE ? OR d.subject LIKE ? OR d.recipient LIKE ?)';
    $like = '%' . $searchQuery . '%';
    array_push($queryParams, $like, $like, $like, $like);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$countStatement = $pdo->prepare("SELECT COUNT(*) FROM documents d $whereSql");
$countStatement->execute($queryParams);
$totalDocuments = (int)$countStatement->fetchColumn();

if ($perPageRaw === 'all') {
    $limitSql = '';
    $totalPages = 1;
    $page = 1;
    $rangeFrom = $totalDocuments ? 1 : 0;
    $rangeTo = $totalDocuments;
} else {
    $perPage = (int)$perPageRaw;
    $totalPages = max(1, (int)ceil($totalDocuments / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $limitSql = "LIMIT $perPage OFFSET $offset";
    $rangeFrom = $totalDocuments ? $offset + 1 : 0;
    $rangeTo = min($offset + $perPage, $totalDocuments);
}

$sqlDir = $dir === 'asc' ? 'ASC' : 'DESC';
$categorySelect = "(SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order SEPARATOR ', ') FROM document_internal_categories m JOIN internal_categories c ON c.id = m.category_id WHERE m.document_id = d.id)";
$orderSql = [
    'number' => "CAST(d.document_number AS UNSIGNED) $sqlDir, d.id $sqlDir",
    'date' => "d.document_date $sqlDir, d.id $sqlDir",
    'recipient' => ($journal === 'internal' ? "$categorySelect $sqlDir, d.id $sqlDir" : "d.recipient $sqlDir, d.id $sqlDir"),
    'subject' => "d.subject $sqlDir, d.id $sqlDir",
][$sort];

$documentsStatement = $pdo->prepare("SELECT d.*, u.username, o.name AS organization_name, $categorySelect AS category_names, (SELECT original_name FROM document_files WHERE document_id = d.id ORDER BY id LIMIT 1) AS original_name, (SELECT stored_name FROM document_files WHERE document_id = d.id ORDER BY id LIMIT 1) AS stored_name, (SELECT mime_type FROM document_files WHERE document_id = d.id ORDER BY id LIMIT 1) AS mime_type, (SELECT pdf_stored_name FROM document_files WHERE document_id = d.id ORDER BY id LIMIT 1) AS pdf_stored_name FROM documents d JOIN users u ON u.id = d.uploaded_by JOIN organizations o ON o.id = d.organization_id $whereSql ORDER BY $orderSql $limitSql");
$documentsStatement->execute($queryParams);
$documents = $documentsStatement->fetchAll();

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function iconWordSvg(): string {
    return '<svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true"><rect width="32" height="32" rx="6" fill="#2B579A"/><text x="16" y="22.5" text-anchor="middle" font-family="Georgia,Times New Roman,serif" font-size="17" font-weight="700" fill="#fff">W</text></svg>';
}
function iconPdfSvg(bool $ready): string {
    if ($ready) {
        return '<svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true"><path d="M8 2.5h11.2L26 9.5V28a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4.5a2 2 0 0 1 2-2z" fill="#E5252A"/><path d="M19.2 2.5V9.5H26" fill="#B91C1C"/><text x="16" y="22" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="8" font-weight="700" fill="#fff">PDF</text></svg>';
    }
    return '<svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true"><path d="M8 2.5h11.2L26 9.5V28a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4.5a2 2 0 0 1 2-2z" fill="#fff" fill-opacity="0" stroke="#E5252A" stroke-width="1.6"/><path d="M19.2 2.5V9.5H26" fill="none" stroke="#E5252A" stroke-width="1.6"/><text x="16" y="22" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="8" font-weight="700" fill="#E5252A">PDF</text></svg>';
}
function iconImageSvg(string $ext): string {
    $label = $ext === 'png' ? 'PNG' : 'JPG';
    return '<svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true"><rect width="32" height="32" rx="6" fill="#C2410C"/><text x="16" y="21.5" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="8.5" font-weight="700" fill="#fff">' . $label . '</text></svg>';
}
function iconFileSvg(): string {
    return '<svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true"><path d="M8 2.5h11.2L26 9.5V28a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4.5a2 2 0 0 1 2-2z" fill="#64748B"/><path d="M19.2 2.5V9.5H26" fill="#475569"/></svg>';
}
function fileCell(array $document): string {
    $id = (int)$document['id'];
    $name = (string)($document['original_name'] ?? '');
    if ($name === '') {
        return '<span class="muted">—</span>';
    }
    $ext = fileExtOf($document);
    $html = '<div class="file-pair">';
    if ($ext === 'pdf') {
        $html .= '<div class="file-item"><a class="file-ico pdf" href="?download=' . $id . '" download title="Скачать: ' . h($name) . '">' . iconPdfSvg(true) . '</a><input type="checkbox" class="file-select" data-kind="pdf" value="' . $id . '" title="Выгрузить PDF"></div>';
    } elseif (isWordExt($ext)) {
        $pdfReady = !empty($document['pdf_stored_name']);
        $pdfTitle = $pdfReady ? 'Скачать PDF' : 'Сформировать PDF из Word и скачать';
        $html .= '<div class="file-item"><a class="file-ico word" href="?download=' . $id . '" download title="Скачать: ' . h($name) . '">' . iconWordSvg() . '</a><input type="checkbox" class="file-select" data-kind="word" value="' . $id . '" title="Выгрузить Word"></div>';
        $html .= '<div class="file-item"><a class="file-ico pdf' . ($pdfReady ? '' : ' ghost') . '" href="?pdf=' . $id . '" download title="' . h($pdfTitle) . '">' . iconPdfSvg($pdfReady) . '</a><input type="checkbox" class="file-select" data-kind="pdf" value="' . $id . '" title="Выгрузить PDF"></div>';
    } else {
        $icon = isImageExt($ext) ? iconImageSvg($ext) : iconFileSvg();
        $kindTitle = isImageExt($ext) ? 'Выгрузить изображение' : 'Выгрузить файл';
        $html .= '<div class="file-item"><a class="file-ico image" href="?download=' . $id . '" download title="Скачать: ' . h($name) . '">' . $icon . '</a><input type="checkbox" class="file-select" data-kind="file" value="' . $id . '" title="' . h($kindTitle) . '"></div>';
    }
    $html .= '</div>';
    return $html;
}
function sortHeader(string $key, string $label, string $sort, string $dir): string
{
    $defaults = ['number' => 'desc', 'date' => 'desc', 'recipient' => 'asc', 'subject' => 'asc'];
    $nextDir = $sort === $key ? ($dir === 'asc' ? 'desc' : 'asc') : ($defaults[$key] ?? 'asc');
    $active = $sort === $key;
    $arrow = $active ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $class = 'sort-link' . ($active ? ' active' : '');
    return '<a class="' . $class . '" href="?' . h(currentQs(['sort' => $key, 'dir' => $nextDir, 'page' => ''])) . '">' . h($label) . $arrow . '</a>';
}
function categoryChips(?string $names): string
{
    if ($names === null || trim($names) === '') {
        return '<span class="muted">—</span>';
    }
    $html = '<div class="cat-chips">';
    foreach (explode(', ', $names) as $name) {
        $html .= '<span class="cat-chip">' . h($name) . '</span>';
    }
    return $html . '</div>';
}

$openDocModal = (bool)$editDocument || isset($_GET['reopen']) || !empty($uploadError);
$openRecipientsModal = !empty($recipientError) || ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_recipient']) || isset($_POST['edit_recipient'])));
$openCategoriesModal = !empty($categoryError) || ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_internal_category']) || isset($_POST['add_internal_category'])));
if ($openRecipientsModal || $openCategoriesModal) {
    $openDocModal = true;
}
$internalForm = ((is_array($editDocument) ? (string)$editDocument['type'] : $journal) === 'internal');
$displayName = trim((string)($_SESSION['full_name'] ?? '')) !== '' ? $_SESSION['full_name'] : $_SESSION['username'];
$pageTitle = $view === 'users' ? 'Пользователи' : $journalTitles[$journal];
$managedUsers = [];
if ($view === 'users' && isAdmin()) {
    $managedUsers = $pdo->query('SELECT id, username, email, full_name, role FROM users ORDER BY id')->fetchAll();
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($pageTitle) ?></title><style>
:root{--bg:#f3f1ea;--paper:#fffcf7;--ink:#1a1f2b;--ink-soft:#667085;--accent:#1f3fb8;--accent-hover:#17318f;--accent-soft:#eef2ff;--rule:#e4dfd2;--danger:#b3261e;--danger-soft:#fbeae9;--mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(1200px 500px at 10% -10%,#e7eefc 0%,transparent 55%),var(--bg);color:var(--ink);font:14.5px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:0 20px}
header{padding:22px 0 16px}
header .top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
header .title{display:flex;flex-direction:column;gap:8px;min-width:0;flex:1}
.brand{display:flex;align-items:center;flex:0 0 auto}
.brand img{height:40px;width:auto;display:block}
header h1{font-family:Georgia,"Iowan Old Style","Palatino Linotype",serif;font-size:28px;font-weight:600;margin:0;letter-spacing:-.02em}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
main{padding:8px 0 56px}
h2{font-family:Georgia,"Iowan Old Style","Palatino Linotype",serif;font-size:19px;font-weight:600;margin:0 0 16px;letter-spacing:-.01em}
.panel{border:1px solid var(--rule);border-radius:12px;padding:20px;background:var(--paper);box-shadow:0 1px 0 rgba(27,33,48,.04)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px 14px}
label{display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--ink-soft);margin-bottom:2px}
input,select,textarea{font:14.5px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:9px 11px;border:1px solid var(--rule);border-radius:8px;background:#fff;color:var(--ink)}
input:disabled{color:var(--ink-soft);background:var(--accent-soft)}
textarea{min-height:42px;resize:vertical}
input:focus,select:focus,textarea:focus{outline:2px solid var(--accent);outline-offset:1px;border-color:var(--accent)}
button{background:var(--accent);color:#fff;border:0;border-radius:8px;padding:9px 16px;font-size:14px;font-weight:600;cursor:pointer}
button:hover{background:var(--accent-hover)}
.hint-org{font-size:12.5px;color:var(--ink-soft);margin:-8px 0 14px}
.hint-org strong{color:var(--ink);font-weight:600}
.table-wrap{overflow:auto;border:1px solid var(--rule);border-radius:12px;background:var(--paper);box-shadow:0 8px 24px rgba(27,33,48,.05)}
table.docs{width:100%;border-collapse:collapse;table-layout:fixed;font-size:13px}
table.docs col.col-check{width:40px}
table.docs col.col-num{width:118px}
.doc-code{display:block;margin-top:3px;font-family:var(--mono);font-size:10.5px;color:var(--ink-soft);letter-spacing:.02em}
table.docs col.col-date{width:96px}
table.docs col.col-rec{width:22%}
table.docs col.col-file{width:118px}
table.docs col.col-user{width:88px}
table.docs col.col-act{width:78px}
th{text-align:left;font-weight:600;font-size:11.5px;color:var(--ink-soft);padding:9px 10px;border-bottom:1px solid var(--rule);white-space:nowrap;background:#faf7f0;position:sticky;top:0}
a.sort-link{color:var(--ink-soft);text-decoration:none}
a.sort-link:hover{color:var(--accent);text-decoration:none}
a.sort-link.active{color:var(--ink)}
td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--rule);vertical-align:middle}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover{background:#f7f5ff}
.doc-number{display:inline-block;font-family:var(--mono);font-size:12px;border:1px solid #cfd3de;border-radius:6px;padding:2px 7px;letter-spacing:.02em;white-space:nowrap;background:#fff}
.error{color:var(--danger);background:var(--danger-soft);padding:10px 12px;border-radius:8px;font-size:13.5px;margin:0 0 16px}
.muted{color:var(--ink-soft)}
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:14px 0 12px}
.add-block{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.next-hint{font-size:12.5px;line-height:1.35;color:var(--ink-soft)}
.next-hint strong{color:var(--ink);font-family:var(--mono);font-size:13.5px;font-weight:700}
.filters{display:flex;gap:8px;flex-wrap:wrap;margin:0;align-items:center}
.filters select{width:220px;max-width:100%;padding:8px 10px}
.filters select[name="per_page"]{width:88px}
.select-row{display:flex;gap:8px;align-items:stretch}
.select-row select{flex:1}
.btn-quiet{background:#fff;color:var(--accent);border:1px solid var(--rule);padding:0 12px;font-size:12.5px;white-space:nowrap}
.btn-quiet:hover{background:var(--accent-soft)}
.pagination{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;padding:12px 2px;font-size:13px}
.pagination .pages{display:flex;gap:4px}
.pagination .pages a,.pagination .pages span.current{padding:4px 10px;border-radius:6px;font-size:13px}
.pagination .pages span.current{background:var(--accent);color:#fff}
dialog.modal{border:0;border-radius:12px;padding:0;max-width:640px;width:92vw;box-shadow:0 16px 48px rgba(20,24,38,.22);resize:both;overflow:auto;min-width:380px;min-height:220px;max-height:92vh}
dialog.modal::backdrop{background:rgba(27,33,48,.45)}
dialog.modal.nested{max-width:520px}
.modal-inner{padding:18px 20px}
.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:12px}
.modal-head h2{margin:0}
.modal-close{background:none;border:0;color:var(--ink-soft);font-size:20px;line-height:1;padding:4px 8px;border-radius:6px;cursor:pointer}
.modal-close:hover{background:var(--rule);color:var(--ink)}
.modal-foot{display:flex;align-items:center;gap:16px;margin-top:10px}
.link-plain{background:none;border:0;padding:0;font:inherit;color:var(--ink-soft);cursor:pointer;text-decoration:none}
.link-plain:hover{color:var(--accent);text-decoration:underline}
.cell-check,.cell-num,.cell-date,.cell-file,.cell-user,.actions-cell{white-space:nowrap}
.cell-rec,.cell-subj{overflow:hidden}
.clamp{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;line-height:1.35;max-height:2.7em}
.file-pair{display:flex;align-items:flex-end;gap:8px}
.file-item{display:flex;flex-direction:column;align-items:center;gap:3px}
.file-item input{width:13px;height:13px;margin:0;accent-color:var(--accent);cursor:pointer}
.file-ico{display:inline-flex;width:28px;height:28px;text-decoration:none;line-height:0}
.file-ico:hover{text-decoration:none;transform:translateY(-1px)}
.file-ico.ghost{opacity:.38}
.file-ico.ghost:hover{opacity:.8}
.file-ico svg{display:block}
.icon-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:7px;border:0;background:none;cursor:pointer;color:var(--ink-soft);text-decoration:none;vertical-align:middle}
.icon-btn:hover{background:#eceaf3;color:var(--ink)}
.icon-edit:hover{color:var(--accent)}
.icon-delete:hover{color:var(--danger);background:var(--danger-soft)}
.actions-cell{white-space:nowrap}
.header-links{display:flex;gap:14px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.app-version{display:inline-flex;flex-direction:column;align-items:flex-end;gap:1px;padding:5px 10px;border:1px solid var(--rule);border-radius:10px;background:#fff;color:var(--ink-soft);cursor:pointer;font:inherit;line-height:1.2}
.app-version:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-soft)}
.app-version-num{font:600 12.5px/1.2 var(--mono);letter-spacing:.02em}
.app-version-date{font-size:11px;opacity:.78}
.changelog-lead{margin:0 0 16px;font-size:13.5px;color:var(--ink-soft)}
.changelog{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:16px}
.changelog-entry{margin:0;padding:0 0 16px;border-bottom:1px solid var(--rule)}
.changelog-entry:last-child{padding-bottom:0;border-bottom:0}
.changelog-meta{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin:0 0 8px}
.changelog-meta strong{font-family:var(--mono);font-size:14px}
.changelog-meta span{font-size:12.5px;color:var(--ink-soft)}
.changelog ul{margin:0;padding:0 0 0 18px}
.changelog ul li{margin:0 0 6px}
.org-switch,.journal-switch{display:flex;gap:6px;flex-wrap:wrap}
.org-pill{font-size:12.5px;padding:4px 11px;border-radius:999px;border:1px solid var(--rule);color:var(--ink-soft);text-decoration:none;background:#fff}
.org-switch .org-pill{display:inline-flex;flex-direction:column;align-items:flex-start;gap:1px;line-height:1.2;border-radius:10px;padding:5px 12px}
.org-inn{font-family:var(--mono);font-size:10.5px;letter-spacing:.02em;opacity:.72}
.org-pill.active .org-inn{opacity:.92}
.org-pill:hover{background:var(--accent-soft);text-decoration:none}
.org-pill.active{background:var(--accent);border-color:var(--accent);color:#fff}
.cat-chips{display:flex;flex-wrap:wrap;gap:4px}
.cat-chip{font-size:11px;padding:2px 8px;border-radius:999px;background:var(--accent-soft);color:var(--accent);border:1px solid #d9e0f7;white-space:nowrap}
.cat-checks{display:flex;flex-wrap:wrap;gap:8px 16px;margin:4px 0 8px}
.cat-checks label{flex-direction:row;align-items:center;gap:6px;margin:0;color:var(--ink);font-size:13.5px;cursor:pointer}
.cat-checks input{width:auto;margin:0}
.cell-input{width:100%;padding:6px 8px;font-size:13px}
@media(max-width:760px){.grid{grid-template-columns:1fr 1fr}table.docs{table-layout:auto}}
@media(max-width:520px){.grid{grid-template-columns:1fr}.grid2{grid-template-columns:1fr}.grid3{grid-template-columns:1fr}header .top{flex-direction:column;align-items:flex-start}.toolbar{flex-direction:column;align-items:stretch}}
</style></head><body><div class="wrap"><header><div class="top"><a class="brand" href="/gost-documents/"><img src="logo-normasoft.png" alt="Норма Софт"></a><div class="title"><h1><?= h($pageTitle) ?></h1><button type="button" class="link-plain" onclick="window.document.getElementById('profile-modal').showModal()">Пользователь: <?= h($displayName) ?><?= isAdmin() ? ' · администратор' : '' ?></button><nav class="org-switch"><?php foreach ($organizations as $organization): ?><a href="?<?= h(currentQs(['switch_org' => (int)$organization['id'], 'view' => '', 'page' => ''])) ?>" class="org-pill<?= $activeOrgId === (int)$organization['id'] ? ' active' : '' ?>"><?= h($organization['name']) ?><?php if (!empty($organization['inn'])): ?><span class="org-inn">ИНН <?= h($organization['inn']) ?></span><?php endif; ?></a><?php endforeach; ?></nav><?php if ($view !== 'users'): ?><nav class="journal-switch"><?php foreach ($journalTitles as $journalValue => $journalLabel): ?><a href="?<?= h(currentQs(['journal' => $journalValue, 'page' => '', 'view' => ''])) ?>" class="org-pill<?= $journal === $journalValue ? ' active' : '' ?>"><?= h($journalLabel) ?></a><?php endforeach; ?></nav><?php endif; ?></div><div class="header-links"><?= appVersionBadge() ?><?php if (isAdmin()): ?><a href="?<?= h(currentQs(['view' => $view === 'users' ? '' : 'users', 'page' => ''])) ?>"><?= $view === 'users' ? 'К журналу' : 'Пользователи' ?></a><?php endif; ?><a href="?logout=1">Выйти</a></div></div></header><main>
<?php if ($view === 'users' && isAdmin()): ?>
<section>
<?php if (!empty($usersError)): ?><p class="error"><?= h($usersError) ?></p><?php endif; ?>
<?php if (!empty($usersInfo)): ?><p class="ok" style="color:#1e7a4c;background:#eaf6ee;padding:10px 12px;border-radius:8px;margin:0 0 16px"><?= h($usersInfo) ?></p><?php endif; ?>
<div class="panel" style="margin-bottom:18px">
<h2>Новый пользователь</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
<div class="grid3">
<label>Логин<input name="username" required pattern="[a-zA-Z0-9._-]{3,64}"></label>
<label>Email<input type="email" name="email" required></label>
<label>Роль<select name="role"><option value="user">Пользователь</option><option value="admin">Администратор</option></select></label>
</div>
<div class="grid2">
<label>ФИО<input name="full_name"></label>
<label>Пароль<input type="password" name="password" required minlength="8"></label>
</div>
<div class="modal-foot"><button name="add_user" value="1">Создать</button></div>
</form>
</div>
<div class="table-wrap">
<table>
<thead><tr><th>Логин</th><th>Email</th><th>ФИО</th><th>Роль</th><th>Новый пароль</th><th></th></tr></thead>
<tbody>
<?php foreach ($managedUsers as $managedUser): ?>
<tr>
<td><?= h($managedUser['username']) ?></td>
<td><?= h($managedUser['email']) ?></td>
<td colspan="4">
<form method="post" class="grid" style="grid-template-columns:1.2fr .9fr 1fr auto;align-items:end;margin:0">
<input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
<input type="hidden" name="user_id" value="<?= (int)$managedUser['id'] ?>">
<label>ФИО<input class="cell-input" name="full_name" value="<?= h((string)$managedUser['full_name']) ?>"></label>
<label>Роль<select name="role"><option value="user" <?= $managedUser['role'] === 'user' ? 'selected' : '' ?>>Пользователь</option><option value="admin" <?= $managedUser['role'] === 'admin' ? 'selected' : '' ?>>Администратор</option></select></label>
<label>Новый пароль<input class="cell-input" type="password" name="password" placeholder="не менять"></label>
<button name="update_user" value="1">Сохранить</button>
</form>
<form method="post" style="margin-top:8px">
<input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
<input type="hidden" name="user_id" value="<?= (int)$managedUser['id'] ?>">
<button class="btn-quiet" name="send_reset" value="1">Отправить ссылку сброса пароля</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>
<?php else: ?>
<section>
<div class="toolbar">
<div class="add-block">
<button type="button" onclick="document.getElementById('doc-modal').showModal()">+ Добавить документ</button>
<div class="next-hint">Рекомендуемый номер: <strong><?= h($recommendedNumber) ?></strong></div>
<button type="button" id="bulk-download-btn" class="btn-quiet" style="display:none" onclick="submitBulkDownload()">Скачать выбранное</button>
</div>
<form method="get" class="filters">
<?php if ($journal !== 'outgoing'): ?><input type="hidden" name="journal" value="<?= h($journal) ?>"><?php endif; ?>
<?php if ($journal === 'internal' && $filterCategory): ?><input type="hidden" name="filter_category" value="<?= (int)$filterCategory ?>"><?php endif; ?>
<input type="search" name="q" value="<?= h($searchQuery) ?>" placeholder="Код, номер, тема" style="width:180px;padding:8px 10px">
<?php if ($sort !== 'date'): ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><?php endif; ?>
<?php if (!($sort === 'date' && $dir === 'desc')): ?><input type="hidden" name="dir" value="<?= h($dir) ?>"><?php endif; ?>
<?php if ($journal !== 'internal'): ?>
<select name="filter_recipient" onchange="this.form.submit()">
<option value="0">Все получатели</option>
<?php foreach ($recipients as $recipientFilterOption): ?><option value="<?= (int)$recipientFilterOption['id'] ?>" <?= $filterRecipient === (int)$recipientFilterOption['id'] ? 'selected' : '' ?>><?= h($recipientFilterOption['name']) ?></option><?php endforeach; ?>
</select>
<?php endif; ?>
<select name="per_page" onchange="this.form.submit()">
<?php foreach (['10' => '10', '20' => '20', '30' => '30', '50' => '50', 'all' => 'Все'] as $ppVal => $ppLabel): ?><option value="<?= $ppVal ?>" <?= $perPageRaw === $ppVal ? 'selected' : '' ?>><?= $ppLabel ?></option><?php endforeach; ?>
</select>
</form>
</div>
<?php if ($journal === 'internal'): ?>
<nav class="org-switch" style="margin:0 0 12px">
<a href="?<?= h(currentQs(['filter_category' => '', 'page' => ''])) ?>" class="org-pill<?= $filterCategory === 0 ? ' active' : '' ?>">Все</a>
<?php foreach ($internalCategories as $categoryFilter): ?><a href="?<?= h(currentQs(['filter_category' => (int)$categoryFilter['id'], 'page' => ''])) ?>" class="org-pill<?= $filterCategory === (int)$categoryFilter['id'] ? ' active' : '' ?>"><?= h($categoryFilter['name']) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<div class="table-wrap">
<table class="docs">
<colgroup><col class="col-check"><col class="col-num"><col class="col-date"><col class="col-rec"><col class="col-subj"><col class="col-file"><col class="col-user"><col class="col-act"></colgroup>
<thead><tr><th class="cell-check"><input type="checkbox" id="select-all" title="Выбрать все файлы"></th><th><?= sortHeader('number', 'Номер', $sort, $dir) ?></th><th><?= sortHeader('date', 'Дата', $sort, $dir) ?></th><th><?= sortHeader('recipient', $journal === 'internal' ? 'Категории' : 'Получатель', $sort, $dir) ?></th><th><?= sortHeader('subject', 'Тема', $sort, $dir) ?></th><th>Файл</th><th>Добавил</th><th>Действия</th></tr></thead><tbody><?php if (!$documents): ?>
<tr><td colspan="8" class="muted" style="text-align:center;padding:28px">Документов пока нет</td></tr>
<?php else: foreach ($documents as $document): ?>
<tr>
<td class="cell-check"><input type="checkbox" class="row-select" value="<?= (int)$document['id'] ?>"></td>
<td class="cell-num"><span class="doc-number"><?= h((string)$document['document_number']) ?></span><?php if (!empty($document['search_code'])): ?><span class="doc-code"><?= h((string)$document['search_code']) ?></span><?php endif; ?></td>
<td class="cell-date"><?= h(date('d.m.Y', strtotime((string)$document['document_date']))) ?></td>
<td class="cell-rec"><?php if ($journal === 'internal'): ?><?= categoryChips($document['category_names'] ?? null) ?><?php else: ?><div class="clamp" title="<?= h((string)$document['recipient']) ?>"><?= h((string)$document['recipient']) ?></div><?php endif; ?></td>
<td class="cell-subj"><div class="clamp" title="<?= h((string)$document['subject']) ?>"><?= h((string)$document['subject']) ?></div></td>
<td class="cell-file"><?= fileCell($document) ?></td>
<td><?= h((string)$document['username']) ?></td>
<td class="cell-act">
<a class="icon-btn" href="?<?= h(currentQs(['edit' => (int)$document['id']])) ?>" title="Изменить" aria-label="Изменить">✎</a>
<form method="post" style="display:inline" onsubmit="return confirm('Удалить документ №' + <?= json_encode((string)$document['document_number'], JSON_UNESCAPED_UNICODE) ?> + '?');">
<input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
<input type="hidden" name="return_qs" value="<?= h(currentQs()) ?>">
<input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
<button name="delete" value="1" class="icon-btn icon-delete" title="Удалить" aria-label="Удалить">🗑</button>
</form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>

<div class="pager" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 0 8px">
<div class="muted"><?php if ($totalDocuments): ?>Показаны <?= (int)$rangeFrom ?>–<?= (int)$rangeTo ?> из <?= (int)$totalDocuments ?><?php else: ?>Нет записей<?php endif; ?></div>
<div style="display:flex;gap:8px;align-items:center">
<?php if ($page > 1): ?><a class="btn-quiet" href="?<?= h(currentQs(['page' => $page - 1])) ?>">← Назад</a><?php endif; ?>
<?php if ($totalPages > 1): ?><span class="muted">Стр. <?= (int)$page ?> / <?= (int)$totalPages ?></span><?php endif; ?>
<?php if ($page < $totalPages): ?><a class="btn-quiet" href="?<?= h(currentQs(['page' => $page + 1])) ?>">Вперёд →</a><?php endif; ?>
</div>
</div>
</section>
<?php endif; ?>
<dialog id="doc-modal" class="modal">
<div class="modal-inner">
<div class="modal-head"><h2>Добавить документ</h2><button type="button" class="modal-close" onclick="document.getElementById('doc-modal').close()">×</button></div>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
<input type="hidden" name="return_qs" value="">
<p class="hint-org">Организация: <strong>ООО «Норма софт»</strong> · Исходящий</p>
<input type="hidden" name="type" value="outgoing">
<div class="grid2">
<label>Дата документа<input type="date" name="document_date" required></label>
<label>Номер<input type="text" name="document_number" value="" placeholder="из документа" required></label>
</div>
<label>Получатель<div class="select-row"><select name="recipient_id"><option value="">— выберите —</option><option value="46">Администрация Балаковского муниципального района Саратовской области — ИНН 6439034991</option><option value="47">Администрация МО «Город Саратов» — ИНН 6450011003</option><option value="48">АНО &quot;ОЭК СТРОЙТРЕСТ&quot; — ИНН 7708442087</option><option value="20">АО «АПЗ» — ИНН 9000000020</option><option value="15">АО «Атомэнерго» — ИНН 7801031451</option><option value="7">АО «ВМЗ» — ИНН 9000000007</option><option value="21">АО «ВНИИГ ИМ.Б.Е.ВЕДЕНЕЕВА» — ИНН 7804004400</option><option value="22">АО «ВПО «Точмаш» — ИНН 9000000022</option><option value="3">АО «Гидроагрегат» — ИНН 9000000003</option><option value="8">АО «Инновации» — ИНН 9000000008</option><option value="11">АО «Иргиредмет» — ИНН 9000000011</option><option value="26">АО «Корпорация Развития Нижегородской области» — ИНН 9000000026</option><option value="2">АО «Мослифт» — ИНН 9000000002</option><option value="23">АО «МСЗ» — ИНН 9000000023</option><option value="5">АО «НЗ 70-ЛЕТИЯ ПОБЕДЫ» — ИНН 5259113339</option><option value="1">АО «НИИ «Экран» — ИНН 9000000001</option><option value="27">АО «Петербургская Сбытовая Компания» — ИНН 9000000027</option><option value="51">АО «Сарапульский электрогенераторный завод» — ИНН 1827001683</option><option value="6">АО «Сахалинское ипотечное агентство» — ИНН 9000000006</option><option value="24">АО «СмАЗ» — ИНН 9000000024</option><option value="25">АО «ТРАНСНЕФТЬ-ПОДВОДСЕРВИС» — ИНН 9000000025</option><option value="28">АО «Фармасинтез-Норд» — ИНН 9000000028</option><option value="43">АО «Фармасинтез» — ИНН 9000000029</option><option value="29">АО «ЦЕМРОС» — ИНН 9000000030</option><option value="45">ГБУК &quot;Самарская областная универсальная научная библиотека&quot; — ИНН 6316008173</option><option value="13">МУ «Дирекция по обеспечению деятельности в сфере ЖКХ» — ИНН 9000000013</option><option value="12">ООО «Агентство по развитию города Рязани» — ИНН 9000000012</option><option value="30">ООО «ВолгаСтальПроект» — ИНН 9000000031</option><option value="9">ООО «Емкемикалс» — ИНН 9000000009</option><option value="19">ООО «КубаньСпецПроект» — ИНН 9000000019</option><option value="31">ООО «Лайнер» — ИНН 9000000032</option><option value="33">ООО «ЛРК «МедиМакс» — ИНН 9000000034</option><option value="34">ООО «ЛУКОЙЛ-Нижегороднефтеоргсинтез» — ИНН 9000000035</option><option value="35">ООО «НПО «Центротех» — ИНН 9000000036</option><option value="36">ООО «Портнер» — ИНН 9000000037</option><option value="16">ООО «ПрофИнженерПроект» — ИНН 9000000016</option><option value="32">ООО «РИАРДЕН ГРУПП» — ИНН 9000000033</option><option value="37">ООО «РосАТ» — ИНН 9000000038</option><option value="38">ООО «Росатом Машиностроение» — ИНН 9000000039</option><option value="39">ООО «РЭНЕРА» — ИНН 9000000040</option><option value="18">ООО «Холдинговая компания Евроангар» — ИНН 9000000018</option><option value="40">ООО «Центротех-Инжиниринг» — ИНН 9000000041</option><option value="17">ООО «ЦСР БМС» — ИНН 9000000017</option><option value="10">ООО «Эколант» — ИНН 9000000010</option><option value="49">ООО НПП «ПРИМА» — ИНН 5257013402</option><option value="50">ООО ПХТИ «Полихимсервис» — ИНН 5260406643</option><option value="41">ПАО «КМЗ» — ИНН 9000000042</option><option value="14">ФГАОУ ВО «ННГУ им. Н.И. Лобачевского» — ИНН 9000000014</option><option value="44">ФГКУ &quot;В/Ч 45187&quot; — ИНН 7805039765</option><option value="42">ФГУП «ГУО МИД РОССИИ» — ИНН 9000000043</option><option value="4">ФГУП «УВО Минтранса России» — ИНН 9000000004</option></select><button type="button" class="btn-quiet" onclick="window.document.getElementById('recipients-modal').showModal()">Получатели</button></div></label>
<label>Файл<input type="file" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" required></label>
<label>Тема<textarea name="subject" required></textarea></label>
<div class="modal-foot"><button name="upload" value="1">Сохранить документ</button><a href="/gost-documents/?">Отмена</a></div>
</form>

<dialog id="recipients-modal" class="modal nested">
<div class="modal-inner">
<div class="modal-head"><h2>Получатели</h2><button type="button" class="modal-close" onclick="window.document.getElementById('recipients-modal').close()">×</button></div>
<form method="post">
<input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
<input type="hidden" name="return_qs" value="">
<div class="grid2">
<label>ИНН<input name="inn" required pattern="\d{10}|\d{12}" maxlength="12" placeholder="10 или 12 цифр"></label>
<label>Наименование<input name="name" required></label>
</div>
<button name="add_recipient" value="1">+ Добавить получателя</button>
</form>
<div class="table-wrap" style="margin-top:16px">
<table>
<thead><tr><th>Наименование</th><th>ИНН</th><th>Действия</th></tr></thead>
<tbody>
<tr><td><input type="text" class="cell-input" name="name" value="Администрация Балаковского муниципального района Саратовской области" form="edit-recipient-46"></td><td><input type="text" class="cell-input" name="inn" value="6439034991" maxlength="12" form="edit-recipient-46"></td><td class="actions-cell"><form id="edit-recipient-46" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="46"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="Администрация МО «Город Саратов»" form="edit-recipient-47"></td><td><input type="text" class="cell-input" name="inn" value="6450011003" maxlength="12" form="edit-recipient-47"></td><td class="actions-cell"><form id="edit-recipient-47" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="47"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><form method="post" style="display:inline" onsubmit="return confirm('Удалить этого получателя?');"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="47"><button name="delete_recipient" value="1" class="icon-btn icon-delete" title="Удалить" aria-label="Удалить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button></form></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АНО &quot;ОЭК СТРОЙТРЕСТ&quot;" form="edit-recipient-48"></td><td><input type="text" class="cell-input" name="inn" value="7708442087" maxlength="12" form="edit-recipient-48"></td><td class="actions-cell"><form id="edit-recipient-48" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="48"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (2)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «АПЗ»" form="edit-recipient-20"></td><td><input type="text" class="cell-input" name="inn" value="9000000020" maxlength="12" form="edit-recipient-20"></td><td class="actions-cell"><form id="edit-recipient-20" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="20"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (11)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «Атомэнерго»" form="edit-recipient-15"></td><td><input type="text" class="cell-input" name="inn" value="7801031451" maxlength="12" form="edit-recipient-15"></td><td class="actions-cell"><form id="edit-recipient-15" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="15"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «ВМЗ»" form="edit-recipient-7"></td><td><input type="text" class="cell-input" name="inn" value="9000000007" maxlength="12" form="edit-recipient-7"></td><td class="actions-cell"><form id="edit-recipient-7" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="7"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «ВНИИГ ИМ.Б.Е.ВЕДЕНЕЕВА»" form="edit-recipient-21"></td><td><input type="text" class="cell-input" name="inn" value="7804004400" maxlength="12" form="edit-recipient-21"></td><td class="actions-cell"><form id="edit-recipient-21" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="21"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (3)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «ВПО «Точмаш»" form="edit-recipient-22"></td><td><input type="text" class="cell-input" name="inn" value="9000000022" maxlength="12" form="edit-recipient-22"></td><td class="actions-cell"><form id="edit-recipient-22" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="22"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «Гидроагрегат»" form="edit-recipient-3"></td><td><input type="text" class="cell-input" name="inn" value="9000000003" maxlength="12" form="edit-recipient-3"></td><td class="actions-cell"><form id="edit-recipient-3" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="3"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «Инновации»" form="edit-recipient-8"></td><td><input type="text" class="cell-input" name="inn" value="9000000008" maxlength="12" form="edit-recipient-8"></td><td class="actions-cell"><form id="edit-recipient-8" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="8"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «Иргиредмет»" form="edit-recipient-11"></td><td><input type="text" class="cell-input" name="inn" value="9000000011" maxlength="12" form="edit-recipient-11"></td><td class="actions-cell"><form id="edit-recipient-11" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="11"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «Корпорация Развития Нижегородской области»" form="edit-recipient-26"></td><td><input type="text" class="cell-input" name="inn" value="9000000026" maxlength="12" form="edit-recipient-26"></td><td class="actions-cell"><form id="edit-recipient-26" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="26"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «Мослифт»" form="edit-recipient-2"></td><td><input type="text" class="cell-input" name="inn" value="9000000002" maxlength="12" form="edit-recipient-2"></td><td class="actions-cell"><form id="edit-recipient-2" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="2"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «МСЗ»" form="edit-recipient-23"></td><td><input type="text" class="cell-input" name="inn" value="9000000023" maxlength="12" form="edit-recipient-23"></td><td class="actions-cell"><form id="edit-recipient-23" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="23"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (2)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «НЗ 70-ЛЕТИЯ ПОБЕДЫ»" form="edit-recipient-5"></td><td><input type="text" class="cell-input" name="inn" value="5259113339" maxlength="12" form="edit-recipient-5"></td><td class="actions-cell"><form id="edit-recipient-5" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="5"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (3)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «НИИ «Экран»" form="edit-recipient-1"></td><td><input type="text" class="cell-input" name="inn" value="9000000001" maxlength="12" form="edit-recipient-1"></td><td class="actions-cell"><form id="edit-recipient-1" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="1"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «Петербургская Сбытовая Компания»" form="edit-recipient-27"></td><td><input type="text" class="cell-input" name="inn" value="9000000027" maxlength="12" form="edit-recipient-27"></td><td class="actions-cell"><form id="edit-recipient-27" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="27"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (4)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «Сарапульский электрогенераторный завод»" form="edit-recipient-51"></td><td><input type="text" class="cell-input" name="inn" value="1827001683" maxlength="12" form="edit-recipient-51"></td><td class="actions-cell"><form id="edit-recipient-51" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="51"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «Сахалинское ипотечное агентство»" form="edit-recipient-6"></td><td><input type="text" class="cell-input" name="inn" value="9000000006" maxlength="12" form="edit-recipient-6"></td><td class="actions-cell"><form id="edit-recipient-6" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="6"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «СмАЗ»" form="edit-recipient-24"></td><td><input type="text" class="cell-input" name="inn" value="9000000024" maxlength="12" form="edit-recipient-24"></td><td class="actions-cell"><form id="edit-recipient-24" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="24"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «ТРАНСНЕФТЬ-ПОДВОДСЕРВИС»" form="edit-recipient-25"></td><td><input type="text" class="cell-input" name="inn" value="9000000025" maxlength="12" form="edit-recipient-25"></td><td class="actions-cell"><form id="edit-recipient-25" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="25"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «Фармасинтез-Норд»" form="edit-recipient-28"></td><td><input type="text" class="cell-input" name="inn" value="9000000028" maxlength="12" form="edit-recipient-28"></td><td class="actions-cell"><form id="edit-recipient-28" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="28"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «Фармасинтез»" form="edit-recipient-43"></td><td><input type="text" class="cell-input" name="inn" value="9000000029" maxlength="12" form="edit-recipient-43"></td><td class="actions-cell"><form id="edit-recipient-43" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="43"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="АО «ЦЕМРОС»" form="edit-recipient-29"></td><td><input type="text" class="cell-input" name="inn" value="9000000030" maxlength="12" form="edit-recipient-29"></td><td class="actions-cell"><form id="edit-recipient-29" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="29"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ГБУК &quot;Самарская областная универсальная научная библиотека&quot;" form="edit-recipient-45"></td><td><input type="text" class="cell-input" name="inn" value="6316008173" maxlength="12" form="edit-recipient-45"></td><td class="actions-cell"><form id="edit-recipient-45" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="45"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="МУ «Дирекция по обеспечению деятельности в сфере ЖКХ»" form="edit-recipient-13"></td><td><input type="text" class="cell-input" name="inn" value="9000000013" maxlength="12" form="edit-recipient-13"></td><td class="actions-cell"><form id="edit-recipient-13" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="13"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «Агентство по развитию города Рязани»" form="edit-recipient-12"></td><td><input type="text" class="cell-input" name="inn" value="9000000012" maxlength="12" form="edit-recipient-12"></td><td class="actions-cell"><form id="edit-recipient-12" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="12"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «ВолгаСтальПроект»" form="edit-recipient-30"></td><td><input type="text" class="cell-input" name="inn" value="9000000031" maxlength="12" form="edit-recipient-30"></td><td class="actions-cell"><form id="edit-recipient-30" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="30"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (3)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «Емкемикалс»" form="edit-recipient-9"></td><td><input type="text" class="cell-input" name="inn" value="9000000009" maxlength="12" form="edit-recipient-9"></td><td class="actions-cell"><form id="edit-recipient-9" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="9"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «КубаньСпецПроект»" form="edit-recipient-19"></td><td><input type="text" class="cell-input" name="inn" value="9000000019" maxlength="12" form="edit-recipient-19"></td><td class="actions-cell"><form id="edit-recipient-19" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="19"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «Лайнер»" form="edit-recipient-31"></td><td><input type="text" class="cell-input" name="inn" value="9000000032" maxlength="12" form="edit-recipient-31"></td><td class="actions-cell"><form id="edit-recipient-31" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="31"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «ЛРК «МедиМакс»" form="edit-recipient-33"></td><td><input type="text" class="cell-input" name="inn" value="9000000034" maxlength="12" form="edit-recipient-33"></td><td class="actions-cell"><form id="edit-recipient-33" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="33"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «ЛУКОЙЛ-Нижегороднефтеоргсинтез»" form="edit-recipient-34"></td><td><input type="text" class="cell-input" name="inn" value="9000000035" maxlength="12" form="edit-recipient-34"></td><td class="actions-cell"><form id="edit-recipient-34" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="34"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «НПО «Центротех»" form="edit-recipient-35"></td><td><input type="text" class="cell-input" name="inn" value="9000000036" maxlength="12" form="edit-recipient-35"></td><td class="actions-cell"><form id="edit-recipient-35" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="35"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><form method="post" style="display:inline" onsubmit="return confirm('Удалить этого получателя?');"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="35"><button name="delete_recipient" value="1" class="icon-btn icon-delete" title="Удалить" aria-label="Удалить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button></form></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «Портнер»" form="edit-recipient-36"></td><td><input type="text" class="cell-input" name="inn" value="9000000037" maxlength="12" form="edit-recipient-36"></td><td class="actions-cell"><form id="edit-recipient-36" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="36"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «ПрофИнженерПроект»" form="edit-recipient-16"></td><td><input type="text" class="cell-input" name="inn" value="9000000016" maxlength="12" form="edit-recipient-16"></td><td class="actions-cell"><form id="edit-recipient-16" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="16"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «РИАРДЕН ГРУПП»" form="edit-recipient-32"></td><td><input type="text" class="cell-input" name="inn" value="9000000033" maxlength="12" form="edit-recipient-32"></td><td class="actions-cell"><form id="edit-recipient-32" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="32"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «РосАТ»" form="edit-recipient-37"></td><td><input type="text" class="cell-input" name="inn" value="9000000038" maxlength="12" form="edit-recipient-37"></td><td class="actions-cell"><form id="edit-recipient-37" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="37"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (2)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «Росатом Машиностроение»" form="edit-recipient-38"></td><td><input type="text" class="cell-input" name="inn" value="9000000039" maxlength="12" form="edit-recipient-38"></td><td class="actions-cell"><form id="edit-recipient-38" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="38"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «РЭНЕРА»" form="edit-recipient-39"></td><td><input type="text" class="cell-input" name="inn" value="9000000040" maxlength="12" form="edit-recipient-39"></td><td class="actions-cell"><form id="edit-recipient-39" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="39"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (2)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «Холдинговая компания Евроангар»" form="edit-recipient-18"></td><td><input type="text" class="cell-input" name="inn" value="9000000018" maxlength="12" form="edit-recipient-18"></td><td class="actions-cell"><form id="edit-recipient-18" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="18"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «Центротех-Инжиниринг»" form="edit-recipient-40"></td><td><input type="text" class="cell-input" name="inn" value="9000000041" maxlength="12" form="edit-recipient-40"></td><td class="actions-cell"><form id="edit-recipient-40" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="40"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><form method="post" style="display:inline" onsubmit="return confirm('Удалить этого получателя?');"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="40"><button name="delete_recipient" value="1" class="icon-btn icon-delete" title="Удалить" aria-label="Удалить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button></form></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «ЦСР БМС»" form="edit-recipient-17"></td><td><input type="text" class="cell-input" name="inn" value="9000000017" maxlength="12" form="edit-recipient-17"></td><td class="actions-cell"><form id="edit-recipient-17" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="17"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО «Эколант»" form="edit-recipient-10"></td><td><input type="text" class="cell-input" name="inn" value="9000000010" maxlength="12" form="edit-recipient-10"></td><td class="actions-cell"><form id="edit-recipient-10" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="10"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (2)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО НПП «ПРИМА»" form="edit-recipient-49"></td><td><input type="text" class="cell-input" name="inn" value="5257013402" maxlength="12" form="edit-recipient-49"></td><td class="actions-cell"><form id="edit-recipient-49" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="49"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ООО ПХТИ «Полихимсервис»" form="edit-recipient-50"></td><td><input type="text" class="cell-input" name="inn" value="5260406643" maxlength="12" form="edit-recipient-50"></td><td class="actions-cell"><form id="edit-recipient-50" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="50"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (6)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ПАО «КМЗ»" form="edit-recipient-41"></td><td><input type="text" class="cell-input" name="inn" value="9000000042" maxlength="12" form="edit-recipient-41"></td><td class="actions-cell"><form id="edit-recipient-41" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="41"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ФГАОУ ВО «ННГУ им. Н.И. Лобачевского»" form="edit-recipient-14"></td><td><input type="text" class="cell-input" name="inn" value="9000000014" maxlength="12" form="edit-recipient-14"></td><td class="actions-cell"><form id="edit-recipient-14" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="14"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><form method="post" style="display:inline" onsubmit="return confirm('Удалить этого получателя?');"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="14"><button name="delete_recipient" value="1" class="icon-btn icon-delete" title="Удалить" aria-label="Удалить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button></form></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ФГКУ &quot;В/Ч 45187&quot;" form="edit-recipient-44"></td><td><input type="text" class="cell-input" name="inn" value="7805039765" maxlength="12" form="edit-recipient-44"></td><td class="actions-cell"><form id="edit-recipient-44" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="44"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ФГУП «ГУО МИД РОССИИ»" form="edit-recipient-42"></td><td><input type="text" class="cell-input" name="inn" value="9000000043" maxlength="12" form="edit-recipient-42"></td><td class="actions-cell"><form id="edit-recipient-42" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="42"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr><tr><td><input type="text" class="cell-input" name="name" value="ФГУП «УВО Минтранса России»" form="edit-recipient-4"></td><td><input type="text" class="cell-input" name="inn" value="9000000004" maxlength="12" form="edit-recipient-4"></td><td class="actions-cell"><form id="edit-recipient-4" method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="return_qs" value=""><input type="hidden" name="recipient_id" value="4"><button type="submit" name="edit_recipient" value="1" class="icon-btn icon-edit" title="Сохранить" aria-label="Сохранить"><svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button></form><span class="muted">исп. (1)</span></td></tr></tbody>
</table>
</div>
</div>
</dialog>
<dialog id="categories-modal" class="modal nested">
<div class="modal-inner">
<div class="modal-head"><h2>Категории внутренних документов</h2><button type="button" class="modal-close" onclick="window.document.getElementById('categories-modal').close()">×</button></div>
<p class="muted">Категории общие для обеих фирм. Один документ можно отнести сразу к нескольким.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
<input type="hidden" name="return_qs" value="">
<label>Новая категория<input name="category_name" required></label>
<button name="add_internal_category" value="1">+ Добавить категорию</button>
</form>
<div class="table-wrap" style="margin-top:16px">
<table>
<thead><tr><th>Название</th><th>Документов</th><th></th></tr></thead>
<tbody>
<tr>
<td>Уставные</td>
<td>14</td>
<td class="actions-cell"><span class="muted">исп.</span></td>
</tr>
<tr>
<td>Сертификаты</td>
<td>16</td>
<td class="actions-cell"><span class="muted">исп.</span></td>
</tr>
<tr>
<td>Информационные</td>
<td>8</td>
<td class="actions-cell"><span class="muted">исп.</span></td>
</tr>
</tbody>
</table>
</div>
</div>
</dialog>
<dialog id="changelog-modal" class="modal changelog-modal"><div class="modal-inner"><div class="modal-head"><h2>История изменений</h2><button type="button" class="modal-close" onclick="document.getElementById('changelog-modal').close()" aria-label="Закрыть">×</button></div><p class="changelog-lead">Текущая версия <strong>v1.2.0</strong> от 22.09.2026</p><ol class="changelog"><li class="changelog-entry"><div class="changelog-meta"><strong>v1.2.0</strong><span>22.09.2026</span></div><ul><li>У каждого документа уникальный поисковый код вида НД-XXXX-XXXX.</li><li>Word и PDF одного документа получают один код — это один документ в двух форматах.</li><li>Код ставится в конец тела документа, колонтитулы не меняются.</li><li>По коду можно найти документ в журнале.</li></ul></li><li class="changelog-entry"><div class="changelog-meta"><strong>v1.1.0</strong><span>22.09.2026</span></div><ul><li>В правом верхнем углу показаны номер версии и дата релиза.</li><li>По клику открывается журнал истории изменений проекта.</li></ul></li><li class="changelog-entry"><div class="changelog-meta"><strong>v1.0.0</strong><span>19.09.2026</span></div><ul><li>Раздельные журналы ООО «Норма софт» (ИНН 5260269813) и ООО «Нормасофт» (ИНН 5259120833).</li><li>Исходящие и внутренние документы: уставные, сертификаты, информационные.</li><li>При импорте номер берётся из файла; чужие номера не присваиваются, без номера — б/н.</li><li>Рекомендуемый номер рядом с кнопкой «Добавить документ».</li><li>Запрос пароля по почте, открытие файлов только скачиванием, иконки по типу файла.</li></ul></li></ol></div></dialog>
<dialog id="profile-modal" class="modal nested" style="max-width:420px">
<div class="modal-inner">
<div class="modal-head"><h2>Профиль</h2><button type="button" class="modal-close" onclick="window.document.getElementById('profile-modal').close()">×</button></div>
<form method="post">
<input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
<input type="hidden" name="return_qs" value="">
<label>Логин<input value="andrey" disabled></label>
<label>ФИО<input name="full_name" value="Западаев Андрей Иванович" placeholder="Иванов Иван Иванович"></label>
<label>Новый пароль (оставьте пустым, чтобы не менять)<input type="password" name="new_password" autocomplete="new-password"></label>
<label>Повтор пароля<input type="password" name="new_password_confirm" autocomplete="new-password"></label>
<div class="modal-foot"><button name="update_profile" value="1">Сохранить</button></div>
</form>
</div>
</dialog>
<?php if (!empty($openDocModal)): ?>
<script>document.getElementById('doc-modal').showModal();</script>
<?php endif; ?>
<?php if (!empty($openRecipientsModal)): ?>
<script>document.getElementById('recipients-modal').showModal();</script>
<?php endif; ?>
<?php if (!empty($openCategoriesModal)): ?>
<script>document.getElementById('categories-modal').showModal();</script>
<?php endif; ?>
<script>
document.querySelectorAll('dialog.modal').forEach(function (dlg) {
  dlg.addEventListener('click', function (event) {
    if (event.target === dlg) {
      dlg.close();
    }
  });
});
function updateBulkButton() {
  var checked = document.querySelectorAll('.file-select:checked');
  var btn = document.getElementById('bulk-download-btn');
  if (!btn) return;
  if (checked.length > 0) {
    btn.style.display = '';
    btn.textContent = 'Скачать выбранное (' + checked.length + ')';
  } else {
    btn.style.display = 'none';
  }
}
function syncRowFromIcons(id) {
  var row = document.querySelector('.row-select[value="' + id + '"]');
  if (!row) return;
  var all = document.querySelectorAll('.file-select[value="' + id + '"]');
  var checked = document.querySelectorAll('.file-select[value="' + id + '"]:checked');
  row.checked = all.length > 0 && all.length === checked.length;
}
document.querySelectorAll('.row-select').forEach(function (cb) {
  cb.addEventListener('change', function () {
    document.querySelectorAll('.file-select[value="' + cb.value + '"]').forEach(function (f) { f.checked = cb.checked; });
    updateBulkButton();
  });
});
document.querySelectorAll('.file-select').forEach(function (cb) {
  cb.addEventListener('change', function () {
    syncRowFromIcons(cb.value);
    updateBulkButton();
  });
});
var selectAllBox = document.getElementById('select-all');
if (selectAllBox) {
  selectAllBox.addEventListener('change', function () {
    document.querySelectorAll('.row-select, .file-select').forEach(function (cb) { cb.checked = selectAllBox.checked; });
    updateBulkButton();
  });
}
function submitBulkDownload() {
  var form = document.getElementById('bulk-form');
  form.querySelectorAll('input[name="items[]"]').forEach(function (el) { el.remove(); });
  document.querySelectorAll('.file-select:checked').forEach(function (cb) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'items[]';
    input.value = (cb.getAttribute('data-kind') || 'word') + ':' + cb.value;
    form.appendChild(input);
  });
  form.submit();
}
</script>
</div></body></html>
