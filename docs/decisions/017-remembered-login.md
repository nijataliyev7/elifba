# 017 — Remembered login: 30 days, on by default, cut at once

- **Status:** Accepted, 2026-11-13 (the owner's decisions of that day: «Məni xatırla» on by default on the login and registration pages, with the hint «Ortaq kompüterdə bu işarəni götürün.» under it; Google sign-in follows the same box; 30 days).
- **Sections:** architecture.md §7 ("Shared computers", "Google sign-in", "Remembered login")
- **Amends:** [008](008-google-sign-in.md) decision 6 ("Everyone else is logged in without 'remember me'"): a Google login is remembered when the box was ticked, and the two-factor challenge carries the same choice. architecture.md §7 "Shared computers" (the box was off by default and Google never remembered). Fortify's logout, which ended every remembered device of the account, is replaced.
- **Why it is hard to reverse:** once teachers and parents expect to stay signed in on their own phone for a month, taking it back signs every one of them out mid-lesson and makes the phone remote (§4) ask for a password again every two hours; and every remembered device carries a cookie that only the rules below can cut.

## Context

A session lasts 120 minutes after its last request (`SESSION_LIFETIME`, the `database` driver). A teacher who drives the board from their phone, or a parent who plays every evening, typed the password again every time a session had ended. The login form had a «Məni xatırla» box, off by default, and with it ticked Laravel kept the login for its default of 400 days; Google sign-in never remembered at all (ADR 008 decision 6). Nothing cut a remembered login but Fortify's logout, which cycled the account's remember token and so ended it on every device at once: one «Çıxış» on a classroom computer signed the teacher's phone out too. Changing the password on the security page wrote it straight to the row and left every other session and remembered device in.

The owner's other project, panelaz, solved the same problem (a 30-day recaller, a token rotated on every credential change, the acting device kept), and the owner asked for the same here on 2026-11-13.

## Decision

1. **Thirty days.** `config/auth.php` gives the `web` guard `'remember' => (int) env('AUTH_REMEMBER_DURATION', 43200)` (minutes): a ticked box sets Laravel's recaller cookie (`remember_web_…`, `id|remember_token|password-hash MAC`, encrypted) for 30 days instead of 400. Sessions stay 120 minutes and in the `database` driver; when one ends, the next request of a remembered browser is logged in again from the cookie, with a new session. The `board` guard and guest boards are not touched: a guest board has no password and no "remember me" (§4).
2. **On by default, one box for both ways in.** The login and registration pages have one «Məni xatırla» box, ticked when the page opens, with «Ortaq kompüterdə bu işarəni götürün.» under it. It is sent with the e-mail form (`remember`, as Fortify reads it), and the Google button's link carries it (`/auth/google/redirect?remember=1|0`). A link without the parameter is not remembered: only the pages say yes.
3. **Google follows the box.** The redirect keeps the choice in the round trip's intent (`google.intent`: `['for' => 'login', 'remember' => bool]`); a new teacher's choice stays with the pending profile through the completion page (`google.pending.remember`). `GoogleSignIn::logIn()` logs in with it, and a two-factor account's challenge gets it as `login.remember`, which Fortify's challenge then honours, exactly as for a password login.
4. **An account without a password can be remembered.** Laravel binds the recaller to the password hash and refuses the cookie of an account whose password is not a string; an account made with Google has none. `User::getAuthPassword()` gives `''` for a null password, so its recaller is bound to an empty hash and guarded by the remember token alone. Hash checks against `''` always fail, so the password login and the password confirmation still refuse such an account.
5. **Every credential change cuts every other device at once.** Changing the password on the security page (`App\Actions\Settings\UpdatePassword`), resetting it by mail (`App\Actions\Fortify\ResetUserPassword`) and the new «Başqa cihazlardan çıx» (`App\Actions\Settings\LogOutOtherDevices`, which the other two call) rotate the account's remember token, so every recaller cookie ever issued stops matching, and delete the account's rows of the `database` session driver but this device's (a reset deletes all of them: it is made by a guest). The log line carries the account id only. Linking an unconfirmed account to Google already did the same (ADR 008 decision 4).
6. **The acting device stays in, and keeps "remember me" only if it had it.** After a password change or «Başqa cihazlardan çıx», this device's session goes on. It gets a new recaller (with the new token) only when the request carried a recaller that is valid, belongs to this account and holds the token from before the rotation (`hash_equals`), as panelaz's `reissueRecallerForCurrentDevice` does (`App\Support\RememberedLogin::keepThisDevice()`). A recaller cookie's name is the same for every account, so a stale cookie left by someone else, or one already revoked, on a shared browser must not mint a 30-day login for the person now at the keyboard.
7. **«Başqa cihazlardan çıx»** is a button on the security page (`DELETE /settings/other-devices`, `other-devices.destroy`), next to the password form and in the same group (`auth`, `role.chosen`, `verified`), behind a fresh password confirmation (`RequirePassword`, or Google's for an account without a password, ADR 008) and `throttle:6,1`. It needs no password typed: the page already asked.
8. **«Çıxış» ends this device only.** `POST /logout` is ours (`App\Http\Controllers\Auth\LogoutController`, registered after Fortify's, so it replaces the route and the name): `logoutCurrentDevice()` forgets this browser's recaller cookie and the session is invalidated, but the remember token is not cycled, so the account's other remembered devices stay in. Ending them is «Başqa cihazlardan çıx».

## Consequences

- A teacher on their own phone signs in about once a month; the remote keeps working across the 120-minute session ends, since a request after the end is logged in again from the cookie. A request sent from a page left open across the end has a stale CSRF token; Laravel 13's `PreventRequestForgery` accepts it when the browser says `Sec-Fetch-Site: same-origin`, so it is not a 419.
- On a shared computer whose box was left ticked, the next person finds the account open for up to 30 days, until the owner presses «Başqa cihazlardan çıx» or changes the password from another device. The hint under the box, the visible «Çıxış» on the board and the guest board (§4) are the defence; the box stays a choice.
- Another session ends at once only with the `database` session driver (the default, `.env.example` and docker-compose). With another driver a cut device keeps its current session until it expires (120 minutes), and then its cookie no longer logs it in.
- A device that logged out keeps no cookie, but the token it held stays valid until the next rotation; that is the cost of not signing every other device out with it.
- No new table or column: the token is `users.remember_token`, the sessions are the `sessions` table. `SESSION_LIFETIME`, the session driver and the `board` guard are unchanged.

## Alternatives rejected

- **Laravel's 400 days:** a lost phone would stay signed in for more than a school year.
- **A 30-day session lifetime instead of a cookie:** every session row would live a month, and the unticked box would mean nothing.
- **Off by default, Google never remembered (the old rule):** the owner decided otherwise; teachers sign in on their own phones, and shared computers have the guest board.
- **Fortify's logout (cycling the token):** one «Çıxış» on a classroom computer would sign the teacher's phone out in the middle of a lesson.
- **`Auth::logoutOtherDevices($password)` with the `AuthenticateSession` middleware:** it needs the password typed again (an account made with Google has none), rehashes it, and runs on every request; deleting the rows of the `database` driver ends the sessions outright.
- **A revocation watermark in the session (panelaz's `sessions_revoked_at`):** panelaz needs it for Redis, where sessions have no user index; Elifba's sessions are rows with `user_id`, and it would need a new column.

## What would make us revisit

A session driver without a user index (Redis), schools asking for a shorter or a per-instance duration (it is already `AUTH_REMEMBER_DURATION`), or a list of devices to end one by one.
