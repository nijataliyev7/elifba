# 010 — Account roles: teacher and parent, from spatie/laravel-permission

- **Status:** Accepted, 2026-11-11 (roadmap step R1; built in R2)
- **Sections:** architecture.md §0, §1 "Accounts and roles", §7, §11
- **Why it is hard to reverse:** every authenticated route is placed in an area by its role, every account gets a role row, and each role's agreement is stamped on the account. Taking the roles out again means deciding what a parent's account becomes; changing the package means migrating `model_has_roles` for every account.

## Context

Until now the only account was the teacher: `App\Models\User` had no role, and every signed-in page assumed a class. The owner decided (2026-11-11) that parents use Elifba at home with their own children, without a class, and fixed four things: two roles for now, `teacher` and `parent`; the user chooses «Müəllim» or «Valideyn» right after registration, by e-mail or with Google; one account may hold both; the roles come from `spatie/laravel-permission`, not from a role system of our own.

Checked on 2026-11-11: the package's current release is 8.5.0 (2026-11-06); it requires `php ^8.3` and `illuminate/* ^12.0|^13.0`, and develops against `larastan/larastan ^3.9` and `phpstan/phpstan ^2.1`, the versions Elifba uses. A throwaway copy of this repository with the package installed, `HasRoles` on `User`, the published config and migration, and role checks through a backed enum passed PHPStan level 8 with no ignore, and a probe test (assign by enum, the `role` middleware, both roles on one account, roles detached when the account is deleted) passed on SQLite.

## Decision

1. **The package, roles only.** `spatie/laravel-permission ^8.5`. `App\Models\User` uses `HasRoles`. `App\Enums\Role` (`Teacher = 'teacher'`, `Parent = 'parent'`) is the only place the names are written; code passes the enum, never a string. The two `roles` rows (guard `web`) are created by the migration, and a test pins that the table equals the enum. No permission is defined: a role says which area an account may enter, and ownership says which rows (decision 3).
2. **Configuration** (`config/permission.php`, published): `teams` false; `register_permission_check_method` false, so the package adds no `Gate::before` and a policy check never reads the permission cache; `cache.store` `default`; everything else as shipped. The migration is the package's, published once and then ours: its five tables as they are, `model_has_roles.model_id` an unsigned big integer like `users.id`. `Relation::morphMap(['user' => User::class])` (in `AppServiceProvider`) makes `model_type` the alias `user`, so the rows do not hold a class name.
3. **Two layers, as for isolation.**
    - _Area_, by route middleware. Every authenticated route is in exactly one group: teacher (`role:teacher` — classes, students, class games, rankings, the podium, teacher words), parent (`role:parent` — children, home games), shared (`role:teacher|parent` — the board page, the join code, the remote, `/pult`, sending a game to a guest board) or account (profile, security, appearance, play settings, roles: no role named). `role` is the package's `RoleMiddleware`. The board endpoints a guest board's bearer may call (`auth:board,web`) carry no role middleware: a bearer is not an account, and `GameSessionPolicy::play` already decides.
    - _Rows_, by ownership: scoped route binding and the policies, unchanged. Policies never ask for a role. Binding resolves before the role middleware, so another account's id is a 404 for every role.
    - A request to an area the account lacks is 403 (`UnauthorizedException`); for a page visit (GET, not JSON) the handler turns it into a redirect to `/start`, so a typed or bookmarked address leads home instead of to an error.
4. **Role choice right after registration.** Registration creates an account with no role, by the form (`CreateNewUser`) and by the Google completion page (`CreateUserFromGoogle`) alike. `EnsureRoleChosen` (alias `role.chosen`, on every group of decision 3 that names a role, and on the settings pages except the profile) sends an account without a role to `GET /role` (page `auth/choose-role`): two cards, «Müəllim» and «Valideyn», each with one sentence about what it is for and the agreement of that role (ADR 013). `POST /role` → `App\Actions\Roles\GrantRole`. The page needs `auth` only, so the choice comes before e-mail confirmation. A JSON or non-GET request without a role gets 409 `{error: "role_required"}`. The profile page stays reachable without a role, so an account can always be deleted.
5. **Home by role.** `GET /start` (`auth`) redirects: no role → `/role`; teacher → `/classes`; parent → `/children`; both → the account's area (decision 7). Everything that sent a signed-in teacher to `/classes` — Fortify's `home`, `redirectUsersTo`, the old `/dashboard` redirect, the Google callback, the logo — points at `/start`.
6. **The second role** is added in Settings → «Rollar» (`/settings/roles`): the role the account lacks, with its agreement; `POST /settings/roles` → the same `GrantRole`. A role is never removed in v1: classes or children would be left without an area. An account that wants out of a role deletes what it holds, or the account.
7. **The area of an account with both roles** is a choice, stored in `users.area` (`teacher` | `parent`, null until a role is chosen): `GrantRole` sets it to the role just granted, `PUT /area` → `App\Actions\Roles\SwitchArea` changes it and redirects to that area's home. The shell shows the switch only to an account with both roles. A page inside an area shows that area's navigation; `users.area` decides only where `/start` lands and which navigation the shared and account pages show. With one role the column is ignored.
8. **Existing accounts become teachers.** The migration gives every existing account the `teacher` role, `teacher_agreed_at` = its `terms_accepted_at` (they accepted the school sentence at registration) and `area` = `teacher`, with the query builder only.
9. **Tests stay green.** `UserFactory` gives a created account the teacher role and its agreement by default, with states `parent()`, `teacherAndParent()` and `withoutRole()`; `make()` touches no table, as unit tests need.
10. **Pages know the roles.** The shared Inertia prop `auth` gains `roles` (the role names) and `area`; `auth.user` is unchanged.

## Consequences

- A new authenticated route cannot forget its area: `RoleAreasTest` enumerates the routes and fails for one that names no role and is not on its short account list.
- A parent never reaches a class, a ranking or a teacher word, and a teacher without the parent role never reaches a child, even on the same instance.
- An account can exist with no role between registration and the choice. It owns nothing and reaches only `/role`, its profile and logout.
- One more query per request (the account's roles); no permission cache is read.
- `terms_accepted_at` keeps its meaning (the privacy note, at registration); the school sentence and the parent sentence get their own moments (ADR 013).
- A third role later is an enum case, a `roles` row, an agreement column and a route group.

## Alternatives rejected

- **A role column or our own pivot table:** the owner asked for the package, and two roles on one account already need a many-to-many.
- **The role as a field of the registration form:** the Google completion page would need the same field, and the agreement of each role would have to be accepted before the account exists; one `/role` page serves the form, Google, accounts without a role and the second role.
- **Policies that check roles instead of route middleware:** the list and create routes have no row to authorize, and a forgotten check opens an area.
- **Permissions (`classes.manage`, `children.manage`…):** two roles with fixed areas need no matrix; the Gate hook would run on every policy check.
- **One sidebar with both areas:** the phone's bottom bar has no room, and the owner asked for a switch.
- **The area kept in the session or a cookie:** it would differ between the laptop and the phone, and reset at every login.
- **403 for a page visit to the wrong area:** a dead end for a parent who typed `/classes`.
- **Removing a role:** it needs a rule for the rows the role owned; not asked for.

## What would make us revisit

A third kind of account (a school administrator, a second parent sharing children), which would bring permissions or teams; or the package dropping support for the Laravel version in use.
