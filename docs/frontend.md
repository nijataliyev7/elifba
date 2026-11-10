# Elifba frontend — UI rules

How Elifba screens look and behave. The rules are adapted from the owner's precedent (the owner's earlier Laravel project) to an app with three kinds of screen: the teacher's pages (laptop and phone), the **board** on the classroom wall, and the **remote** on the teacher's phone. Change this file first when a rule changes; a review checks new UI against it.

Step A2 shipped the starter kit's screens (welcome, authentication, settings), all translated. The kit's empty dashboard is gone: a signed-in teacher lands on Siniflər. The rules below apply to every screen added from A3 on.

## Building blocks

| Layer      | Where                                                                     | What                                                                                                                                                                                                                                                                                                    |
| ---------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tokens     | `resources/css/app.css`                                                   | Colours, radius, sidebar and chart tokens for light and `.dark`; `font-sans` (Inter) for the interface and `font-board` (Andika) for text children read, both self-hosted in `resources/fonts/`.                                                                                                        |
| Primitives | `resources/js/components/ui`                                              | shadcn/ui from the starter kit. Not linted or formatted; keep them close to upstream. Their few built-in words (`Close`, `More`…) use `useTranslation()`.                                                                                                                                               |
| Shared     | `resources/js/components`                                                 | The teacher shell (`app-header`, `app-sidebar`, `bottom-nav`, `area-pills`, `user-menu`, `breadcrumbs`; the nav definition in `components/app/nav-areas.ts`), the kit's parts (`heading`, `text-link`, `input-error`…) and Elifba's own (`language-switcher`). From A3 a kit grows in `components/app`. |
| Features   | `resources/js/components/{classes,students,games,rankings,share,welcome}` | Screens built from the shared parts (A3 on).                                                                                                                                                                                                                                                            |
| Game       | `resources/js/game/`                                                      | The board's engine, contract and Phaser scenes (A6 on); React owns everything around the canvas.                                                                                                                                                                                                        |

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
- **Auth pages.** Every `auth/*` page shares `layouts/auth/auth-simple-layout.tsx`. On `lg+` a quiet brand side (5/12 of the width, `green-50`, dark `green-950/30`) carries the logo linking home, a few of the logo's balloons as faint decoration and the welcome page's promise (`welcome.hero.title`, `welcome.hero.who`); the form column has the language switch at the top right, the page's title and description centred over a `max-w-sm` column, and the footer (Məxfilik, Müəlliflər). On a phone it is one column: the logo and the compact language switch, the page, the footer.
    - Login and registration put Google first when the instance offers it (the `googleSignIn` prop, ADR 008): the full-width «Google ilə daxil ol» / «Google ilə qeydiyyatdan keç» button (`GoogleSignIn`), a quiet «və ya e-poçt ilə» divider (`AuthDivider`), then the e-mail form, and the link to the other page last. Without Google the form stands alone, with no divider, and the first field takes the focus. A server `status` shows above everything in `AuthStatus`.
    - The Google button follows Google's sign-in branding guidelines and nothing comes from Google: the light theme (white, a 1px `#747775` border, `#1F1F1F` text) and in dark mode the dark one (`#131314`, `#8E918F`, `#E3E3E3`) with the unchanged «G» on its white circle, 12px padding, Google's approved wording translated (`auth.google.sign_in`, `sign_up`, `confirm`). Its text is Inter, not Google Sans: no font loads from Google, and Google Sans is not in `resources/fonts/`.
    - Tab order is the DOM order; no explicit `tabIndex`. «Şifrəni unutmusunuz?» sits on the «Məni xatırla» row, after the password.
- A page sets its layout props statically: `Login.layout = { title: 'auth.login.title', description: 'auth.login.description' }` or `Profile.layout = { breadcrumbs: [{ title: 'settings.profile.title', href: edit() }] }`. Those values are **translation keys**; the layout translates them. A page that changes them at run time calls `setLayoutProps` with keys too.
- Nav items (`NavItem.title`) and breadcrumbs (`BreadcrumbItem.title`) are translation keys as well, translated where they are rendered.

### 2. Language

