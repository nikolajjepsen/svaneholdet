<?php

use App\Auth\Memberships;
use App\Enums\ShiftStatus;
use App\Models\Membership;
use App\Models\Season;
use App\Models\Shift;
use App\Models\Slot;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('rechecks admin-only eligibility when :dataset waits for demotion', function (string $action) {
    // Child processes need to see the fixtures outside the test transaction.
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    $team = Team::factory()->create();
    $season = Season::factory()->for($team)->create();
    $slot = Slot::factory()->for($season)->create([
        'is_admin_only' => true,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(4),
    ]);
    $admin = Membership::factory()->admin()->for($team)->create();
    $actingAdmin = Membership::factory()->admin()->for($team)->create();
    $shift = $action === 'confirmation' ? Shift::factory()->pending()->for($slot)->for($admin)->create() : null;

    $workerScript = <<<'WORKER'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$action, $slotId, $adminId, $actingAdminId, $shiftId] = array_slice($argv, 2);
$slot = App\Models\Slot::query()->findOrFail($slotId);
$admin = App\Models\Membership::query()->with('user')->findOrFail($adminId);
$actingAdmin = App\Models\User::query()->findOrFail($actingAdminId);
$shifts = app(App\Scheduling\Shifts::class);

echo Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()').PHP_EOL;

try {
    match ($action) {
        'signup' => $shifts->signUp($slot, $admin->user, acceptWarnings: true),
        'assignment' => $shifts->assign($slot, $admin, $actingAdmin, acceptWarnings: true),
        'confirmation' => $shifts->confirm(App\Models\Shift::query()->findOrFail($shiftId), $actingAdmin, acceptWarnings: true),
    };
    echo 'accepted';
} catch (Illuminate\Auth\Access\AuthorizationException|Illuminate\Validation\ValidationException) {
    echo 'refused';
}
WORKER;

    $worker = new Process([
        PHP_BINARY, '-r', $workerScript, base_path(), $action, (string) $slot->id,
        (string) $admin->id, (string) $actingAdmin->user_id, (string) $shift?->id,
    ], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => config()->string('database.connections.pgsql.database'),
        'MAIL_MAILER' => 'array',
    ], timeout: 15);

    try {
        DB::beginTransaction();
        Membership::query()->lockForUpdate()->findOrFail($admin->id);
        $worker->start();

        $blocked = false;
        $deadline = microtime(true) + 5;
        while ($worker->isRunning() && microtime(true) < $deadline) {
            $workerPid = (int) $worker->getOutput();
            if ($workerPid > 0 && DB::scalar('select pg_backend_pid() = any(pg_blocking_pids(?))', [$workerPid])) {
                $blocked = true;
                break;
            }
            usleep(10_000);
        }

        expect($blocked)->toBeTrue($worker->getOutput().$worker->getErrorOutput());

        app(Memberships::class)->demote($admin, $actingAdmin->user);
        DB::commit();
        $worker->wait();

        expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
        expect($worker->getOutput())->toEndWith('refused');
        expect($slot->shifts()->whereIn('status', [ShiftStatus::Pending, ShiftStatus::Confirmed])->exists())->toBeFalse();
    } finally {
        DB::rollBack();
        $worker->stop();
        $team->delete();
        $admin->user->delete();
        $actingAdmin->user->delete();
    }
})->with(['signup', 'assignment', 'confirmation']);
