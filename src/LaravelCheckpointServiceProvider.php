<?php

namespace KamellionDev\LaravelCheckpoint;

use Illuminate\Support\ServiceProvider;
use KamellionDev\LaravelCheckpoint\Commands\CheckpointClear;
use KamellionDev\LaravelCheckpoint\Commands\CheckpointHelp;
use KamellionDev\LaravelCheckpoint\Commands\CheckpointList;
use KamellionDev\LaravelCheckpoint\Commands\CheckpointLoad;
use KamellionDev\LaravelCheckpoint\Commands\CheckpointSave;
use KamellionDev\LaravelCheckpoint\Services\DatabaseCheckpointService;

class LaravelCheckpointServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		$this->app->singleton(DatabaseCheckpointService::class);
	}

	public function boot(): void
	{
		if ($this->app->runningInConsole()) {
			$this->commands([
				CheckpointSave::class,
				CheckpointLoad::class,
				CheckpointClear::class,
				CheckpointList::class,
				CheckpointHelp::class,
			]);
		}
	}
}
