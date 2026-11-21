# 032 — The game frame: a room's page without the app's shell

- **Status:** Accepted, 2026-11-19 (step F1: design only, no code). Variant A, «Başlıq», of three clickable prototypes, taken under the owner's standing rule of 2026-11-18 — a session that would ask the owner takes its recommended option and says so. The owner may switch to B, «Səhnə», or C, «Dok», before F2 builds it: the prototype is the private Artifact <https://claude.ai/artifact/Gp8hGfAjX7JntEfG78gXTX>, and a switch changes decisions 3–6 and rule 15 "The game frame", nothing of the state, the routes or the words. The owner confirmed variant A on 2026-11-19 while F2 was building it («a variantı»). Built by F2 (2026-11-19, the branch `feat/rooms-game-frame`); what it built beyond this text is "As built (F2)" at the end, architecture.md §12 "The game frame (F2)", "As built (F2)" and docs/frontend.md rule 15 "The game frame", "As built (F2)". Decision 2 amended by [033 the invitation link](033-invitation-link.md) (2026-11-19, I1; built by I2): the frame begins where the game begins — after joining, by «Qoşul» or by a link opened by hand, which skips `/oyun/{code}`; the entrance keeps the area's shell.
- **Sections:** architecture.md §12 "The game frame (F2)"; docs/frontend.md rule 1 and rule 15 "The game frame"
- **Amends:**
    - docs/frontend.md rule 1 (the pages without the app's shell gain `rooms/show`) and rule 15's first paragraph and "Phone first" (the player area is the same `AppLayout` except `rooms/show`; `RoomActionBar` stands at the bottom of the viewport, not above a tab bar).
    - [027](027-rivals-face-to-face.md) decision 11 and "As built (P2)" item 1: the phone's grid limit is `100svh − 21.25rem`, not `100svh − 28.5rem`, and the round fits 375 × 667 in the frame, not "with the shell" (decision 7 below).
    - [029](029-taunts.md) decision 39 and [031](031-live-voice-in-rooms.md) decision 34 as amended: what they place against the app's header — the reaction layer above it, the voice's note under it — is placed the same way against the frame's top bar; their numbers do not change (decision 7).
    - [019](019-rooms-area-and-player-role.md) to [031](031-live-voice-in-rooms.md) otherwise unchanged ("What does not change" below).
- **Why it is hard to reverse:** a game's page is what players learn by hand — where the way out is, where the sound and the code are, where the round's number stands. Moving them again teaches them again.

## Context

The owner, on 2026-11-19: «oyuna girdikdə tətbiq hissini uşaq oyununda verir ama böyük oyunu qeyri peşakar görünür misal oyunda header sidebar filan qeyri peşakardır bi sözlə oyun hissi vermir». The children's remote (`games/remote`) feels like a game: it fills the phone, one header line holds ‹ back, the game with «n/N · status» and a QR, and a footer holds the action. A room's page (`rooms/show`) is drawn inside the area's shell (`AppLayout` → `AppSidebarLayout`): the app's header with the logo and the account menu on every viewport, the sidebar from `lg`, and below `lg` the tab bar «Oyunlar · Keçmiş oyunlar · Ayarlar»; the page's own header (`RoomHeader`) and the round's head (`RoundHead`) come under the app's header. On a 375 × 667 phone the shell takes 121px of a round's 667 (57 above, 64 below), and the room's own bars are counted around it: `RoomActionBar` sticks at `calc(4rem + env(safe-area-inset-bottom))` above the tab bar, the voice's note at `env(safe-area-inset-top) + 4.25rem` under the app's header, and the grid's limit `100svh − 28.5rem` leaves the 5 × 5 and 6 × 6 grids scrolling by about 32px and 82px (ADR 027 "As built (P2)" item 1). The «before» pictures are outside the repository, in `~/elifba-shots/game-frame/before/{light,dark}/` (the host and a player at 375 × 667 and 1280 × 800: the start screen, the study, a question under the strip, the fan, a reveal, the final, a closed room).

Two precedents exist and nothing of the children's side may be imported (`RoomsApartTest`): the remote's header line, and the screen's own top bar (`rooms/screen`, the one room page without the shell since B4).

F1 drew one clickable page (`~/elifba-shots/game-frame/src/`, from the taunts' engine; shots in `~/elifba-shots/game-frame/shots/`) with today's page beside three frames, each on the host's phone, a player's phone and a laptop (1280 × 800), at 375 × 667 and 390 × 844, light and dark, in a browser and installed, with 4 × 4, 5 × 5 and 6 × 6 grids, the live voice on and off, and every moment — the start screen, a question under the strip, the taunt fan open (T4's fan; the rival's side of the strip throws), a reveal, the final and a closed room. The room's parts are drawn as rule 15 has them; only the frame differs:

- **A, «Başlıq»:** the remote's header line made the room's: ‹, the round's title and the game (or the page's title), then sound, live voice and the code — outside a round the live voice and the host's ⋯. The app's tokens. The action at the bottom of the viewport.
- **B, «Səhnə»:** a room surface of its own (a pale ink in light, a deep navy in dark, the cards lifted by shadows); a top bar with ✕, the round's title over five dots of the game's rounds, sound, voice and code; on the start screen the chosen game in the top bar in place of the choice's row; the action as a floating button.
- **C, «Dok»:** nothing above: the page starts with a status line and the strip; a bottom dock holds ‹, the code, sound and voice in a round, and outside one ‹, the action of the moment and the host's ⋯.

## Decision

### Which pages

1. **`rooms/show` has no app shell, in every state** — the start screen, a round in each of its phases, the final, a player who left an open room, and a closed room's results. The `layout` callback in `resources/js/app.tsx` returns `null` for it, as for `rooms/screen`, and the page draws its own frame (`RoomFrame`, `components/rooms/room-frame.tsx`): no app header, logo, account menu, sidebar, area pills, breadcrumbs, tab bar or install banner. `RoomsShow.layout`'s breadcrumb goes.
2. **The area keeps its shell:** «Oyunlar» (`rooms/index`), «Keçmiş oyunlar» (`rooms/past`) and the entrance (`rooms/join`) at `/oyun` and at `/oyun/{code}`. `/oyun/{code}` is a decision before the game, not a part of it: it shows nothing of the game but its code, the account's name with «Dəyiş» into Settings → Profil, and «Qoşul»; someone who scanned a code they are not sure of leaves it by the area's navigation; and `/oyun` and `/oyun/{code}` are one page component. The frame begins where the game begins — after «Qoşul».

### The top bar (variant A)

3. **One line, the remote's order.** Sticky at the top, `h-14` (56px) under a padding of `env(safe-area-inset-top)`, `z-30`, a bottom border, `bg-background/95` with a backdrop blur (the action bar's look), `px-2` and `gap-2`, its row as wide as the page's column (`max-w-xl`, centred) on a laptop. Left to right:
    - **‹** — a 44px `ghost` icon link (`ChevronLeft`) to «Oyunlar» (`rooms.index`), `aria-label` «Oyunlar» (`nav.rooms`); for a closed room to «Keçmiş oyunlar» (`rooms.past`, `nav.rooms_past`). On every state, first, always in the same place.
    - **The heading**, the page's `h1`, 16px semibold, one line, truncated with an ellipsis and never wrapped: in a round the round's title — «Raund :index / :total», in the reveal «Raund :index / :total bitdi» — over the game and its difficulty (12px, muted), its pop when a round begins as ADR 025 has it; outside a round the title the page's header has today — «Oyunçuları çağırın» with «Kilidli» while locked (the host's start screen), «Oyun» (a player's, and a player who left), «Yekun» (after a game), «Nəticələr» with «Bağlanıb» (a closed room).
    - **In a round:** «Oyun səsləri» (`SoundToggle`, 44px round), the live voice's button while the voice is on (`VoiceButton`, ADR 031 decision 34), then the code: below `sm` a 44px round `QrCode` button, `aria-label` «Oyun kodu :code və QR kod» (`rooms.show.code_chip`), from `sm` the chip with the digits as today (`CodeChip`); either opens `CodeDialog`. **Outside a round:** the live voice's button, then the host's 44px ⋯ «Otağın idarəsi» (`RoomTools`). **A closed room:** nothing more.
4. **The round's head gives its title to the bar.** On a phone `RoundHead` draws only its second row — the time bar with the seconds or «Növbəti: :seconds san.» beside it; its first row (the title, the game, `aside`) is the frame's top bar. The screen's `RoundHead` (`big`) is unchanged. A 375px phone gives the heading 151px with the voice on (‹ and three 44px buttons) and 203px with it off — «Raund 2 / 5 bitdi» fits; a longer title truncates, and its words are said again by the time row.

### The way out

5. **‹ is not leaving the room.** It goes to «Oyunlar» and never asks: the player stays present, the round goes on, and «Oyunlar» shows the game's card with «Oyuna qayıt», one tap back (rule 15 "Games first"). Leaving is what it is today and where it is today: a player's «Oyundan çıx» on the start screen and the final, without asking; the host's «Otağı bağla» on the final and in «Otağın idarəsi», asking first; the host's «Oyunu bitir» a quiet button at the end of a round, asking first — never in the top bar, one tap from ‹, and never on one screen with «Otağı bağla» (ADR 026 decision 2). The account menu (theme, language, «Çıxış») is not on this page; it is one tap away on «Oyunlar». In an installed app, with no browser back button, ‹ is the way to the rest of the app — the reason it stands on every state.

### The surface

6. **The app's tokens, nothing new.** The page is `bg-background` with `text-foreground` in both themes, as every room page is; the top bar and the action bar share one look (`bg-background/95`, blur, a border). No logo, no avatar, no colour of the frame's own. The room's colours — right, wrong, the lamp, the strip's sky and orange, the taunt's violet, the voice's indigo, the QR dark on white — stay on the background they were chosen and checked against (ADRs 025, 027, 029, 031). B's own surface is not taken ("Alternatives rejected").

### Fit

7. **What the frame gives back, and the new numbers.**
    - **The grid's limit is `100svh − 21.25rem`** (`mechanics/recall-cells.tsx`, from `100svh − 28.5rem`): the top bar (56px and its border) takes the app header's place one for one; the tab bar's 4rem goes; the round head's first row and its gap (2.75rem + 0.5rem) move into the top bar. The page around a question is then the top bar, the column's 1rem above, the strip with its gap (103px), the time row (20px) and its gap, the line, the row under the grid and the column's 1.5rem below — the same margin the as-built 28.5rem kept.
    - **At 375 × 667** the grid has 327px: a 4 × 4 grid stays at its cap of 19rem (cells about 71px, from 47px), a 5 × 5 grid's cells are about 61px and a 6 × 6 grid's about 50px — every grid of every difficulty fits without scrolling (until now the 5 × 5 and 6 × 6 scrolled). **At 390 × 844** the column's width decides: about 71px, 67px and 55px. Installed on a 375 × 667 iPhone (a 20px status bar above the page, `svh` 647) the 6 × 6 cells are about 46px. The 8-letter anagram, its places and its two rows of tiles fit with room to spare.
    - **What takes no height:** the live voice's button (in the top bar), the taunt fan (it opens over the thrower's own round, T4), a received taunt (a layer over the whole viewport), the voice's panel and its consent (sheets). A reveal may scroll, as it may today; the host's «Oyunu bitir» stays under the round and may need a scroll there.
    - **`RoomActionBar`** sticks to the bottom of the viewport on every width (`sticky bottom-0`, from `bottom-[calc(4rem+env(safe-area-inset-bottom))]` and `lg:bottom-0`) and pads its own safe area under its row (`pb-[max(env(safe-area-inset-bottom),0.75rem)]`). The page's root is a column at least one screen high (`min-h-svh`), the bar at its end (`mt-auto`), so the action is at the bottom of a short page too.
    - **The voice's note** keeps `top-[calc(env(safe-area-inset-top)+4.25rem)]`: under the frame's 3.5rem top bar, 0.75rem below it, as it stood under the app's header. **The taunt reaction** stays a layer over the whole viewport (`fixed inset-0`), above the frame's top bar.

