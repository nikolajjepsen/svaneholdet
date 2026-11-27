<?php

namespace App\Console\Commands;

use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Makes a checkout ready to run `composer test`. Every step is safe to run
 * again, so it also refreshes a checkout that fell behind main.
 *
 * Run it through `composer worktree:setup`, which installs `vendor/` first.
 */
class SetupWorktree extends Command
{
    protected $signature = 'worktree:setup';

    protected $description = 'Make this checkout ready to run the test suite';

    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('worktree:setup is for development checkouts only.');

            return self::FAILURE;
        }

        $this->call('config:clear');
        $this->syncEnvironmentFile();
        if (! is_link(public_path('storage'))) {
            $this->call('storage:link');
        }

        if (! $this->ensurePostgresIsRunning()) {
            return self::FAILURE;
        }

        if (! $this->runStep(['npm', 'ci', '--no-audit', '--no-fund'])) {
            return self::FAILURE;
        }

        if (! File::exists(public_path('build/manifest.json')) && ! $this->runStep(['npm', 'run', 'build'])) {
            return self::FAILURE;
        }

        $this->info('This checkout is ready. Run `composer test`.');

        return self::SUCCESS;
    }

    /**
     * Creates `.env` from `.env.example`, or appends the keys `.env` lacks.
     * Values already in `.env` are never changed.
     */
    private function syncEnvironmentFile(): void
    {
        $environmentPath = $this->laravel->environmentFilePath();
        $examplePath = base_path('.env.example');

        if (! File::exists($environmentPath)) {
            File::copy($examplePath, $environmentPath);
            $this->info('Created .env from .env.example.');
        } else {
            $currentKeys = array_keys(Dotenv::parse(File::get($environmentPath)));

            $missingLines = collect(File::lines($examplePath))
                ->filter(fn (string $line): bool => preg_match('/^([A-Z0-9_]+)=/', $line, $match) === 1
                    && ! in_array($match[1], $currentKeys, true))
                ->values();

            if ($missingLines->isNotEmpty()) {
                $contents = rtrim(File::get($environmentPath)).PHP_EOL.PHP_EOL
                    .'# Added by worktree:setup from .env.example'.PHP_EOL
                    .$missingLines->implode(PHP_EOL).PHP_EOL;

                File::put($environmentPath, $contents);
                $this->info('Added to .env: '.$missingLines->map(fn (string $line): string => Str::before($line, '='))->implode(', '));
            }
        }

        if (blank(Dotenv::parse(File::get($environmentPath))['APP_KEY'] ?? null)) {
            $this->call('key:generate', ['--force' => true]);
        }
    }

    /**
     * Reuses whatever Postgres answers on the configured port. Only when
     * nothing answers does it start the container from compose.yaml.
     */
    private function ensurePostgresIsRunning(): bool
    {
        $host = (string) config('database.connections.pgsql.host');
        $port = (int) config('database.connections.pgsql.port');

        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 1);

        if ($socket !== false) {
            fclose($socket);
            $this->info("Postgres answers on {$host}:{$port}. Reusing it.");

            return true;
        }

        $this->info("Nothing answers on {$host}:{$port}. Starting Postgres with docker compose.");

        return $this->runStep(['docker', 'compose', 'up', '--detach', '--wait', 'postgres']);
    }

    /**
     * @param  list<string>  $command
     */
    private function runStep(array $command): bool
    {
        $this->line('> '.implode(' ', $command));

        $result = Process::path(base_path())
            ->forever()
            ->run($command, fn (string $type, string $output) => $this->output->write($output));

        if ($result->failed()) {
            $this->error(implode(' ', $command).' failed.');

            return false;
        }

        return true;
    }
}
