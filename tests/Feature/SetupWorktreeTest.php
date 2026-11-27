<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake();

    $this->environmentDirectory = sys_get_temp_dir().'/worktree-setup-'.uniqid();
    File::ensureDirectoryExists($this->environmentDirectory);
    $this->app->useEnvironmentPath($this->environmentDirectory);
});

afterEach(function () {
    File::deleteDirectory($this->environmentDirectory);
});

it('creates .env from .env.example with an application key', function () {
    config(['app.key' => '']);

    $this->artisan('worktree:setup')->assertSuccessful();

    $environment = File::get($this->environmentDirectory.'/.env');
    expect($environment)
        ->toContain('SESSION_LIFETIME=129600')
        ->toMatch('/^APP_KEY=base64:.+$/m');
});

it('adds the keys .env lacks and keeps the values it has', function () {
    File::put($this->environmentDirectory.'/.env', "APP_KEY=base64:existing\nDB_PASSWORD=my-own-password\n");

    $this->artisan('worktree:setup')
        ->expectsOutputToContain('SESSION_LIFETIME')
        ->assertSuccessful();

    $environment = File::get($this->environmentDirectory.'/.env');
    expect($environment)
        ->toContain('APP_KEY=base64:existing')
        ->toContain('DB_PASSWORD=my-own-password')
        ->toContain('SESSION_LIFETIME=129600')
        ->not->toContain('DB_PASSWORD=svaneholdet');
});

it('reuses a running Postgres instead of starting the container', function () {
    File::put($this->environmentDirectory.'/.env', "APP_KEY=base64:existing\n");

    $this->artisan('worktree:setup')
        ->expectsOutputToContain('Reusing it')
        ->assertSuccessful();

    Process::assertNotRan(fn ($process): bool => $process->command[0] === 'docker');
    Process::assertRan(['npm', 'ci', '--no-audit', '--no-fund']);
});

it('starts the Postgres container when nothing answers on the port', function () {
    File::put($this->environmentDirectory.'/.env', "APP_KEY=base64:existing\n");
    config(['database.connections.pgsql.port' => 1]);

    $this->artisan('worktree:setup')->assertSuccessful();

    Process::assertRan(['docker', 'compose', 'up', '--detach', '--wait', 'postgres']);
});

it('stops when the Postgres container fails to start', function () {
    File::put($this->environmentDirectory.'/.env', "APP_KEY=base64:existing\n");
    config(['database.connections.pgsql.port' => 1]);
    Process::fake(fn ($process) => Process::result(exitCode: $process->command[0] === 'docker' ? 1 : 0));

    $this->artisan('worktree:setup')->assertFailed();

    Process::assertNotRan(fn ($process): bool => $process->command[0] === 'npm');
});
