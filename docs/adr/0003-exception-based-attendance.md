# Exception-based attendance

Confirmed **Shifts** count as worked according to their **Slot**'s planned times by default, without requiring an **Admin** to check every **Volunteer** in. During the festival, admins are busy running shifts and should only have to intervene for exceptions: adjusting actual arrival or leaving times, or marking a **No-show** (zero **Worked hours**). We considered requiring explicit attendance confirmation on every shift, but that adds repetitive administrative work for the normal case where everyone works as planned.

## Consequences

A confirmed **Shift** automatically contributes to **Worked hours** once its **Slot** has ended, or immediately if an **Admin** records adjusted times or a **No-show** before it ends. Unedited shifts follow the **Slot**'s planned times, while edited shifts keep their adjusted actual times (along with who made the change and when) until reset to planned.