- Every interface string is in `lang/az.json` and `lang/en.json` (Azerbaijani is the base) and is read with `const { t } = useTranslation()`. The parity test fails on a missing key, an extra key, an empty value or different `:placeholders`.
- Keys are dotted and grouped by screen: `auth.login.*`, `settings.profile.*`, `nav.*`, `common.*` for words used everywhere (`common.save`, `common.cancel`, `common.password`…). Reuse a `common.*` key before adding a new one.
- Placeholders use `:name`; `t('words.edit.button', { word })`. Plurals use `{one}…|{other}…` with a numeric `count`; Azerbaijani keeps the noun singular after a number, so its two forms are usually equal.
- Never join translated fragments into a sentence: Azerbaijani word order differs from English. Give the whole sentence one key with placeholders.
- The language switch sits in the auth pages' footer, the welcome page's header and Settings → Appearance and language (`LanguageSwitcher`), and in the teacher shell's user menu in the header (`UserMenu`), on every viewport. Both call `PUT /locale`; the server keeps the choice.
- Child-facing game text (prompts, words, sentences) is **content**, always Azerbaijani, and never goes into `lang/*.json`.
- Write in plain words a teacher uses. Code comments are English.

### 3. Page header and actions

- Each page starts with one `h1` or a `Heading` and, beside it, the page's main action. A short intro is one line under the title.
- Each teacher page declares its breadcrumbs through its layout props.

### 4. Buttons

