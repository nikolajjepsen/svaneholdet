<?php

namespace Database\Seeders;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $team = Team::query()->firstOrCreate(['name' => 'Hvid opstilling']);
            $admin = User::query()->firstOrCreate(
                ['email' => Str::lower(trim(config()->string('seed.admin_email')))],
                ['name' => config()->string('seed.admin_name')],
            );

            $team->memberships()->updateOrCreate(['user_id' => $admin->id], [
                'role' => MembershipRole::Admin,
                'status' => MembershipStatus::Active,
            ]);

            foreach (range(1, 10) as $number) {
                $volunteer = User::query()->firstOrCreate(
                    ['email' => 'volunteer'.$number.'@example.com'],
                    ['name' => fake()->name()],
                );

                $team->memberships()->firstOrCreate(['user_id' => $volunteer->id], [
                    'role' => MembershipRole::Volunteer,
                    'status' => MembershipStatus::Active,
                ]);
            }
        });
    }
}
