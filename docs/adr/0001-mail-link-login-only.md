# Mail-link login only

Users log in only through a **Login link** or an **Invitation** sent by email. We removed the starter kit's registration, password login, password reset, email verification, two-factor authentication and passkeys, along with the `password` column. Volunteers log in on their phones a few times a year, so a password is one more thing they forget, and every extra way in is one more path to secure and test. Reaching the inbox proves the email, so email verification has nothing left to do.

## Consequences

Both kinds of link open a page with a single button, and only the button press uses the token. Mail scanners such as Outlook Safe Links open every link in an email before the person does, and would otherwise use up a single-use link.
