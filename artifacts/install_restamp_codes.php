<?php
/**
 * One-shot: restamp НД-XXXX-XXXX into all DOCX (right tab on last content line)
 * and sync document_files.file_size / pdf_file_size = filesize().
 *
 * Upload via FileZilla next to index.php, then open:
 *   /gost-documents/install_restamp_codes.php?key=NormaRestamp2026
 * Delete after SUMMARY.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
const INSTALL_KEY = 'NormaRestamp2026';
if (!hash_equals(INSTALL_KEY, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$publicDir = __DIR__;
$appRoot = (is_file(dirname($publicDir) . '/config.php') || is_dir(dirname($publicDir) . '/storage'))
    ? dirname($publicDir) : $publicDir;

$configFile = is_file($appRoot . '/config.php') ? $appRoot . '/config.php' : $appRoot . '/public/config.php';
if (!is_file($configFile)) {
    echo "ERROR: config.php not found under $appRoot\n";
    exit(1);
}

/** @var array|PDO|null $config */
$config = require $configFile;
$pdo = null;
if ($config instanceof PDO) {
    $pdo = $config;
} elseif (is_array($config)) {
    $host = $config['db_host'] ?? $config['DB_HOST'] ?? ($config['db']['host'] ?? 'localhost');
    $name = $config['db_name'] ?? $config['DB_NAME'] ?? ($config['db']['name'] ?? $config['db']['database'] ?? '');
    $user = $config['db_user'] ?? $config['DB_USER'] ?? ($config['db']['user'] ?? $config['db']['username'] ?? '');
    $pass = $config['db_pass'] ?? $config['DB_PASS'] ?? ($config['db']['pass'] ?? $config['db']['password'] ?? '');
    if ($name !== '') {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
if (!$pdo instanceof PDO) {
    echo "ERROR: PDO not configured\n";
    exit(1);
}

$storageCandidates = [
    $appRoot . '/storage/files',
    $appRoot . '/storage/documents',
    $appRoot . '/storage',
    $publicDir . '/../storage/files',
    $publicDir . '/../storage',
];
$storage = null;
foreach ($storageCandidates as $dir) {
    if (is_dir($dir)) {
        $storage = $dir;
        break;
    }
}
if ($storage === null) {
    $sample = $pdo->query("SELECT stored_name FROM document_files WHERE stored_name LIKE '%.docx' LIMIT 1")->fetchColumn();
    if ($sample) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getFilename() === $sample) {
                $storage = $f->getPath();
                break;
            }
        }
    }
}
if ($storage === null) {
    echo "ERROR: storage not found\n";
    exit(1);
}
echo "appRoot=$appRoot\nstorage=$storage\n";

function restamp_docx(string $path, string $code): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'ZipArchive missing'];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['ok' => false, 'error' => 'cannot open zip'];
    }
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        return ['ok' => false, 'error' => 'no document.xml'];
    }

    $xml = preg_replace(
        '~<w:p\b[^>]*>(?:(?!</w:p>).)*?(?:Код\s+документа\s*:\s*)?НД-[A-Z0-9]{4}-[A-Z0-9]{4}(?:(?!</w:p>).)*</w:p>~su',
        '',
        $xml
    );
    $xml = preg_replace('~(?:Код\s+документа\s*:\s*)?НД-[A-Z0-9]{4}-[A-Z0-9]{4}~u', '', $xml);

    if (!preg_match_all('~<w:t([^>]*)>([^<]*)</w:t>~u', $xml, $mm, PREG_OFFSET_CAPTURE)) {
        $zip->close();
        return ['ok' => false, 'error' => 'no text nodes'];
    }
    $last = end($mm[0]);
    $lastPos = $last[1];
    $paraEnd = strpos($xml, '</w:p>', $lastPos);
    if ($paraEnd === false) {
        $zip->close();
        return ['ok' => false, 'error' => 'no para end'];
    }
    $injectAt = $paraEnd;

    $paraStart = strrpos(substr($xml, 0, $injectAt), '<w:p');
    $paraXml = substr($xml, $paraStart, $injectAt - $paraStart);
    if (strpos($paraXml, 'w:val="right"') === false) {
        if (preg_match('~<w:pPr\b[^>]*>~', $paraXml, $pm, PREG_OFFSET_CAPTURE)) {
            $abs = $paraStart + $pm[0][1] + strlen($pm[0][0]);
            $tabXml = '<w:tabs><w:tab w:val="right" w:pos="9700"/></w:tabs>';
            $xml = substr($xml, 0, $abs) . $tabXml . substr($xml, $abs);
            $injectAt += strlen($tabXml);
        } elseif (preg_match('~<w:p\b[^>]*>~', $paraXml, $pm, PREG_OFFSET_CAPTURE)) {
            $abs = $paraStart + $pm[0][1] + strlen($pm[0][0]);
            $pPr = '<w:pPr><w:tabs><w:tab w:val="right" w:pos="9700"/></w:tabs><w:spacing w:before="0" w:after="0"/></w:pPr>';
            $xml = substr($xml, 0, $abs) . $pPr . substr($xml, $abs);
            $injectAt += strlen($pPr);
        }
    }

    $codeXml = '<w:r><w:tab/></w:r>'
        . '<w:r><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr>'
        . '<w:t xml:space="preserve">' . htmlspecialchars($code, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</w:t></w:r>';
    $xml = substr($xml, 0, $injectAt) . $codeXml . substr($xml, $injectAt);

    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    clearstatcache(true, $path);
    return ['ok' => true, 'size' => filesize($path)];
}

