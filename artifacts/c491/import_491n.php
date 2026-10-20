<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
$secret = 'c491_import_once_20261020';
if (($_GET['k'] ?? '') !== $secret) {
    http_response_code(403);
    exit("forbidden\n");
}

$root = dirname(__DIR__); // gost-documents
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    // public/ is cwd parent of this script in public/
    $root = dirname(__DIR__);
    $configFile = dirname(__DIR__) . '/config.php';
}
// This script lives in public/
$appRoot = dirname(__DIR__);
$configFile = $appRoot . '/config.php';
if (!is_file($configFile)) {
    exit("no config: $configFile\n");
}
$config = require $configFile;
$pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$partsDir = __DIR__ . '/_c491_parts';
$b64 = '';
for ($i = 0; $i < 50; $i++) {
    $f = sprintf('%s/part_%02d.txt', $partsDir, $i);
    if (!is_file($f)) break;
    $b64 .= file_get_contents($f);
}
if ($b64 === '') {
    exit("no parts in $partsDir\n");
}
$pdf = $b64 !== '' ? base64_decode($b64, true) : false;

// Fallback: local PDF next to this script or downloaded via ?pdf_url=
$localPdf = __DIR__ . '/Dogovor_491N_01.06.26_ECP.pdf';
$c491Pdf = __DIR__ . '/_c491_parts/Dogovor_491N_01.06.26_ECP.pdf';
if (($pdf === false || strlen($pdf) < 1000) && is_file($localPdf)) {
    $pdf = file_get_contents($localPdf);
}
if (($pdf === false || strlen($pdf) < 1000) && is_file($c491Pdf)) {
    $pdf = file_get_contents($c491Pdf);
}
if (($pdf === false || strlen($pdf) < 1000) && !empty($_GET['pdf_url'])) {
    $url = (string)$_GET['pdf_url'];
    if (preg_match('#^https://raw\.githubusercontent\.com/#', $url) || preg_match('#^https://gost\.info/#', $url)) {
        $pdf = @file_get_contents($url);
    }
}

if ($pdf === false || strlen((string)$pdf) < 1000) {
    exit("bad pdf decode len=" . strlen((string)$pdf) . "\n");
}
echo "pdf_bytes=" . strlen($pdf) . "\n";

// Org: НормаСофт INN 5259120833
$org = $pdo->query("SELECT id, name, inn FROM organizations ORDER BY id")->fetchAll();
echo "orgs=" . json_encode($org, JSON_UNESCAPED_UNICODE) . "\n";
$orgId = null;
foreach ($org as $o) {
    $inn = preg_replace('/\D+/', '', (string)($o['inn'] ?? ''));
    if ($inn === '5259120833' || stripos((string)$o['name'], 'НормаСофт') !== false || stripos((string)$o['name'], 'Норма софт') !== false) {
        // Prefer exact INN match
        if ($inn === '5259120833') { $orgId = (int)$o['id']; break; }
        if ($orgId === null) $orgId = (int)$o['id'];
    }
}
if ($orgId === null && $org) $orgId = (int)$org[0]['id'];
echo "orgId=$orgId\n";

// Recipient ВолгаСтальПроект INN 5260156231
$inn = '5260156231';
$recName = 'ООО «ВолгаСтальПроект»';
$st = $pdo->prepare("SELECT id, name, inn FROM recipients WHERE organization_id = ? AND (REPLACE(REPLACE(inn,' ',''),'-','') = ? OR name LIKE ? OR card_inn = ?) LIMIT 5");
$st->execute([$orgId, $inn, '%ВолгаСталь%', $inn]);
$recs = $st->fetchAll();
echo "recs_found=" . json_encode($recs, JSON_UNESCAPED_UNICODE) . "\n";
$recipientId = $recs ? (int)$recs[0]['id'] : 0;
if (!$recipientId) {
    // detect columns
    $cols = $pdo->query("SHOW COLUMNS FROM recipients")->fetchAll(PDO::FETCH_COLUMN);
    $fields = ['organization_id' => $orgId, 'name' => $recName];
    if (in_array('inn', $cols, true)) $fields['inn'] = $inn;
    if (in_array('card_inn', $cols, true)) $fields['card_inn'] = $inn;
    if (in_array('card_full', $cols, true)) $fields['card_full'] = $recName;
    if (in_array('card_short', $cols, true)) $fields['card_short'] = 'ВолгаСтальПроект';
    $keys = array_keys($fields);
    $sql = 'INSERT INTO recipients (' . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($fields));
    $recipientId = (int)$pdo->lastInsertId();
    echo "recipient_created=$recipientId\n";
} else {
    echo "recipient_existing=$recipientId\n";
}