### Laptop, installed, motion

8. **A laptop (≥ `lg`)** gets the same frame: no sidebar; the top bar across the width with its row over the page's column; the code chip with its digits; the action bar's row over the column. The room's laptop is the screen (`rooms/screen`); a phone page opened there stays a phone page, centred.
9. **An installed app** (the manifest's `display: standalone`): the top bar pads `env(safe-area-inset-top)`, the action bar `env(safe-area-inset-bottom)`, and both rows `env(safe-area-inset-left)` and `-right` in landscape. The viewport meta stays as it is (`width=device-width, initial-scale=1`, no `viewport-fit=cover`), so these are 0 where the system keeps the page clear of its bars, and right if that ever changes. «Full screen» here means no shell, not the Fullscreen API: a phone page never asks for it (iPhone Safari has none for a page; the screen keeps its «Tam ekran»).
10. **Motion.** The frame has none of its own: no entrance, no sliding bar, no transition between moments. The round title's pop moves with the title into the top bar, still `motion-safe:` and only for a round the page saw begin (ADR 025); under `prefers-reduced-motion` nothing of the frame moves. `useTopOnTurn()` still brings a new game and a turned page to the top.

### Words

11. **No new string.** ‹ says `nav.rooms` or `nav.rooms_past`, the code's round button `rooms.show.code_chip`; every title is a key the page has today. `lang/az.json` and `lang/en.json` do not change, and nothing promises a benefit (`RoomsWordingTest`). «Oyun» and «otaq» keep ADR 026 decision 2's places.

### What does not change

12. Every rule of ADRs 019–031 holds; this record moves the page's frame and nothing else:
    - **019** — the area, the role, its sentence and the switch; the player's navigation «Oyunlar», «Keçmiş oyunlar», Ayarlar on every page of the area but `rooms/show`.
    - **020** — codes and joining; `/oyun` and `/oyun/{code}` as pages of the area (decision 2).
    - **021, 022** — the state and its version, the protocol, `RoomWaits`, the server's clock, the channel's `room.changed`, the points and the ranking; a page that left by ‹ simply stops reading the state.
    - **023, 024** — the games and their content; no name in a URL, a log or a broadcast; nothing kept about a person.
    - **025, 028, 030** — every moment's motion, the sounds and who plays them, the final, the winner's name on the screen alone; the frame adds no motion and no sound.
    - **026** — games first: the start screen's parts (the invitation, the choice's row, who joined, «Başla»), «Oyun ayarları», «Otağın idarəsi» never in a round, the two words.
    - **027** — the strip's look, colours, place above the round and motion; only decision 11's numbers change (decision 7).
    - **029** — taunts: the rival's side of the strip throws, the fan, the reaction, its voice, the host's switch.
    - **031** — the live voice: the button and its forms, the panel, the consent, the host's tools; the button stands in the frame's top bar where it stood in the page's head.
    - `rooms/screen`, every children's page and `RoomsApartTest`: `RoomFrame` lives in `components/rooms/` and imports nothing of the children's side — the remote is matched in spirit, never imported.

