<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PDO;
use Throwable;

/**
 * Automates MySQL database backup, gzip compression, optional AES-256 encryption,
 * and direct streaming to Cloudflare R2 storage with automated snapshot retention.
 */
class BackupDatabaseToR2Command extends Command
{
    protected $signature = 'backup:database-r2
                            {--retention=30 : Number of daily backup snapshots to retain}
                            {--no-encrypt : Skip AES-256-CBC encryption of backup file}';

    protected $description = 'Dump, compress, encrypt, and upload database snapshot directly to Cloudflare R2';

    public function handle(): int
    {
        $this->info('Starting database backup to Cloudflare R2...');

        $dbConnection = (string) Config::get('database.default', 'mysql');
        $dbConfig = Config::get("database.connections.{$dbConnection}", []);

        $timestamp = date('Y-m-d_His');
        $rawDbName = (string) ($dbConfig['database'] ?? 'betplus');
        $databaseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($rawDbName, PATHINFO_FILENAME));

        try {
            // 1. Generate SQL dump
            $this->comment('Dumping database tables...');
            $sqlContent = $this->dumpDatabase($dbConnection, $dbConfig);

            if (empty($sqlContent)) {
                $this->error('Database dump produced empty content. Aborting backup.');
                return self::FAILURE;
            }

            $uncompressedSize = strlen($sqlContent);
            $this->comment("Dump completed ({$uncompressedSize} bytes). Compressing with gzip...");

            // 2. Compress using gzip level 9
            $compressedData = gzencode($sqlContent, 9);
            if ($compressedData === false) {
                $this->error('Failed to compress SQL dump with gzip.');
                return self::FAILURE;
            }
            $compressedSize = strlen($compressedData);
            $this->comment("Compression completed ({$compressedSize} bytes).");

            // 3. Optional AES-256-CBC Encryption
            $shouldEncrypt = !$this->option('no-encrypt')
                && (bool) Config::get('services.cloudflare.r2_backup_encrypt', true);

            $finalData = $compressedData;
            $extension = 'sql.gz';

            if ($shouldEncrypt) {
                $this->comment('Encrypting backup with AES-256-CBC...');
                $encryptionKey = (string) (env('BACKUP_ENCRYPTION_KEY') ?: Config::get('app.key'));
                $encrypted = $this->encryptData($compressedData, $encryptionKey);
                if ($encrypted === null) {
                    $this->error('Failed to encrypt backup. Aborting.');
                    return self::FAILURE;
                }
                $finalData = $encrypted;
                $extension = 'sql.gz.enc';
            }

            // 4. Upload directly to Cloudflare R2 disk
            $filename = "backups/{$databaseName}_{$timestamp}.{$extension}";
            $diskName = (string) Config::get('services.cloudflare.r2_backup_disk', 'r2');
            $this->comment("Streaming snapshot to disk [{$diskName}] -> {$filename}...");

            $r2Disk = Storage::disk($diskName);
            $uploaded = $r2Disk->put($filename, $finalData);

            if (!$uploaded) {
                $this->error("Failed to upload {$filename} to Cloudflare R2.");
                Log::error('Cloudflare R2 database backup upload failed', ['filename' => $filename]);
                return self::FAILURE;
            }

            $this->info("Successfully uploaded backup to Cloudflare R2: {$filename} (" . strlen($finalData) . " bytes)");
            Log::info('Cloudflare R2 database backup completed successfully', [
                'file' => $filename,
                'bytes' => strlen($finalData),
                'encrypted' => $shouldEncrypt,
            ]);

            // 5. Apply Retention Policy
            $retention = (int) $this->option('retention');
            $this->applyRetentionPolicy($r2Disk, $retention);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Backup exception: ' . $e->getMessage());
            Log::error('Database backup to Cloudflare R2 failed with exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Dumps database using mysqldump if available or fallback to PDO exporter.
     */
    private function dumpDatabase(string $connection, array $config): string
    {
        $driver = $config['driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            $dbPath = (string) ($config['database'] ?? '');
            if ($dbPath !== '' && $dbPath !== ':memory:' && file_exists($dbPath)) {
                $content = (string) file_get_contents($dbPath);
                if (!empty($content)) {
                    return $content;
                }
            }
            return $this->dumpViaPdo('sqlite');
        }

        // Try mysqldump command line tool if available
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        $mysqldumpPath = $this->findMysqldump();

        if ($mysqldumpPath !== null) {
            $cmd = sprintf(
                '%s --host=%s --port=%s --user=%s --password=%s --single-transaction --quick --skip-lock-tables %s 2>/dev/null',
                escapeshellcmd($mysqldumpPath),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($database),
            );

            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);

            if ($exitCode === 0 && !empty($output)) {
                return implode("\n", $output);
            }
        }

        // PDO Fallback Dumper for environments without mysqldump binary
        return $this->dumpViaPdo('mysql');
    }

    /**
     * Portable PDO-based database dumper (guarantees backup works in any PHP environment).
     */
    private function dumpViaPdo(string $driver = 'mysql'): string
    {
        /** @var PDO $pdo */
        $pdo = DB::connection()->getPdo();

        if ($driver === 'sqlite') {
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        }

        $buffer = "-- Betplus Portable Database Backup\n";
        $buffer .= "-- Generated: " . date('c') . "\n";
        if ($driver !== 'sqlite') {
            $buffer .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        }

        foreach ($tables as $table) {
            $table = (string) $table;
            if ($driver === 'sqlite') {
                $createRow = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name = " . $pdo->quote($table))->fetch(PDO::FETCH_NUM);
                $createSql = $createRow[0] ?? null;
            } else {
                $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
                $createSql = $createRow[1] ?? null;
            }

            if (!empty($createSql)) {
                $buffer .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $buffer .= $createSql . ";\n\n";
            }

            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                continue;
            }

            $columns = array_keys($rows[0]);
            $quotedColumns = array_map(fn ($col) => "`{$col}`", $columns);
            $colList = implode(', ', $quotedColumns);

            foreach (array_chunk($rows, 100) as $chunk) {
                $valueRows = [];
                foreach ($chunk as $row) {
                    $escaped = array_map(function ($val) use ($pdo) {
                        return $val === null ? 'NULL' : $pdo->quote((string) $val);
                    }, $row);
                    $valueRows[] = '(' . implode(', ', $escaped) . ')';
                }
                $buffer .= "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $valueRows) . ";\n";
            }
            $buffer .= "\n";
        }

        if ($driver !== 'sqlite') {
            $buffer .= "SET FOREIGN_KEY_CHECKS=1;\n";
        }
        return $buffer;
    }