- The main action of a form or page is the default `<Button>`; secondary controls are `outline` or `ghost`; deleting is `destructive` and asks first in a `Dialog`.
- A navigating action is an Inertia `<Link>` (with `Button asChild` when it should look like a button), never a button with `router.visit`. The one exception leaves the app: the Google buttons (`GoogleSignIn`) are plain `<a>` links to our own `/auth/google/*` routes, whose answer is a redirect to Google (architecture §7 "Google sign-in").
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
- **The logo** (architecture §8 "Elifba's mark") is a balloon from the `find` game with «ə» cut out, drawn as SVG paths (`AppLogoIcon`, one colour from the caller's `fill-*` class). Its green, `#15803D` (`bg-green-700`), is the other palette exception: the logo's tile (white mark on green) in the header, the sidebar, the auth pages, the welcome page and the install banner (rule 11), the auth pages' brand side (`green-50`) and the welcome page's main action. The master files are `resources/images/brand/mark.svg` (one colour) and `tile.svg` (on the green tile); `public/favicon.svg` is `tile.svg`, and `public/images/og.png` shows the tile. The app icons are drawn from `tile.svg` by `php scripts/app-icons/generate.php` (architecture §10 "Installing Elifba").
- Every teacher screen works in light and dark; check both. The board is always light: its page holds `forceLightTheme()` (`use-appearance`) while it is mounted, so the dialogs it opens are light too.
- Text children read — the board's prompts, items and words, the remote's item buttons — uses `font-board` (Andika, made for beginning readers: clear a, g, I/l and Ə ə Ğ ğ İ ı). Everything else uses `font-sans` (Inter).

### 8. Inertia, not a SPA router

- Links are Inertia `<Link>` (except the Google buttons, rule 4); visits are `router.get/put/...` with `preserveScroll` where the page stays.
- Shared state comes from Inertia props (`usePage().props`: `auth`, `locale`, `locales`, `translations`, `sidebarOpen`), not a client store. No i18next, axios, zustand, TanStack Query or react-hook-form.
- Routes are Wayfinder functions (`@/routes/*`, `@/actions/*`), never hand-written URLs.

### 9. Nothing leaves the host

- No CDN, external font, analytics, tracker or error service. Fonts and pictures are files in the repository; the font loader is `local()` in `vite.config.ts`.
- Pictures and sounds arrive as URLs in the page props (an avatar is `{ key, url }`); TypeScript never builds an asset path. A student's avatar is drawn by `StudentAvatar`.
- Children's names appear only on the teacher's pages, the board and the share card's allowed fields (architecture §6, §7), never in a URL, a log or a broadcast.

### 10. The welcome page

- `/` (`WelcomeController`, page `welcome`) is the landing page for a teacher who opens a shared link, often on a phone. In this order it says what Elifba is, how a lesson goes, what a teacher gets, what children's data it keeps and what it costs (nothing), then shows the way in. A guest and a signed-in teacher see the same page; only the actions differ («Qeydiyyat» and «Daxil ol», or «Siniflər»).
- Its facts come from the server as props and are never retyped in `lang/` or TSX: the mechanics (`Mechanic` cases, named by `games.mechanics.*`), the packs (title in the interface language and palette, `ContentRepository`), the alphabet (`Alphabet`), a few avatars (`Avatars`) and the repository's address (`elifba.source_url`). It has no testimonials, school logos, user or star counts and no ratings.
- **The wall** at the top plays real rounds: `Play\Support\SampleRounds` builds a few `find` rounds at the first letter level with the real builder, with every letter learned and one built-in pack. The pack and the letters change with the Baku date. A visitor taps an item on the wall or the same item on the phone beside it (below it on a phone), the two ways a class plays. The wall is always light, like the board, with its colours from `game/palette.ts`, and Phaser is not loaded. It plays one round by itself after the page loads, which is the page's only motion that nobody triggered. With `prefers-reduced-motion` it does not, and a hit changes the item without animation.
- Type and colour follow rule 7 with one addition. Inter is for the teacher. Andika is only for what a child reads on the wall and the phone and for letters shown as letters (the alphabet). Colour comes from the tokens, the logo's green (`#15803D`) for the main action and the step numbers, and the packs' palettes.
- «Dərs necə keçir» is a sequence and the only numbered part. Its pictures are screenshots of the app with demo data and made-up names, kept in `resources/images/welcome/` as WebP. They are imported through Vite and get `width`, `height` and an `alt` in both languages, plus `loading="lazy"` below the fold.
- The GitHub button is a plain link to `elifba.source_url` with GitHub's mark as an inline SVG (`components/github-link.tsx`, architecture §8 "Brand marks"). There is no GitHub script, badge, iframe or star count.
- `resources/views/app.blade.php` prints the description, Open Graph and Twitter tags on every page from `welcome.meta.*` (via `__()`, in the page's locale). A link shared on WhatsApp then shows a title, one sentence and `public/images/og.png` (1200×630, an absolute URL on the app's own host).

### 11. The install banner

- Chrome offers to install Elifba as an app when the manifest qualifies (architecture §10 "Installing Elifba"). An inline script in `app.blade.php` catches `beforeinstallprompt` before React mounts, holds back Chrome's own install bar on every page (the board and the remote included) and keeps the event on `window.elifbaInstall`; `appinstalled` clears it. Elifba's own banner, `InstallBanner` (`components/install-banner.tsx`), makes the offer.
- **Look.** A card: the logo's tile, «Elifba-nı quraşdırın», one line on what it gives (its own window, one tap from the home screen or the desktop), «Quraşdır» (the default `Button`; it calls the event's `prompt()`, which opens Chrome's own install dialog) and «İndi yox» (`ghost`). Tokens only (`bg-popover`, `text-muted-foreground`), so it follows dark mode; it slides in only without `prefers-reduced-motion`.
- **Place.** It floats (`fixed`, `z-40`: over the page and the bottom tab bar, under dialogs), so nothing moves when it comes or goes: across the bottom of a phone with the 16px gutter, bottom right from `sm` (384px wide), clear of the safe areas, and in the teacher shell below `lg` above the bottom tab bar.
- **Access.** A `section` named by its heading and described by its line, so screen readers list it as a region; it is reached by Tab after the page's content and never takes the focus. Esc inside it equals «İndi yox».
- **Where.** The welcome page, the auth pages (`AuthSimpleLayout`) and the teacher shell (`AppSidebarLayout`) each mount it once; `bannerAllowedOn()` in `lib/install-banner.ts` lists the pages that may show it, and Vitest checks the list against every page in `pages/`. Never the board (`games/board`, `lovhe`), the remote (`games/remote`, `pult/*`), the podium (made for the projector), `privacy`, `credits` or the share card. A new page shows nothing until it is listed.
- **When.** Chrome has an unused offer, the app was not installed during the visit, the page does not run installed (`display-mode: standalone`, `window-controls-overlay` or `minimal-ui`) and «İndi yox» was not chosen in this browser in the last 14 days. The time of «İndi yox» is the one `localStorage` key `elifba.install-banner.dismissed-at`, read and written inside `try/catch` (without storage it holds until the page is reloaded). Cancelling Chrome's dialog counts as «İndi yox». An offer is used once; Chrome makes a new one on a later visit. Safari and Firefox never make one, so they show nothing (an iPhone adds Elifba from Share → Add to Home Screen, with `apple-touch-icon.png`).
- These rules live once, in the pure `showInstallBanner()` (`lib/install-banner.ts`, covered by Vitest); `hooks/use-install-offer.ts` only feeds it the browser's state and runs the two buttons.
