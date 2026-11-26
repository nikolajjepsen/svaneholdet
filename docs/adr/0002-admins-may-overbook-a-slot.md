# Admins may overbook a slot

Capacity binds **Volunteers** and only warns **Admins**. A volunteer's signup on a full **Slot** is refused, under a lock on the slot, so two volunteers can't take the last place at once. An admin who assigns a **Member**, confirms a pending **Shift** or lowers the **Capacity** below the confirmed count gets an over-capacity warning and can go ahead. On the day, the admin knows when one more pair of hands is needed, and a hard limit would only make them raise the capacity, assign the person and lower it again.

## Consequences

The shift module is the one place that enforces capacity, and only for volunteer signups. The database holds no capacity constraint, because an overbooked slot is a valid state. Which shifts are over capacity is worked out when it is read, from the order the shifts were confirmed.