    private function findMysqldump(): ?string
    {
        $candidates = [
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
        ];

        foreach ($candidates as $bin) {
            if (DIRECTORY_SEPARATOR === '/') {
                $check = exec("command -v {$bin} 2>/dev/null");
                if (!empty($check)) {
                    return $check;
                }
            }
        }
        return null;
    }

    private function encryptData(string $data, string $key): ?string
    {
        try {
            // Normalize key to 32 bytes using SHA-256
            $derivedKey = hash('sha256', $key, true);
            $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
            $encrypted = openssl_encrypt($data, 'aes-256-cbc', $derivedKey, OPENSSL_RAW_DATA, $iv);

            if ($encrypted === false) {
                return null;
            }

            // Prepend IV to ciphertext (16 bytes IV + ciphertext)
            return $iv . $encrypted;
        } catch (Throwable) {
            return null;
        }
    }

    private function applyRetentionPolicy($r2Disk, int $retention): void
    {
        try {
            $files = $r2Disk->files('backups');
            if (count($files) <= $retention) {
                return;
            }

            // Sort files by name (which begins with ISO timestamp) ascending
            sort($files);
            $toDeleteCount = count($files) - $retention;
            $filesToDelete = array_slice($files, 0, $toDeleteCount);

            $this->comment("Pruning {$toDeleteCount} backup(s) exceeding retention limit of {$retention}...");
            $r2Disk->delete($filesToDelete);
            $this->info("Pruned old backups: " . implode(', ', $filesToDelete));
        } catch (Throwable $e) {
            Log::warning('Failed to prune old Cloudflare R2 backups', ['error' => $e->getMessage()]);
        }
    }
}
