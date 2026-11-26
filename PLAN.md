# Svaneholdet shift planning

The first version lets a Smukfest team of more than 70 volunteers go from invitation to a staffed shift plan and recorded hours. Svaneholdet is the first team. From the start, the data model must hold several teams, each with its own members, roles and shift plans. The interface for several teams waits until after the pilot.

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
- Start with the roles **volunteer** and **admin**. An admin can also take shifts. Both roles use the same signup, and the admin can approve and assign places as a separate action.
- **Login is by a link sent by email**, not by password. The user enters their email and gets a time-limited, single-use link. On a phone the user stays logged in for a long time, so volunteers rarely have to log in again during the festival.
- An admin creates an invitation with a name and email. Every invitation makes the person a volunteer, and an admin promotes volunteers to admin afterwards. The link in the invitation email activates the account and logs the person in. If the person already has an account, the membership is added to that account. The admin can resend the invitation, which expires the old link.
- `php artisan team:create` creates a team and its first admin. The first festival year comes with feature 2.

### Festival years

- Every shift belongs to a festival year, for example "Smukfest 2027". The shift plan, day overview and hours overview show the current year by default.
- It takes one table now and saves migrating every shift next year. Copying a whole shift plan to a new year gets built later, but the data model is ready for it.

### Shifts and rollout

- A shift has a title, a start and end time with date, a number of places, and a setting for whether signup needs approval. There can be several shifts on the same day, also at the same time. Separate start and end dates make it possible to plan a shift past midnight. Show times in `Europe/Copenhagen` and store unambiguous timestamps in the database.
- **An admin creates shifts by rolling a pattern out over a period.** That is the normal way to create shifts. Single shifts can still be created and edited afterwards.

The rollout asks for:

| Field | Example |
| --- | --- |
| Title | Bar |
| Period | 3 to 10 August |
| Pattern | 2 × 6 hours, 1 × 12 hours, or a custom count and length |
| First shift starts | 08:00 |
| Places per shift | 6 |
| Needs approval | No |

- With 2 × 6 hours from 08:00, each day gets one shift 08:00–14:00 and one 14:00–20:00. With 1 × 12 hours from 20:00, the shift runs to 08:00 the next morning.
- Before the shifts are saved, a preview shows every shift day by day. The admin can deselect single days, such as setup or the last day. If a new shift has the same title as an existing one and overlaps it, the preview shows a warning.
- All shifts from one rollout are saved in the same transaction and belong to a **shift series**. Afterwards each shift is an ordinary shift that can be edited on its own. From the series, the admin can change the number of places or the approval setting on every upcoming shift in the series at once. A series can be deleted as long as none of its shifts have signups.

### Signing up and cancelling

- On a shift with open signup, the member gets a confirmed place at once if there is free capacity. On a shift that needs approval, signing up creates a pending request, which an admin can approve or reject.
- Pending requests are shown separately and only take a place when approved. Direct signup, approval and manual assignment check the number of places and save the change in the same database transaction, with a lock on the shift. That way two simultaneous actions can't both take the last place. A member can only have one active signup for the same shift. [Laravel Pessimistic Locking](https://laravel.com/framework/docs/13.x/queries#pessimistic-locking)
- **Volunteers can cancel on their own until 48 hours before the shift.** After that they have to contact an admin, who can cancel for them. The deadline is a setting on the team.
- **The shift plan** shows shifts day by day with time, free places and clear signup actions. **My shifts** shows upcoming and past shifts with status and hours.

### Staffing and hours

- **The day overview** shows each shift's confirmed places out of its capacity, pending requests, and the total number of unique confirmed people that day. A person with two shifts counts once in the day total and once on each of their shifts. Shifts past midnight show on both days they are staffed. The page refreshes itself every 10 seconds with `wire:poll`, so the admin sees new signups without reloading.
- An admin can open a shift and edit actual arrival and leaving times directly on the participant list. When confirming attendance, the shift's planned times are the starting point. If the shift was planned for 12:00–16:00 and a volunteer arrived at 12:30 and left at 15:45, 3 hours and 15 minutes are recorded. Record who changed the times and when.
- The hours overview shows planned hours and recorded working hours per person. Working hours only count once attendance is confirmed, and no-shows are recorded with 0 hours. The history shows the shift's date, its planned times and the actually recorded times.

## Data model

| Entity | Contents |
| --- | --- |
| User | Name and email |
| Login link | User, single-use token, expiry and use |
| Team | Team name, timezone and cancellation deadline |
| Festival year | Team, name, first and last day |
| Membership | User, team, role and access status |
| Invitation | Team, recipient's name and email, expiry, redemption and withdrawal |
| Shift series | Festival year, title, period, and the pattern the series was rolled out with |
| Shift | Festival year, optional shift series, title, planned start and end, capacity and approval setting |
| Signup | Shift, membership, status, attendance and actual times |

A signup connects a shift with a membership in the same team. That keeps the history when a member's access is removed. The shift plan and the hours overview can be filtered to a period within the festival year.

## Build sequence

1. **Access and invitations.** Set up Laravel with Livewire, Flux, Pest, Larastan and Pint. Build teams, memberships, roles, mail-link login and invitations. Run a real invitation in a test environment from creation to first login. Use two teams in the tests, so role and access are checked across them from the start.
2. **Festival years, rollout and self-service.** Build festival years, rollout with preview, shift series, the mobile-friendly day list and My shifts. Make direct signup, pending requests, approval and cancellation work end to end. Test rollout with 2 × 6 and 1 × 12 hours, a shift past midnight, several shifts on the same day, an admin on a shift, cancelling after the deadline, and two simultaneous attempts at the last place.
3. **Day overview and hour registration.** Build the day count, the participant list with time editing, and history per volunteer. Check that two shifts on the same day count as one person, and that a shortened shift gives the right number of hours. Also test shifts past midnight and no-shows.
4. **Pilot with Svaneholdet.** Set up email sending, HTTPS, backup and restore. Let a few admins and volunteers run the flow on their phones from invitation to hour registration. Use their feedback to adjust the navigation before the rest of the team is invited.

## Open decisions

| Topic | Proposal or question |
| --- | --- |
| Overlap | Should overlapping shifts for the same person be blocked or give a warning, also when they are in different teams? |
| Approval | Should an admin be able to put themselves directly on a shift that otherwise needs approval? |
| Changes after signup | Should the volunteer be notified when the time changes, the shift is cancelled, or a request is approved? How do we handle a new capacity below the number already confirmed, also when it changes for a whole shift series? |
| Hours | Are breaks deducted, especially on 12-hour shifts? Will the hours be used for a minimum requirement or an export? |
| New teams and copying | Should new teams be created centrally? Should next year's shift plan be copied from last year or rolled out again? |