## Consequences

- A room's game reads as a game on a phone: the round, the strip and the action own the screen, and every grid fits 375 × 667.
- The account menu, the theme and the language are one tap away inside a game rather than on the page; the install banner, never shown on `rooms/show`, still is not.
- `RoundHead` draws differently on a phone and on the screen; `LiveRound`'s `aside` becomes the frame's. No test reads the layout's choice today: F2 makes it one (a pure function of the page's name, under Vitest — `rooms/show` and `rooms/screen` without the shell, `rooms/index`, `rooms/past` and `rooms/join` with it).
- Any new room page decides its frame: inside the area's shell, or a part of a game and inside `RoomFrame`.

## Alternatives rejected

- **B, «Səhnə»** (a room surface of its own, a floating action, the chosen game in the top bar): the most "game-like" picture, but every colour the rooms chose — right, wrong, the lamp, the strip's two sides, the taunt's violet, the voice's indigo, the QR on white — would be checked again on a new ground in both themes, and the start screen would lose the choice's row G3 built. Its grid fit is A's.
- **C, «Dok»** (nothing above, everything in a bottom dock): the thumb reaches it, but the way out and the code sit beside the round's taps row and «Yenidən oyna», the page has no heading for a screen reader in a round, a dock on a laptop is odd, and with its status line its grid has 2rem less than A's (the 6 × 6 cells about 44px).
- **Hiding only the tab bar in a round:** the start screen and the final would stay inside the admin shell, and the page would change shells between moments.
- **The Fullscreen API:** iPhone Safari has none for a page, it asks for a gesture each time and ends on navigation; no shell gives the same room.
- **`/oyun/{code}` without the shell:** see decision 2.

