# Svaneholdet shift planning

The first version lets a Smukfest team of more than 70 volunteers go from invitation to a staffed schedule and recorded hours. Svaneholdet is the first team. From the start, the data model must hold several teams, each with its own members, roles and schedules. The interface for several teams waits until after the pilot.

## Technology

One Laravel app with no API between backend and frontend. Laravel handles routing, authorization and data, and pages render on the server.

| Part | Choice |
| --- | --- |
| Framework | Laravel with the official Livewire starter kit |
| Interface | Livewire with single-file components, so logic and markup sit together. Alpine for small things in the browser |
| Components | Flux for forms, modals, tables and buttons, so pages look finished without our own design work |
| Database | PostgreSQL |
| Authorization | Laravel Policies on every read and write, including Livewire actions |
| Quality | Pest for tests, Larastan for static analysis and Pint for formatting |
| AI agents | Laravel Boost, so agents know the project's Laravel version and conventions |

[Laravel Starter Kits](https://laravel.com/framework/docs/13.x/starter-kits) · [Laravel Authorization](https://laravel.com/framework/docs/13.x/authorization) · [PostgreSQL Constraints](https://www.postgresql.org/docs/current/ddl-constraints.html)

## First version

### Teams, roles and login

- A user can be a member of several teams and have a different role on each team. The data model and every policy account for this from the start. A team switcher shows when a user has more than one team. New teams get created with an artisan command.
- Start with the roles **volunteer** and **admin**. An admin can also take shifts. Both roles sign up the same way, and the admin can confirm and assign shifts as a separate action.
- **Login is by a link sent by email**, not by password. The user enters their email and gets a time-limited, single-use link. On a phone the user stays logged in for a long time, so volunteers rarely have to log in again during the festival.
- An admin creates an invitation with a name and email. Every invitation makes the person a volunteer, and an admin promotes volunteers to admin afterwards. The link in the invitation email activates the account and logs the person in. If the person already has an account, the membership is added to that account. The admin can resend the invitation, which expires the old link.
- `php artisan team:create` creates a team and its first admin. From feature 2 it also creates the team's first season.

### Seasons

- Every slot belongs to a season, for example "Smukfest 2027". A team has one current season, and volunteers only ever see that one. Admins will be able to look back at earlier seasons, but that waits until there is more than one.
- `team:create` creates the first season, Smukfest 2027, and makes it current. There is no form for seasons yet. A way to start the next season, and to copy a schedule to it, comes when 2028 planning starts.

### Team settings

- Admins change the team's settings on a settings page. Feature 2 brings three: the cancellation deadline, 48 hours by default; whether shifts need confirmation, off by default; and whether volunteers see the first names of confirmed volunteers on the schedule, on by default.
- The settings live in one `settings` jsonb column on `teams`, cast to a typed `TeamSettings` class that holds a default for every setting. We expect 10 to 20 settings in all, and adding one needs no migration.
- Changing whether shifts need confirmation leaves existing shifts as they are. Pending shifts stay pending until an admin confirms or rejects them.

### Slots and rollout

- A slot is a planned need for people: a start, an end and a capacity. It has no title. There can be several slots on the same day, also at the same time, and a slot can end the day after it starts. Show times in the team's timezone, `Europe/Copenhagen`, and store unambiguous timestamps in the database.
- **An admin creates slots by rolling them out.** That is the normal way to create slots. Single slots can still be created and edited afterwards.
- The admin picks dates in a calendar and adds one or more intervals, each with a start, an end and a capacity. The rollout creates one slot per date per interval. An interval whose end is before its start ends the next day.
- Picking 3, 4, 5 and 6 August with 08:00–16:00 for 6 people and 16:00–00:00 for 4 people gives 8 slots.
- Before the slots are saved, a preview sums up what will be created, such as "8 slots on 4 days, 2 already exist". It warns when a new slot has exactly the same start and end as an existing one, and the admin can save anyway. All slots from one rollout are saved in the same transaction.
- **Each interval becomes a slot series**, the slots it created across the picked dates. From the series, the admin can change start, end or capacity on every slot in it that hasn't started. A bulk edit writes only the fields the admin changed, so a slot edited on its own keeps its other values. The admin can also delete the whole series.
- A slot that hasn't started can be deleted, also when it has shifts. The modal says how many people are confirmed or pending, and has a checkbox, ticked by default, to email them that the slot is cancelled. A slot that has started can't be deleted.

### Shifts and signing up

- A shift is one member's place on one slot. It is pending, confirmed, rejected or cancelled. It is called a shift everywhere: in the database, in the code and on the page.
- When the team doesn't need confirmation, signing up gives a confirmed shift at once if the slot has room, and a full slot refuses it. The capacity check and the save happen in one database transaction with a lock on the slot, so two volunteers can't both take the last place. [Laravel Pessimistic Locking](https://laravel.com/framework/docs/13.x/queries#pessimistic-locking)
- When the team needs confirmation, signing up creates a pending shift, which an admin confirms or rejects. A pending shift doesn't take a place, so a volunteer can send one to a full slot after a warning.
- A member can only have one pending or confirmed shift on the same slot.
- An admin can assign any member, themselves included, to any slot. Assigning creates a confirmed shift and skips confirmation.
- **Admins may go over capacity.** Assigning, confirming a pending shift and lowering a slot's capacity all go through on a full slot after an over-capacity warning. Admins see "7 / 6", and the shifts confirmed last carry an "over capacity" badge, ranked by when each was confirmed. Volunteers see "6 / 6". See ADR 0002.

### Overlap

- When a new shift overlaps another of the member's pending or confirmed shifts on the same team, the app warns and lets them go ahead. The overlapping hours count once when hours are recorded.
- When it overlaps a shift on another team, the app blocks it. That holds for signing up, for an admin assigning, and for an admin changing a slot's times. The admin sees which team the clash is with.
- Warnings and confirmations use Flux components, never the browser's native dialogs.

### Cancelling

- **A volunteer can withdraw a pending shift at any time, and cancel a confirmed one until the team's cancellation deadline**, 48 hours before the slot starts by default. After that they have to contact an admin, who can cancel for them. After cancelling, the member can sign up again.

### Email

- A volunteer gets an email when their pending shift is confirmed or rejected, when an admin cancels their shift, when the times of a slot they are confirmed on change, and when such a slot is deleted with the email box ticked. A member's own actions send nothing, and there are no reminders.

### Schedule and My shifts

- **The schedule** shows the current season's slots day by day, built for phones first, with the time, how much of the capacity is taken, and clear signup actions. With the team setting on, it shows the first names of confirmed volunteers. Only admins see pending shifts.
- **My shifts** shows the member's upcoming and past shifts with status and hours.

### Staffing and hours

- **The day overview** shows each slot's confirmed shifts out of its capacity, its pending shifts, and the total number of unique confirmed people that day. A person with two shifts counts once in the day total and once on each slot. Slots past midnight show on both days they are staffed. The page refreshes itself every 10 seconds with `wire:poll`, so the admin sees new shifts without reloading.
- An admin can open a slot and edit actual arrival and leaving times directly on the participant list. When recording attendance, the slot's planned times are the starting point. If the slot was planned for 12:00–16:00 and a volunteer arrived at 12:30 and left at 15:45, 3 hours and 15 minutes are recorded. Record who changed the times and when.
- The hours overview shows planned hours and recorded working hours per person. Working hours only count once attendance is recorded, and no-shows are recorded with 0 hours. Overlapping shifts on the same team count their shared hours once. The history shows the slot's date, its planned times and the actually recorded times.

## Data model

| Entity | Contents |
| --- | --- |
| User | Name and email |
| Login link | User, single-use token, expiry and use |
| Team | Team name, timezone, current season and settings |
| Season | Team and name |
| Membership | User, team, role and access status |
| Invitation | Team, recipient's name and email, expiry, redemption and withdrawal |
| Slot series | Season, and the interval it was rolled out with |
| Slot | Season, optional slot series, planned start and end, and capacity |
| Shift | Slot, membership, status, when it was confirmed, attendance and actual times |

A shift connects a slot with a membership in the same team. That keeps the history when a member's access is removed. The schedule and the hours overview can be filtered to a period within the season.

## Build sequence

1. **Access and invitations.** Set up Laravel with Livewire, Flux, Pest, Larastan and Pint. Build teams, memberships, roles, mail-link login and invitations. Run a real invitation in a test environment from creation to first login. Use two teams in the tests, so role and access are checked across them from the start.
2. **Seasons, rollout and self-service.** Build seasons, team settings, rollout with preview, slot series, the mobile-friendly schedule and My shifts. Make signing up, pending shifts, confirming, rejecting and cancelling work end to end. Test a rollout over several dates with two intervals, a slot past midnight, several slots on the same day, an admin assigning themselves, going over capacity, overlap on the same team and across teams, cancelling after the deadline, and two simultaneous attempts at the last place.
3. **Day overview and hour registration.** Build the day count, the participant list with time editing, and history per volunteer. Check that two shifts on the same day count as one person, that overlapping shifts count their shared hours once, and that a shortened shift gives the right number of hours. Also test slots past midnight and no-shows.
4. **Pilot with Svaneholdet.** Set up email sending, HTTPS, backup and restore. Let a few admins and volunteers run the flow on their phones from invitation to hour registration. Use their feedback to adjust the navigation before the rest of the team is invited.

## Open decisions

| Topic | Proposal or question |
| --- | --- |
| Hours | Are breaks deducted, especially on 12-hour shifts? Will the hours be used for a minimum requirement or an export? |
| New teams and copying | Should new teams be created centrally? Should next season's schedule be copied from the last one or rolled out again? |
