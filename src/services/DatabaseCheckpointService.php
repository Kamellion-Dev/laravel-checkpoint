<?php

namespace KamellionDev\LaravelCheckpoint\Services;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use stdClass;
use Symfony\Component\Finder\SplFileInfo;

class DatabaseCheckpointService
{
    public function save(?string $comment = null): array
    {
        $checkpoint = $this->createCheckpointDirectory();
        $metadata = $this->checkpointMetadata($checkpoint['name']);
        $normalizedComment = $this->normalizeComment($comment);

        $artifactPath = match ($metadata['driver']) {
            'mysql', 'mariadb' => $this->saveMysqlCheckpoint($checkpoint['path'], $metadata),
            'sqlite' => $this->saveSqliteCheckpoint($checkpoint['path'], $metadata),
            default => throw new RuntimeException("Database driver [{$metadata['driver']}] is not supported for checkpoints."),
        };

        $filesystems = $this->saveFilesystemCheckpoint($checkpoint['path']);
        $commentFile = $this->writeCommentFile($checkpoint['path'], $normalizedComment);

        File::put(
            $checkpoint['path'].DIRECTORY_SEPARATOR.'metadata.json',
            json_encode([
                ...$metadata,
                'artifact' => basename($artifactPath),
                'filesystems' => $filesystems,
                'comment' => $normalizedComment,
                'comment_file' => $commentFile,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        return [
            'name' => $checkpoint['name'],
            'path' => $checkpoint['path'],
            'artifact' => $artifactPath,
            'filesystems' => $filesystems,
            'comment' => $normalizedComment,
            'comment_file' => $commentFile,
            'size_bytes' => $this->directorySize($checkpoint['path']),
        ];
    }

    public function load(?string $name = null): array
    {
        $checkpointPath = $this->resolveCheckpointPath($name);
        $metadata = $this->readMetadata($checkpointPath);
        $artifactPath = $checkpointPath.DIRECTORY_SEPARATOR.$metadata['artifact'];
        $runtimeState = $this->captureRuntimeState();

        if (! File::exists($artifactPath)) {
            throw new FileNotFoundException("Checkpoint artifact not found at [{$artifactPath}].");
        }

        $currentDriver = $this->driver();

        if ($metadata['driver'] !== $currentDriver) {
            throw new RuntimeException("Checkpoint driver [{$metadata['driver']}] does not match current connection driver [{$currentDriver}].");
        }

        match ($currentDriver) {
            'mysql', 'mariadb' => $this->loadMysqlCheckpoint($artifactPath),
            'sqlite' => $this->loadSqliteCheckpoint($artifactPath),
            default => throw new RuntimeException("Database driver [{$currentDriver}] is not supported for checkpoints."),
        };

        $this->restoreRuntimeState($runtimeState);
        $this->loadFilesystemCheckpoint($checkpointPath, $metadata);

        DB::purge();

        return [
            'name' => basename($checkpointPath),
            'path' => $checkpointPath,
            'artifact' => $artifactPath,
            'filesystems' => $metadata['filesystems'] ?? [],
        ];
    }

    public function clearCheckpoints(): array
    {
        $rootPath = $this->rootPath();
        $deletedCheckpoints = 0;

        if (File::isDirectory($rootPath)) {
            $deletedCheckpoints = count(File::directories($rootPath));
            File::deleteDirectory($rootPath);
        }

        File::ensureDirectoryExists($rootPath);

        return [
            'path' => $rootPath,
            'deleted_checkpoints' => $deletedCheckpoints,
        ];
    }

    public function latestCheckpointName(): ?string
    {
        $directories = collect($this->checkpointDirectories())
            ->map(static fn (string $path): string => basename($path))
            ->sortDesc()
            ->values();

        return $directories->first();
    }

    /**
     * @return list<array{name: string, path: string, comment: ?string, display_name: string, created_at: ?string}>
     */
    public function listCheckpoints(): array
    {
        return collect($this->checkpointDirectories())
            ->sortDesc()
            ->values()
            ->map(function (string $path): array {
                $name = basename($path);
                $metadata = $this->safeReadMetadata($path);
                $comment = $this->commentFromMetadata($metadata) ?? $this->commentFromDirectory($path);

                return [
                    'name' => $name,
                    'path' => $path,
                    'comment' => $comment,
                    'display_name' => $comment ?? $name,
                    'created_at' => is_array($metadata) ? ($metadata['created_at'] ?? null) : null,
                ];
            })
            ->all();
    }

    public function rootPath(): string
    {
        return base_path('.dev-checkpoint');
    }

    /**
     * @return list<string>
     */
    private function checkpointDirectories(): array
    {
        if (! File::isDirectory($this->rootPath())) {
            return [];
        }

        return File::directories($this->rootPath());
    }

    private function createCheckpointDirectory(): array
    {
        File::ensureDirectoryExists($this->rootPath());

        $baseName = now()->format('Ymd_His');
        $name = $baseName;
        $suffix = 1;

        while (File::exists($this->rootPath().DIRECTORY_SEPARATOR.$name)) {
            $name = $baseName.'_'.$suffix;
            $suffix++;
        }

        $path = $this->rootPath().DIRECTORY_SEPARATOR.$name;

        File::makeDirectory($path, 0755, true);

        return [
            'name' => $name,
            'path' => $path,
        ];
    }

    private function checkpointMetadata(string $name): array
    {
        $connectionName = config('database.default');
        $config = config("database.connections.{$connectionName}");

        if (! is_array($config)) {
            throw new RuntimeException("Database connection [{$connectionName}] is not configured.");
        }

        return [
            'name' => $name,
            'connection' => $connectionName,
            'driver' => (string) ($config['driver'] ?? ''),
            'database' => (string) ($config['database'] ?? ''),
            'created_at' => now()->toIso8601String(),
        ];
    }

    private function normalizeComment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $comment) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    private function writeCommentFile(string $checkpointPath, ?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }

        $fileName = $this->commentFileName($comment);
        File::put($checkpointPath.DIRECTORY_SEPARATOR.$fileName, $comment.PHP_EOL);

        return $fileName;
    }

    private function commentFileName(string $comment): string
    {
        $sanitized = preg_replace('/[\\\\\/:*?"<>|]/', '-', $comment) ?? $comment;
        $sanitized = trim($sanitized, ". \t\n\r\0\x0B");

        if ($sanitized === '') {
            $sanitized = 'checkpoint';
        }

        return $sanitized.'.comment';
    }

    private function safeReadMetadata(string $checkpointPath): ?array
    {
        try {
            return $this->readMetadata($checkpointPath);
        } catch (RuntimeException | FileNotFoundException | JsonException) {
            return null;
        }
    }

    private function commentFromMetadata(?array $metadata): ?string
    {
        if (! is_array($metadata)) {
            return null;
        }

        $comment = $metadata['comment'] ?? null;

        return is_string($comment) && trim($comment) !== '' ? $comment : null;
    }

    private function commentFromDirectory(string $checkpointPath): ?string
    {
        $commentFiles = File::glob($checkpointPath.DIRECTORY_SEPARATOR.'*.comment');

        if ($commentFiles === false || $commentFiles === []) {
            return null;
        }

        $commentFile = basename($commentFiles[0]);

        return pathinfo($commentFile, PATHINFO_FILENAME);
    }

    private function saveMysqlCheckpoint(string $checkpointPath, array $metadata): string
    {
        $tablesDirectory = $checkpointPath.DIRECTORY_SEPARATOR.'tables';
        $manifestPath = $checkpointPath.DIRECTORY_SEPARATOR.'manifest.json';

        File::ensureDirectoryExists($tablesDirectory);

        $manifest = [
            'database' => $metadata['database'],
            'driver' => $metadata['driver'],
            'tables' => [],
            'views' => [],
            'routines' => [],
            'triggers' => [],
            'events' => [],
        ];

        foreach ($this->mysqlTables($metadata['database']) as $table) {
            $dataFileName = $table.'.jsonl';
            $dataFilePath = $tablesDirectory.DIRECTORY_SEPARATOR.$dataFileName;

            $this->writeMysqlTableData($table, $dataFilePath);

            $manifest['tables'][] = [
                'name' => $table,
                'schema' => $this->mysqlCreateStatement('TABLE', $table),
                'data_file' => 'tables/'.$dataFileName,
            ];
        }

        foreach ($this->mysqlViews($metadata['database']) as $view) {
            $manifest['views'][] = [
                'name' => $view,
                'schema' => $this->mysqlCreateStatement('VIEW', $view),
            ];
        }

        foreach ($this->mysqlRoutines($metadata['database']) as $routine) {
            $manifest['routines'][] = [
                'name' => $routine['name'],
                'type' => $routine['type'],
                'schema' => $this->mysqlCreateStatement($routine['type'], $routine['name']),
            ];
        }

        foreach ($this->mysqlTriggers($metadata['database']) as $trigger) {
            $manifest['triggers'][] = [
                'name' => $trigger,
                'schema' => $this->mysqlCreateStatement('TRIGGER', $trigger),
            ];
        }

        foreach ($this->mysqlEvents($metadata['database']) as $event) {
            $manifest['events'][] = [
                'name' => $event,
                'schema' => $this->mysqlCreateStatement('EVENT', $event),
            ];
        }

        File::put(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        return $manifestPath;
    }

    private function saveSqliteCheckpoint(string $checkpointPath, array $metadata): string
    {
        $databasePath = $metadata['database'];

        if ($databasePath === '' || ! File::exists($databasePath)) {
            throw new FileNotFoundException("SQLite database file not found at [{$databasePath}].");
        }

        $artifactPath = $checkpointPath.DIRECTORY_SEPARATOR.'database.sqlite';

        DB::disconnect();
        File::copy($databasePath, $artifactPath);

        return $artifactPath;
    }

    private function loadMysqlCheckpoint(string $artifactPath): void
    {
        $manifest = json_decode((string) File::get($artifactPath), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($manifest)) {
            throw new RuntimeException("Checkpoint manifest at [{$artifactPath}] is invalid.");
        }

        $this->wipeMysqlDatabase((string) ($manifest['database'] ?? $this->connectionConfig()['database'] ?? ''));

        DB::reconnect();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($manifest['tables'] ?? [] as $table) {
                DB::unprepared((string) $table['schema']);
            }

            foreach ($manifest['tables'] ?? [] as $table) {
                $dataFile = dirname($artifactPath).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $table['data_file']);
                $this->loadMysqlTableData((string) $table['name'], $dataFile);
            }

            foreach ($manifest['views'] ?? [] as $view) {
                DB::unprepared((string) $view['schema']);
            }

            foreach ($manifest['routines'] ?? [] as $routine) {
                DB::unprepared((string) $routine['schema']);
            }

            foreach ($manifest['triggers'] ?? [] as $trigger) {
                DB::unprepared((string) $trigger['schema']);
            }

            foreach ($manifest['events'] ?? [] as $event) {
                DB::unprepared((string) $event['schema']);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function loadSqliteCheckpoint(string $artifactPath): void
    {
        $databasePath = (string) ($this->connectionConfig()['database'] ?? '');

        if ($databasePath === '') {
            throw new RuntimeException('SQLite database path is not configured.');
        }

        DB::disconnect();
        File::ensureDirectoryExists(dirname($databasePath));
        File::copy($artifactPath, $databasePath);
    }

    private function wipeMysqlDatabase(string $database): void
    {
        DB::reconnect();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($this->mysqlViews($database) as $view) {
                DB::unprepared('DROP VIEW IF EXISTS '.$this->wrapMysqlIdentifier($view));
            }

            foreach ($this->mysqlTables($database) as $table) {
                DB::unprepared('DROP TABLE IF EXISTS '.$this->wrapMysqlIdentifier($table));
            }

            foreach ($this->mysqlRoutines($database) as $routine) {
                DB::unprepared('DROP '.$routine['type'].' IF EXISTS '.$this->wrapMysqlIdentifier($routine['name']));
            }

            foreach ($this->mysqlTriggers($database) as $trigger) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$this->wrapMysqlIdentifier($trigger));
            }

            foreach ($this->mysqlEvents($database) as $event) {
                DB::unprepared('DROP EVENT IF EXISTS '.$this->wrapMysqlIdentifier($event));
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * @return list<string>
     */
    private function mysqlViews(string $database): array
    {
        return collect(DB::select(
            'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?',
            [$database]
        ))
            ->map(static fn (stdClass $view): string => (string) $view->TABLE_NAME)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function mysqlTables(string $database): array
    {
        return collect(DB::select(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
            [$database]
        ))
            ->map(static fn (stdClass $table): string => (string) $table->TABLE_NAME)
            ->all();
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    private function mysqlRoutines(string $database): array
    {
        return collect(DB::select(
            'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?',
            [$database]
        ))
            ->map(static fn (stdClass $routine): array => [
                'name' => (string) $routine->ROUTINE_NAME,
                'type' => strtoupper((string) $routine->ROUTINE_TYPE),
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function mysqlEvents(string $database): array
    {
        return collect(DB::select(
            'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?',
            [$database]
        ))
            ->map(static fn (stdClass $event): string => (string) $event->EVENT_NAME)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function mysqlTriggers(string $database): array
    {
        return collect(DB::select(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?',
            [$database]
        ))
            ->map(static fn (stdClass $trigger): string => (string) $trigger->TRIGGER_NAME)
            ->all();
    }

    private function mysqlCreateStatement(string $type, string $name): string
    {
        $statement = match ($type) {
            'TABLE' => 'SHOW CREATE TABLE '.$this->wrapMysqlIdentifier($name),
            'VIEW' => 'SHOW CREATE VIEW '.$this->wrapMysqlIdentifier($name),
            'TRIGGER' => 'SHOW CREATE TRIGGER '.$this->wrapMysqlIdentifier($name),
            'PROCEDURE', 'FUNCTION' => 'SHOW CREATE '.$type.' '.$this->wrapMysqlIdentifier($name),
            'EVENT' => 'SHOW CREATE EVENT '.$this->wrapMysqlIdentifier($name),
            default => throw new RuntimeException("Unsupported MySQL schema export type [{$type}]."),
        };

        $result = DB::selectOne($statement);

        if (! $result instanceof stdClass) {
            throw new RuntimeException("Could not read schema for [{$type}] [{$name}].");
        }

        return $this->extractCreateStatement($result);
    }

    private function extractCreateStatement(stdClass $result): string
    {
        $values = (array) $result;

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'Create ')) {
                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        throw new RuntimeException('Could not extract the CREATE statement from schema metadata.');
    }

    private function writeMysqlTableData(string $table, string $path): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to create checkpoint data file [{$path}].");
        }

        try {
            foreach (DB::table($table)->cursor() as $row) {
                fwrite($handle, $this->encodeJsonLine((array) $row).PHP_EOL);
            }
        } finally {
            fclose($handle);
        }
    }

    private function loadMysqlTableData(string $table, string $path): void
    {
        if (! File::exists($path)) {
            throw new FileNotFoundException("Checkpoint table data not found at [{$path}].");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read checkpoint data file [{$path}].");
        }

        $batch = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($decoded)) {
                    throw new RuntimeException("Invalid row payload found in [{$path}].");
                }

                $batch[] = $decoded;

                if (count($batch) >= 500) {
                    DB::table($table)->insert($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                DB::table($table)->insert($batch);
            }
        } finally {
            fclose($handle);
        }
    }

    private function resolveCheckpointPath(?string $name): string
    {
        $checkpointName = $name ?? $this->latestCheckpointName();

        if ($checkpointName === null || $checkpointName === '') {
            throw new RuntimeException('No checkpoints were found in .dev-checkpoint.');
        }

        $checkpointPath = $this->rootPath().DIRECTORY_SEPARATOR.$checkpointName;

        if (! File::isDirectory($checkpointPath)) {
            throw new RuntimeException("Checkpoint [{$checkpointName}] does not exist.");
        }

        return $checkpointPath;
    }

    /**
     * @return list<array{disk: string, root: string, snapshot: string}>
     */
    private function saveFilesystemCheckpoint(string $checkpointPath): array
    {
        $filesystemRoot = $checkpointPath.DIRECTORY_SEPARATOR.'filesystems';
        File::ensureDirectoryExists($filesystemRoot);

        $snapshots = [];

        foreach ($this->checkpointableDisks() as $disk) {
            $snapshotRelativePath = 'filesystems/'.$disk['name'];
            $snapshotPath = $checkpointPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $snapshotRelativePath);

            File::ensureDirectoryExists($snapshotPath);

            if (File::isDirectory($disk['root'])) {
                File::copyDirectory($disk['root'], $snapshotPath);
            }

            $snapshots[] = [
                'disk' => $disk['name'],
                'root' => $disk['root'],
                'snapshot' => $snapshotRelativePath,
            ];
        }

        return $snapshots;
    }

    private function loadFilesystemCheckpoint(string $checkpointPath, array $metadata): void
    {
        $filesystems = $metadata['filesystems'] ?? [];

        if (! is_array($filesystems)) {
            return;
        }

        foreach ($filesystems as $filesystem) {
            if (! is_array($filesystem)) {
                continue;
            }

            $root = (string) ($filesystem['root'] ?? '');
            $snapshot = (string) ($filesystem['snapshot'] ?? '');

            if ($root === '' || $snapshot === '') {
                continue;
            }

            $snapshotPath = $checkpointPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $snapshot);

            if (File::exists($root)) {
                File::deleteDirectory($root);
            }

            File::ensureDirectoryExists($root);

            if (File::isDirectory($snapshotPath)) {
                File::copyDirectory($snapshotPath, $root);
            }
        }
    }

    private function readMetadata(string $checkpointPath): array
    {
        $metadataPath = $checkpointPath.DIRECTORY_SEPARATOR.'metadata.json';

        if (! File::exists($metadataPath)) {
            throw new FileNotFoundException("Checkpoint metadata not found at [{$metadataPath}].");
        }

        $metadata = json_decode((string) File::get($metadataPath), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($metadata)) {
            throw new RuntimeException("Checkpoint metadata at [{$metadataPath}] is invalid.");
        }

        return $metadata;
    }

    /**
     * @return array{database_sessions?: array{connection: string, table: string, rows: list<array<string, mixed>>}}
     */
    private function captureRuntimeState(): array
    {
        $state = [];

        $databaseSessions = $this->captureDatabaseSessions();

        if ($databaseSessions !== null) {
            $state['database_sessions'] = $databaseSessions;
        }

        return $state;
    }

    /**
     * @param  array{database_sessions?: array{connection: string, table: string, rows: list<array<string, mixed>>}}  $state
     */
    private function restoreRuntimeState(array $state): void
    {
        $databaseSessions = $state['database_sessions'] ?? null;

        if (is_array($databaseSessions)) {
            $this->restoreDatabaseSessions($databaseSessions);
        }
    }

    /**
     * @return array{connection: string, table: string, rows: list<array<string, mixed>>}|null
     */
    private function captureDatabaseSessions(): ?array
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        $connectionName = (string) (config('session.connection') ?: config('database.default'));
        $table = (string) config('session.table', 'sessions');

        if ($table === '') {
            return null;
        }

        if (! $this->databaseTableExists($connectionName, $table)) {
            return null;
        }

        $rows = DB::connection($connectionName)
            ->table($table)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->values()
            ->all();

        return [
            'connection' => $connectionName,
            'table' => $table,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{connection: string, table: string, rows: list<array<string, mixed>>}  $snapshot
     */
    private function restoreDatabaseSessions(array $snapshot): void
    {
        $connectionName = $snapshot['connection'];
        $table = $snapshot['table'];
        $rows = $snapshot['rows'];

        DB::purge($connectionName);

        if (! $this->databaseTableExists($connectionName, $table)) {
            return;
        }

        $connection = DB::connection($connectionName);
        $connection->table($table)->truncate();

        foreach (array_chunk($rows, 500) as $batch) {
            if ($batch === []) {
                continue;
            }

            $connection->table($table)->insert($batch);
        }
    }

    private function databaseTableExists(string $connectionName, string $table): bool
    {
        return DB::connection($connectionName)
            ->getSchemaBuilder()
            ->hasTable($table);
    }

    private function connectionConfig(): array
    {
        $connectionName = config('database.default');
        $config = config("database.connections.{$connectionName}");

        if (! is_array($config)) {
            throw new RuntimeException("Database connection [{$connectionName}] is not configured.");
        }

        return $config;
    }

    private function driver(): string
    {
        return (string) ($this->connectionConfig()['driver'] ?? '');
    }

    /**
     * @return list<array{name: string, root: string}>
     */
    private function checkpointableDisks(): array
    {
        $storageRoot = $this->normalizePath(storage_path());
        $disks = config('filesystems.disks', []);

        if (! is_array($disks)) {
            return [];
        }

        return collect($disks)
            ->filter(function (mixed $config): bool {
                return is_array($config) && ($config['driver'] ?? null) === 'local' && is_string($config['root'] ?? null);
            })
            ->map(function (array $config, string $name): array {
                return [
                    'name' => $name,
                    'root' => (string) $config['root'],
                ];
            })
            ->filter(function (array $disk) use ($storageRoot): bool {
                return str_starts_with($this->normalizePath($disk['root']), $storageRoot);
            })
            ->values()
            ->all();
    }

    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rtrim($path, '/\\'));
    }

    private function directorySize(string $path): int
    {
        if (! File::isDirectory($path)) {
            return File::exists($path) ? File::size($path) : 0;
        }

        return collect(File::allFiles($path))
            ->sum(static fn (SplFileInfo $file): int => $file->getSize());
    }

    private function wrapMysqlIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function encodeJsonLine(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode checkpoint row: '.$exception->getMessage(), 0, $exception);
        }
    }
}
