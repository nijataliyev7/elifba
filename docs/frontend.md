# Elifba frontend — UI rules

How Elifba screens look and behave. The rules are adapted from the owner's precedent (the owner's earlier Laravel project) to an app with three kinds of screen: the teacher's pages (laptop and phone), the **board** on the classroom wall, and the **remote** on the teacher's phone. Change this file first when a rule changes; a review checks new UI against it.

Step A2 ships only the starter kit's screens (welcome, authentication, settings, an empty dashboard), all translated. The rules below apply to every screen added from A3 on.

## Building blocks

| Layer      | Where                                                             | What                                                                                                                                                                                             |
| ---------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Tokens     | `resources/css/app.css`                                           | Colours, radius, sidebar and chart tokens for light and `.dark`; `font-sans` (Inter) for the interface and `font-board` (Andika) for text children read, both self-hosted in `resources/fonts/`. |
| Primitives | `resources/js/components/ui`                                      | shadcn/ui from the starter kit. Not linted or formatted; keep them close to upstream. Their few built-in words (`Close`, `More`…) use `useTranslation()`.                                        |
| Shared     | `resources/js/components`                                         | The kit's shell (`app-sidebar`, `breadcrumbs`, `heading`, `text-link`, `input-error`…) and Elifba's own (`language-switcher`). From A3 a kit grows in `components/app`.                          |
| Features   | `resources/js/components/{classes,students,games,rankings,share}` | Screens built from the shared parts (A3 on).                                                                                                                                                     |
| Game       | `resources/js/game/`                                              | The board's engine, contract and Phaser scenes (A6 on); React owns everything around the canvas.                                                                                                 |

Use a shared component when one fits; add to the shared layer instead of copying markup into a feature folder.

## Rules

### 1. Layouts

- `resources/js/app.tsx` picks the layout by page name: `welcome`, `privacy` and `credits` (public pages) have none, `auth/*` uses `AuthLayout`, `settings/*` uses `AppLayout` + `SettingsLayout`, everything else `AppLayout` (sidebar shell). The board (`games/board` from A6, `lovhe` from A7), the remote (`games/remote`, A7) and the phone's pairing pages (`pult/*`, A7) have no app shell: full screen, no sidebar.
- A page sets its layout props statically: `Login.layout = { title: 'auth.login.title', description: 'auth.login.description' }` or `Profile.layout = { breadcrumbs: [{ title: 'settings.profile.title', href: edit() }] }`. Those values are **translation keys**; the layout translates them. A page that changes them at run time calls `setLayoutProps` with keys too.
- Nav items (`NavItem.title`) and breadcrumbs (`BreadcrumbItem.title`) are translation keys as well, translated where they are rendered.

### 2. Language

- Every interface string is in `lang/az.json` and `lang/en.json` (Azerbaijani is the base) and is read with `const { t } = useTranslation()`. The parity test fails on a missing key, an extra key, an empty value or different `:placeholders`.
- Keys are dotted and grouped by screen: `auth.login.*`, `settings.profile.*`, `nav.*`, `common.*` for words used everywhere (`common.save`, `common.cancel`, `common.password`…). Reuse a `common.*` key before adding a new one.
- Placeholders use `:name`; `t('settings.passkeys.added', { time })`. Plurals use `{one}…|{other}…` with a numeric `count`; Azerbaijani keeps the noun singular after a number, so its two forms are usually equal.
- Never join translated fragments into a sentence: Azerbaijani word order differs from English. Give the whole sentence one key with placeholders.
- The language switch (`LanguageSwitcher`) sits in the auth pages' footer, the welcome page's header, the sidebar footer and Settings → Appearance and language. It calls `PUT /locale`; the server keeps the choice.
- Child-facing game text (prompts, words, sentences) is **content**, always Azerbaijani, and never goes into `lang/*.json`.
- Write in plain words a teacher uses. Code comments are English.

### 3. Page header and actions

- Each page starts with one `h1` or a `Heading` and, beside it, the page's main action. A short intro is one line under the title.
- Each teacher page declares its breadcrumbs through its layout props.

### 4. Buttons

- The main action of a form or page is the default `<Button>`; secondary controls are `outline` or `ghost`; deleting is `destructive` and asks first in a `Dialog`.
- A navigating action is an Inertia `<Link>` (with `Button asChild` when it should look like a button), never a button with `router.visit`.
- A form's submit button shows `Spinner` while processing and is disabled meanwhile.
- On the board and the remote, targets are big (at least 64px on the remote's item buttons) and never depend on hover.

### 5. Forms

- Forms are Inertia forms (`<Form {...action.form()}>` with Wayfinder, or `useForm`). Validation stays on the server (`*Data::rules()`, framework messages in `lang/{az,en}/validation.php`); `InputError` shows the field's message.
- Labels are visible (`Label htmlFor`); placeholders only show an example.
- Never show ids or codes as something to type, except the 6-digit pairing code (A7), which is made for that.

### 6. Spacing, grids and phones

- Every grid starts with `grid-cols-1` and adds columns from a breakpoint: `grid grid-cols-1 gap-4 md:grid-cols-2`.
- Nothing may widen a 375px screen: give flex children `min-w-0`, truncate long names, let wide tables scroll inside their own container.
- The remote is used one-handed on a phone: its controls sit in the lower half of the screen.

### 7. Colour, type and theme

- Use the tokens (`bg-background`, `text-muted-foreground`, `bg-primary`, `text-destructive`…), not raw palette classes, on teacher pages. The board's look comes from the theme pack's palette (architecture §2), not from the app's tokens.
- The medal colours of places 1–3 (gold, silver, bronze; `components/share/place-colors.ts`, from OpenMoji's palette) are the one palette exception on teacher pages: the podium page and the share card use them alike, with dark text in both themes. The share card has one fixed light look and never follows dark mode.
- Every teacher screen works in light and dark; check both. The board is always light: its page holds `forceLightTheme()` (`use-appearance`) while it is mounted, so the dialogs it opens are light too.
- Text children read — the board's prompts, items and words, the remote's item buttons — uses `font-board` (Andika, made for beginning readers: clear a, g, I/l and Ə ə Ğ ğ İ ı). Everything else uses `font-sans` (Inter).

### 8. Inertia, not a SPA router

- Links are Inertia `<Link>`; visits are `router.get/put/...` with `preserveScroll` where the page stays.
- Shared state comes from Inertia props (`usePage().props`: `auth`, `locale`, `locales`, `translations`, `sidebarOpen`), not a client store. No i18next, axios, zustand, TanStack Query or react-hook-form.
- Routes are Wayfinder functions (`@/routes/*`, `@/actions/*`), never hand-written URLs.

### 9. Nothing leaves the host

- No CDN, external font, analytics, tracker or error service. Fonts and pictures are files in the repository; the font loader is `local()` in `vite.config.ts`.
- Pictures and sounds arrive as URLs in the page props (an avatar is `{ key, url }`); TypeScript never builds an asset path. A student's avatar is drawn by `StudentAvatar`.
- Children's names appear only on the teacher's pages, the board and the share card's allowed fields (architecture §6, §7), never in a URL, a log or a broadcast.
