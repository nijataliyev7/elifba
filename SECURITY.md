# Security

## Threat model

Elifba is a web application that teachers use over the internet, in classrooms full of other people's phones. It holds data about children who cannot consent, so the design keeps that data small and isolated ([architecture §7](docs/architecture.md#7-childrens-privacy)).

What Elifba relies on, now and as the steps land:

- **Teacher accounts only.** Children never log in. Authentication is the Laravel starter kit's (Fortify): hashed passwords, rate-limited login, optional two-factor authentication and passkeys, CSRF protection on every state-changing request.
- **Teacher isolation.** Classes, students and games are resolved only for the teacher who owns them; another teacher's id is a 404 before any controller runs, and a test enumerates every such route (step A3).
- **Pairing codes never authorize.** The 6-digit code on the board only says which board; the phone acts as the logged-in teacher. A guest board on a shared computer holds a secret that reaches only the game it shows (step A7).
- **Minimum data about children:** a display name and an avatar. No surnames, photos, birth dates or contacts. Names never appear in URLs, logs, exceptions or broadcasts.
- **No third-party requests** from pages: fonts and pictures are served by the instance itself.

## Supported versions

Security fixes go into the latest release.

## Reporting a vulnerability

Please use [GitHub's private vulnerability reporting](https://github.com/nijataliyev7/elifba/security/advisories/new) instead of a public issue, or write to the operator of the instance you use (named on its privacy page). Include the steps to reproduce and the impact you see. You will get an answer within a week.

Never test against a public instance with real classes; run your own copy (`composer setup`).