function resolve_path(string $storage, string $name): ?string
{
    foreach ([$storage . '/' . $name, dirname($storage) . '/' . $name] as $p) {
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

$rows = $pdo->query(
    "SELECT d.id, d.search_code, d.document_number, f.id AS fid, f.stored_name, f.file_size, f.pdf_stored_name, f.pdf_file_size
     FROM documents d
     JOIN document_files f ON f.document_id = d.id
     WHERE d.search_code IS NOT NULL AND d.search_code != ''
     ORDER BY d.id"
)->fetchAll(PDO::FETCH_ASSOC);

$stats = ['docx_ok' => 0, 'docx_fail' => 0, 'size_fixed' => 0, 'pdf_size_fixed' => 0, 'skip' => 0];
$upd = $pdo->prepare('UPDATE document_files SET file_size=? WHERE id=?');
$updPdf = $pdo->prepare('UPDATE document_files SET pdf_file_size=? WHERE id=?');

foreach ($rows as $row) {
    $path = resolve_path($storage, $row['stored_name']);
    if ($path === null) {
        echo "MISSING id={$row['id']} {$row['stored_name']}\n";
        $stats['docx_fail']++;
        continue;
    }

    if (preg_match('/\.docx$/i', $row['stored_name'])) {
        $res = restamp_docx($path, $row['search_code']);
        if (!$res['ok']) {
            echo "FAIL id={$row['id']} №{$row['document_number']} {$res['error']}\n";
            $stats['docx_fail']++;
        } else {
            $sz = (int)$res['size'];
            $upd->execute([$sz, $row['fid']]);
            echo "OK id={$row['id']} №{$row['document_number']} {$row['search_code']} size={$sz}\n";
            $stats['docx_ok']++;
            if ($sz !== (int)$row['file_size']) {
                $stats['size_fixed']++;
            }
        }
    } else {
        $sz = filesize($path);
        if ($sz !== false) {
            $upd->execute([(int)$sz, $row['fid']]);
            if ((int)$sz !== (int)$row['file_size']) {
                $stats['size_fixed']++;
                echo "SIZE id={$row['id']} {$row['file_size']}->{$sz}\n";
            } else {
                $stats['skip']++;
            }
        }
    }

    if (!empty($row['pdf_stored_name'])) {
        $pdf = resolve_path($storage, $row['pdf_stored_name']);
        if ($pdf !== null) {
            $psz = filesize($pdf);
            if ($psz !== false) {
                $updPdf->execute([(int)$psz, $row['fid']]);
                if ((int)$row['pdf_file_size'] !== (int)$psz) {
                    $stats['pdf_size_fixed']++;
                    echo "PDFSIZE id={$row['id']} {$row['pdf_file_size']}->{$psz}\n";
                }
            }
        }
    }
}

echo "\nSUMMARY " . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
echo "Delete this installer after use.\n";
