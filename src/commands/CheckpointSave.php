<?php

namespace KamellionDev\LaravelCheckpoint\Commands;

use Illuminate\Console\Command;
use KamellionDev\LaravelCheckpoint\Services\DatabaseCheckpointService;
use Throwable;

class CheckpointSave extends Command
{
    protected $signature = 'checkpoint:save
        {--comment= : Optional human-readable label saved as a .comment file}';

    protected $description = 'Save a full database checkpoint under .dev-checkpoint using a timestamped folder.';

    public function __construct(private DatabaseCheckpointService $checkpointService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $checkpoint = $this->checkpointService->save($this->option('comment'));
        } catch (Throwable $throwable) {
            $this->error('Checkpoint save failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $sizeInMb = round($checkpoint['size_bytes'] / 1024 / 1024, 2);

        $this->info("Checkpoint [{$checkpoint['name']}] saved successfully.");
        if (($checkpoint['comment'] ?? null) !== null) {
            $this->line("Comment: {$checkpoint['comment']}");
        }
        $this->line("Path: {$checkpoint['path']}");
        $this->line("Artifact: {$checkpoint['artifact']}");
        $this->line("Size: {$sizeInMb} MB");

        return self::SUCCESS;
    }
}
