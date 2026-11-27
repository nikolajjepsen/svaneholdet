<?php

/**
 * Gives every checkout its own test database, so tests can run in several
 * git worktrees at once. RefreshDatabase drops every table at the start of a
 * run, which breaks any other run that shares the database.
 *
 * The name is the DB_DATABASE base from phpunit.xml plus the checkout's
 * folder name and a short hash of its path. The database is created on the
 * first run.
 *
 * It also creates the public/storage link that `php artisan storage:link`
 * makes, so a fresh checkout can't fail tests that serve uploaded files.
 */

use Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

$projectPath = dirname(__DIR__);

$publicStorage = $projectPath.'/public/storage';

if (! is_link($publicStorage) && ! file_exists($publicStorage)) {
    // A parallel test process may create it between the check and here.
    @symlink($projectPath.'/storage/app/public', $publicStorage);
}

$fileEnvironment = file_exists($projectPath.'/.env')
    ? Dotenv::parse((string) file_get_contents($projectPath.'/.env'))
    : [];

$setting = fn (string $key, string $default): string => (string) ($_SERVER[$key] ?? $_ENV[$key] ?? $fileEnvironment[$key] ?? $default);

$checkoutSlug = substr((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(basename($projectPath))), 0, 20);
$testDatabase = sprintf('%s_%s_%s', $setting('DB_DATABASE', 'svaneholdet_testing'), $checkoutSlug, substr(md5($projectPath), 0, 6));

$databaseHost = $setting('DB_HOST', '127.0.0.1');
$databasePort = $setting('DB_PORT', '5432');

try {
    $connection = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres', $databaseHost, $databasePort),
        $setting('DB_USERNAME', 'svaneholdet'),
        $setting('DB_PASSWORD', 'svaneholdet'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (PDOException $exception) {
    fwrite(STDERR, "Can't reach Postgres on {$databaseHost}:{$databasePort}. Run `composer worktree:setup`.".PHP_EOL);

    throw $exception;
}

$lookup = $connection->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
$lookup->execute([$testDatabase]);

if ($lookup->fetchColumn() === false) {
    try {
        $connection->exec(sprintf('CREATE DATABASE "%s"', $testDatabase));
    } catch (PDOException $exception) {
        // A parallel test process created it first.
        if ($exception->getCode() !== '42P04') {
            throw $exception;
        }
    }
}

$connection = null;

putenv("DB_DATABASE={$testDatabase}");
$_ENV['DB_DATABASE'] = $testDatabase;
$_SERVER['DB_DATABASE'] = $testDatabase;
