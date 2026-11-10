# Elifba frontend — UI rules

How Elifba screens look and behave. The rules are adapted from the owner's precedent (the owner's earlier Laravel project) to an app with three kinds of screen: the teacher's pages (laptop and phone), the **board** on the classroom wall, and the **remote** on the teacher's phone. Change this file first when a rule changes; a review checks new UI against it.

Step A2 shipped the starter kit's screens (welcome, authentication, settings), all translated. The kit's empty dashboard is gone: a signed-in teacher lands on Siniflər. The rules below apply to every screen added from A3 on.

## Building blocks

| Layer      | Where                                                             | What                                                                                                                                                                                                                                                                                                    |
| ---------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tokens     | `resources/css/app.css`                                           | Colours, radius, sidebar and chart tokens for light and `.dark`; `font-sans` (Inter) for the interface and `font-board` (Andika) for text children read, both self-hosted in `resources/fonts/`.                                                                                                        |
| Primitives | `resources/js/components/ui`                                      | shadcn/ui from the starter kit. Not linted or formatted; keep them close to upstream. Their few built-in words (`Close`, `More`…) use `useTranslation()`.                                                                                                                                               |
| Shared     | `resources/js/components`                                         | The teacher shell (`app-header`, `app-sidebar`, `bottom-nav`, `area-pills`, `user-menu`, `breadcrumbs`; the nav definition in `components/app/nav-areas.ts`), the kit's parts (`heading`, `text-link`, `input-error`…) and Elifba's own (`language-switcher`). From A3 a kit grows in `components/app`. |
| Features   | `resources/js/components/{classes,students,games,rankings,share}` | Screens built from the shared parts (A3 on).                                                                                                                                                                                                                                                            |
| Game       | `resources/js/game/`                                              | The board's engine, contract and Phaser scenes (A6 on); React owns everything around the canvas.                                                                                                                                                                                                        |

Use a shared component when one fits; add to the shared layer instead of copying markup into a feature folder.

## Rules

### 1. Layouts

- `resources/js/app.tsx` picks the layout by page name: `welcome`, `privacy` and `credits` (public pages) have none, `auth/*` uses `AuthLayout`, `settings/*` uses `AppLayout` + `SettingsLayout`, everything else `AppLayout` (sidebar shell). The board (`games/board` from A6, `lovhe` from A7), the remote (`games/remote`, A7) and the phone's pairing pages (`pult/*`, A7) have no app shell: full screen, no sidebar.
- There is no dashboard. A signed-in teacher lands on Siniflər (`/classes`, Fortify's `home`); `/dashboard` is an unnamed 302 to `/classes` for old bookmarks.
- `AppLayout` is the teacher shell, after the owner's precedent. It navigates between **areas** and the **pages** inside an area, never with browser-style work tabs or a "More" sheet:
    - **One nav definition**, `components/app/nav-areas.ts`: Siniflər (`/classes`), Sözlər (`/words`) and Ayarlar (Profil, Təhlükəsizlik, Görünüş və dil, Oyun — `/settings/play`, the phone remote on or off, architecture §4 "Pairing"), each with a translation key, a lucide icon and its pages. The sidebar, the bottom bar and the pill strip all read it. The open area and page come from the URL (`lib/nav-active.ts`, covered by Vitest), never from stored state: every page under `/classes` (a class, its rankings, podium, game setup) belongs to Siniflər. A new teacher page joins an area by its URL; a new area is one entry in the definition.
    - **Header** (sticky, `h-14`, every viewport): the sidebar toggle (`lg+` only), the brand linking to Siniflər, and on the right the user menu (`UserMenu`): name and email, language, theme, Ayarlar and Çıxış.
    - **`lg+` (≥1024px)**: the collapsible icon sidebar (`collapsible="icon"`, open or closed in the `sidebar_state` cookie, `SidebarRail` on its edge). An area with one page is a link; an area with more is a collapsible group, open while one of its pages is, and collapsed to an icon it lists its pages in a flyout menu. The sidebar holds navigation only.
    - **Below `lg`**: no sidebar (`useIsMobile` breaks at 1024 and the toggle is hidden, so the drawer cannot open). A fixed bottom tab bar (`BottomNav`) switches areas and marks the open one; it pads for the safe area, and the content is padded so nothing hides under it. An area with two or more pages shows them as a sticky pill strip under the header (`AreaPills`; today only Ayarlar). `SettingsLayout` has no nav list of its own.
    - **Breadcrumbs** show under the header on every viewport when a page has two or more, so a deep class page always has a way back (Siniflər › 3A › Reytinq). A single crumb repeats the page title and is not shown.
- A page sets its layout props statically: `Login.layout = { title: 'auth.login.title', description: 'auth.login.description' }` or `Profile.layout = { breadcrumbs: [{ title: 'settings.profile.title', href: edit() }] }`. Those values are **translation keys**; the layout translates them. A page that changes them at run time calls `setLayoutProps` with keys too.
- Nav items (`NavItem.title`) and breadcrumbs (`BreadcrumbItem.title`) are translation keys as well, translated where they are rendered.

### 2. Language

- Every interface string is in `lang/az.json` and `lang/en.json` (Azerbaijani is the base) and is read with `const { t } = useTranslation()`. The parity test fails on a missing key, an extra key, an empty value or different `:placeholders`.
- Keys are dotted and grouped by screen: `auth.login.*`, `settings.profile.*`, `nav.*`, `common.*` for words used everywhere (`common.save`, `common.cancel`, `common.password`…). Reuse a `common.*` key before adding a new one.
- Placeholders use `:name`; `t('settings.passkeys.added', { time })`. Plurals use `{one}…|{other}…` with a numeric `count`; Azerbaijani keeps the noun singular after a number, so its two forms are usually equal.
- Never join translated fragments into a sentence: Azerbaijani word order differs from English. Give the whole sentence one key with placeholders.
- The language switch sits in the auth pages' footer, the welcome page's header and Settings → Appearance and language (`LanguageSwitcher`), and in the teacher shell's user menu in the header (`UserMenu`), on every viewport. Both call `PUT /locale`; the server keeps the choice.
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
- The teacher shell's breakpoint is `lg` (1024px): the sidebar from `lg`, the bottom tab bar and the pill strip below it. A phone and a tablet get the same navigation.
- The remote is used one-handed on a phone: its controls sit in the lower half of the screen.
- The board also opens on a phone or tablet, but it is made for the projector: from `lg` up its layout is the projector's and stays as it is; below `lg` the same parts shrink around the canvas, which keeps its 16:9 (Phaser's `Scale.FIT`; there is no portrait game layout). Below `lg` the header's controls are 44px icon buttons whose label stays for screen readers (`max-lg:sr-only`), «Keç», «Fasilə/Davam» and «Bitir» among them, one tap away; the curtains and the result use smaller type; the start screen puts its title and «Başla» beside the pairing card in landscape; the pairing card stacks below `sm` with a QR of at least 144px, and during play it opens in a dialog instead of the corner of the canvas. In portrait a short hint asks to turn the phone sideways, and «Başla» tries to lock landscape after entering full screen. «Tam ekran» shows only where `document.fullscreenEnabled` is true (never on an iPhone). The board page never scrolls; a curtain too tall for the screen scrolls inside itself.

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
