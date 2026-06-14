# laravel-checkpoint

Simple Laravel development checkpoints for your database and local storage disks.

## Quick start

Install the package:

```bash
composer require kamellion-dev/laravel-checkpoint
```

Laravel will auto-discover the service provider, so the commands become available immediately:

```bash
php artisan checkpoint:save
php artisan checkpoint:load
php artisan checkpoint:clear
```

## Package structure

This package keeps its classes inside `src/` and registers them through a service provider instead of copying files into the host application's `app/` directory.

- `src/commands/CheckpointSave.php`
- `src/commands/CheckpointLoad.php`
- `src/commands/CheckpointClear.php`
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

5. On Packagist, click `Update` or enable the GitHub hook so new tags are indexed automatically.

## Notes

- Composer package names must be lowercase, so the package name is `kamellion-dev/laravel-checkpoint`.
- Auto-discovery is enabled through the `extra.laravel.providers` section in `composer.json`.
- No publish step is required because the commands and service are resolved directly from the package.
