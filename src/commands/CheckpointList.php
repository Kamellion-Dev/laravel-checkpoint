<?php

namespace KamellionDev\LaravelCheckpoint\Commands;

use Illuminate\Console\Command;
use KamellionDev\LaravelCheckpoint\Services\DatabaseCheckpointService;
use Throwable;

class CheckpointList extends Command
{
    protected $signature = 'checkpoint:list';

    protected $description = 'List saved checkpoints, showing comments when available and folder names for loading.';

    public function __construct(private DatabaseCheckpointService $checkpointService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $checkpoints = $this->checkpointService->listCheckpoints();
        } catch (Throwable $throwable) {
            $this->error('Checkpoint list failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        if ($checkpoints === []) {
            $this->warn('No checkpoints were found in .dev-checkpoint.');

            return self::SUCCESS;
        }

        $this->table(
            ['Display', 'Folder', 'Created At'],
            collect($checkpoints)
                ->map(static fn (array $checkpoint): array => [
                    $checkpoint['display_name'],
                    $checkpoint['name'],
                    $checkpoint['created_at'] ?? '-',
                ])
                ->all()
        );

        $this->newLine();
        $this->line('Use the folder name with: php artisan checkpoint:load --name=folder_name');

        return self::SUCCESS;
    }
}