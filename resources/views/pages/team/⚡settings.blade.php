<?php

use App\Auth\CurrentTeam;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $teamId;

    #[Locked]
    public bool $saved = false;

    public string $cancellationDeadlineHours = '';

    public string $defaultHourGoal = '';

    public bool $shiftsRequireConfirmation = TeamSettings::DEFAULT_SHIFTS_REQUIRE_CONFIRMATION;

    public bool $showConfirmedVolunteerNames = TeamSettings::DEFAULT_SHOW_CONFIRMED_VOLUNTEER_NAMES;

    protected Team $team;

    public function mount(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_if($team === null, 403);
        Gate::authorize('updateSettings', $team);

        $this->team = $team;
        $this->teamId = $team->id;

        $settings = $team->settings;
        $this->cancellationDeadlineHours = (string) $settings->cancellationDeadlineHours;
        $this->defaultHourGoal = (string) $settings->defaultHourGoal;
        $this->shiftsRequireConfirmation = $settings->shiftsRequireConfirmation;
        $this->showConfirmedVolunteerNames = $settings->showConfirmedVolunteerNames;
    }

    public function hydrate(CurrentTeam $currentTeam): void
    {
        $team = request()->attributes->get(Team::class) ?? $currentTeam->resolve($this->user());
        abort_unless($team?->id === $this->teamId, 403);
        Gate::authorize('updateSettings', $team);

        $this->team = $team;
    }

    public function save(): void
    {
        $this->saved = false;
        Gate::authorize('updateSettings', $this->team);

        $validated = $this->validate([
            'cancellationDeadlineHours' => ['required', 'integer', 'min:0'],
            'defaultHourGoal' => ['nullable', 'integer', 'min:1'],
            'shiftsRequireConfirmation' => ['boolean'],
            'showConfirmedVolunteerNames' => ['boolean'],
        ], [
            'cancellationDeadlineHours.required' => __('Enter a whole number of hours, 0 or more.'),
            'cancellationDeadlineHours.integer' => __('Enter a whole number of hours, 0 or more.'),
            'cancellationDeadlineHours.min' => __('Enter a whole number of hours, 0 or more.'),
            'defaultHourGoal.integer' => __('Enter a whole number of hours, 1 or more.'),
            'defaultHourGoal.min' => __('Enter a whole number of hours, 1 or more.'),
        ]);

        $defaultHourGoal = $validated['defaultHourGoal'];
        $this->team->update([
            'settings' => new TeamSettings(
                cancellationDeadlineHours: (int) $validated['cancellationDeadlineHours'],
                shiftsRequireConfirmation: (bool) $validated['shiftsRequireConfirmation'],
                showConfirmedVolunteerNames: (bool) $validated['showConfirmedVolunteerNames'],
                defaultHourGoal: $defaultHourGoal === null || $defaultHourGoal === '' ? null : (int) $defaultHourGoal,
            ),
        ]);

        $this->cancellationDeadlineHours = (string) (int) $validated['cancellationDeadlineHours'];
        $this->saved = true;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        return $user;
    }

    public function render(): View
    {
        return $this->view([
            'team' => $this->team,
        ])->title(__('Team settings'));
    }
}; ?>

<section class="max-w-lg space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Team settings') }}</flux:heading>
        <flux:text class="mt-2">{{ $team->name }}</flux:text>
    </div>

    @if ($saved)
        <flux:callout icon="check-circle" color="green" role="status">
            <flux:callout.text>{{ __('Team settings saved.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="save" class="space-y-6">
        <flux:field>
            <flux:label>{{ __('Cancellation deadline (hours)') }}</flux:label>
            <flux:description>{{ __('Volunteers can cancel confirmed shifts until this many hours before the shift starts.') }}</flux:description>
            <flux:input
                type="number"
                name="cancellationDeadlineHours"
                min="0"
                step="1"
                wire:model="cancellationDeadlineHours"
                data-test="cancellation-deadline-hours"
                required
            />
            <flux:error name="cancellationDeadlineHours" />
        </flux:field>

        <flux:switch
            wire:model="shiftsRequireConfirmation"
            name="shiftsRequireConfirmation"
            :label="__('Require confirmation for shifts')"
            :description="__('New shift requests wait for an admin to confirm or reject them. Existing pending shifts stay pending if you turn this off.')"
            data-test="shifts-require-confirmation"
        />

        <flux:switch
            wire:model="showConfirmedVolunteerNames"
            name="showConfirmedVolunteerNames"
            :label="__('Show confirmed volunteer names on the schedule')"
            :description="__('Show the first names of confirmed volunteers on each slot.')"
            data-test="show-confirmed-volunteer-names"
        />

        <flux:field>
            <flux:label>{{ __('Default hour goal') }}</flux:label>
            <flux:description>{{ __('Leave blank to use no default hour goal.') }}</flux:description>
            <flux:input type="number" name="defaultHourGoal" min="1" step="1" wire:model="defaultHourGoal" />
            <flux:error name="defaultHourGoal" />
        </flux:field>

        <flux:button variant="primary" type="submit" data-test="save-team-settings">
            {{ __('Save') }}
        </flux:button>
    </form>
</section>
