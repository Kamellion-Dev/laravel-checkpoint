<?php

namespace KamellionDev\LaravelCheckpoint\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use KamellionDev\LaravelCheckpoint\Services\DatabaseCheckpointService;
use Throwable;

class CheckpointLoad extends Command
{
    protected $signature = 'checkpoint:load
        {--name= : Specific checkpoint folder name to load}
        {--migrate : Run database migrations after the checkpoint is loaded}';

    protected $description = 'Replace the current database with the latest checkpoint or a named checkpoint from .dev-checkpoint.';

    public function __construct(private DatabaseCheckpointService $checkpointService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $checkpointName = $this->option('name') ?: $this->checkpointService->latestCheckpointName();
        $broughtApplicationDown = false;

        if ($checkpointName === null) {
            $this->error('No checkpoints were found in .dev-checkpoint.');

            return self::FAILURE;
        }

        try {
            if (! app()->isDownForMaintenance()) {
                Artisan::call('down', ['--no-interaction' => true]);
                $broughtApplicationDown = true;
            }

            $checkpoint = $this->checkpointService->load($this->option('name'));

            if ($this->option('migrate')) {
                $migrationExitCode = Artisan::call('migrate', ['--no-interaction' => true]);

                if ($migrationExitCode !== self::SUCCESS) {
                    throw new \RuntimeException('Checkpoint loaded, but running migrations failed.');
                }
            }
        } catch (Throwable $throwable) {
            $this->error('Checkpoint load failed: '.$throwable->getMessage());

            return self::FAILURE;
        } finally {
            if ($broughtApplicationDown) {
                Artisan::call('up', ['--no-interaction' => true]);
            }
        }

        Artisan::call('config:clear', ['--no-interaction' => true]);

        $this->info("Checkpoint [{$checkpoint['name']}] loaded successfully.");
        $this->line("Path: {$checkpoint['path']}");
        $this->line("Artifact: {$checkpoint['artifact']}");

        return self::SUCCESS;
    }
}