$num = '491Н';
$date = '2026-06-01';
$subject = 'Сублицензионный договор NormaCS 4.x (сетевая, 5 р.м.) — ЭЦП';
$taken = $pdo->prepare("SELECT id FROM documents WHERE organization_id = ? AND type = 'contract' AND document_number = ?");
$taken->execute([$orgId, $num]);
$existingId = (int)($taken->fetchColumn() ?: 0);
if ($existingId) {
    echo "already_exists id=$existingId\n";
    $docId = $existingId;
} else {
    // admin user
    $uid = (int)$pdo->query("SELECT id FROM users WHERE username='andrey' OR role='admin' ORDER BY id LIMIT 1")->fetchColumn();
    // search code like НД-XXXX-XXXX
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    do {
        $code = 'НД-';
        for ($i=0;$i<4;$i++) $code .= $alphabet[random_int(0, strlen($alphabet)-1)];
        $code .= '-';
        for ($i=0;$i<4;$i++) $code .= $alphabet[random_int(0, strlen($alphabet)-1)];
        $chk = $pdo->prepare('SELECT id FROM documents WHERE search_code = ?');
        $chk->execute([$code]);
    } while ($chk->fetchColumn());

    $pdo->prepare('INSERT INTO documents (organization_id, type, document_number, document_date, recipient, recipient_id, subject, uploaded_by, search_code) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$orgId, 'contract', $num, $date, $recName, $recipientId ?: null, $subject, $uid, $code]);
    $docId = (int)$pdo->lastInsertId();
    echo "document_created id=$docId code=$code\n";
}

// storage
$storage = rtrim((string)$config['storage_path'], "/\\");
if (!is_dir($storage)) mkdir($storage, 0700, true);
$stored = bin2hex(random_bytes(24)) . '.pdf';
$path = $storage . DIRECTORY_SEPARATOR . $stored;
if (file_put_contents($path, $pdf) === false) {
    exit("failed write $path\n");
}
$orig = 'Договор №491Н от 01.06.26 ЭЦП.pdf';
$size = strlen($pdf);
$mime = 'application/pdf';

$fileRow = $pdo->prepare('SELECT id, stored_name, pdf_stored_name FROM document_files WHERE document_id = ? ORDER BY id LIMIT 1');
$fileRow->execute([$docId]);
$fr = $fileRow->fetch();
if ($fr) {
    foreach (['stored_name','pdf_stored_name'] as $k) {
        if (!empty($fr[$k])) @unlink($storage . DIRECTORY_SEPARATOR . $fr[$k]);
    }
    $pdo->prepare('UPDATE document_files SET original_name=?, stored_name=?, mime_type=?, file_size=?, pdf_stored_name=?, pdf_original_name=?, pdf_file_size=?, file_uploaded_at=NOW() WHERE id=?')
        ->execute([$orig, $stored, $mime, $size, $stored, $orig, $size, (int)$fr['id']]);
    echo "file_updated id={$fr['id']}\n";
} else {
    $pdo->prepare('INSERT INTO document_files (document_id, original_name, stored_name, mime_type, file_size, pdf_stored_name, pdf_original_name, pdf_file_size, file_uploaded_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
        ->execute([$docId, $orig, $stored, $mime, $size, $stored, $orig, $size]);
    echo "file_inserted\n";
}

// contract_details
$pdo->exec("CREATE TABLE IF NOT EXISTS contract_details (
  document_id BIGINT UNSIGNED PRIMARY KEY,
  status VARCHAR(32) NOT NULL DEFAULT 'approval',
  amount DECIMAL(15,2) NULL,
  product VARCHAR(255) NULL,
  license_type VARCHAR(255) NULL,
  seats INT NULL,
  sections TEXT NULL,
  period_text VARCHAR(255) NULL,
  payment_terms TEXT NULL,
  sign_method VARCHAR(255) NULL,
  special_terms TEXT NULL,
  note TEXT NULL,
  terms_sheet_stored_name VARCHAR(255) NULL
)");
$payment = "До 15.06.2026 — 62 775; До 15.09.2026 — 62 775; До 15.12.2026 — 62 775; До 15.03.2027 — 62 775";
$pdo->prepare('INSERT INTO contract_details (document_id, status, amount, product, license_type, seats, sections, period_text, payment_terms, sign_method, special_terms, note, terms_sheet_stored_name)
 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
 ON DUPLICATE KEY UPDATE status=VALUES(status), amount=VALUES(amount), product=VALUES(product), license_type=VALUES(license_type), seats=VALUES(seats), period_text=VALUES(period_text), payment_terms=VALUES(payment_terms), sign_method=VALUES(sign_method), note=VALUES(note)')
->execute([
    $docId,
    'signed',
    251100.00,
    'NormaCS 4.x',
    'сетевая',
    5,
    null,
    '12 мес.',
    $payment,
    'ЭЦП (Диадок)',
    null,
    'Подписан ЭЦП: НормаСофт 10.07.2026, ВолгаСтальПроект 28.07.2026',
    null,
]);
echo "contract_details_ok\n";

// cleanup parts + self optionally
foreach (glob($partsDir . '/part_*.txt') ?: [] as $f) @unlink($f);
@rmdir($partsDir);
echo "DONE doc_id=$docId num=$num org=$orgId recipient=$recipientId stored=$stored\n";
