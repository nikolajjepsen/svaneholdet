<?php

use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('gives one confirmed shift and one refusal when two processes race for the last place', function () {
    // Commit RefreshDatabase's outer transaction so child processes see the fixture rows in Postgres.
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    $team = Team::factory()->create(['name' => 'Race Team']);
    $season = Season::factory()->for($team)->create();
    $team->update(['current_season_id' => $season->id]);
    $slot = Slot::factory()->for($season)->create(['capacity' => 1]);

    $firstUser = User::factory()->create(['locale' => 'en']);
    $secondUser = User::factory()->create(['locale' => 'en']);
    Membership::factory()->for($firstUser)->for($team)->create();
    Membership::factory()->for($secondUser)->for($team)->create();

    $barrierDir = sys_get_temp_dir().'/svaneholdet-race-'.uniqid('', true);
    mkdir($barrierDir, 0777, true);
    $goFile = $barrierDir.'/go';

    $workerScript = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$slotId = (int) $argv[2];
$userId = (int) $argv[3];
$readyFile = $argv[4];
$goFile = $argv[5];

$slot = App\Models\Slot::query()->findOrFail($slotId);
$user = App\Models\User::query()->findOrFail($userId);

file_put_contents($readyFile, '1');
$deadline = microtime(true) + 10.0;
while (! file_exists($goFile) && microtime(true) < $deadline) {
    usleep(1000);
}

try {
    app(App\Scheduling\Shifts::class)->signUp($slot, $user);
    echo 'confirmed';
} catch (Illuminate\Validation\ValidationException) {
    echo 'refused';
}
PHP;

    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => config()->string('database.connections.pgsql.database'),
    ];

    try {
        $readyA = $barrierDir.'/ready-a';
        $readyB = $barrierDir.'/ready-b';

        $processA = new Process([PHP_BINARY, '-r', $workerScript, base_path(), (string) $slot->id, (string) $firstUser->id, $readyA, $goFile], base_path(), $env);
        $processB = new Process([PHP_BINARY, '-r', $workerScript, base_path(), (string) $slot->id, (string) $secondUser->id, $readyB, $goFile], base_path(), $env);

        $processA->start();
        $processB->start();

        $deadline = microtime(true) + 10.0;
        while ((! file_exists($readyA) || ! file_exists($readyB)) && microtime(true) < $deadline) {
            usleep(5000);
        }

        file_put_contents($goFile, '1');

        $processA->wait();
        $processB->wait();

        expect($processA->getExitCode())->toBe(0, $processA->getErrorOutput());
        expect($processB->getExitCode())->toBe(0, $processB->getErrorOutput());

        $results = [trim($processA->getOutput()), trim($processB->getOutput())];
        sort($results);

        expect($results)->toBe(['confirmed', 'refused']);
        expect(Shift::query()->where('slot_id', $slot->id)->where('status', ShiftStatus::Confirmed)->count())->toBe(1);
    } finally {
        $team->delete();
        $firstUser->delete();
        $secondUser->delete();

        foreach (glob($barrierDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($barrierDir);
    }
});
