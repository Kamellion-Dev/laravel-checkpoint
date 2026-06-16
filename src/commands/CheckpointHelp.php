<?php

namespace KamellionDev\LaravelCheckpoint\Commands;

use Illuminate\Console\Command;

class CheckpointHelp extends Command
{
    protected $signature = 'checkpoint:help';

    protected $description = 'Show the available checkpoint commands and common usage examples.';

    public function handle(): int
    {
        $this->info('Laravel Checkpoint commands');
        $this->newLine();

        $this->table(
            ['Command', 'Description'],
            [
                ['checkpoint:save', 'Save a new checkpoint.'],
                ['checkpoint:save --comment="amazing starter point"', 'Save a checkpoint with a human-readable comment.'],
                ['checkpoint:list', 'List recent checkpoints and their folder names.'],
                ['checkpoint:load', 'Load the latest checkpoint.'],
                ['checkpoint:load --name=your_checkpoint_folder', 'Load a specific checkpoint by folder name.'],
                ['checkpoint:load --migrate', 'Load a checkpoint and run migrations after restore.'],
                ['checkpoint:clear', 'Clear all saved checkpoints.'],
            ]
        );

        return self::SUCCESS;
    }
}