# laravel-checkpoint

Simple Laravel development checkpoints for your database and local storage disks.

## Why use it?

When you are working on a long backend flow, the slowest part is often getting the app back to a known state.

Instead of reseeding, rebuilding data manually, or replaying the same steps over and over, you can:

- save a checkpoint after setup is complete
- try a new branch of logic
- load the checkpoint and start again immediately

Think of it like save points for your Laravel development environment.

## Quick start

Install the package:

```bash
composer require kamellion-dev/laravel-checkpoint
```

Laravel will auto-discover the service provider, so the commands become available immediately:

```bash
php artisan checkpoint:save
php artisan checkpoint:load
```

Saved checkpoints are stored in the project's `.dev-checkpoint` folder.

Save a checkpoint with a comment:

```bash
php artisan checkpoint:save --comment="amazing starter point"
```

This saves the comment as a `.comment` file inside that checkpoint folder and shows it in `checkpoint:list`.

List recent checkpoints:

```bash
php artisan checkpoint:list
```

The list shows the display label and the actual folder name you can pass to `checkpoint:load --name=...`.

Load a specific checkpoint folder by name:

```bash
php artisan checkpoint:load --name=your_checkpoint_folder
```

Load a checkpoint and re-run migrations when needed:

```bash
php artisan checkpoint:load --migrate
```

Clear all saved checkpoints:

```bash
php artisan checkpoint:clear
```

Get command help:

```bash
php artisan help checkpoint:save
php artisan help checkpoint:list
php artisan help checkpoint:load
php artisan help checkpoint:clear
php artisan help checkpoint:help
```

## Package structure

This package keeps its classes inside `src/` and registers them through a service provider instead of copying files into the host application's `app/` directory.

- `src/commands/CheckpointSave.php`
- `src/commands/CheckpointLoad.php`
- `src/commands/CheckpointClear.php`
- `src/commands/CheckpointHelp.php`
- `src/commands/CheckpointList.php`
- `src/services/DatabaseCheckpointService.php`
- `src/LaravelCheckpointServiceProvider.php`

To the consuming Laravel app, the commands behave like native Artisan commands without physically adding files under `app/Console/Commands` or `app/Services`.

## Packagist publish checklist

1. Push this repository to GitHub at `https://github.com/Kamellion-Dev/laravel-checkpoint`.
2. Create a Packagist account and submit the repository URL.
3. Make sure the default branch contains this `composer.json` and the package source.
4. Tag a release, for example `v1.0.0`, then push the tag:

```bash
git tag v1.0.0
git push origin main --tags
```

1. On Packagist, click `Update` or enable the GitHub hook so new tags are indexed automatically.

## Notes

- Composer package names must be lowercase, so the package name is `kamellion-dev/laravel-checkpoint`.
- Auto-discovery is enabled through the `extra.laravel.providers` section in `composer.json`.
- No publish step is required because the commands and service are resolved directly from the package.
