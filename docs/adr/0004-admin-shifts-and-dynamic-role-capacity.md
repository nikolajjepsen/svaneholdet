# Admin shifts and dynamic role capacity

**Admins** can take **Shifts**, and on regular **Slots** their **Shifts** do not count toward **Capacity**, which measures the **Volunteers** needed. On **Admin-only slots**, **Capacity** counts **Admins** instead, and **Volunteers** cannot see, sign up for, or be assigned to them. Whether a confirmed **Shift** counts toward a **Slot**'s **Capacity** is derived dynamically from the **Membership**'s current **Role** when read, rather than snapshotting the role at signup time.

## Consequences

Promoting a **Volunteer** to **Admin** frees their regular-**Slot** **Capacity** automatically, and demoting an **Admin** to **Volunteer** moves their regular-**Slot** **Shifts** into **Capacity** (potentially putting a full **Slot** over capacity, which is valid per ADR 0002) while cancelling their upcoming **Shifts** on **Admin-only slots**. Over-capacity ranking and **Slot** counts always reflect the current roster without stale role data on **Shifts**.
