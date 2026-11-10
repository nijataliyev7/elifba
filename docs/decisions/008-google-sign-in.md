# 008 — Google sign-in for teachers

- **Status:** Accepted, 2026-11-10
- **Sections:** architecture.md §1, §7, §8, §10
- **Why it is hard to reverse:** once teachers sign in with Google, many accounts have no password. Removing the button later locks them out until each one sets a password through a reset mail, which a self-hosted instance without SMTP cannot send.

## Context

A teacher registers with an e-mail address and a password, and confirms the address with a link (`verified` guards the classes, the games and most settings). Production mail is `log` until an operator sets SMTP (§10), so on many instances the confirmation mail never arrives. Almost every teacher in Azerbaijan already has a Google account, and a school computer or phone is easier with one tap than with a new password. The owner decided (2026-11-10) to offer «Google ilə daxil ol» on `/login` and `/register`, for teachers only, without weakening any existing rule.

## Decision

1. **Laravel Socialite's Google driver, server side only.** `GET /auth/google/redirect` (guest) sends the browser to Google with the scopes `openid email profile`, `prompt=select_account` (shared school browsers stay signed in to someone's Google account) and PKCE; Socialite keeps the OAuth `state` in the session. `GET /auth/google/callback` takes the answer. The redirect URI is the relative `/auth/google/callback`, so it follows the host the teacher used. No Google script, One Tap or font is loaded by a page: the button is a plain link to our own route and the G mark is an inline SVG (§8).
2. **Off unless configured.** The routes always exist (Wayfinder generates them everywhere), but answer 404 and the button is hidden unless both `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` are set.
3. **Stored: Google's account id only.** `users.google_id` (nullable, unique) holds the OpenID `sub`. No picture, no access or refresh token, no other profile field. The name and e-mail Google sends become the account's own name and e-mail only when the account is created.
4. **Who signs in.** The callback refuses an address Google does not vouch for: one it has not verified, and one that is neither Gmail nor a Google Workspace account (`hd`), which Google verified only once and which may have changed hands since. It finds the teacher by `google_id`, else by e-mail (case-insensitive) and links the account:
    - an account whose address was confirmed is linked as it is, unless it is already linked to another Google account (refused);
    - an account whose teacher changed to this address and has not confirmed it yet (`users.email_changed_at`) is refused: it is theirs, whoever holds the address;
    - an account whose registration address was never confirmed may have been registered by someone else with this address. Linking marks it confirmed and drops every credential set before: the password (now null), two-factor secret and recovery codes, passkeys, the remember token and every other session of the account (rows of the `database` session driver, the default and the one production uses).
5. **New teachers** go through a completion page: the name from Google, editable, and the same privacy-note checkbox as registration (§7 "Teacher's agreement"). The pending Google profile lives only in the session; it is honoured for 10 minutes and forgotten when the account is created or the teacher cancels. `App\Actions\Fortify\CreateUserFromGoogle` (next to `CreateNewUser`) creates the account with `terms_accepted_at`, `email_verified_at`, `locale` and `google_id`, and no password.
6. **Two-factor is never skipped.** A teacher with confirmed two-factor authentication is sent to Fortify's challenge (`login.id`, `login.remember` = false in the session), exactly as a password login is. Everyone else is logged in without "remember me", with a fresh session id.
7. **The link follows the address.** Changing the address in the profile unlinks Google and stamps `email_changed_at`; a teacher without a password must set one first.
8. **Accounts without a password.** Password login fails for them with the usual "credentials do not match", so the form does not reveal which addresses use Google. Where Fortify asks for the password (the security page, two-factor and passkey management), the confirm page offers «Google ilə təsdiqlə» instead of a password field — only to them: a teacher with a password confirms with it, as a Google session left open on a school computer must not stand in for it: `GET /auth/google/confirm` (auth) makes a round trip to Google, and only the same `google_id` marks the password as confirmed. The security page lets them set a password without a current one, and deleting the account asks for that fresh confirmation instead of the password. A password reset mail still works for them, as it proves the address.

## Consequences

- An instance without SMTP can still onboard teachers: Google's verified address is the confirmation.
- Teachers' names and e-mail addresses go from Google to Elifba at sign-in; nothing about children ever goes to Google. `/privacy` says so.
- Teachers whose Google account uses another provider's address (mail.ru, a school mailbox outside Google Workspace) cannot sign in with Google; they use a password.
- Google can stop a teacher from signing in (a closed or suspended Google account). Such a teacher sets a password through a reset mail or asks the operator.
- The owner of each instance registers its own OAuth client in Google Cloud Console; the redirect URI must list every host teachers use.
- Logs carry account ids, never an e-mail or a name from Google.

## Alternatives rejected

- **Google Identity Services (the JavaScript button, One Tap):** a third-party script on our pages, against §7 "Never on the wire".
- **Linking an unconfirmed account and keeping its password:** whoever registered the address first, before the real owner, would keep access to the owner's account.
- **Creating the account straight from the callback:** the teacher would skip the privacy-note agreement that registration requires.
- **Trusting every address Google marks verified:** for an address outside Gmail and Google Workspace, Google only saw it once, when the Google account was made; a school mailbox handed to a new teacher would let its former holder into the new teacher's account.
- **Storing the avatar or tokens:** nothing in Elifba uses them, and every stored field is one more to protect.
- **Other providers (Microsoft, Apple, Facebook):** not asked for; each would be a new ADR.

## What would make us revisit

Schools asking for Microsoft accounts, a need for Google Classroom data (that would mean tokens and a new privacy notice), or Google changing its OpenID Connect claims.