## What would make us revisit

- The owner switches to B or C (the Artifact above), or players do not find their way out or to Settings.
- The app sets `viewport-fit=cover`, or a room page is added that is part of a game (it takes the frame).

## As built (F2, 2026-11-19)

Built as decided; what the build names, adds or had to settle:

1. **The shell's choice** is a pure function, `pageShell()` in `resources/js/lib/page-shell.ts`, which `app.tsx` maps to the layouts (Consequences). `page-shell.test.ts` holds it: "draws a room's page in its own game frame, never inside the app's shell (ADR 032)", "keeps «Oyunlar», «Keçmiş oyunlar» and the entrance inside the area's shell", "decides the shell of every room page by name" (every `pages/rooms/*.tsx`, found by `import.meta.glob`, must be in `WITHOUT_SHELL` or `ROOM_PAGES_IN_SHELL`, so a new room page fails until it decides — Consequences, last item), and the board's, the remote's, the public and the teachers' and parents' pages as before.
2. **The parts.** `components/rooms/room-frame.tsx` holds `RoomFrame` (`back`: `rooms` or `past`; `heading`; `tools`), `FrameTitle` (the `h1` and the page's `<title>`, with a badge) and `LockedBadge`. In a round the heading is `RoundTitle` from `live-round.tsx`: the `h1` with the id `round-heading` that labels the round's section, keyed on the round so its pop is ADR 025's. `RoundHead`'s phone form is the time row alone and `LiveRound` lost its `aside`; the screen's `RoundHead` draws as before.
3. **`RoomActionBar framed`.** The bar is shared with «Oyunlar» and the entrance, which keep the tab bar: decision 7's `sticky bottom-0` with its own safe area is the `framed` form, which only `rooms/show` passes; without it the bar stands above the tab bar as before.
4. **The code** is one button for both widths: 44px and round below `sm` (the digits `hidden sm:inline`), the chip from `sm`, one `CodeDialog`.
5. **The column under the bar** begins `pt-3` on the start screen and the final (`pt-1` under the room's own header row before), `pt-4` in a round and on a closed room, whose line «Otaq bağlanıb.» opens the body under «Nəticələr · Bağlanıb».
6. **The bar's ground is a layer of its own** (`absolute inset-0 -z-10` inside the sticky header): a `backdrop-filter` on the header itself would make it the containing block of the live voice's `fixed` note, which the voice's button renders in the bar. So the note is placed against the viewport, at decision 7's `env(safe-area-inset-top) + 4.25rem`, as before.
7. **Measured** with the switch and the live voice on, reduced motion, outside the repository (`~/elifba-shots/game-frame/f2/phonefit.mjs`, from the rivalry check's `screenfit.mjs`; tables `f2/fit-{light,dark}/fit.md`): each mechanic at difficulty 4, and `recall_cells` at 2 and 3 too, on a player's phone at 375 × 667 and 390 × 844 and on the playing host's at 375 × 647 (installed). Every question's items and the row under them stand on the first screen, every item's centre is its own (nothing of the frame over it), no player's page scrolls, and the open fan adds no height and ends on the screen. The smallest items: `recall_cells` 4 × 4 70px everywhere; 5 × 5 61, 67 and 57px; 6 × 6 50, 55 and 46px (375 × 667, 390 × 844, installed) — decision 7's numbers; `recall_order` and `anagram` 60px, `next_in_sequence` and `odd_one_out` 52px, `spelling` 56px. The host's page scrolls only to «Oyunu bitir» under the round (decision 7). The same with the voice off. `rooms/index`, `rooms/past`, `/oyun` and `/oyun/{code}` (for an account not in the room) keep the shell on a phone and at 1280 × 800.
8. **The live run** (`~/elifba-shots/game-frame/after.mjs`, the «before» script's moments with the screen added; shots in `~/elifba-shots/game-frame/after/{light,dark}/`, beside `before/`): the start screen with «Başla» on the first screen, a tap that starts the game and a page that opens at its top (`useTopOnTurn()` with the document as the scroller), a question, the fan, ‹ to «Oyunlar» and «Oyuna qayıt» back into the running round, a reveal, the final with «Yenidən oyna» on the first screen and a replay started by its tap, a player who left («Oyun»), and a closed room whose ‹ leads to «Keçmiş oyunlar»; the host, a player and the screen, at 375 × 667 and 1280 × 800, light and dark.
