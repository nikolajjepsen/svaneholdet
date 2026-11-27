<?php

namespace App\Console\Commands;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Season;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateTeam extends Command
{
    protected $signature = 'team:create';

    protected $description = 'Create a team and its first admin';

    public function handle(): int
    {
        $input = [
            'team name' => trim((string) $this->ask('Team name')),
            'admin name' => trim((string) $this->ask('Admin name')),
            'admin email' => Str::lower(trim((string) $this->ask('Admin email'))),
        ];

        $validator = Validator::make($input, [
            'team name' => ['required', 'string', 'max:255'],
            'admin name' => ['required', 'string', 'max:255'],
            'admin email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($input): void {
            $user = User::query()->firstOrCreate(
                ['email' => $input['admin email']],
                ['name' => $input['admin name']],
            );
            $team = Team::query()->create(['name' => $input['team name']]);
            $season = $team->seasons()->create(['name' => Season::DEFAULT_NAME]);
            $team->update(['current_season_id' => $season->id]);

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => MembershipRole::Admin,
                'status' => MembershipStatus::Active,
            ]);
        });

        $this->info('Login URL: '.route('login'));

        return self::SUCCESS;
    }
}
