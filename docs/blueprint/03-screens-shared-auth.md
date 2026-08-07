# Phase 2 — Shared & Authentication Screens

18 screens used across every client. Conventions from
[02-screens-conventions.md](02-screens-conventions.md) apply throughout.

---

## Authentication

### SH-01 · Sign In `P0` `FR-01`

**Purpose** — Establish identity and start a session.
**Access** — Public.
**Entry** — App launch when unauthenticated; any protected URL; expired session; sign-out;
email link.
**Exit** — Role-appropriate landing screen (`AD-01` / `DR-01` / `ST-01` / `PA-01`) · Forgot
password `SH-03` · Register `SH-02` · MFA challenge `SH-06`.

**Actions**
- Enter email or registration number, and password
- Toggle password visibility
- Remember this device (extends refresh lifetime on trusted devices only)
- Sign in with institutional SSO, where configured
- Navigate to registration or password reset
- Choose interface language

**Validations**
- Email format; password non-empty
- Both fields validated client-side before the request, and server-side always
- Failure messaging is identical for unknown account and wrong password
- Attempts rate-limited per account and per origin address; the limit is stated when reached
- Progressive delay after repeated failures on the same account

**States**
- *Error — bad credentials*: generic message, password cleared, email retained
- *Error — account deactivated*: distinct message directing the user to the transport office.
  This is safe to distinguish because it is only reachable with a correct password
- *Error — locked*: states when it unlocks and offers the reset path
- *Error — offline*: cached credentials do not authenticate; explain that a connection is
  required to sign in, and that an existing driver session continues to work offline

