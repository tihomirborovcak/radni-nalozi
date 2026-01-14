<?php
/**
 * Backup skripta - baza + fajlovi
 * Sprema na server i nudi download
 */

require_once __DIR__ . '/config.php';

$backupDir = __DIR__ . '/backups';

// Helper funkcije
function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

function copyDirectory($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst, 0755, true);
    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            if (is_dir("{$src}/{$file}")) {
                copyDirectory("{$src}/{$file}", "{$dst}/{$file}");
            } else {
                copy("{$src}/{$file}", "{$dst}/{$file}");
            }
        }
    }
    closedir($dir);
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = "{$dir}/{$file}";
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

function addFolderToZip($zip, $folder, $zipPath) {
    $files = scandir($folder);
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') continue;

        $filePath = "{$folder}/{$file}";
        $zipFilePath = $zipPath ? "{$zipPath}/{$file}" : $file;

        if (is_dir($filePath)) {
            $zip->addEmptyDir($zipFilePath);
            addFolderToZip($zip, $filePath, $zipFilePath);
        } else {
            $zip->addFile($filePath, $zipFilePath);
        }
    }
}

// Download handler - bez auth (link se otvara u novom tabu)
if (isset($_GET['download'])) {
    $filename = basename($_GET['download']);
    $filepath = "{$backupDir}/{$filename}";

    if (file_exists($filepath) && pathinfo($filename, PATHINFO_EXTENSION) === 'zip') {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        http_response_code(404);
        die('Backup not found');
    }
}

// Auth provjera za ostale operacije
$userId = isset($_SERVER['HTTP_X_USER_ID']) ? (int)$_SERVER['HTTP_X_USER_ID'] : null;

if (!$userId) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

$db = getDB();
$stmt = $db->prepare("SELECT uloga FROM korisnici WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || $user['uloga'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['error' => 'Samo admin može raditi backup']));
}

// GET - lista svih backupa
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');

    if (!is_dir($backupDir)) {
        echo json_encode([]);
        exit;
    }

    $backups = glob("{$backupDir}/backup_*.zip");
    usort($backups, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $result = array_map(function($file) {
        $filename = basename($file);
        $size = filesize($file);
        $timestamp = filemtime($file);

        return [
            'filename' => $filename,
            'size' => $size,
            'sizeFormatted' => formatBytes($size),
            'date' => date('d.m.Y H:i', $timestamp),
            'timestamp' => $timestamp
        ];
    }, $backups);

    echo json_encode($result);
    exit;
}

// POST - kreiraj backup
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Kreiraj backup folder ako ne postoji
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    // Generiraj ime backupa
    $timestamp = date('Y-m-d_H-i-s');
    $backupName = "backup_{$timestamp}";
    $backupPath = "{$backupDir}/{$backupName}";

    // Kreiraj privremeni folder za backup
    $tempDir = "{$backupPath}_temp";
    mkdir($tempDir, 0755, true);

    try {
        // 1. BACKUP BAZE
        $sqlFile = "{$tempDir}/baza.sql";

        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $sql = "-- Backup baze: " . DB_NAME . "\n";
        $sql .= "-- Datum: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- ========================================\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $createTable = $db->query("SHOW CREATE TABLE `{$table}`")->fetch();
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $createTable['Create Table'] . ";\n\n";

            $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 0) {
                $columns = array_keys($rows[0]);
                $columnList = '`' . implode('`, `', $columns) . '`';

                foreach ($rows as $row) {
                    $values = array_map(function($val) use ($db) {
                        if ($val === null) return 'NULL';
                        return $db->quote($val);
                    }, array_values($row));
                    $sql .= "INSERT INTO `{$table}` ({$columnList}) VALUES (" . implode(', ', $values) . ");\n";
                }
                $sql .= "\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        file_put_contents($sqlFile, $sql);

        // 2. BACKUP UPLOADS FOLDERA
        $uploadsDir = __DIR__ . '/uploads';
        $uploadsBackupDir = "{$tempDir}/uploads";

        if (is_dir($uploadsDir)) {
            copyDirectory($uploadsDir, $uploadsBackupDir);
        }

        // 3. BACKUP APLIKACIJSKIH FAJLOVA
        $appBackupDir = "{$tempDir}/app";
        mkdir($appBackupDir, 0755, true);

        $skipItems = ['uploads', 'backups', '.git', '.claude', 'config.php', 'nul'];
        $skipPatterns = ['tmpclaude-'];

        $items = scandir(__DIR__);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (in_array($item, $skipItems)) continue;

            $skip = false;
            foreach ($skipPatterns as $pattern) {
                if (strpos($item, $pattern) === 0) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            $sourcePath = __DIR__ . '/' . $item;
            $destPath = $appBackupDir . '/' . $item;

            if (is_dir($sourcePath)) {
                copyDirectory($sourcePath, $destPath);
            } else {
                copy($sourcePath, $destPath);
            }
        }

        // 4. KREIRAJ ZIP
        $zipFile = "{$backupPath}.zip";
        $zip = new ZipArchive();

        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Ne mogu kreirati ZIP fajl");
        }

        addFolderToZip($zip, $tempDir, '');
        $zip->close();

        // 5. OBRIŠI TEMP FOLDER
        deleteDirectory($tempDir);

        // 6. OBRIŠI STARE BACKUPE (zadrži zadnjih 10)
        $backups = glob("{$backupDir}/backup_*.zip");
        usort($backups, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $toDelete = array_slice($backups, 10);
        foreach ($toDelete as $oldBackup) {
            unlink($oldBackup);
        }

        // Vrati info o backupu
        $fileSize = filesize($zipFile);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'filename' => "{$backupName}.zip",
            'path' => "backups/{$backupName}.zip",
            'size' => $fileSize,
            'sizeFormatted' => formatBytes($fileSize),
            'timestamp' => $timestamp
        ]);

    } catch (Exception $e) {
        if (is_dir($tempDir)) {
            deleteDirectory($tempDir);
        }

        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Nepoznata metoda
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>
