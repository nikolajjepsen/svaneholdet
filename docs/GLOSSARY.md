# Svaneholdet

Shift planning for volunteer teams at Smukfest, from invitation to staffed shifts and recorded hours.

## Language

### Access

**Team**:
A group of volunteers at Smukfest that plans its own shifts, such as Svaneholdet.
_Avoid_: Organisation, group, hold

**User**:
A person who can log in, identified by their email. A **User** can be a **Member** of several **Teams**.
_Avoid_: Account, profile

**Membership**:
The link between a **User** and a **Team**, carrying one **Role**. It is either active or removed, and a removed **Membership** keeps its history.
_Avoid_: Access, enrolment

**Member**:
A **User** with an active **Membership** in a given **Team**.

**Role**:
What a **Member** may do on a **Team**: **Volunteer** or **Admin**.

**Volunteer**:
The **Role** of a **Member** who takes shifts. Every **Membership** starts as **Volunteer**.

**Admin**:
The **Role** of a **Member** who runs the **Team**, given by another **Admin** through promotion. An **Admin** can also take **Shifts**, which do not count toward **Capacity** on regular **Slots**.
_Avoid_: Manager, leader, organiser

**Invitation**:
An **Admin**'s offer of a **Membership** as **Volunteer** to an email address. Redeeming it creates the **Membership**, or brings back a removed one, and logs the person in. It can be pending, redeemed, withdrawn or expired.
_Avoid_: Invite code, signup

**Login link**:
A short-lived, single-use link sent by email that logs a **User** in.
_Avoid_: Magic link, token, password

### Scheduling

**Season**:
One Smukfest that a **Team** plans for, such as Smukfest 2027. A **Team** has one current **Season**, and it is the only one **Volunteers** see.
_Avoid_: Festival year, edition, event

**Schedule**:
A **Team**'s **Slots** in a **Season**, laid out day by day, where **Members** sign up.
_Avoid_: Shift plan, day list, calendar

**Slot**:
A planned need for people on the **Schedule**: a start, an end and a **Capacity**. It can end the day after it starts.
_Avoid_: Opening, position, interval

**Admin-only slot**:
A **Slot** that only **Admins** can see on the **Schedule**, sign up for, or be assigned to, and whose **Capacity** counts **Admins**.
_Avoid_: Leader slot, coordinator slot, staff slot

**Slot series**:
The **Slots** one rollout interval creates, one per picked date, which an **Admin** can change or delete together.
_Avoid_: Shift series, pattern, recurrence

**Capacity**:
The number of **Volunteers** a regular **Slot** asks for, or the number of **Admins** an **Admin-only slot** asks for. **Admins** may confirm more **Shifts** than the **Capacity**.
_Avoid_: Places, seats, requested volunteers

**Shift**:
One **Member**'s place on one **Slot**. It is pending, confirmed, rejected or cancelled. A pending **Shift** is what people call a request, which an **Admin** confirms or rejects. An **Admin** assigning a **Member** creates a confirmed **Shift**.
_Avoid_: Signup, booking, registration, approved

### Staffing and hours

**Day overview**:
The **Admin** view of a single day's **Slots**, showing each **Slot**'s confirmed and pending **Shifts** and the day's total unique confirmed **Members**.
_Avoid_: Staffing view, daily dashboard

**Attendance**:
Whether and when a **Member** worked a confirmed **Shift**. By default it follows the **Slot**'s planned times; an **Admin** only intervenes to adjust actual times or mark a **No-show**.
_Avoid_: Check-in, presence, time log

**No-show**:
An **Attendance** state where a **Member** did not turn up for a confirmed **Shift**, counting as zero **Worked hours**.
_Avoid_: Absent, missed shift

**Planned hours**:
The total scheduled duration of a **Member**'s confirmed **Shifts**, counting overlapping time once.
_Avoid_: Scheduled hours, expected hours

**Worked hours**:
The total actual duration of a **Member**'s ended (or adjusted) confirmed **Shifts**, using adjusted times when edited, zero for a **No-show**, and planned times by default, counting overlapping time once.
_Avoid_: Recorded hours, working hours, actual hours, logged hours

**Hours overview**:
The **Admin** view of every **Member**'s **Planned hours**, **Worked hours**, and **Shift** history for the **Season**.
_Avoid_: Timesheet, hour report

**Hour goal**:
The target number of hours a **Member** is expected to work in a **Season**, defaulting to the **Team** setting with an optional per-**Member** override. Progress counts confirmed and pending **Shifts** (using adjusted times or zero for a **No-show**, and counting overlapping time once).
_Avoid_: Quota, target hours, hour requirement