**Workflows** — [F-02 Authentication](09-system-flows.md#f-02).

---

### SH-02 · Register `P0` `FR-01`

**Purpose** — Self-service student account creation.
**Access** — Public. **Creates a student account only** — no other role is selectable, and
requesting one is refused server-side.
**Entry** — Sign in screen; institutional invitation link.
**Exit** — Verification pending `SH-04` · Sign in.

**Actions**
- Enter name, email, phone, password and confirmation
- Enter registration number, department, year of study
- Accept terms and the privacy notice (explicit, unticked by default)
- Submit; resend verification

**Validations**
- Email unique and institutional-domain-restricted where configured
- Registration number unique, and matched against the institution's student roll where an
  integration exists; unmatched numbers go to a manual approval queue rather than being
  rejected outright
- Phone unique and format-valid
- Password meets the published strength policy, shown as live feedback, not as a post-submit
  error
- Confirmation matches
- Terms acceptance required
- Bot protection on submission

**States**
- *Success*: account created, verification dispatched, routed to `SH-04`
- *Error — duplicate*: field-level, with a route to sign-in or password reset
- *Error — roll not matched*: explains the record is pending manual approval and what happens next

**Workflows** — [F-01 Onboarding](09-system-flows.md#f-01).

---

### SH-03 · Forgot Password `P0` `FR-01`

**Purpose** — Begin credential recovery.
**Access** — Public.
**Entry** — Sign in.
**Exit** — Reset link sent confirmation · Sign in.

**Actions** — Enter email; submit; resend after a cooldown.

**Validations** — Email format. Rate-limited per address and per origin.

**States**
- *Success*: an identical confirmation is shown **whether or not** the address exists — this
  screen must not become an account-enumeration oracle
- *Cooldown*: resend disabled with a visible countdown

---

### SH-04 · Verify Account `P1` `FR-01`

**Purpose** — Confirm ownership of the email address or phone number.
**Access** — Authenticated but unverified.
**Entry** — After registration; from the emailed link; on sign-in while unverified.
**Exit** — Landing screen on success.

**Actions** — Enter the code, or follow the link; resend; change the address if it was
mistyped; contact support.

**Validations** — Code format and expiry; attempt limit; resend cooldown.

**States** — *Expired*: offer resend rather than failure. *Already verified*: proceed
silently rather than erroring.

---

### SH-05 · Reset Password `P0` `FR-01`

**Purpose** — Set a new password from a recovery link.
**Access** — Holder of a valid, unexpired, single-use reset token.
**Entry** — Emailed link.
**Exit** — Sign in.

**Actions** — Enter new password and confirmation; submit.

**Validations** — Token valid, unexpired, unused; password meets policy; must differ from the
current password; confirmation matches.

**States**
- *Success*: **all existing sessions on all devices are terminated**, and the screen says so
  explicitly before routing to sign-in
- *Error — token invalid or used*: explain and offer to restart recovery

---

### SH-06 · Multi-Factor Challenge `P1` `[NEW]`

**Purpose** — Second authentication factor. Mandatory for all staff roles.
**Access** — Users with MFA enabled, mid-authentication.
**Entry** — After successful password verification.
**Exit** — Landing screen · Recovery code entry.

**Actions** — Enter the time-based code; use a recovery code; trust this device for a bounded
period; resend an SMS code where SMS is the configured factor.

**Validations** — Code validity and window; attempt limit before lockout; recovery codes are
single-use.

---

### SH-07 · MFA Enrolment `P1` `[NEW]`

**Purpose** — Register a second factor.
**Access** — Any authenticated user; forced for staff on first sign-in.
**Entry** — Security settings; forced interstitial.
**Exit** — Security settings.

**Actions** — Choose method; scan or enter the secret; confirm with a code; download recovery
codes; acknowledge that recovery codes have been stored.

**Validations** — Confirmation code must verify before enrolment completes; recovery codes
must be explicitly acknowledged, since a lost factor without them is an account loss.

---

### SH-08 · Session Expired `P1`

**Purpose** — Explain an interrupted session without losing the user's work.
**Access** — Any.
**Entry** — Token expiry or revocation detected mid-use.
**Exit** — Sign in, returning to the interrupted destination.

**Actions** — Re-authenticate inline where possible; recover unsaved form content.

**States** — Where a form was in progress, its content is preserved locally and restored
after re-authentication. Losing a half-completed incident report because a token expired is
an unacceptable failure.

---

## Profile and settings

### SH-09 · My Profile `P0`

**Purpose** — View and edit one's own account.
**Access** — Any authenticated user, own record only.
**Entry** — Avatar menu; settings.
**Exit** — Edit form · Security settings · Notification preferences.

**Actions**
- View name, contact details, role, photograph, and role-specific profile summary
- Edit name, phone, address, emergency contact, photograph
- Request correction of fields the user cannot edit themselves (registration number, licence
  data, role) — routed to staff as a request, not applied directly

**Validations** — Email and phone unique and format-valid; changing either requires
re-verification of the new value before it takes effect; image type and size limits.

**States** — *Pending verification*: the new contact value is shown as pending alongside the
current one, which remains in force until verified.

---

### SH-10 · Edit Profile `P1`

**Purpose** — The editing form for SH-09.
**Access** — Own record.
**Actions** — Save; cancel; remove photograph; revert a pending contact change.
**Validations** — As SH-09. Unsaved-changes protection on exit.

---

### SH-11 · Change Password `P0` `FR-01`

**Purpose** — Rotate one's own password.
**Access** — Own account.
**Entry** — Security settings.
**Exit** — Sign in.

**Actions** — Enter current password, new password, confirmation; submit.

**Validations** — Current password verified by hash comparison; new password meets policy,
differs from current, and is not among the recent passwords retained for reuse prevention;
rate-limited.

**States** — *Success*: **every session everywhere is revoked**, stated plainly, and the user
is returned to sign-in. This is deliberate: if the old password leaked, the attacker's
session must die with it.

---

### SH-12 · Security Settings `P1` `[NEW]`

**Purpose** — See and control the account's security posture.
**Access** — Own account.
**Entry** — Settings.
**Exit** — Change password · MFA enrolment · Active sessions.

**Actions** — Manage MFA; view recent sign-in activity with time, approximate location and
device; sign out of all devices; download one's own data; request account deletion.

**States** — Unrecognised sign-in activity is highlighted with a direct route to "secure my
account", which rotates the password and revokes all sessions in one step.

---

### SH-13 · Active Sessions `P2` `[NEW]`

**Purpose** — Enumerate and revoke sessions.
**Access** — Own account; staff can view (not impersonate) another user's sessions for support.
**Actions** — Revoke an individual session; revoke all others; mark a device trusted.
**States** — The current session is labelled and cannot be revoked from itself without an
explicit sign-out.

---

### SH-14 · Notification Preferences `P1` `FR-10`

**Purpose** — Control what the system sends and how.
**Access** — Own account.
**Entry** — Settings; a link at the foot of any notification.
**Exit** — Settings.

**Actions**
- Per category (trip started, approaching, delay, incident, announcement, attendance,
  finance), choose channels: push, SMS, email, in-app
- Set quiet hours
- Mute a category entirely
- Send a test notification

**Validations** — Safety-critical categories (SOS, incident, cancellation) **cannot** be
muted and cannot be silenced by quiet hours. They are displayed as locked-on with an
explanation, not hidden.

---

### SH-15 · Notification Centre `P0` `FR-10`

**Purpose** — The history of everything the system has told this user.
**Access** — Own notifications only.
**Entry** — Bell icon on every screen; push notification tap.
**Exit** — The referenced entity (trip, incident, announcement).

**Actions** — Read; mark read; mark all read; filter by category and read state; delete;
deep-link through to the subject.

**List behaviour** — Newest first, grouped by day. Unread badge. Infinite scroll. Retained
for a configured period, then purged.

**States** — *Empty*: "No notifications yet — you'll be told when your bus is on the way."

---

### SH-16 · Announcements `P1` `FR-10`

**Purpose** — Read broadcast messages from the transport office.
**Access** — Any authenticated user; targeted by audience.
**Entry** — Home screen banner; notification centre.
**Exit** — Announcement detail.

**Actions** — Read; acknowledge, where acknowledgement is required; filter by category.

**List behaviour** — Pinned and urgent items first, then newest. Expired announcements drop
out automatically.

---

### SH-17 · Help & Support `P2`

**Purpose** — Self-service answers and a route to a human.
**Access** — Any.
**Actions** — Browse FAQ by role; search help; view transport office contact details and
hours; report a problem (attaches diagnostic context automatically); view emergency contacts.
**States** — Emergency contact information is available offline and without authentication —
it is useless if it requires a working session to reach.

---

### SH-18 · About & Legal `P2`

**Purpose** — Version, terms, privacy, licences.
**Access** — Any, including unauthenticated.
**Actions** — View version and build; read terms and privacy notice; view third-party
licences; view data-retention summary; withdraw optional consents.
**States** — When terms have changed since acceptance, an interstitial requires review before
continuing; safety-critical functions remain reachable meanwhile.
