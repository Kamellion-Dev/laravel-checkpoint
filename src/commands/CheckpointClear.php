<?php

namespace KamellionDev\LaravelCheckpoint\Commands;

use Illuminate\Console\Command;
use KamellionDev\LaravelCheckpoint\Services\DatabaseCheckpointService;
use Throwable;

class CheckpointClear extends Command
{
    protected $signature = 'checkpoint:clear
        {--force : Skip the destructive confirmation prompt}';

    protected $description = 'Empty the .dev-checkpoint folder and remove all saved checkpoints.';

    public function __construct(private DatabaseCheckpointService $checkpointService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('This will permanently delete all saved checkpoints in .dev-checkpoint. Continue?')) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        try {
            $result = $this->checkpointService->clearCheckpoints();
        } catch (Throwable $throwable) {
            $this->error('Checkpoint clear failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('Checkpoint storage cleared successfully.');
        $this->line("Path: {$result['path']}");
        $this->line("Deleted checkpoints: {$result['deleted_checkpoints']}");

        return self::SUCCESS;
    }
}
