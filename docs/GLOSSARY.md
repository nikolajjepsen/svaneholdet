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
The **Role** of a **Member** who runs the **Team**, given by another **Admin** through promotion. An **Admin** can also take shifts like a **Volunteer**.
_Avoid_: Manager, leader, organiser

**Invitation**:
An **Admin**'s offer of a **Membership** as **Volunteer** to an email address. Redeeming it creates the **Membership**, or brings back a removed one, and logs the person in. It can be pending, redeemed, withdrawn or expired.
_Avoid_: Invite code, signup

**Login link**:
A short-lived, single-use link sent by email that logs a **User** in.
_Avoid_: Magic link, token, password
