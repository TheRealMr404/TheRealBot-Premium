<?php

$isDockerBackup = getenv('MIRZA_DOCKER_INSTANCE') !== false;
if ($isDockerBackup && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

chdir(dirname(__DIR__));
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../botapi.php';

$instance = preg_replace('/[^a-z0-9-]/', '', (string) (getenv('MIRZA_DOCKER_INSTANCE') ?: 'main'));
$lock = fopen(sys_get_temp_dir() . '/mirza-backup-' . $instance . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

$workDir = sys_get_temp_dir() . '/mirza-backup-' . bin2hex(random_bytes(8));
if (!mkdir($workDir, 0700)) {
    error_log('Unable to create the backup workspace.');
    exit(1);
}

$failed = false;
$chatId = '';
try {
    $setting = select('setting', '*');
    $chatId = trim((string) ($setting['Channel_Report'] ?? ''));
    $topic = select('topicid', 'idreport', 'report', 'backupfile', 'select');
    $threadId = (int) ($topic['idreport'] ?? 0);
    $sendReport = static function (array $data) use ($chatId, $threadId) {
        if ($chatId === '') return ['ok' => false];
        $data['chat_id'] = $chatId;
        if ($threadId > 0) $data['message_thread_id'] = $threadId;
        return telegram(isset($data['document']) ? 'sendDocument' : 'sendMessage', $data);
    };

    $dumpBinary = is_executable('/usr/bin/mysqldump') ? '/usr/bin/mysqldump' : '/usr/bin/mariadb-dump';
    if (!is_executable($dumpBinary)) throw new RuntimeException('Database dump client is unavailable.');
    $sqlPath = $workDir . '/database.sql';
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['MYSQL_PWD'] = (string) $passworddb;
    $process = proc_open(
        [$dumpBinary, '--host=' . ($dbhost ?: 'localhost'), '--user=' . $usernamedb, '--single-transaction', '--quick', '--no-tablespaces', $dbname],
        [0 => ['pipe', 'r'], 1 => ['file', $sqlPath, 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workDir,
        $environment
    );
    if (!is_resource($process)) throw new RuntimeException('Unable to start database dump.');
    fclose($pipes[0]);
    $dumpError = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_file($sqlPath) || filesize($sqlPath) === 0) {
        error_log('Automatic database dump failed: ' . substr(trim((string) $dumpError), 0, 500));
        throw new RuntimeException('Database dump failed.');
    }

    $archiveDir = $isDockerBackup ? '/var/backups/therealbot/auto' : $workDir;
    if (!is_dir($archiveDir) && !mkdir($archiveDir, 0700, true)) {
        throw new RuntimeException('Backup storage is not writable.');
    }
    $archivePath = $archiveDir . '/backup_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Unable to create backup archive.');
    }
    $added = $zip->addFile($sqlPath, 'database.sql');
    $encrypted = $added && $zip->setEncryptionName('database.sql', ZipArchive::EM_AES_256, 'mirzapro2026#$');
    $closed = $zip->close();
    if (!$added || !$encrypted || !$closed) {
        @unlink($archivePath);
        throw new RuntimeException('Unable to encrypt backup archive.');
    }
    @chmod($archivePath, 0600);

    if ($chatId !== '') {
        $sent = $sendReport([
            'document' => new CURLFile($archivePath),
            'caption' => 'خروجی دیتابیس ربات - ' . date('Y-m-d H:i'),
        ]);
        if (empty($sent['ok'])) error_log('Backup saved locally but Telegram delivery failed.');
    }

    if ($isDockerBackup) {
        $archives = glob($archiveDir . '/backup_*.zip') ?: [];
        usort($archives, static function ($a, $b) { return filemtime($b) <=> filemtime($a); });
        foreach (array_slice($archives, 14) as $oldArchive) @unlink($oldArchive);
    }

    $bots = select('botsaz', '*', null, null, 'fetchAll');
    $resellerRoot = realpath(__DIR__ . '/../vpnbot');
    if ($chatId !== '' && $resellerRoot && is_array($bots)) {
        foreach ($bots as $bot) {
            $folderName = (string) ($bot['id_user'] ?? '') . (string) ($bot['username'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $folderName)) continue;
            $folder = realpath($resellerRoot . '/' . $folderName);
            if (!$folder || strpos($folder . DIRECTORY_SEPARATOR, $resellerRoot . DIRECTORY_SEPARATOR) !== 0) continue;
            $resellerArchive = $workDir . '/reseller_' . bin2hex(random_bytes(5)) . '.zip';
            $resellerZip = new ZipArchive();
            if ($resellerZip->open($resellerArchive, ZipArchive::CREATE) !== true) continue;
            $filesAdded = 0;
            foreach (['product.json', 'product_name.json'] as $fileName) {
                $filePath = $folder . '/' . $fileName;
                if (is_file($filePath) && !is_link($filePath)) {
                    $filesAdded += (int) $resellerZip->addFile($filePath, $fileName);
                }
            }
            if (is_dir($folder . '/data')) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder . '/data', FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if (!$file->isFile() || $file->isLink()) continue;
                    $relative = substr($file->getPathname(), strlen($folder) + 1);
                    $filesAdded += (int) $resellerZip->addFile($file->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', $relative));
                }
            }
            $resellerZip->close();
            if ($filesAdded > 0) {
                $sendReport([
                    'document' => new CURLFile($resellerArchive),
                    'caption' => '@' . $bot['username'] . ' | ' . $bot['id_user'],
                ]);
            }
            @unlink($resellerArchive);
        }
    }
} catch (Throwable $e) {
    $failed = true;
    error_log('Automatic backup failed: ' . $e->getMessage());
    if ($chatId !== '' && isset($sendReport)) {
        $sendReport(['text' => 'بکاپ خودکار انجام نشد؛ وضعیت سرویس بکاپ سرور را بررسی کنید.']);
    }
} finally {
    foreach (glob($workDir . '/*') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    @rmdir($workDir);
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($failed) exit(1);
