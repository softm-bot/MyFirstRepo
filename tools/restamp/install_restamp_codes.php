<?php
/**
 * Batch restamp: place search_code (НД-XXXX-XXXX) as a compact right-aligned
 * last paragraph in every DOCX, strip old «Код документа:» stamps, fix file sizes.
 *
 * Upload to public/ and open:
 *   https://gost.info/gost-documents/public/install_restamp_codes.php?key=NormaRestamp2026
 * Optional: &limit=10 &offset=0 &id=127 &dry=1
 * Self-deletes only with &delete=1 after success.
 */
declare(strict_types=1);

const INSTALL_KEY = 'NormaRestamp2026';

$key = (string)($_GET['key'] ?? '');
if (!hash_equals(INSTALL_KEY, $key)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');

$publicDir = __DIR__;
$appRoot = is_file(dirname($publicDir) . '/config.php')
    ? dirname($publicDir)
    : $publicDir;

$configFile = $appRoot . '/config.php';
if (!is_file($configFile)) {
    echo "config.php not found at $configFile\n";
    exit(1);
}
/** @var array $config */
$config = require $configFile;
$db = $config['db'] ?? $config;
$user = (string)($db['user'] ?? $db['username'] ?? '');
$pass = (string)($db['password'] ?? $db['pass'] ?? '');
if (!empty($db['dsn'])) {
    $dsn = (string)$db['dsn'];
} else {
    $host = (string)($db['host'] ?? 'localhost');
    $port = (int)($db['port'] ?? 3306);
    $name = (string)($db['name'] ?? $db['dbname'] ?? '');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
}
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$storage = (string)($config['storage_path'] ?? '');
if ($storage === '' || !is_dir($storage)) {
    $storage = $appRoot . '/storage/documents';
}
if (!is_dir($storage)) {
    $storage = $appRoot . '/storage';
}

$dry = isset($_GET['dry']);
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 500;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$onlyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

function restampDocx(string $path, string $code): array
{
    if ($code === '' || !is_file($path) || !class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'bad path/code/zip'];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['ok' => false, 'error' => 'zip open failed'];
    }
    $xml = $zip->getFromName('word/document.xml');
    if (!is_string($xml) || $xml === '') {
        $zip->close();
        return ['ok' => false, 'error' => 'no document.xml'];
    }

    // Strip old stamp paragraphs / leftover labels/codes
    $xml = preg_replace('/<w:p\b[^>]*>(?:(?!<\/w:p>).)*?Код документа:(?:(?!<\/w:p>).)*?<\/w:p>/u', '', $xml) ?? $xml;
    $xml = preg_replace(
        '/<w:p\b[^>]*>(?:(?!<\/w:p>).)*?<w:jc w:val="right"\/>(?:(?!<\/w:p>).)*?НД-[A-Z0-9]{4}-[A-Z0-9]{4}(?:(?!<\/w:p>).)*?<\/w:p>/u',
        '',
        $xml
    ) ?? $xml;
    $xml = preg_replace('/\s*Код документа:\s*НД-[A-Z0-9]{4}-[A-Z0-9]{4}/u', '', $xml) ?? $xml;
    // Remove prior right-tab stamp runs (tab + code)
    $xml = preg_replace('/<w:r>\s*<w:tab\/>\s*<\/w:r>\s*<w:r>(?:(?!<\/w:r>).)*?НД-[A-Z0-9]{4}-[A-Z0-9]{4}(?:(?!<\/w:r>).)*?<\/w:r>/u', '', $xml) ?? $xml;
    $xml = preg_replace('/(?<=>)\s*НД-[A-Z0-9]{4}-[A-Z0-9]{4}\s*(?=<)/u', '', $xml) ?? $xml;

    $safeCode = htmlspecialchars($code, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $stampRuns = '<w:r><w:tab/></w:r>'
        . '<w:r><w:rPr><w:sz w:val="15"/><w:szCs w:val="15"/><w:color w:val="666666"/></w:rPr>'
        . '<w:t xml:space="preserve">' . $safeCode . '</w:t></w:r>';

    // Collect body paragraphs before sectPr
    if (!preg_match('/<w:body\b[^>]*>([\s\S]*)<\/w:body>/', $xml, $bodyMatch)) {
        $zip->close();
        return ['ok' => false, 'error' => 'no body'];
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
        return ['ok' => false, 'error' => 'no paragraphs'];
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
        return ['ok' => false, 'error' => 'no target paragraph'];
    }

    $p = $paragraphs[$targetIdx];
    // Ensure right tab stop (~content width for A4 with typical KP margins)
    if (preg_match('/<w:tabs\b[^>]*>[\s\S]*?<\/w:tabs>/', $p)) {
        $p = preg_replace('/<w:tabs\b[^>]*>[\s\S]*?<\/w:tabs>/', '<w:tabs><w:tab w:val="right" w:pos="9781"/></w:tabs>', $p, 1) ?? $p;
    } elseif (preg_match('/<w:pPr\b[^>]*>/', $p)) {
        $p = preg_replace('/(<w:pPr\b[^>]*>)/', '$1<w:tabs><w:tab w:val="right" w:pos="9781"/></w:tabs>', $p, 1) ?? $p;
    } else {
        $p = preg_replace('/(<w:p\b[^>]*>)/', '$1<w:pPr><w:tabs><w:tab w:val="right" w:pos="9781"/></w:tabs></w:pPr>', $p, 1) ?? $p;
    }
    // Compact spacing on last paragraph to help keep 1 page
    if (preg_match('/<w:spacing\b[^>]*\/>/', $p)) {
        $p = preg_replace('/<w:spacing\b[^>]*\/>/', '<w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/>', $p, 1) ?? $p;
    } elseif (preg_match('/<w:pPr\b[^>]*>/', $p)) {
        $p = preg_replace('/(<w:pPr\b[^>]*>)/', '$1<w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/>', $p, 1) ?? $p;
    }
    // Append tab+code before </w:p>
    $p = preg_replace('/<\/w:p>/', $stampRuns . '</w:p>', $p, 1) ?? $p;
    $paragraphs[$targetIdx] = $p;
    $newBody = implode('', $paragraphs) . $sect;
    $xml = preg_replace('/<w:body\b[^>]*>[\s\S]*<\/w:body>/', '<w:body>' . $newBody . '</w:body>', $xml, 1) ?? $xml;

    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    clearstatcache(true, $path);
    return ['ok' => true, 'changed' => true, 'size' => filesize($path)];
}


$sql = "SELECT d.id, d.search_code, d.document_number, d.type, d.organization_id,
               f.id AS file_id, f.stored_name, f.original_name, f.file_size,
               f.pdf_stored_name, f.pdf_file_size
        FROM documents d
        JOIN document_files f ON f.document_id = d.id
        WHERE d.search_code IS NOT NULL AND d.search_code <> ''";
$params = [];
if ($onlyId > 0) {
    $sql .= ' AND d.id = ?';
    $params[] = $onlyId;
}
$sql .= ' ORDER BY d.id LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

echo "REStamp start storage=$storage dry=" . ($dry ? '1' : '0') . " rows=" . count($rows) . "\n";

$ok = 0;
$fail = 0;
$skip = 0;
$fixedSizes = 0;
$clearedPdf = 0;

$updFile = $pdo->prepare('UPDATE document_files SET file_size = ? WHERE id = ?');
$updPdf = $pdo->prepare('UPDATE document_files SET pdf_file_size = ? WHERE id = ?');
$clearPdf = $pdo->prepare('UPDATE document_files SET pdf_stored_name = NULL, pdf_original_name = NULL, pdf_file_size = NULL WHERE id = ?');

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $code = (string)$row['search_code'];
    $stored = (string)$row['stored_name'];
    $path = $storage . DIRECTORY_SEPARATOR . $stored;
    $ext = strtolower(pathinfo((string)$row['original_name'], PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
    }

    if (!is_file($path)) {
        echo "MISS id=$id path=$path\n";
        $fail++;
        continue;
    }

    $diskSize = filesize($path);
    if ((int)$row['file_size'] !== (int)$diskSize) {
        if (!$dry) {
            $updFile->execute([(int)$diskSize, (int)$row['file_id']]);
        }
        $fixedSizes++;
        echo "SIZE id=$id db={$row['file_size']} disk=$diskSize\n";
    }

    // Fix PDF size / drop corrupt PDFs (no %%EOF)
    if (!empty($row['pdf_stored_name'])) {
        $pdfPath = $storage . DIRECTORY_SEPARATOR . $row['pdf_stored_name'];
        if (is_file($pdfPath)) {
            $pdfDataHead = file_get_contents($pdfPath, false, null, 0, 5);
            $pdfTail = file_get_contents($pdfPath, false, null, max(0, filesize($pdfPath) - 32), 32);
            $pdfOk = is_string($pdfDataHead) && str_starts_with($pdfDataHead, '%PDF')
                && is_string($pdfTail) && str_contains($pdfTail, '%%EOF');
            $pdfSize = filesize($pdfPath);
            if (!$pdfOk) {
                if (!$dry) {
                    @unlink($pdfPath);
                    $clearPdf->execute([(int)$row['file_id']]);
                }
                $clearedPdf++;
                echo "PDF_BAD id=$id cleared\n";
            } elseif ((int)($row['pdf_file_size'] ?? 0) !== (int)$pdfSize) {
                if (!$dry) {
                    $updPdf->execute([(int)$pdfSize, (int)$row['file_id']]);
                }
                echo "PDF_SIZE id=$id db={$row['pdf_file_size']} disk=$pdfSize\n";
            }
        } else {
            if (!$dry) {
                $clearPdf->execute([(int)$row['file_id']]);
            }
            $clearedPdf++;
            echo "PDF_MISS id=$id cleared meta\n";
        }
    }

    if (!in_array($ext, ['docx', 'doc'], true)) {
        echo "SKIP id=$id ext=$ext\n";
        $skip++;
        continue;
    }

    if ($dry) {
        echo "DRY id=$id code=$code num={$row['document_number']}\n";
        $ok++;
        continue;
    }

    $result = restampDocx($path, $code);
    if (!$result['ok']) {
        echo "FAIL id=$id err={$result['error']}\n";
        $fail++;
        continue;
    }
    $newSize = (int)$result['size'];
    $updFile->execute([$newSize, (int)$row['file_id']]);
    // Invalidate PDF cache so next download regenerates from restamped Word (if converter available)
    if (!empty($row['pdf_stored_name'])) {
        $pdfPath = $storage . DIRECTORY_SEPARATOR . $row['pdf_stored_name'];
        if (is_file($pdfPath)) {
            @unlink($pdfPath);
        }
        $clearPdf->execute([(int)$row['file_id']]);
        $clearedPdf++;
    }
    echo "OK id=$id code=$code changed=" . ($result['changed'] ? '1' : '0') . " size=$newSize\n";
    $ok++;
}

echo "DONE ok=$ok fail=$fail skip=$skip fixed_sizes=$fixedSizes cleared_pdf=$clearedPdf\n";

if (isset($_GET['delete']) && $fail === 0 && !$dry) {
    @unlink(__FILE__);
    echo "installer deleted\n";
}
