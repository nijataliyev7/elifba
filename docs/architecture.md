# Elifba architecture

This document is the contract the code follows. It was written in roadmap step A1, before any code existed, so that steps A2–A11 can be built without re-deciding. Change this file first when the model changes; a step that has to depart from it updates it in the same change.

- Azerbaijani terms and the English names used in code: [`glossary.md`](glossary.md).
- The decisions that are hardest to reverse have their own records in [`decisions/`](decisions/):
  [001 students without accounts](decisions/001-students-without-accounts.md) ·
  [002 live play: client events, the board writes](decisions/002-live-play-client-events-board-writes.md) ·
  [003 game model and round contract](decisions/003-game-model-and-round-contract.md) ·
  [004 content as versioned files](decisions/004-content-as-versioned-files.md) ·
  [005 points on rounds, Baku calendar](decisions/005-points-on-rounds-baku-calendar.md) ·
  [006 PostgreSQL in production, SQLite in development](decisions/006-postgresql-production-sqlite-development.md) ·
  [007 teacher words](decisions/007-teacher-words.md)

Every decision below is written as **Decision** (the choice), **Why** (the reason) and **Rejected** (the alternative and why not).

## 0. The product

Elifba is an open-source (MIT) web platform for primary-school teachers in Azerbaijan. It teaches children to read Azerbaijani — letters, syllables (heca), words and sentences — through short games played on the classroom wall.

```
 classroom wall (projector)                     teacher's phone
┌────────────────────────────┐                 ┌──────────────┐
│  "Ə hərfini tap!"          │   tap "Ə"       │  [Ə] [E] [A] │
│   (E)   (Ə)   (A)   (O)    │ ◀────────────── │  [O]         │
│          ✸ burst ✸         │   ≤ 300 ms      │  At the board│
└────────────────────────────┘                 │  🐼 Aylin     │
   a child touches "Ə"                         └──────────────┘
```

1. A **teacher** registers, creates a **class** and adds **students** by pasting a list of names. Students have no accounts.
2. The teacher opens a **game** on the laptop connected to the projector — or, on a shared school computer, sends it there from the phone: the **board**. A game is one **mechanic** (find, read and tap, build a syllable, build a word, build a sentence) dressed in one **theme pack** (train, balloons, animals, fruit, our cities, national symbols) at one **level**.
3. A child goes to the wall and touches an item. At the same moment the teacher taps the same item on the phone (the **remote**). The board bursts the item and moves on — to the class it looks as if the wall reacted to the child. On a touch screen or with a mouse the board also works without a phone.
4. Every solved round gives the student at the board points. Each class has a **daily** and a **weekly** ranking; places 1–3 get a **podium** page that the teacher saves as a 1080×1920 image and posts to WhatsApp status.

Owner decisions (fixed): name `elifba`; interface Azerbaijani first, bilingual foundation (az default + en) from day one; MIT unless the owner changes it (roadmap step O1); public GitHub repository; reuse ready-made packages where they fit.

### Stack

Versions checked 2026-11-09. Replace one only with a concrete reason, recorded here.

| Layer   | Choice                                                                                                                                                                                                  |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Server  | Laravel 13, PHP 8.4, the official React starter kit (Laravel Installer 5.31.1) with its built-in authentication                                                                                         |
| Pages   | Inertia 3 + React 19.2 + TypeScript + Tailwind 4, shadcn/ui from the starter kit, Wayfinder typed routes                                                                                                |
| Live    | Laravel Reverb 1.x (Pusher protocol), `laravel-echo` + `@laravel/echo-react` 2.6.3                                                                                                                      |
| Board   | Phaser 4.2.1, loaded only on the board page                                                                                                                                                             |
| Extras  | `qrcode.react` 4.2.0 (pairing QR), `modern-screenshot` 4.7.0 (share card), `canvas-confetti` 1.9.4 (podium), `zod` 4.6 (the round contract and client-event validation in TypeScript, §2, §4)           |
| Tests   | Pest (PHP); Vitest for pure TypeScript modules from A6, run as `vp test` (Vite+ ships Vitest 5; `npm run test`, configured in `vite.config.ts`'s `test` block); PHPStan level 8 (Larastan), Pint, `tsc` |
| Runtime | Node 22 for builds; dev served by Laravel Herd; production as docker compose (§10)                                                                                                                      |

### Decisions at a glance

| #   | Decision                                                                                                                                                                        | Section | Record                                                           |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | ---------------------------------------------------------------- |
| D1  | Five domains, one-way: Language → Content → Classroom → Play → Rankings                                                                                                         | §1      | —                                                                |
| D2  | A game = mechanic (code) × theme pack (data) × level (data); rounds are generated by the server and stored as snapshots                                                         | §2      | [003](decisions/003-game-model-and-round-contract.md)            |
| D3  | Built-in content is versioned JSON in `content/`, validated and cached, never seeded into tables; text is cased and syllabified on the server                                   | §3      | [004](decisions/004-content-as-versioned-files.md)               |
| D4  | A hit travels phone → Reverb → board as a client event; the board is the only writer of results (HTTP, idempotent, fenced); the server judges points                            | §4      | [002](decisions/002-live-play-client-events-board-writes.md)     |
| D5  | The phone is logged in as the same teacher; the QR / short code only says which board; a shared computer runs a guest board claimed from the phone, with no teacher login on it | §4      | [002](decisions/002-live-play-client-events-board-writes.md)     |
| D6  | Points live on the solved round with its Baku date and Monday week; rankings are derived; ties share a place (dense)                                                            | §5      | [005](decisions/005-points-on-rounds-baku-calendar.md)           |
| D7  | The share card is rendered in the browser, shared as a file, and carries only first names, avatars, places, points and the date                                                 | §6      | —                                                                |
| D8  | Students are a first name + avatar owned by one teacher; no student login; isolation by scoped route binding                                                                    | §7      | [001](decisions/001-students-without-accounts.md)                |
| D9  | Avatars and the share card use only CC0 or own art; OpenMoji stays in its own folder under CC BY-SA 4.0                                                                         | §8      | —                                                                |
| D10 | PostgreSQL 17 in production, SQLite in development and tests, CI on both                                                                                                        | §10     | [006](decisions/006-postgresql-production-sqlite-development.md) |

---

## 1. Domains and data model

**Decision.** Code lives in `app/Domain/{Language,Content,Classroom,Play,Rankings}`. A domain may use the ones before it in that list, never the ones after. Each domain has `Actions/`, `Models/`, `Enums/`, `Data/`, `Events/`, `Support/` and `Policies/` as needed. Only Actions write. Controllers, commands and channel callbacks validate, call an action and shape the response.

**Why.** It is the owner's proven shape (the owner's earlier Laravel project): agents find code by domain, a one-way order prevents cycles, and "only Actions write" gives every write one place to test.

**Rejected.** Laravel's flat `app/Models` + fat controllers: fine for a CRUD app, but the game rules (judge, points, calendar) would end up spread over controllers. A package per domain (modules with their own service providers): too much ceremony for five small domains.

| Domain      | Owns                                                                                                                         | Tables                                                         | Depends on                      |
| ----------- | ---------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- | ------------------------------- |
| `Language`  | The 32-letter alphabet, Azerbaijani casing, normalization, syllabification, name sorting, ordinals                           | none                                                           | —                               |
| `Content`   | Theme packs, levels, prompts, avatars, asset credits; teacher words; `ContentRepository`, `ContentValidator`, `TeacherPacks` | `custom_words`, `hidden_words` (built-in content: files, §3)   | Language                        |
| `Classroom` | Classes and students                                                                                                         | `school_classes`, `students`                                   | Language, Content (avatar keys) |
| `Play`      | Game sessions, rounds, answers, guest boards; round generation, the judge, points, pairing codes, the school calendar        | `game_sessions`, `game_rounds`, `game_answers`, `guest_boards` | Language, Content, Classroom    |
| `Rankings`  | Daily and weekly class rankings, the podium                                                                                  | none (reads Play)                                              | Language, Classroom, Play       |

The teacher is the starter kit's `App\Models\User` (table `users`), kept where the starter kit puts it, with its authentication code. It gains two columns: `locale` (`az` | `en`) and `terms_accepted_at` (§7). It is not a domain: every domain may reference `User`.

The share card (§6) is front-end only and needs no domain.

### Tables

All public identifiers are ULIDs (`HasUlids`), so URLs and channel names cannot be enumerated. Timestamps are stored in UTC (`APP_TIMEZONE=UTC`).

**`custom_words`** (`Content\Models\CustomWord`) — a teacher's own word in a theme pack (§3 "Teacher words", ADR 007)

| Column      | Type              | Notes                                                                                                  |
| ----------- | ----------------- | ------------------------------------------------------------------------------------------------------ |
| `id`        | ulid              | how routes address the word; its text never goes into a URL                                            |
| `user_id`   | fk users, cascade | the teacher; the word plays in all of their classes                                                    |
| `pack`      | string            | a built-in theme pack key                                                                              |
| `text`      | string(32)        | normalized, as written: lowercase, or capitalised as a proper noun; ≤ `elifba.custom_words.max_length` |
| `word_key`  | string(40)        | `word:{lower}` (`Az::lower`), the content key the word plays under                                     |
| `syllables` | json null         | the teacher's override; null = `Syllabifier::split()`                                                  |
| timestamps  |                   |                                                                                                        |

Unique: `(user_id, pack, word_key)`. Azerbaijani casing is computed in PHP and stored in `word_key`, so the database enforces "one word per teacher and pack, case-insensitively" on every engine; `AddCustomWord` and `UpdateCustomWord` still check first, under a lock on the teacher's `users` row, to answer with a message.

**`hidden_words`** (`Content\Models\HiddenWord`) — a built-in word a teacher has hidden from their own games

| Column       | Type              | Notes                                          |
| ------------ | ----------------- | ---------------------------------------------- |
| `id`         | bigint            | never in a URL                                 |
| `user_id`    | fk users, cascade |                                                |
| `pack`       | string            | a built-in theme pack key                      |
| `word_key`   | string(40)        | `word:{lower}` of a built-in word of that pack |
| `created_at` | timestamp         |                                                |

Unique: `(user_id, pack, word_key)`. A row whose word a later pack version removed does nothing.

**`school_classes`** (`Classroom\Models\SchoolClass`; `class` is a reserved word)

| Column            | Type              | Notes                                                                    |
| ----------------- | ----------------- | ------------------------------------------------------------------------ |
| `id`              | ulid              |                                                                          |
| `user_id`         | fk users, cascade | the owning teacher                                                       |
| `name`            | string(40)        | e.g. "1A"; the teacher's own label                                       |
| `grade`           | tinyint null      | 1–4                                                                      |
| `learned_letters` | json null         | lowercase letters the class has learned; null = all 32 (§3)              |
| `archived_at`     | timestamp null    | hidden from pickers; deleted automatically after `elifba.retention_days` |
| timestamps        |                   |                                                                          |

**`students`** (`Classroom\Models\Student`)

| Column            | Type                       | Notes                                                                     |
| ----------------- | -------------------------- | ------------------------------------------------------------------------- |
| `id`              | ulid                       |                                                                           |
| `school_class_id` | fk school_classes, cascade |                                                                           |
| `display_name`    | string(40)                 | first name, and a surname initial only when needed to tell two apart (§7) |
| `avatar`          | string(32)                 | key from `content/avatars.json`                                           |
| `position`        | int                        | order in the class (turns, lists)                                         |
| `archived_at`     | timestamp null             | left the class; out of pickers and rankings                               |
| timestamps        |                            |                                                                           |

Unique among active students of a class: `display_name`, compared case-insensitively with Azerbaijani casing (`AddStudents`, `UpdateStudent` and `RestoreStudent` enforce it; the database cannot, because of the casing rules). Each of them locks the class row (`Classroom\Support\ClassLock`, `lockForUpdate` through the query builder) inside its transaction before it compares, so two requests cannot add the same name at once; `ReorderStudents` takes the same lock.

**`game_sessions`** (`Play\Models\GameSession`)

| Column                                       | Type                       | Notes                                            |
| -------------------------------------------- | -------------------------- | ------------------------------------------------ |
| `id`                                         | ulid                       | also the channel name `game.{id}`                |
| `user_id`                                    | fk users, cascade          | owner; equals the class's teacher                |
| `school_class_id`                            | fk school_classes, cascade |                                                  |
| `mechanic`                                   | string                     | `Mechanic` enum (§2)                             |
| `pack`                                       | string                     | theme pack key                                   |
| `pack_version`                               | int                        | the pack version the rounds were built from      |
| `level`                                      | string                     | level key                                        |
| `letter_case`                                | string                     | `upper` \| `lower`                               |
| `seed`                                       | int                        | makes round generation reproducible in tests     |
| `status`                                     | string                     | `GameSessionStatus` (below)                      |
| `board_epoch`                                | int                        | fencing token of the board allowed to write (§4) |
| `join_code`                                  | char(6) null, unique       | remote pairing (§4); cleared when the game ends  |
| `join_code_expires_at`                       | timestamp null             |                                                  |
| `started_at`, `ended_at`, `last_activity_at` | timestamps                 |                                                  |

**`game_rounds`** (`Play\Models\GameRound`)

| Column            | Type                        | Notes                                                                              |
| ----------------- | --------------------------- | ---------------------------------------------------------------------------------- |
| `id`              | ulid                        |                                                                                    |
| `game_session_id` | fk game_sessions, cascade   |                                                                                    |
| `school_class_id` | fk school_classes, cascade  | copied from the session, for ranking queries                                       |
| `position`        | int                         | 0-based                                                                            |
| `payload`         | json                        | the round snapshot (`RoundData` without `id`/`index`/`total`, §2)                  |
| `progress`        | json                        | item ids hit correctly so far, in order (sequence mechanics; an id may repeat, §2) |
| `misses`          | int                         | wrong hits in this round                                                           |
| `solved_at`       | timestamp null              |                                                                                    |
| `skipped_at`      | timestamp null              |                                                                                    |
| `student_id`      | fk students, null on delete | who solved it                                                                      |
| `points`          | tinyint                     | 0 until solved (§5)                                                                |
| `played_on`       | date null                   | Baku date of `solved_at` (§5)                                                      |
| `week_start`      | date null                   | Monday of `played_on`                                                              |

Indexes: `(school_class_id, played_on)`, `(school_class_id, week_start)`, `(game_session_id, position)` unique.

**`game_answers`** (`Play\Models\GameAnswer`) — every hit, correct or not.

| Column          | Type                        | Notes                                                                  |
| --------------- | --------------------------- | ---------------------------------------------------------------------- |
| `id`            | bigint                      |                                                                        |
| `event_id`      | ulid, unique                | generated by the device that produced the hit; makes writes idempotent |
| `game_round_id` | fk game_rounds, cascade     |                                                                        |
| `student_id`    | fk students, null on delete | at the board when the hit happened                                     |
| `item_id`       | string(8)                   | id inside the round                                                    |
| `content_key`   | string                      | e.g. `letter:ə`, `word:alma`, for later "what is hard" statistics      |
| `correct`       | bool                        | judged by the server                                                   |
| `source`        | string                      | `HitSource`: `remote` \| `board`                                       |
| `board_epoch`   | int                         |                                                                        |
| `client_at`     | timestamp null              | the sender's clock, to the second, for latency diagnostics only        |
| `created_at`    | timestamp                   | server time                                                            |

**`guest_boards`** (`Play\Models\GuestBoard`) — a board on a shared computer, claimed from the teacher's phone (§4).

| Column            | Type                                  | Notes                                                      |
| ----------------- | ------------------------------------- | ---------------------------------------------------------- |
| `id`              | ulid                                  |                                                            |
| `code`            | char(6) null, unique                  | pairing code while unclaimed; cleared on claim             |
| `code_expires_at` | timestamp null                        |                                                            |
| `secret_hash`     | string                                | hash of the secret that only the board's browser tab holds |
| `user_id`         | fk users null, cascade                | the teacher who claimed it                                 |
| `game_session_id` | fk game_sessions null, null on delete | the game it shows now                                      |
| `claimed_at`      | timestamp null                        |                                                            |
| `expires_at`      | timestamp                             | creation + `elifba.guest_board_hours` (12)                 |
| `closed_at`       | timestamp null                        |                                                            |
| timestamps        |                                       |                                                            |

A guest board is **active** while it is neither closed nor past `expires_at`; an unclaimed one also needs its code to be valid, so it dies with the code (`GuestBoard::isActive()`, scope `active`). The secret is 32 random bytes (URL-safe base64, 43 characters); `secret_hash` is its SHA-256 — a fast hash is enough for a random secret of that size — compared with `hash_equals`. `secret_hash` is hidden from every serialization.

### Status lifecycle

`GameSessionStatus::allowedTransitions()`:

```
live ──▶ paused ──▶ live
live | paused ──▶ finished    (last round solved or skipped, or the teacher ends it)
live | paused ──▶ abandoned   (no activity for elifba.abandon_after_hours, by the scheduler)
finished, abandoned: final
```

A session is created `live` by `StartGame`. Only `TransitionGameSession` changes the status; it locks the game row, sets `ended_at` and clears the join code when the game ends, and dispatches `GameSessionTransitioned` after commit (the event implements `ShouldDispatchAfterCommit`). Asking for the status a game already has changes nothing and dispatches nothing, so a repeated pause or end is harmless; any other change outside the diagram throws `Play\Exceptions\InvalidTransition`. The current round is derived (the first round neither solved nor skipped), never stored. `game_sessions` has no `created_at`/`updated_at`: `started_at`, `ended_at` and `last_activity_at` (set by the start, every claim, every applied sync event and every status change) say what happened. `seed` fits a signed 32-bit integer, the same on every engine.

### Actions

| Domain    | Actions                                                                                                                                                                                                                                    |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Content   | `AddCustomWord`, `UpdateCustomWord`, `DeleteCustomWord`, `HideWord`, `ShowWord` (teacher words, §3); `WriteContentCache`, `DeleteContentCache`, `WriteCredits` (files)                                                                     |
| Classroom | `CreateClass`, `UpdateClass`, `ArchiveClass`, `RestoreClass`, `DeleteClass`, `UpdateLearnedLetters`, `AddStudents` (pasted list), `UpdateStudent`, `ReorderStudents`, `ArchiveStudent`, `RestoreStudent`, `DeleteStudent`, `PruneArchived` |
| Play      | `StartGame`, `ClaimBoard`, `IssueJoinCode`, `RecordBoardEvents`, `TransitionGameSession`, `AbandonStaleGames`, `PruneOldGames`, `OpenGuestBoard`, `ClaimGuestBoard`, `ShowGameOnGuestBoard`, `CloseGuestBoard`, `PruneGuestBoards`         |

Classroom actions take the `Classroom\Data` objects (`ClassData`, `StudentData`, `AddStudentsData`, `LearnedLettersData`, `ReorderStudentsData`; each with `rules()` and `validate()`, normalizing names before they are checked) and report a conflict the teacher can fix (a taken name, a restore blocked by a name, an order that is not exactly the class's active students) as a `ValidationException` on the field, with a translated message that never contains the name. The paste preview is `Classroom\Support\NameListParser` (§7); it writes nothing.

The teacher-word actions take `Content\Data\CustomWordData` (`pack`, `text`, `syllables`) and `HiddenWordData` (`pack`, `word`), each with `prepare()` (normalizing with `Az::normalize`), `rules()` and `validate()`, and report the same way: a `ValidationException` on `text` or `syllables` with a `words.errors.*` message that never contains the word (§3 "Teacher words").

Events dispatched after commit: `GameSessionTransitioned` (its listener `Play\Listeners\BroadcastSessionStatus`, registered in `AppServiceProvider`, broadcasts `session.status` on the game channel so a device that is still connected learns the status; a failed broadcast is reported with ids only and never fails the change, §4), `RoundSolved` (no listener in v1; it is the hook for future statistics). Both carry ids only.

Scheduled (one `scheduler` process, §10; the commands are in `routes/console.php`): `AbandonStaleGames` (`play:abandon-stale`; it checks the idle time again under the row lock, so a board that synced a moment ago keeps its game) and `PruneGuestBoards` (`play:prune-guest-boards`: closed boards, boards past `expires_at` and boards never claimed before their code expired; games are untouched) hourly; `PruneOldGames` (`play:prune-old-games`, games whose `started_at` is older than `elifba.retention_days`, with their rounds and answers by cascade) and `PruneArchived` daily; expired join codes are simply ignored (no job), and `TransitionGameSession` clears the code of a game that ends.

---

## 2. Game model

**Decision.** A game is **mechanic × theme pack × level**:

| Part           | Is                                                                                                         | Where                                                                                                  | Adding one needs                   |
| -------------- | ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ | ---------------------------------- |
| **Mechanic**   | The rules of a round: what is asked, how many hits solve it, in which order                                | Code: a round builder in `Play\Support\Mechanics\*` and a Phaser scene in `resources/js/game/scenes/*` | Code                               |
| **Theme pack** | The look and the vocabulary: item style, backdrop, palette, effect, sounds, words with pictures, sentences | Data: `content/packs/{key}.json` + `content/assets/{key}/`                                             | A folder and a JSON file — no code |
| **Level**      | Which unit (letter, syllable, word, sentence), how hard, how many items, which mechanics fit               | Data: `content/levels.json`                                                                            | A JSON entry — no code             |

The server generates all rounds of a game when it starts, stores each as a snapshot (`game_rounds.payload`) and sends the same snapshots to the board and the phone. Neither client invents a round.

**Why.** Six themes × five mechanics × six levels give dozens of valid games; written as separate games that is unmaintainable, written as data it is one engine. Snapshots keep old games, answers and rankings valid after a pack changes, make the board and phone agree by construction, and let a reloaded board resume exactly where it was.

**Rejected.** A theme as code (one Phaser game per theme): every new theme would need a developer, and the owner wants teachers to contribute content by pull request. Generating rounds on the board: the server could not judge answers or resume a game, and the phone would need the same generator.

### Mechanics (code)

`Content\Enums\Mechanic` (with `units()`, `supports()` and `builds()`). The enum lives in Content, not Play: `levels.json` and `prompts.json` name mechanics and `ContentValidator` must check them, and Content may not use Play (§1). The rules of each mechanic stay code in Play (`Play\Support\Mechanics\*`) and in the scenes.

| Key              | UI name (az)                                          | Units                  | Round                                                                                                       | Solution                  |
| ---------------- | ----------------------------------------------------- | ---------------------- | ----------------------------------------------------------------------------------------------------------- | ------------------------- |
| `find`           | Tap! («find!»; "Şarı partlat" with the balloons pack) | letter, syllable, word | A prompt names a target; 3–6 items float on the board, one is the target                                    | `any`, one item           |
| `pop`            | Oxu və vur («read and tap»; for beginners)            | letter, syllable, word | No target: the level's items float on the board, the child reads any one aloud and hits it; all are right   | `any`, every item         |
| `build_syllable` | Heca qur                                              | syllable               | Build the shown syllable from letters into slots (train wagons in the train pack)                           | `sequence`, its letters   |
| `build_word`     | Sözü yığ                                              | word                   | A picture; assemble the word (2+ syllables) from its syllables (+1 decoy)                                   | `sequence`, its syllables |
| `build_sentence` | Cümlə qur                                             | sentence               | Put 2–4 words (the level decides) in order, +1 decoy; the final punctuation stays fixed after the last slot | `sequence`, its words     |

Rules shared by all mechanics:

- Item texts are unique within a round, so a tap on the phone names exactly one item on the board.
- In a `sequence` round an item is a reusable **tile**: it stays on the board and a copy flies into the slot, so a solution may name the same item more than once: with the tile `nə` as item `a`, the word `nənə` is `["a", "a"]`; the syllable `nən` uses the `n` tile twice. Common first words (`ba-ba`, `nə-nə`, `də-də`) need this. The judge expects `solution.item_ids[progress.length]`. In an `any` round (`find`, `pop`) an item hit correctly bursts and is gone, and a wrong item wobbles and stays. Each wrong hit counts as a miss. A `pop` round has no wrong item: every item is in its solution, so it is never missed and is solved when every item is gone.
- Decoys for letters come first from the letter's confusables in `content/alphabet.json` (e/ə, i/ı, o/ö, u/ü, s/ş, c/ç, g/ğ…); for syllables and words, from the same pack and level; for a sentence's words, from the level's other sentences in the same pack ("The build builders" below).
- Only content whose letters are all in the class's `learned_letters` is used; a game whose level has too little such content cannot start (`GameSetupData::rules()` says why).
- The same target never appears twice in a row.

### Item styles and effects (code, chosen by data)

A pack picks its look from closed lists implemented in `resources/js/game/styles/`: item style `balloon` | `wagon` | `card` | `bubble`; slot style `wagon` | `frame`; effect `burst` | `sparkle`. A new style is code; a new theme that reuses the styles is data only.

The code (A6, A8.2, A8.3): one registry, `styles/registry.ts`, maps each key to its implementation — `ITEM_STYLES` (`balloon.ts`, `wagon.ts`, `card.ts`, `bubble.ts`), `SLOT_STYLES` (`slot-wagon.ts`, `slot-frame.ts`) and `EFFECTS` (`burst.ts`, `sparkle.ts`); a scene takes the item style from `pack.style.item`, the slot style from `pack.style.slot` (both through `stage.ts`) and the effect from `round.effect`, and asks the registry, never a style module directly. An item style (`styles/types.ts`) is `layout(count, band?)` (where the items rest and how big: across the 1920×1080 board for `find` and `pop`, or inside a band — a build round's tiles under its slots — sized to fit it), `stagger` (ms between items entering; 0 for wagons, which move as one train), an optional `scenery(band?)` drawn once (the train's rails), `draw(scene, text, look)` — a still picture of one item, which the build scenes fly into a slot — and `create()`, the living `ItemView` built on that picture (`styles/item.ts`, `BoardItem`): it enters, idles, wobbles on a miss, `pop()`s for `burst` or `lift()`s for `sparkle`, and leaves when its round is over. All drawing is Phaser Graphics and Andika text in the pack palette; no asset files.

| Key       | Enters                                                     | Waits               | Miss                   | Leaves                           |
| --------- | ---------------------------------------------------------- | ------------------- | ---------------------- | -------------------------------- |
| `balloon` | rises from below                                           | floats and sways    | wobbles                | drifts up                        |
| `wagon`   | the train rolls in from the left, wheels turning, on rails | chugs               | rattles on its wheels  | the train rolls off to the right |
| `card`    | dealt from above, turning face up                          | hovers              | shakes                 | slides down                      |
| `bubble`  | grows from a small bubble low down                         | drifts and breathes | squashes and stretches | floats up                        |

`burst` swells the item and breaks it into shards of its colour with a ring of air. `sparkle` never breaks anything (§8): the item glows, lifts and fades away whole while four-pointed stars in its colour and white twinkle over it and rise with it.

A slot style (A8.3) draws a build round's slots: `create(scene, {count, band, aspect, tail, palette})` gives a `SlotView` — `spot(i)` (where slot `i` is now, and the box a copy fits in; `aspect` is the item style's height ÷ width, so a slot hugs the copy it takes), `enter()`, `progress(filled)` (filled slots solid, the next one glowing), `complete(text)` (the unit whole) and `leave()` — plus an optional `scenery(band)` drawn once. The build scene keeps each landed copy on its slot's `spot()` every frame, so the copies ride with a moving train without belonging to it.

| Key     | Slots                                                                                                                                   | Tail (`prompt.tail`)              | Solved                                                      | Leaves                         |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------- | ----------------------------------------------------------- | ------------------------------ |
| `wagon` | empty wagons coupled behind a locomotive that leads on the left, on their own track; the train rolls in from the right, puffs and chugs | a short end car carrying the mark | the wagons turn solid and carry one long sign with the unit | the train pulls away leftwards |
| `frame` | plain rounded frames in a row, popping in                                                                                               | the mark after the last frame     | the frames give way to one plate with the unit              | floats up and fades            |

In both the next slot to fill glows. A correct hit flies a copy of the tile (`draw()`) into it and the tile stays; when the last copy lands the round's effect takes the copies — they burst, or sparkle away whole for `symbols` and `cities` — and the slots show the unit whole: «TUT», «nənə», «Tar gözəldir.».

Default looks: balloons → `balloon`; train → `wagon` (slots `wagon`); animals, our cities → `card`; fruit → `bubble`; national symbols → `card` with `sparkle` — a state symbol is never burst (§8).

The closed lists that data names are enums in `Content\Enums`: `Unit`, `Mechanic`, `ItemStyle`, `SlotStyle`, `Effect`, `Sound` (`hit` | `miss` | `solved`, the keys of a pack's `style.sounds`) and `License` (§8).

### What the board can draw

**Decision.** `Play\Support\Playable` is the one server-side list of what the board can draw: the mechanics with a round builder and a scene (`mechanics()`, `builder()`), and the item styles, slot styles and effects implemented in `resources/js/game/styles/` (`itemStyles()`, `slotStyles()`, `effects()`). A6 draws `find` in the `balloon` style with the `burst` effect, so "Şarı partlat" (find × balloons) is the only game that can start; A8 extends the lists: A8.1 lists the three build mechanics with their builders (below), which stay `not_playable` while `slotStyles()` is empty; A8.2 adds the item styles and `sparkle` (every key of `ItemStyle` and `Effect`, so `find` plays in all six packs), A8.3 the slot styles `wagon` and `frame` (every key of `SlotStyle`). Since A8.3 every mechanic plays in every pack: with all 32 letters learned, all 90 options of the setup page (15 mechanic × level pairs × 6 packs; 60 before `pop` joined the five letter, syllable and word levels) start, and `POST /classes/{class}/games` starts each build mechanic in each pack (`GameSetupTest`). No pack reaches `not_playable` today; it stays the answer for a key added to a closed list before the board draws it. `tests/Fixtures/playable.json` pins the three lists (`PlayableTest`), and Vitest (`styles/registry.test.ts`) checks every key in it has its code in `styles/registry.ts`, and nothing more. A pack is playable with a mechanic only when the board draws its item style and its effect (and its slot style for a build mechanic; `find` and `pop` have no slots, `! Mechanic::builds()`). `pop` needs no scene of its own: `stage.ts` gives it the `find` scene, which already bursts each correct item and keeps the rest. `Playable::reason()` says why a mechanic × pack × level cannot start for a class's learned letters (the level lacks the mechanic → letters chosen for a non-letter level → the board cannot draw it → the builder's content shortage), as a translated message; `GameSetupData::rules()` (through the `StartableGame` rule on `level`) and the setup page's `options` (`Playable::options($teacher, $learned)`) ask exactly that question, both about the pack as the class's teacher plays it (`TeacherPacks`, §3 "Teacher words"), which `StartGame` then builds from. Rounds carry the pack's own effect, so the `symbols` and `cities` packs (`sparkle`) are never burst (§8).

**Why.** A game the board cannot draw must not start, and the setup page must grey out exactly what the rules refuse; one list read by both cannot drift. Keeping the list in Play, not in the content, means a pack contributor never has to know which styles exist yet.

**Rejected.** A `playable` flag in each pack's JSON: content would have to change when code does, and a pack could claim a style the board lacks. Hiding packs in the setup page only: a request built by hand (or A7's phone) would still start an undrawable game.

### Levels (data)

`content/levels.json` — the A4 starting set:

| Key                | Unit     | Content filter                         | Mechanics                 | Items           |
| ------------------ | -------- | -------------------------------------- | ------------------------- | --------------- |
| `letters`          | letter   | the chosen or learned letters          | find, pop                 | 4               |
| `syllables-open`   | syllable | shapes V, CV, VC from the pack's words | find, pop, build_syllable | 4               |
| `syllables-closed` | syllable | shapes CVC, VCC, CVCC                  | find, pop, build_syllable | 4               |
| `words-short`      | word     | 1–2 syllables                          | find, pop, build_word     | 4               |
| `words-long`       | word     | 3–4 syllables                          | find, pop, build_word     | 4               |
| `sentences`        | sentence | 2–4 words                              | build_sentence            | words + 1 decoy |

`find` comes first in each list, so it stays the setup page's default; `pop` follows it.

Syllables are not listed by hand: they come from the pack's words through the syllabifier (§3), so every pack has syllable levels for free. The titles shown to teachers are "Qısa hecalar" / "Uzun hecalar" (short / long syllables: V, CV, VC have one or two letters, the closed shapes three or four), "Qısa sözlər" / "Uzun sözlər", "Hərflər", "Cümlələr".

A level entry (`Content\Data\LevelData`):

```json
{
    "key": "words-short",
    "title": { "az": "Qısa sözlər", "en": "Short words" },
    "unit": "word",
    "syllables": { "min": 1, "max": 2 },
    "mechanics": ["find", "pop", "build_word"],
    "items": 4,
    "decoys": 1
}
```

- Each unit has exactly one filter: `shapes` (syllable shapes as `Syllabifier::shape()` names them) for syllable levels, `syllables` (`min`/`max` per word) for word levels, `words` (`min`/`max` per sentence, within 2–4) for sentence levels, none for letter levels.
- `mechanics` must suit the unit (`Mechanic::supports()`); `items` (2–8, the items a `find` or `pop` round shows) is present exactly when the level has a mechanic that does not build (`find`, `pop`: `! Mechanic::builds()`); `decoys` (0–4, the decoy tiles a build round adds) exactly when it has a build mechanic.
- `LevelData::keysFrom($pack, $letters)` lists the distinct items of a pack that fit the level and the learned letters, as content keys; the round builders (A6, A8) start from it.

### Game setup

`Play\Data\GameSetupData::rules($class)`: `mechanic`, `pack`, `level` (must allow the mechanic, and the game must be playable with enough content for the class: `Playable::reason()`), `rounds` (5–30, default 10), `letter_case` (`upper` | `lower`, default `upper` for a letter level, `lower` otherwise), optional `letters` (a subset of the class's learned letters, only for a letter level; at least two, so targets can alternate). Every refusal is a translated message on its field (`games.setup.errors.*`), and the content shortages name the counts ("… :found heca var, ən azı :count lazımdır"). An archived class is hidden from games: its setup page and `POST /classes/{class}/games` answer 404.

`LetterCase` (`Play\Enums`): `upper` is every letter capital with Azerbaijani casing (`Az::upper`: i → İ, ı → I); `lower` is the content as written — letters and syllables lowercase, a word as the pack spells it, so a proper noun keeps its capital ("Bakı"), the syllables of a `build_word` round as split from that word ("Ba", "kı"), a sentence's words as the sentence writes them ("Tar", "gözəldir"). Text is cased by the builder on the server, never on the board.

`StartGame` takes the pack as the class's teacher plays it (`TeacherPacks::pack()`, §3 "Teacher words": the built-in words the teacher has not hidden, then the teacher's own words), hands it to the mechanic's builder (`RoundBuilder::build($setup, $pack, $learned, $random)`), builds every round with `Random\Randomizer` on `Mt19937($seed)` (a random seed unless a test gives one, stored on the game), so the same setup, learned letters, teacher's pack and seed give the same rounds (only the round ids differ), and stores the game (`live`, `board_epoch` 0, the class's teacher as owner) with its round snapshots in one transaction.

### The `find` builder

`Play\Support\Mechanics\FindRoundBuilder` (behind the `RoundBuilder` interface that the build builders implement too). Everything below but the solution and the prompt lives in `AnyRoundBuilder`, which the `pop` builder shares (next section):

- **Content.** A letter level takes the class's learned letters (targets: the teacher's `letters` among them when given); syllable and word levels take `LevelData::keysFrom($pack, $learned)` of the teacher's pack. The pool must hold at least the level's `items` (4) and two targets; otherwise the game cannot start.
- **Targets** go round in shuffled decks of every target; a new deck never starts with the target that ended the last one. Every target comes up equally often (±1) and none twice in a row. The deck, the item ids and the content as written live in `RoundParts` (`deck()`, `itemId()`, `value()`, `written()`), which the build builders share.
- **Decoys** for a letter are its confusables first (`Alphabet::confusables()`, most confusable first) among the learned letters, then other learned letters at random; for syllables and words, other items of the same pack and level at random. Item ids are `a`, `b`, `c`… in display order; the target's position is random.
- **No pictures.** `find` items carry `image: null`, and so does the prompt: a picture next to the target text, or on the items, would let a child match pictures instead of reading. The prompt shows the target as text (`show`, filled into `prompts.json`'s template with `PromptTemplateData::fill()`). Pictures belong to `build_word` (A8), where the picture is the question.

### The `pop` builder

`Play\Support\Mechanics\PopRoundBuilder` — «Oxu və vur», for beginners (five-year-olds) who cannot yet find a named item: **a `pop` round is a `find` round whose solution is every item.** Both builders extend `AnyRoundBuilder`, which picks a round's items — the same pool, the same target deck (`RoundParts::deck()`), the same decoys (confusables first for a letter), item ids, casing and `shortage()` (so the same `too_few_*` reasons) — and leaves each builder only its solution and its prompt. The same setup and seed give a `pop` game exactly the items of the `find` game it mirrors.

- **Items.** The level's `items` (4): the deck's item and `items` − 1 others. The deck still spreads the pool evenly over the rounds and never leads two rounds in a row with the same item; for a letter level the teacher's chosen `letters` lead the rounds, with their confusables beside them.
- **Solution.** `any` with every item id in display order; `slots` 0. Whatever the child hits is right: it bursts (or sparkles), the rest stay, and the round is solved when the last one goes; then the next set comes in. `RoundJudge` and `engine.ts` need nothing new: an `any` round already takes its solution's items in any order (`judge.json`).
- **Points.** There is no wrong item, so a solved `pop` round always has 0 misses and gives 3 points, credited to the student at the board and counted in the rankings like any other round (§5; `PointsRule` is unchanged).
- **Prompt.** The template takes no text: «Hər hərfi oxu və vur!», «Hər hecanı oxu və vur!», «Hər sözü oxu və vur!» — the child reads each item aloud before hitting it (the board does not listen, §3 "Voice"). So `show` is null and the prompt text is the template as written. No pictures, for the reason `find` has none.
- **Board and phone.** The board draws it with the `find` scene. The phone marks no item as the answer when every item is one (`remote-items.tsx`); a hit item shows as done.

### The build builders

`Play\Support\Mechanics\BuildSyllableRoundBuilder`, `BuildWordRoundBuilder` and `BuildSentenceRoundBuilder` share one flow (`BuildRoundBuilder`) and, with the find builder, `RoundParts` (the target deck, item ids, content as written). A build round is a target, built from its tiles in order, plus the level's `decoys` (1) decoy tiles.

- **Targets.** Each builder starts from `LevelData::keysFrom($pack, $learned)` and keeps what its mechanic can build:
    - `build_syllable`: syllables of two or more letters; a one-letter `V` syllable would be a one-slot `find`.
    - `build_word`: words of two or more syllables, as the Mechanics table says, so the one-syllable words of `words-short` are left out; with a picture, which every built-in word has (§3) and a teacher word never has, so teacher words never play in `build_word`.
    - `build_sentence`: every sentence of the level.

    A target is kept only when it can get all of the level's decoys (below). Targets go round in the find builder's decks (`RoundParts::deck()`): every target equally often (±1), never twice in a row.

- **Minimum pool.** Two targets for every build mechanic, the find builder's rule for chosen letters: a target needs another one to alternate with. A build round takes nothing from the pool but its target and its decoys, so the level's `items` play no part. With fewer, `shortage()` names the counts — `too_few_syllables`, `too_few_words`, `too_few_sentences` (`:found`, `:count` = 2), where `:found` counts the targets that can get their decoys. With all 32 letters learned every pack has at least 7 targets per build level.
- **Answer tiles.**
    - `build_syllable`: the syllable's letters (`letter:{lower}`), each cased; the syllable itself is the prompt's `show` (cased), filled into the template.
    - `build_word`: the word's syllables (its override when it has one, §3), each cased: in lower case «Bakı» gives «Ba», «kı», in upper case «BA», «KI»; content keys `syllable:{lower}`.
    - `build_sentence`: the sentence's words as written (lower case keeps the capital of the first word and of a proper noun; upper case is `Az::upper`); content keys `word:{lower}` (`word:tar` for «Tar»). The final `.`, `?` or `!` goes into `prompt.tail` and is never a tile.
- **Decoys**, always case-insensitively different from every answer tile and from each other:
    - `build_syllable`: letters. Confusables of the syllable's letters among the learned letters first (`Alphabet::confusables()`): the most confusable of each letter, then the next ones, in random order within a rank; then other learned letters at random. Never a letter of the syllable.
    - `build_word`: syllables of the level's other words with a picture (same pack, learned letters; the one-syllable words of `words-short` count as sources; teacher words do not), at random, written lowercase: a decoy belongs to no word on the board, so a proper noun's capital would give it away.
    - `build_sentence`: words of the level's other sentences (same pack, learned letters), at random, written as in their own sentence (the first of those sentences, in pack order, that has the word), so a capitalised decoy competes for the first slot.
- **One tile per text.** Item texts are unique within a round after casing. A part that comes again reuses its tile, and the tile's id repeats in `solution.item_ids`: `tut` → `t`, `u`, `t`; `nə-nə` → `nə` twice. The judge already expects that (`judge.json`'s `nə-nə`).
- **Order.** The tiles are shuffled and get ids `a`, `b`, `c`… in display order. The shuffle is redone while the answer's tiles (two or more, in order of first use) stand side by side in the answer's order, so the board never shows the answer already built; their reverse order is always allowed, so the redo ends.
- **Prompt.** `build_syllable` shows the cased syllable. `build_word` shows the picture: `prompt.image` is `ContentAssets::url($word->image, $pack->version)`, built like the board's other content URLs, and `show` is null. `build_sentence` has neither, only `tail`. The `build_word` and `build_sentence` templates take no text (`{x}` would put the answer on the board), so their prompt text is the template as written. Items carry `image: null`.
- **Round.** `slots` is the solution's length, `solution.order` is `sequence`, the effect is the pack's own (the `symbols` pack's `sparkle`).

### The round contract

One shape, shared by the server (PHP readonly DTOs in `app/Domain/Play/Data`), the board and the phone (zod schemas in `resources/js/game/contract.ts`, with the TypeScript types inferred from them, so schema and type cannot differ). JSON keys are snake_case. Both sides are pinned to the same fixtures in `tests/Fixtures/contract/*.json`:

- Pest (`tests/Unit/Play/ContractTest.php`): for every fixture, `Dto::fromArray($fixture)->toArray()` equals the fixture exactly (same keys in the same order, same values, same types).
- Vitest (`resources/js/game/contract.test.ts`): every fixture parses with the strict schema (`z.strictObject`, so an extra key fails as well as a missing one) and comes out unchanged.

The prefix of a fixture's file name names its DTO and schema: `round.*` (`RoundData` / `Round`: at least one `find` round per unit it supports, a round of every mechanic and a sequence round with a reused tile), `state.*` (`GameStateData` / `GameState`), `sync-request.*` (`BoardEventsData` / `SyncRequest`), `sync-response.*` (`SyncResultData` / `SyncResponse`), `game-status.*` (`GameStatusData` / `GameStatus`); a file with another prefix fails both tests. `judge.json` holds the judge cases (below).

A key added on one side breaks that side's test until the fixture changes, and the changed fixture then breaks the other side's test. The same schemas validate every client event the board receives (§4), so nothing malformed reaches the engine.

**Rejected:** a type-only check (`fixture satisfies Round` under `tsc`). TypeScript widens the strings of an imported JSON file to `string`, so the check fails on every literal union (`mechanic`, `unit`…) and never catches an extra key. Generated TypeScript from PHP (ADR 003) is also rejected.

```php
// app/Domain/Play/Data — properties are named like their JSON keys (snake_case), so toArray() is a plain mapping
final readonly class RoundData {
    public function __construct(
        public string $id,              // game_rounds.id
        public int $index,              // 0-based position
        public int $total,              // rounds in the game
        public Mechanic $mechanic,
        public Unit $unit,              // letter | syllable | word | sentence
        public string $pack,
        public string $level,
        public PromptData $prompt,
        /** @var list<RoundItemData> */
        public array $items,            // display order
        public int $slots,              // 0 for find and pop; the solution length for build mechanics
        public SolutionData $solution,
        public Effect $effect,          // burst | sparkle
    ) {}
}

final readonly class PromptData {
    public function __construct(
        public string $text,            // child-facing, always Azerbaijani: "«Ə» hərfini tap!"
        public ?string $show,           // what to show big: "Ə", "ba", null for picture prompts
        public ?string $image,          // URL of the picture, e.g. /content/fruit/alma.svg?v=3
        public ?string $audio,          // URL; null in v1 (no recorded voice, §3)
        public ?string $tail,           // fixed punctuation after the slots: "." "?" "!"
    ) {}
}

final readonly class RoundItemData {
    public function __construct(
        public string $id,              // short, unique in the round: "a", "b", …
        public string $text,            // already cased by the server
        public ?string $image,
        public string $content_key,     // letter:ə | syllable:ba | word:alma | sentence:{pack}:{id} | word:{text} for a sentence's word
    ) {}
}

final readonly class SolutionData {
    public function __construct(
        public SolutionOrder $order,    // any | sequence
        /** @var list<string> */
        public array $item_ids,         // in order for sequence; an id may repeat (reusable tile)
    ) {}
}

final readonly class HitData {           // one item touched, from the phone or the board
    public function __construct(
        public string $event_id,        // ULID made by the sender; idempotency key
        public string $round_id,
        public string $item_id,
        public HitSource $source,       // remote | board
        public ?string $student_id,     // at the board, null = whole class (no points)
        public ?CarbonImmutable $client_at,
    ) {}
}

final readonly class SkipData {          // the teacher passed on a round
    public function __construct(
        public string $event_id,
        public string $round_id,
        public ?CarbonImmutable $client_at,
    ) {}
}

final readonly class AnswerResultData {
    public function __construct(
        public string $event_id,
        public AnswerStatus $status,    // correct | miss | skipped | duplicate | stale
        public bool $round_solved,
        public ?int $points,            // set when this hit solved the round
    ) {}
}

// Also in Play\Data: BoardEventsData (the /sync body), SyncResultData (its answer),
// GameStatusData ({status, board_epoch}), GameData, RoundProgressData and GameStateData.
// `client_at` is written back as UTC with milliseconds, as JavaScript's toISOString() writes it.
```

```ts
// resources/js/game/contract.ts — schemas are the source; types are inferred
import { z } from 'zod';

const id = z.string().min(1);
const nullableText = z.string().nullable();

export const Mechanic = z.enum([
    'find',
    'pop',
    'build_syllable',
    'build_word',
    'build_sentence',
]);
export const Unit = z.enum(['letter', 'syllable', 'word', 'sentence']);
export const Effect = z.enum(['burst', 'sparkle']);

export const Round = z.strictObject({
    id,
    index: z.int().min(0),
    total: z.int().min(1),
    mechanic: Mechanic,
    unit: Unit,
    pack: z.string(),
    level: z.string(),
    prompt: z.strictObject({
        text: z.string(),
        show: nullableText,
        image: nullableText,
        audio: nullableText,
        tail: nullableText,
    }),
    items: z.array(
        z.strictObject({
            id,
            text: z.string(),
            image: nullableText,
            content_key: z.string(),
        }),
    ),
    slots: z.int().min(0),
    solution: z.strictObject({
        order: z.enum(['any', 'sequence']),
        item_ids: z.array(id).min(1),
    }),
    effect: Effect,
});

export const Hit = z.strictObject({
    event_id: id,
    round_id: id,
    item_id: id,
    source: z.enum(['remote', 'board']),
    student_id: id.nullable(),
    client_at: z.iso.datetime().nullable(),
});

export const Skip = z.strictObject({
    event_id: id,
    round_id: id,
    client_at: z.iso.datetime().nullable(),
});

export const BoardEvent = z.discriminatedUnion('type', [
    Hit.extend({ type: z.literal('hit') }),
    Skip.extend({ type: z.literal('skip') }),
]);

export const AnswerResult = z.strictObject({
    event_id: id,
    status: z.enum(['correct', 'miss', 'skipped', 'duplicate', 'stale']),
    round_solved: z.boolean(),
    points: z.int().nullable(),
});

export const GameState = z.strictObject({
    // GET /games/{game}/state, also the base of the board's client-state event
    game: z.strictObject({
        id,
        status: z.enum(['live', 'paused', 'finished', 'abandoned']),
        board_epoch: z.int(),
        pack: z.string(),
        mechanic: Mechanic,
    }),
    rounds: z.array(Round),
    progress: z.array(
        z.strictObject({
            round_id: id,
            hit_item_ids: z.array(id),
            misses: z.int(),
            solved: z.boolean(),
            skipped: z.boolean(),
        }),
    ),
});

export type Round = z.infer<typeof Round>; // likewise Hit, BoardEvent, AnswerResult, GameState, Mechanic, Unit, Effect
```

`contract.ts` also has the HTTP shapes of §4 around them: `SessionStatus`, `GameStatus` (`{status, board_epoch}`), `RoundProgress`, `SyncRequest` (`{board_epoch, events}`, 1–50 events), `SyncResponse` (`{results, game: GameStatus}`), `BoardReplaced` (the 409 body) and `GameStatusResponse` (`{game: GameStatus}`, the claim's and the status change's answer). The client-event payloads of §4 join the same file in A7 (`ClientHit`, `ClientState`, …).

The **judge** — "is this item the next correct one?" — is the only rule both sides implement: `Play\Support\RoundJudge::judge($round, $progress, $itemId)` (authoritative, decides what is stored) and `judge(round, progress, itemId)` in `resources/js/game/engine.ts` (instant feedback). Both return `correct`, `miss` or `stale`, the progress after the hit and whether the round is solved: in an `any` round an unhit solution item is correct and the round is solved when every solution item is hit, a burst item hit again is `stale`; in a `sequence` round only `solution.item_ids[progress.length]` is correct; any other item of the round is a miss; an item not in the round, or any hit on a solved round, is `stale`. Both run the same cases from `tests/Fixtures/contract/judge.json` (Pest dataset and Vitest): each case has `items`, a `solution`, an optional starting `progress` and `hits` with the expected `status`, `progress`, running `misses` and `solved`; they include a reused tile (`nə-nə`), a miss, two misses on the same decoy, a burst item hit again, an unknown item and a hit after the round is solved. The 800 ms cross-source rule (§4) belongs to the board alone and has its own Vitest cases. Points (§5) exist only in PHP; the board shows the number the server returns.

Example `find` round (letters, balloons pack):

```json
{
    "id": "01JC…",
    "index": 0,
    "total": 10,
    "mechanic": "find",
    "unit": "letter",
    "pack": "balloons",
    "level": "letters",
    "prompt": {
        "text": "«Ə» hərfini tap!",
        "show": "Ə",
        "image": null,
        "audio": null,
        "tail": null
    },
    "items": [
        { "id": "a", "text": "E", "image": null, "content_key": "letter:e" },
        { "id": "b", "text": "Ə", "image": null, "content_key": "letter:ə" },
        { "id": "c", "text": "A", "image": null, "content_key": "letter:a" },
        { "id": "d", "text": "O", "image": null, "content_key": "letter:o" }
    ],
    "slots": 0,
    "solution": { "order": "any", "item_ids": ["b"] },
    "effect": "burst"
}
```

### Adding a theme without code

1. Add `content/packs/{key}.json` (title in az and en, style keys from the lists above, sounds, words, sentences); give each word an OpenMoji picture from `content/assets/openmoji/` or an own SVG under `content/assets/{key}/` (§8).
2. Add every new asset to `content/credits.json` (§8).
3. Run `composer ci:check`: `ContentValidator` (in the Pest suite) checks the pack and its files; the game setup lists the pack at once.

No migration, seeder or translation key is involved: a pack's titles live in the pack.

---

## 3. Azerbaijani content

### Alphabet

**Decision.** `content/alphabet.json` holds the 32 letters in alphabet order with upper and lower form, vowel or consonant, Azerbaijani name and confusables:

```
A B C Ç D E Ə F G Ğ H X I İ J K Q L M N O Ö P R S Ş T U Ü V Y Z
```

Vowels (9): a, e, ə, ı, i, o, ö, u, ü. Consonants (23): b, c, ç, d, f, g, ğ, h, x, j, k, q, l, m, n, p, r, s, ş, t, v, y, z. `y` is always a consonant. Ə/ə are U+018F/U+0259 — never Ǝ (U+018E) or the Cyrillic Ә/ә (U+04D8/U+04D9).

`Language\Support\Alphabet` exposes it to PHP; a Pest test pins the count (32), the order, the vowel set and the names. Each letter has `upper`, `lower`, `vowel`, `name` and `confusables`, and the file has a `lookalikes` map (see Normalization). `Az` builds its case mapping from the same pairs, so no PHP or TypeScript file lists letters.

- **Names** (`Alphabet::name()`), as children hear a letter spelled: a, be, ce, çe, de, e, ə, fe, ge, ğe, he, xe, ı, i, je, ke, qe, el, em, en, o, ö, pe, er, se, şe, te, u, ü, ve, ye, ze.
- **Confusables** (`Alphabet::confusables()`, lowercase, most confusable first, symmetric — the validator rejects a pair listed one way only): a–ə, e–ə, i–ı, o–ö, u–ü, s–ş, c–ç, g–ğ, g–q, k–q, h–x, b–d, b–p, m–n. Letter decoys come from here first (§2). The owner reviews both lists in O2.

### Casing

**Decision.** `Language\Support\Az::upper()` / `Az::lower()` map i↔İ and ı↔I explicitly, then apply `mb_strtoupper` / `mb_strtolower` to the rest. All text that reaches the board or the phone is cased by the server; TypeScript never changes the case of content.

**Why.** `mb_strtoupper('i')` gives `I` and `mb_strtolower('İ')` gives `i̇` (i + combining dot) — both wrong for Azerbaijani. One implementation on the server means one set of tests.

**Rejected.** `toLocaleUpperCase('az')` in the browser: correct in current engines, but a second implementation, and the server needs casing anyway for names and the judge.

### Normalization

`Az::normalize()` runs on every name a teacher types and on every content string at validation: invalid UTF-8 repaired (`mb_scrub`), Unicode NFC (`Normalizer`, so `ext-intl` is required), the look-alikes in `alphabet.json` replaced (Cyrillic Ә/ә → Ə/ə, Ǝ/ǝ (U+018E/U+01DD) → Ə/ə, i + combining dot above (what `mb_strtolower('İ')` produces) → i), invisible characters removed (zero-width space, joiners, word joiner, soft hyphen, BOM), every run of whitespace (tabs, no-break spaces) turned into one space, trimmed. Content that still contains a letter outside the alphabet (besides space, hyphen and `. , ! ? « »`) fails validation.

`Az::title()` writes the first letter of every word and hyphenated part upper and the rest lower ("ƏLİ" → "Əli", "gül-ayşə" → "Gül-Ayşə"); the paste preview uses it for names.

### Syllabification

**Decision.** `Language\Support\Syllabifier` applies the standard rule for Azerbaijani (one vowel per syllable; consonants between two vowels):

| Between two vowels | Split               | Example                                         |
| ------------------ | ------------------- | ----------------------------------------------- |
| no consonant       | between the vowels  | `sa-at`, `mü-əl-lim`                            |
| one consonant      | before it           | `a-ta`, `a-na`                                  |
| two or more        | before the last one | `al-ma`, `qar-pız`, `dost-luq`, `A-zər-bay-can` |

Consonants before the first vowel join the first syllable (`stol`, `qra-fin`); consonants after the last vowel join the last (`dost`, `üst`). Casing is kept as in the word.

A word may override the algorithm in its pack: `{"text": "qreypfrut", "syllables": ["qreyp", "frut"]}` (the rule alone would give `qreypf-rut`). The validator rejects an override whose parts do not join back to the word exactly (same letters, same case), whose part does not have exactly one vowel, or that equals what the rule gives already — so the overrides in the packs are exactly the words the rule gets wrong. The override, when present, always wins (`WordData::$syllables`, `WordData::$overridden`).

`Syllabifier::shape()` names a syllable's pattern of vowels and consonants (`a` → V, `ba` → CV, `al` → VC, `bal` → CVC, `üst` → VCC, `dost` → CVCC); syllable levels pick by it (§2). Vowels are tested through `Az::lower`, so `I` and `İ` count; `y` and `ğ` are consonants.

**Why.** The rule is short, deterministic and right for native words; loanwords and teacher disputes are exactly what per-word overrides are for, and they are reviewed in pull requests by the owner (step O2).

**Rejected.** A hyphenation dictionary (TeX/Hunspell `hyph_az`): hyphenation rules for line breaks differ from the syllables a first-grade textbook teaches, and the license and quality of an `az` pattern file are unclear. Storing syllables for every word by hand: slow to write and easy to get inconsistent.

The test set `content/tests/syllables.json` (235 words in A4, grouped by the rule they exercise and written with hyphens, `"A-zər-bay-can"`; reviewed by the owner in O2) runs as a Pest dataset. Its expectations come from the textbook rule and Azerbaijani usage, never from running the Syllabifier; it covers every row and example of the table above, `y` and `ğ` as consonants, words starting with i, ı, İ or I, words in capitals, hiatus (`a-i-lə`, `şe-ir`) and proper nouns.

### Where content lives

**Decision.** Built-in content is versioned files in the repository, read through `Content\Support\ContentRepository`, validated by `Content\Support\ContentValidator` and cached; it is never seeded into database tables.

```
content/
├── alphabet.json          # 32 letters, vowels, names, confusables, look-alikes
├── levels.json            # §2
├── prompts.json           # child-facing prompt templates per mechanic and unit (Azerbaijani only)
├── avatars.json           # avatar keys and their pictures (§7, §8)
├── credits.json           # every asset file → source, author, license (§8)
├── packs/
│   ├── train.json   balloons.json   animals.json
│   └── fruit.json   cities.json     symbols.json
├── assets/                # served at /content/… (public/content → content/assets)
│   ├── avatars/           # Kenney "Animal Pack Redux", one SVG per key (CC0, §8)
│   ├── sounds/            # Kenney "Interface Sounds" as MP3 (CC0, §8)
│   ├── openmoji/          # OpenMoji word pictures, unmodified, and their LICENSE.txt (CC BY-SA 4.0, §8)
│   └── {pack}/            # Elifba's own SVGs for what OpenMoji lacks (MIT, §8)
└── tests/
    └── syllables.json     # ≥ 100 words with expected syllables, by rule
```

A pack:

```json
{
    "key": "fruit",
    "version": 3,
    "title": { "az": "Meyvələr", "en": "Fruit" },
    "style": {
        "item": "bubble",
        "slot": "frame",
        "effect": "burst",
        "palette": ["#F6C445", "#E85D4A", "#5DB075"],
        "sounds": {
            "hit": "sounds/pop.mp3",
            "miss": "sounds/bong.mp3",
            "solved": "sounds/success.mp3"
        }
    },
    "words": [
        { "text": "alma", "image": "openmoji/1F34E.svg" },
        { "text": "armud", "image": "openmoji/1F350.svg" },
        {
            "text": "qreypfrut",
            "image": "fruit/qreypfrut.svg",
            "syllables": ["qreyp", "frut"]
        }
    ],
    "sentences": [{ "id": "alma-qirmizidir", "text": "Alma qırmızıdır." }]
}
```

Asset paths are relative to `content/assets`; a path must exist with exactly that spelling (a case-insensitive disk would hide `1f34e.svg` on a Mac and fail on Linux) and needs a credits entry (§8). Folders are lowercase; file names may hold capitals, for OpenMoji's code points.

- **Sounds.** Every pack has `style.sounds` with `hit`, `miss` and `solved` (A5): the hit sound follows the effect — a pop for `burst`, a gentle chime for `sparkle` (a state symbol is never popped, §8).
- **Pictures.** A word's `image` is an OpenMoji file named by its code points (`openmoji/1F34E.svg`, `openmoji/1F9D1-200D-1F3EB.svg`) when an emoji clearly shows the word, otherwise an own SVG under the pack's folder (`fruit/qreypfrut.svg`). No two words of one pack share a picture; words of different packs may. From A5 on every word has one: A5 drew own art for the words OpenMoji lacks and switched the check on (below).
- **Backdrop.** `style.backdrop` is optional own art (§8): A5 drew the `cities` pack's (`cities/qiz-qalasi.svg`, Qız qalası); A6 and A8 add others.

`content/avatars.json` lists the avatars in the order new students get them (A3); each has a key that fits `students.avatar` and a picture:

```json
{ "avatars": [{ "key": "panda", "image": "avatars/panda.svg" }] }
```

`Content\Support\Avatars` reads it; page props carry each avatar's URL (`Avatars::all()`, built by `ContentAssets::url()`), so TypeScript never builds an asset path.

`content/prompts.json` has one template per mechanic × unit that `levels.json` allows (`Content\Data\PromptTemplateData`, filled with `fill()`; Play's `PromptData` is the prompt of one built round):

```json
{
    "prompts": [
        { "mechanic": "find", "unit": "letter", "text": "«{x}» hərfini tap!" },
        {
            "mechanic": "find",
            "unit": "syllable",
            "text": "«{x}» hecasını tap!"
        },
        { "mechanic": "find", "unit": "word", "text": "«{x}» sözünü tap!" },
        {
            "mechanic": "pop",
            "unit": "letter",
            "text": "Hər hərfi oxu və vur!"
        },
        {
            "mechanic": "pop",
            "unit": "syllable",
            "text": "Hər hecanı oxu və vur!"
        },
        { "mechanic": "pop", "unit": "word", "text": "Hər sözü oxu və vur!" },
        {
            "mechanic": "build_syllable",
            "unit": "syllable",
            "text": "«{x}» hecasını qur!"
        },
        {
            "mechanic": "build_word",
            "unit": "word",
            "text": "Sözü hecalardan yığ!"
        },
        {
            "mechanic": "build_sentence",
            "unit": "sentence",
            "text": "Sözlərdən cümlə qur!"
        }
    ]
}
```

- **Versioning.** `version` is an integer bumped on every change to the pack; `game_sessions.pack_version` records it and asset URLs carry it (`?v=3`) for cache busting. Snapshots (§2) mean an old game never reads the current pack.
- **Serving assets.** `config/filesystems.php` `links` gains `public_path('content') => base_path('content/assets')`, so `php artisan storage:link` (run by `composer setup` and the Docker image) creates the link. The JSON files are not public.
- **Cache.** `php artisan content:cache` validates every file and, only when they are valid, writes one compiled PHP array to `bootstrap/cache/content.php` (`Content\Actions\WriteContentCache`, compiled from the JSON files, never from an older compiled file), which OPcache keeps in memory the way `config:cache` does; `content:clear` removes it (`DeleteContentCache`). The Content service provider (`app/Providers/ContentServiceProvider.php`) registers them with `$this->optimizes(optimize: 'content:cache', clear: 'content:clear')`, so the deploy's `php artisan optimize` builds it and `optimize:clear` removes it. Without that file (development) `ContentRepository` reads the JSON files directly, once per request (the container holds one instance), so an edited pack shows on the next reload. Tests never read the compiled file — the provider gives the repository no compiled path when `runningUnitTests()` — so a stale `bootstrap/cache/content.php` cannot change their results. `php artisan content:validate` prints every problem. (Rejected: the Laravel cache keyed by a hash of the files. Computing the key means reading every file on every request, and with the default database cache store each request would also fetch a large blob.)
- **Validation** (`ContentValidator`, run by a Pest test over the real `content/` and over `tests/Fixtures/content/`, one broken fixture per rule in `broken.json`). It takes a content root, so fixtures are validated the same way, and lists every problem as `file: where: what`:
    - **Every file:** valid JSON objects with exactly the documented keys (a typo such as `syllabels` is an unknown key); every string stored normalized — anything `Az::normalize()` would change (Cyrillic ә, Ǝ/ǝ, invisible characters, double spaces) is rejected, not fixed.
    - **`alphabet.json`:** every letter has an `upper`, `lower`, `vowel`, a name of alphabet letters and confusables that are other letters of the alphabet and list the letter back; look-alikes map to letters.
    - **`avatars.json`:** unique keys matching `Avatars::KEY_PATTERN`; every avatar has an `image` under `content/assets`, and no two avatars share one.
    - **`levels.json`:** unique keys; titles in az and en; the filter, `items` and `decoys` rules of §2; mechanics that suit the unit.
    - **`prompts.json`:** one template per mechanic × unit that a level allows, no more; `{x}` at most once and only as `«{x}»` with nothing attached; alphabet letters, spaces and `. , ! ? -` only; a final `.`, `!` or `?`.
    - **Packs:** the key is the file name; `version` ≥ 1; titles in az and en; style keys from the closed lists, `#RRGGBB` palette colours (1–8), sounds only for `hit`, `miss`, `solved`; every picture, backdrop and sound exists under `content/assets`; no two words of the pack share an `image`. A word is a single word of alphabet letters (no space, hyphen or sign), lowercase or capitalised as a proper noun, unique in its pack by `Az::lower`, with a vowel; overrides as in Syllabification. A sentence is 2–4 words of alphabet letters separated by single spaces, starts with a capital letter and ends with exactly one `.`, `!` or `?`; its id matches `^[a-z0-9]+(-[a-z0-9]+)*$` and is unique in the pack, and its text is not repeated.
    - **The bar:** every pack has at least 20 words and 10 sentences (A4's bar). With all 32 letters learned, every syllable, word and sentence level gets at least 6 distinct items from every pack (`find` shows 4 and never repeats a target in a row); every word fits a word level and every sentence a sentence level.
    - **Credits** (§8): every file under `content/assets` and `resources/fonts` (hidden files aside) has an entry; every entry's patterns match a file and stay in those folders; licenses from the allow list; share-alike files only in `content/assets/openmoji/`.
    - **SVG files** (§8): every `.svg` under `content/assets` has no `<script`, no `on…=` event attribute, no `<foreignObject` and no `href` (or `xlink:href`) other than an in-file `#…` reference.
    - **Every word has a picture:** `ContentValidator::WORD_PICTURES_REQUIRED`, on since A5 drew own art for the words OpenMoji lacks; a word without an `image` is a problem.
    - **`tests/syllables.json`:** alphabet letters with a hyphen between parts of one vowel each, no word twice.
- **Child-facing text** (prompts, words, sentences) is Azerbaijani regardless of the teacher's interface language: the product teaches Azerbaijani reading. Prompt templates wrap inserted content in «» and never attach a suffix to it (`«{x}» hecasını tap!`), so vowel harmony never has to be computed.
- **Content keys** are stable strings: `letter:{lower}`, `syllable:{lower}`, `word:{lower}`, `sentence:{pack}:{id}` (`WordData::key()`, `SentenceData::key()`, `LevelData::keysFrom()`).

**Why.** Content is small (hundreds of entries), changes with the code, and must be reviewed by a teacher (O2) — a pull request diff is the right review tool. Files are one source of truth: no seeding step at deploy, no drift between the repository and a database, tests read exactly what production reads.

**Rejected.** JSON packs seeded into tables: two sources of truth, a deploy step that can be forgotten, and no query that needs SQL. Content edited in an admin panel: there is no admin role in v1, and it would hide content changes from review. Teachers' own words are not built-in content: they live in tables next to the files and are merged per teacher ("Teacher words" below, ADR 007) — additive, not a reversal.

### Teacher words

**Decision** (ADR 007). A teacher may add their own words to any theme pack, edit and delete them, and hide built-in words from their own games and show them again. Built-in words stay the same for everyone; a teacher's words and hidden words apply to all of that teacher's classes and to no other teacher. Teacher words have no picture. Tables: `custom_words` and `hidden_words` (§1).

- **One merge.** `Content\Support\TeacherPacks::pack($teacher, $key)` — and `packs($teacher)`, every pack in key order, in two queries — gives a pack as that teacher plays it: the built-in words in file order without the hidden ones, then the teacher's words in the order they were added (`created_at`, then `id`), as `WordData` with `image: null` and their override or the Syllabifier's split. A teacher word whose `Az::lower` equals a built-in word of the pack, hidden or not, is dropped, so when a later pack version adds a word a teacher already has, the built-in one wins with its picture. Sentences, look, title and version are the built-in pack's. `TeacherPacks::merge()` is the pure function behind it; `ContentRepository` stays the reader of the files only.
- **Who reads it.** Every Play reader that builds or checks rounds: `Playable::options($teacher, $learned)` (the setup page), `StartableGame` in `GameSetupData::rules()`, and `StartGame`, which passes the pack to the builder (`RoundBuilder::build($setup, $pack, $learned, $random)`). The teacher is the class's teacher, who owns the game. `BoardProps` and `GameSetupOptions::shared()` read the built-in pack: they need only its look and title.
- **Where a teacher word plays.** Syllables come from a pack's words (§2), so a teacher word gives its syllables to the syllable levels by itself: it plays in `find` and `pop` at word and syllable levels and in `build_syllable`. It has no picture, so `build_word` never takes it, as a target or as a source of decoys. A hidden built-in word is gone from every level of that teacher's games, and so are the syllables no other word of the pack gives; sentences are not affected.
- **Rules for a teacher word** (`Content\Data\CustomWordData`). The input is normalized first (`Az::normalize`, each override part too: phone keyboards type Cyrillic ә), then:
    - the rules every pack word follows, from the one checker `ContentValidator` also uses (`Content\Support\WordRules`, problems as `Content\Enums\WordProblem`): one word of alphabet letters with no space, hyphen or sign; lowercase, or capitalised as a proper noun; a vowel; an override that joins back to the word exactly with one vowel in each part. An override equal to the Syllabifier's split is dropped (stored as null) rather than refused: a pack file refuses it to keep diffs minimal, while a teacher's form may send the split back unchanged;
    - at most `elifba.custom_words.max_length` (20) characters;
    - not a built-in word of that pack, hidden or not (`built_in`: the teacher shows the hidden word instead);
    - it fits at least one word level (`LevelData::fitsWord()`, with the override when there is one), so no word is stored that no game can use (`no_level`);
    - checked by `AddCustomWord` and `UpdateCustomWord` under a lock on the teacher's `users` row: not already one of the teacher's words in that pack, compared with `Az::lower` (`taken`; the unique index on `word_key` backs it), and at most `elifba.custom_words.max_per_teacher` (200) words over all packs (`too_many`).

    Each refusal is a `ValidationException` on `text` or `syllables` with a `words.errors.*` message (`letters`, `casing`, `no_vowel`, `split`, `built_in`, `no_level`, `taken`, `too_many`, `not_in_pack`) that never contains the word.

- **Hiding.** `HiddenWordData` (`pack`; `word`, a built-in word of that pack, normalized and compared with `Az::lower`, `not_in_pack` otherwise). `HideWord` is idempotent (it holds the teacher's lock and finds the row before it adds one); `ShowWord` deletes the row and does nothing when there is none.
- **Shortages.** Hiding words can leave a level with too little content for a class. `Playable::reason()` then gives the builder's `shortage()` (`too_few_words`, `too_few_syllables`), exactly as for unlearned letters, on the setup page and in `GameSetupData::rules()`.
- **Snapshots.** A teacher word reaches the board only inside a round snapshot, under the key `word:{lower}` like any word; the round contract is unchanged. `game_sessions.pack_version` records the built-in pack's version.
- **Privacy.** A teacher word may be a child's name. It is never written to a log, an exception message or a URL (§7): routes address a teacher word by its ULID, and hiding or showing carries the pack and the word in the request body.
- **Page and routes** (`routes/words.php`, `Http\Controllers\Content\{WordController,HiddenWordController}`, page `words/index`, sidebar item «Sözlər»). `GET /words?pack={key}` shows one pack (the first one for a missing or unknown key): `packs` (`key`, `title` in the interface language), `builtInWords` (`text`, `syllables`, `hidden`), the teacher's `customWords` in that pack (`id`, `text`, `syllables`, `override`, `played` — false when a later pack version has the word built in) and `limits` (`max_length`, `max_per_teacher`, `count` over all packs). Syllables reach React joined by the server (`WordData::hyphenated()`, `al-ma`); the override field is typed the same way and `CustomWordData::prepare()` splits it at its hyphens (a phone's dash counts; empty parts are dropped, so an empty field is no override). `POST /words` (`AddCustomWord`), `PATCH /words/{word}` (`UpdateCustomWord`, which may move the word to another pack) and `DELETE /words/{word}` (`DeleteCustomWord`) redirect to the word's pack; `{word}` is a ULID (`whereUlid`) bound only among the current teacher's words (`BelongsToTeacher`) and checked by `Content\Policies\CustomWordPolicy`. `POST /words/hidden` (`HideWord`) and `DELETE /words/hidden` (`ShowWord`) take `pack` and `word` in the body. Validation messages name the fields in the interface language (`words.attributes.*`); flash messages never carry a word; `text`, `syllables` and `word` are not flashed back into the session. The page says that teacher words have no picture and so are not used in «Sözü yığ»; deleting asks first in a dialog.

**Why.** Teachers play with the words their class is reading this week, which no pack can predict, and some built-in words do not suit every class. Tables per teacher keep the files the single, reviewed source for everyone, and one merge read by every Play reader means the setup page never offers a game `StartGame` cannot build.

**Rejected.** Teacher words in the pack files (a pull request per word, and every teacher would get them). Words per class (a teacher with two classes would type them twice; learned letters already narrow each class). Deleting or copying built-in words per teacher (a copy misses pack fixes; hiding is undone with one click). Pictures for teacher words (uploads bring storage, moderation and licenses, §8; `build_word` is the only place pictures are used).

### Learned letters

**Decision.** The class page shows the 32 letters as toggles (with "all" and "none"); the teacher switches on the letters the class has learned (`school_classes.learned_letters`, default all 32). Every level uses only letters, syllables and words made of switched-on letters. No textbook order is built in.

**Why.** It works with any textbook edition and any pace, and the teacher knows exactly which letters were taught.

**Rejected.** A built-in teaching order with "up to letter X": it would tie Elifba to one textbook edition, orders differ between editions, and a teacher who goes at another pace would fight it. If a common order is wanted later, it becomes a preset in `alphabet.json` — data only.

### Voice

**Decision.** v1 has no recorded voice. The board shows the prompt in large text (with a picture where there is one) and plays sound effects; the teacher reads the prompt aloud. `prompt.audio` stays in the contract and is `null`.

**Why.** Recording 32 letter sounds and every prompt in a clear, child-friendly voice is a production task of its own, and the classroom already has a voice: the teacher's. The games work without it.

**Rejected.** Browser speech synthesis: school computers and phones usually have no Azerbaijani voice, and a voice of another language would teach wrong sounds. Recording before the first games: it would hold back every game step for something the teacher already covers.

Adding voice later is content work only: audio files referenced from `alphabet.json` and the packs, listed in `credits.json`, and `prompt.audio` filled by the round builders.

### Ordinals and dates

Ordinals take vowel harmony (1-ci, 2-ci, 3-cü, 4-cü, 5-ci, 6-cı, 9-cu, 10-cu…). `Language\Support\Ordinal::make()` builds them from the last vowel of the last number word said aloud: 21 "iyirmi bir" → 21-ci, 40 "qırx" → 40-cı, 100 "yüz" → 100-cü, 110 "yüz on" → 110-cu, 1000 "min" → 1000-ci, 2500 "iki min beş yüz" → 2500-cü. Places start at 1. The server sends `place_label`, and TypeScript never concatenates a suffix: `Ordinal::label($place, $locale)` gives "1-ci", "2-ci", "3-cü" in the `az` interface and "1st", "2nd", "3rd", "11th", "21st" in `en`. Dates are formatted by the server with Carbon's month names in the interface language (`Language\Support\DateLabel`): "9 noyabr 2026" / "9 November 2026"; a range names each month and year once — "9–15 noyabr 2026", "26 oktyabr – 1 noyabr 2026", "28 dekabr 2026 – 3 yanvar 2027". TypeScript never formats a date or names a month.

---

## 4. Live play

### Devices

| Device                                                        | Page                                                                                               | Authenticated as                                                                | Writes                                                   |
| ------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- | -------------------------------------------------------- |
| **Board** — the laptop/PC on the projector, or a touch screen | `/games/{game}/board` (teacher board) or `/lovhe` (guest board); full screen, no app shell, Phaser | the teacher's web session (teacher board) or a guest board bearer (guest board) | results: hits and skips (`POST /games/{game}/sync`)      |
| **Remote** — the teacher's phone                              | `/games/{game}/remote` (React, big buttons, no Phaser)                                             | the same teacher (web session, "remember me")                                   | nothing that counts; it sends client events to the board |

### Pairing

**Decision.** The phone is always logged in as the teacher. A 6-digit code (and its QR) only says which board; it grants nothing by itself. A board runs in one of two modes with the same protocol:

| Mode              | Where                                   | The board is authenticated as                | How a game gets there                                                                                                           |
| ----------------- | --------------------------------------- | -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| **Teacher board** | the teacher's own laptop                | the teacher's web session                    | the teacher starts the game on the laptop (`/classes/{class}/games/create`), then pairs the phone with the code the board shows |
| **Guest board**   | a shared school computer or smart board | a guest board bearer — never a teacher login | anyone opens `/lovhe`; the board shows a code; the teacher's phone claims it and sends a game to it                             |

**Codes.** `Play\Support\PairingCode` makes every code: 6 digits, unique across live games' join codes and open guest boards (one code space), valid for `elifba.pairing_code_ttl_minutes` (10). The board shows a QR (`qrcode.react`) of `{APP_URL}/pult/{code}` and the code in large type ("Telefonda {domain}/pult açın, kod: 482 913", where `{domain}` is the host of `APP_URL`). The phone opens `/pult/{code}` or types the code at `/pult` (`GET /pult?code=482 913` → `/pult/482913`; spaces and dashes are dropped, anything but 6 digits is a validation error on `code`); not logged in → login → back to the same URL. `/pult` also lists the teacher's live and paused games, newest first, so a logged-in phone can join without a code (with exactly one it opens its remote directly; `/pult?list=1` keeps the list, so the code can still be typed). `/pult`, `GET /pult/{code}` and `POST /pult/{code}` share one limiter (`pult`, 10 a minute per teacher); an unknown, expired or malformed code, another teacher's game, a finished or abandoned game and a claimed or closed guest board all give the same 404, so a code reveals nothing.

No database constraint spans the two tables, so `PairingCode::issue()` skips a code that is valid in either and retries (up to 20 times) when the store hits a unique index — an expired code still sits in its column, and two requests may race. Each attempt writes in its own transaction (a savepoint inside an outer one), so a violation rolls back only that attempt. `PairingCode::resolve(code, teacher)` is deterministic when a code is found in both tables: the teacher's own live game with that join code first (→ its remote), then the open guest board (→ what to show on it).

**Teacher board.** `IssueJoinCode` puts a code on the game when the board shows its pairing card: `POST /games/{game}/join-code` on load and on «Pultu qoş» (teacher only). A code still valid is kept, so a QR already on the wall keeps working and a phone can scan it again; an expired one is replaced; it is cleared when the game ends, and an ended game gets 409 `{error: "game_over", game: {status, board_epoch}}`. The answer is `{pairing: {code, expires_at, url, entry_url}}` (`PairingData`): `url` is `{APP_URL}/pult/{code}` for the QR and `entry_url` `{APP_URL}/pult` for typing, both on `APP_URL`, not on the request's host, so `composer dev:lan` and a proxy give phones an address they can open. Issuing a code is not activity (`last_activity_at` stays). `/pult/{code}` finds a live game with that join code owned by the logged-in teacher and opens `/games/{game}/remote`. The board hides the pairing card once a remote says hello.

**Guest board.**

1. `/lovhe` (no login; the page itself writes nothing) posts `POST /guest-boards`, which calls `OpenGuestBoard`: a `guest_boards` row with a code and a random 32-byte secret. The answer, 201 `{board, token}`, is the only time the secret leaves the server: `token` is the bearer `{board_id}.{secret}`, kept only in the tab's `sessionStorage` (`elifba.guest-board`, as `{board_id, secret}`) and in memory, and sent only in the `Authorization` header — never in a URL, `localStorage`, a cookie, Inertia props, a log or an error; the row stores the secret's hash. A reload of the tab continues with the same board; a new tab starts with a new code. `POST /guest-boards` is throttled per IP (limiter `guest-boards`, 6 a minute); unclaimed rows expire with their code.
2. The teacher scans the QR. `GET /pult/{code}` finds the open guest board and asks what to show (page `pult/guest-board`, props from `Play\Support\PultChoices`: the code, the teacher's live games, their active classes, and per class the setup form's options and learned letters from `Play\Support\GameSetupOptions` — the builder the setup page uses too): one of the teacher's live games or a new one (the same setup form, `components/games/game-setup-form.tsx`, with the class as a hidden field). `POST /pult/{code}` takes `{game}` or `{class, mechanic, pack, level, rounds?, letter_case?, letters?}` (`GuestBoardGameData::rules()`: the game must be the teacher's live or paused one, the class the teacher's active one — another teacher's id fails the same `exists` rule as an unknown one — then `GameSetupData::rules()` for a new game). `ClaimGuestBoard` locks the board, binds it to the teacher and clears the code; `ShowGameOnGuestBoard` attaches the game (starting it with `StartGame` when it is new), in the same transaction. A board claimed, closed or expired a moment ago is a 404. The phone opens `/games/{game}/remote`, whose props carry `guestBoard: {id} | null` (the active board of this teacher showing the game).
3. The guest board polls `GET /guest-boards/{board}` with `Authorization: Bearer {board_id}.{secret}` every 2 s while it waits (a code, or a game that is over), every 10 s while it shows a live or paused game, and at once (at most once a second) when one of its board requests answers 401 or 404 (its own limiter, `guest-board`, 120 a minute per board, apart from the opening limit). The answer is `{board: {id, expires_at, pairing: {code, expires_at, url, entry_url} | null, teacher: {name} | null, game: {id, status} | null}, props: BoardProps | null}`: a bearer cannot make an Inertia visit, so `props` carries exactly the teacher board's page props (`Play\Support\BoardProps`, one builder for both, and for the remote). When a game appears it loads it exactly like a teacher board (`ClaimBoard`, `/state`, `/sync`, `/status`, the channel), with the same bearer; the tab keeps the props of that game as first received, so a poll never rebuilds the board. The bearer reaches only the board's current game — never the teacher's classes, rankings or other games — and an expired, closed or wrong bearer is 401, which tells the tab to open a new board.
4. When a game ends, the board shows its result and «Növbəti oyun telefondan gözlənilir…»; the phone's finished screen offers «Növbəti oyunu lövhəyə göndər» (`GET /guest-boards/{board}/game`, the page of step 2 sending to the board instead of a code) and sends the next game (`PUT /guest-boards/{board}/game`, the same body as `POST /pult/{code}`, `ShowGameOnGuestBoard` again) without a new code; from then on the old game is a 404 for the bearer. Each game gets its own board in the tab (its own outbox key `elifba.board.{game}.outbox`, epoch and tab fencing); before the next game replaces the one on the wall, the old board sends what its outbox holds once (`BoardController::settle()`, at most 5 s). Since the bearer has already lost the old game by then, anything still unsent stays in the tab under that game's key and is sent first if that game comes back to the tab. The board closes after `elifba.guest_board_hours` (12) or when either side presses «Lövhəni bağla» (`DELETE /guest-boards/{board}`, `CloseGuestBoard`: 204 to the board's `fetch`, a redirect back to the phone's Inertia visit); the bearer stops working at once, the game goes on, and `PruneGuestBoards` deletes the row within the hour; nothing of it survives closing the tab. On the board, «Lövhəni bağla» (asked first) settles the outbox, closes, forgets the bearer and shows «Lövhə bağlandı» with «Yeni kod»; on the phone it sits in the remote's header (in place of the QR button, which a guest board does not need) and on its finished screen. A board closed from the phone learns it from its next poll (401) and opens a new board with a new code. A phone whose board was closed on the wall (or expired) gets a 404 from these routes; the remote then reloads its `guestBoard` prop instead of showing the error.
5. The claimed board shows the claiming teacher's name («Müəllim: …») and «Mənim deyil — yeni kod» on its start and result screens. The code is visible to the whole room, so another registered teacher could claim it first; they would only see their own game on it, and one tap undoes it: the board closes itself and opens a new one with a new code.

**Why.** A QR projected on the classroom wall can be scanned by anyone in the room; if the code were the credential, a parent's or child's phone could control the game, so authority comes from the teacher's login on the phone. Teachers need that login on the phone anyway to share the podium (§6). Many classrooms project from a shared school computer: the guest board keeps the teacher's password and session off it, and the computer keeps nothing once the tab is closed. The teacher board stays for teachers with their own laptop and for play without a phone.

**Rejected.** A code-as-credential remote (no login on the phone): anyone in the room could control the game. Guest boards only: a game without a phone would then still need a phone to start. A second token handed to the guest board after the claim: the secret the board already holds does the job, and it never leaves the tab.

### Channel and authorization

One private channel per game: `private-game.{gameId}` (Echo: `echo.private('game.' + id)`).

```php
// routes/channels.php — the teacher's session or a guest board's bearer
Broadcast::channel('game.{game}', static fn (User|GuestBoard $who, string $game): bool
    => GameChannel::allows($who, $game), ['guards' => ['board', 'web']]);

// Play\Support\GameChannel::allows(): the game exists, is live or paused,
// and Gate::forUser($who)->allows('play', $game) — its owner, or the active
// guest board that shows it (GameSessionPolicy::play).
```

The callback takes the id as a string and loads the game itself: a typed `GameSession $game` would go through the `{game}` route binding, which follows the default (`web`) guard, so during `/broadcasting/auth` a bearer would get 403.

The `board` guard (`Auth::viaRequest('board', new GuestBoardGuard)` in `AppServiceProvider`; `config/auth.php` names the guard) reads the bearer, finds the row, compares the secret's hash with `hash_equals`, refuses a closed or expired board (`isActive()`), and returns the `GuestBoard` (which implements `Authenticatable`, with no password and no "remember me"). The guest board's Echo instance sends the bearer to `/broadcasting/auth`.

**Guard order.** The board routes take `auth:board,web` and the channel `guards: ['board', 'web']`: a bearer sent with a request wins over a session cookie in the same browser. So a guest board's tab on a computer where some teacher is still logged in acts as the board — never with that teacher's wider reach, and still working when that teacher does not own the game. A request without a bearer is the teacher's, as before — and so is one whose bearer no longer works (a closed or expired board authenticates nothing, so the session decides: the board's game is then a 404 unless that teacher owns it); the guest board's own poll is `auth:board` only, answers 401 and makes the tab open a new board. `GuestBoardTest` ("lets the bearer win over a teacher logged in on the same computer…") and `ChannelAuthTest` ("refuses the bearer even when the owner is logged in on the same computer…") pin this.

**Bindings and policies.** On the board routes `Auth::user()` is a `GuestBoard` when the bearer authenticated. `{game}` (`GameSession::resolveRouteBinding`) then finds only the game the board shows (`GuestBoard::showsGame()`: active, claimed, `game_session_id`, and the same owner); a teacher finds their own games (`BelongsToTeacher`); anything else is a 404 before controller code. `{board}` finds the board itself for its bearer, or the active boards the current teacher claimed. `Play\Support\Requester::current()` reads the authenticated user through the auth manager's resolver (what `Auth::user()` returns at run time; static analysis types `Auth::user()` by the default guard alone). `GameSessionPolicy` has `view` and `update` for the teacher alone (board page, join code, remote) and `play` — claim, state, sync, status — for the owner or the guest board showing the game; `GuestBoardPolicy` has `view` (poll: the board itself), `show` (next game: the claiming teacher) and `close` (either). The shared Inertia prop `auth.user` is a teacher or null, never a board.

**Status for the bearer.** `PATCH /games/{game}/status` is a board route like claim, state and sync: `board.ts` pauses, resumes and ends through it, and the remote's `client-control` reaches the server through the board, so a guest board needs it for the game it shows.

Routes and who reaches them (all in the `web` middleware group; "teacher" = the owner's session, `verified`):

| Method and path                  | Guards                  | Reaches it                                                                   | Answer                                                  |
| -------------------------------- | ----------------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------- |
| `GET /games/{game}/board`        | `auth`                  | teacher (`view`)                                                             | page `games/board` (`BoardProps`)                       |
| `POST /games/{game}/join-code`   | `auth`                  | teacher (`update`)                                                           | `{pairing}` / 409 `game_over`                           |
| `GET /games/{game}/remote`       | `auth`                  | teacher (`update`)                                                           | page `games/remote` (`BoardProps` + `guestBoard`)       |
| `POST /games/{game}/board/claim` | `auth:board,web`        | teacher, or the bearer of the board showing the game (`play`)                | `{game: {status, board_epoch}}`                         |
| `GET /games/{game}/state`        | `auth:board,web`        | same (`play`)                                                                | `GameState`                                             |
| `POST /games/{game}/sync`        | `auth:board,web`        | same (`play`), 240 events a minute per game                                  | `SyncResponse` / 409 `board_replaced`                   |
| `PATCH /games/{game}/status`     | `auth:board,web`        | same (`play`)                                                                | `{game}` / 409 `invalid_transition`                     |
| `GET /pult`                      | `auth`, `throttle:pult` | teacher                                                                      | page `pult/index`, or a redirect to the one live remote |
| `GET /pult/{code}`               | `auth`, `throttle:pult` | teacher                                                                      | redirect to the remote, page `pult/guest-board`, or 404 |
| `POST /pult/{code}`              | `auth`, `throttle:pult` | teacher, for an open guest board                                             | redirect to the remote / validation errors / 404        |
| `GET /guest-boards/{board}/game` | `auth`                  | the teacher who claimed the board (`show`)                                   | page `pult/guest-board` (the next game)                 |
| `PUT /guest-boards/{board}/game` | `auth`                  | the teacher who claimed the board (`show`)                                   | redirect to the remote                                  |
| `DELETE /guest-boards/{board}`   | `auth:board,web`        | the board's bearer, or the teacher who claimed it (`close`)                  | 204 (JSON) / redirect back                              |
| `GET /guest-boards/{board}`      | `auth:board`            | the board's bearer (`view`), `throttle:guest-board`                          | `{board, props}`                                        |
| `POST /guest-boards`             | none                    | anyone, `throttle:guest-boards` (6 a minute per IP)                          | 201 `{board, token}`                                    |
| `GET /lovhe`                     | none                    | anyone                                                                       | page `lovhe`                                            |
| `POST /broadcasting/auth`        | channel guards          | the owner, or the bearer of the board showing the game, while live or paused | the channel signature / 403                             |

`tests/Feature/Play/BearerReachTest.php` requests every route of the app with a valid bearer and without credentials: the bearer gets exactly what a visitor gets, except its own board routes, and the board routes of any other game answer it 404. `tests/Feature/Play/ChannelAuthTest.php` covers the channel through the real `/broadcasting/auth`.

Reverb settings: client events accepted only from members of the channel (`accept_client_events_from = members`, pinned in `config/reverb.php` — Reverb itself falls back to `all` when the key is missing; Reverb 1.12 answers a non-member with error 4009); `allowed_origins` = the host of `APP_URL` (`REVERB_ALLOWED_ORIGINS`, comma-separated, overrides it; another origin gets 4009 "Origin not allowed"); Reverb is reached through the same host as the app (§10). The app's HTTP client to Reverb gives up after 2 s to connect and 3 s in all (`broadcasting.connections.reverb.client_options`), because `session.status` is sent inside the request that changed the status. Payloads carry ids only — student names reach the devices through authenticated HTTP, never through the channel. Tests boot the real `reverb` broadcaster with test keys (phpunit.xml), so `/broadcasting/auth` signs for real, and `tests/Support/FakeReverb` replaces its HTTP client with one that records what would be published (or fails like a Reverb that is down); no test reaches a Reverb server.

### How a hit travels

**Decision.** A tap on the phone is a Reverb **client event** (`whisper`) to the board. The board judges it locally, starts the effect in the same frame, acknowledges it to the phone, and queues it for the server. The **board is the only writer of results**: it posts its queue to `POST /games/{game}/sync`; the server judges again, stores and returns points.

```
phone            Reverb             board                          server (PHP)
 │ client-hit ──▶  │ ── client-hit ──▶ │ judge (engine.ts)              │
 │                 │                   │ burst starts (same frame)      │
 │ ◀── client-ack ─┼──────────────────│                                │
 │                 │                   │ outbox ── POST /sync ────────▶ │ RecordBoardEvents:
 │                 │                   │                                │ judge, store, points
 │                 │                   │ ◀── results (+3 ⭐) ───────────│
 │ ◀─ client-state ┼──────────────────│ next round                     │
```

**Why.** The visible path has no PHP, no database and no queue: one hop phone → Reverb, one hop Reverb → board. A direct touch on a touch screen takes the same path inside the board (source `board`), so there is exactly one place that writes results, whatever the input. The server still decides what counts, so a buggy or reloaded board cannot double-count.

**Rejected.** Phone → HTTP → server writes → broadcast → board: the visible path then contains a mobile HTTP request, a PHP request, a transaction and a broadcast hop — roughly twice the latency of client events, and a slow server would freeze the classroom. Two writers (phone and board both post): every hit would need cross-device de-duplication.

#### Latency budget (A7's 300 ms bar)

| Leg                                                                    | Budget   |
| ---------------------------------------------------------------------- | -------- |
| phone tap → WebSocket send                                             | < 20 ms  |
| phone → Reverb (school Wi-Fi or 4G, server within ~100 ms RTT of Baku) | ≤ 100 ms |
| Reverb fan-out                                                         | < 5 ms   |
| Reverb → board                                                         | ≤ 100 ms |
| board: judge + first frame of the effect                               | ≤ 20 ms  |

Measured on the phone: `ack_ms` = time from sending `client-hit` to receiving its `client-ack`. The board starts the effect before it sends the ack, so tap→effect ≤ `ack_ms`. Target: p95 `ack_ms` ≤ 300 ms; `?debug=1` on the remote page shows it (count, p50, p95 of the last 500 taps; a retried tap counts from its first send, so a lost first try shows as ≥ 1 s). The server's region is part of O1: a server more than ~100 ms RTT from Baku breaks the budget.

Measured in A7 on localhost (`composer dev`, wss through Herd's certificate, board and remote as two Chrome tabs on one laptop): p50 16 ms, p95 33 ms over 32 taps; with a guest board (A7.3, the board's channel authorized by its bearer): p50 8 ms, p95 23 ms over 15 taps, and p50 9 ms, p95 18 ms over 27 taps. That is the floor of the pipeline, not a classroom figure; a real phone on school Wi-Fi with a deployed server is still to be measured (O1).

#### Client events (protocol v1)

Every payload carries `v: 1`. Both sides parse every event with its zod schema from `contract.ts` (strict: an extra key, such as a name, fails it) and drop what is malformed without an answer; the board answers `stale` to a hit that does not match the current round, and to any hit while it is not playing (waiting for «Başla», paused).

| Event (wire name) | From → to      | Payload                                                                                          | Meaning                                                                                                                                                                                      |
| ----------------- | -------------- | ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `client-hello`    | remote → board | `{v, device_id}`                                                                                 | a remote (re)joined; the board answers with `client-state`                                                                                                                                   |
| `client-hit`      | remote → board | `{v, event_id, round_id, item_id, client_at}`                                                    | the teacher tapped an item                                                                                                                                                                   |
| `client-student`  | remote → board | `{v, student_id \| null}`                                                                        | who is at the board (null = whole class, no points)                                                                                                                                          |
| `client-control`  | remote → board | `{v, action: pause \| resume \| skip \| end \| pairing}`                                         |                                                                                                                                                                                              |
| `client-ping`     | remote → board | `{v, t}`                                                                                         | every 5 s                                                                                                                                                                                    |
| `client-state`    | board → remote | `{v, board_epoch, status, round_id, round_index, student_id, hit_item_ids, misses, last_points}` | snapshot; sent on hello, after every change and every 5 s (`round_id` null: the board is not playing — waiting for «Başla», or over; `last_points`: what `/sync` gave the round solved last) |
| `client-ack`      | board → remote | `{v, event_id, status: correct \| miss \| duplicate \| stale}`                                   |                                                                                                                                                                                              |

Server broadcast on the same channel: `session.status` (`Play\Events\SessionStatusChanged`, `ShouldBroadcastNow`, no queue), payload `{v: 1, status: live | paused | finished | abandoned}` — the channel names the game — when a game is paused, resumed, finished or abandoned through HTTP (`/status`, a `/sync` that solves the last round) or by the scheduler. `BroadcastSessionStatus` sends it when `GameSessionTransitioned` is dispatched, after the commit; nothing is sent for a rolled-back change or a status asked for again. When Reverb is down or unreachable the change still succeeds: the listener catches the failure and reports `Play\Exceptions\SessionStatusNotBroadcast` (the game id, the status and the cause's class — no message, no previous exception), and the board learns the status on its next claim or sync.

Rules:

- **Duplicates.** The same `event_id` is applied once on the board and stored once on the server (unique `event_id`). A hit on the same item from the _other_ source within 800 ms of an accepted one (a child on a touch screen and the teacher on the phone together) is the same touch: the board acks it `duplicate` and does not queue it. In an `any` round a hit on an item that already burst is `stale`. In a `sequence` round tiles are reusable (§2), so a repeated item is judged like any other hit.
- **Retry.** The phone shows a tap as pending until its ack; after 1 s without one it sends the same event again (same `event_id`), once; without an ack 1 s after that, the tap shows "not delivered" and is dropped. The board remembers the last 256 `event_id`s with their ack and answers a repeated one with the same ack, without touching again.
- **Hello.** The remote says hello on every (re)subscription and again when a `client-state` arrives with a higher epoch than the last (a reloaded board, or one opened elsewhere, has not heard it yet). Hello hides the board's pairing card.
- **Fencing.** Each board load calls `ClaimBoard` (`POST /games/{game}/board/claim` → `{game: {status, board_epoch}}`), which increments `game_sessions.board_epoch` under the game row's lock and returns it. A finished or abandoned game is not written to: the claim returns its status and current epoch, and the board shows the result. `/sync` refuses an old epoch (409 `board_replaced`); that board stops and says the game was opened in another window. The phone ignores `client-state` with a lower epoch.
- **Who is at the board** is the board's state: the phone proposes it (`client-student`), the board attaches it to each hit, the server credits the round to the student of the hit that solved it. The phone offers a student grid (avatars, from the page props) and "next" (class order, skipping archived students).
- **Board without a phone.** Pointer events on items call the same `applyHit()` with source `board`; the pairing card can be closed. With no remote connected, the board picks who is at the board itself: React around the canvas shows the same student grid and "next" from the board page's props (`students`: id, display name, avatar `{key, url}`, active only, in class order), starting with the whole class (no one credited). The choice is board state in both cases, attached to each hit and kept in `sessionStorage` across a reload; when a remote says hello, its `client-student` replaces it. Everything else is identical.

#### `POST /games/{game}/sync`

Body `{board_epoch, events: BoardEvent[]}` (1–50, in order; `BoardEventsData::rules()`). `Play\Actions\RecordBoardEvents`, in one transaction per event with the game row and then the round row locked (`lockForUpdate`; claims and status changes lock the game row too, so the order is always game → round):

1. an epoch that is not the game's → 409 `{error: "board_replaced", game: {status, board_epoch}}`; events before it in the batch stay stored;
2. an `event_id` already stored → `duplicate`, with the first result's `round_solved` and `points` (the hit that solved a round is the last correct answer of a solved round). Two requests with the same `event_id` at once: the game lock makes the second see the first's answer; where locks cannot meet (the same `event_id` in two games), the unique index refuses the second insert, the violation is caught and the event answers `duplicate`, without reporting anything of the other game;
3. `stale`, nothing written: a finished or abandoned game (`/sync` never writes to a game in a final status, and its answer's `game.status` tells the board), a `round_id` that is not a round of this game (a foreign or made-up id), a round already solved or skipped (for a hit), an item not in the round or already burst (the judge);
4. a `skip` → `skipped` and `skipped_at` set; a skip of a round already skipped → `duplicate` (skips are not stored by event id, so a repeated or doubled skip finds its round skipped); a skip of a solved round → `stale`;
5. a hit → judged with `RoundJudge` against its own round (the server does not require it to be the current one), the answer stored, `progress` / `misses` updated; the hit that solves the round sets `solved_at`, `student_id`, `points` (`PointsRule`), `played_on`, `week_start` (`SchoolCalendar`) and dispatches `RoundSolved` after commit.

Ids in the body are untrusted. A `student_id` that is not an active student of the game's class (another teacher's, another class's, archived, unknown) is stored as null: the hit counts, no one is credited. Only a malformed body (422: a missing key, a type outside its list, an `event_id` that is not a ULID, more than 50 events) or an old epoch fails the request; every other problem is the result of its own event, because a 422 for the whole batch would block the board's outbox for ever. A **paused** game still takes events: the board stops input while paused, so what arrives was made before the pause reached it. When no open round remains, the game transitions to `finished` inside the same transaction. Every applied event (correct, miss, skipped) touches `last_activity_at`. Answer `{results: AnswerResult[], game: {status, board_epoch}}` (`SyncResponse`), one result per event in order. Throttled per game at 240 **events** a minute (`App\Http\Middleware\ThrottleBoardEvents` counts the events of each body, so bigger batches do not get around it): over the limit, 429 `{error: "too_many_events"}` with `Retry-After`, and the board keeps its outbox.

The other board endpoints: `GET /games/{game}/state` answers `GameState` (`Play\Support\BoardState`: every round snapshot in order and where each stands; no student names); `PATCH /games/{game}/status` takes `{status: live | paused | finished}` (`StatusChangeData`; `abandoned` is the scheduler's alone) and answers `{game: {status, board_epoch}}`, or 409 `{error: "invalid_transition", game}` for a change the lifecycle does not allow.

### Reconnects

| What happens                                                     | Board                                                                                                                                                                 | Remote                                                                                                                                   | Data                                                                                                   |
| ---------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| Phone loses the socket (screen off, network)                     | keeps playing; shows "remote disconnected" after 10 s without a ping                                                                                                  | buttons disabled, "reconnecting…"; pusher-js reconnects and Echo re-subscribes; it sends `client-hello` and rebuilds from `client-state` | nothing lost: the phone holds no results                                                               |
| Board loses the socket                                           | keeps playing by touch; events keep queuing                                                                                                                           | "board not connected" after 10 s without `client-state`                                                                                  | outbox flushes over HTTP when it can                                                                   |
| Board loses HTTP (Wi-Fi down)                                    | keeps playing; "not saved yet" indicator with the count                                                                                                               | unaffected if its own network works                                                                                                      | outbox in `sessionStorage` (cap 500), retried with backoff; `beforeunload` warns while it is not empty |
| Board reloads                                                    | flushes the stored outbox, `ClaimBoard` (new epoch), `GET /games/{game}/state`, resumes at the first open round; who is at the board comes back from `sessionStorage` | receives the new `client-state`                                                                                                          | server state + outbox = exact                                                                          |
| The teacher ends the game from the phone while the board is gone | sees `finished` on its next load or `session.status`                                                                                                                  | `PATCH /games/{game}/status`                                                                                                             |                                                                                                        |
| A guest board reloads or is closed                               | a reload in the same tab keeps the bearer (`sessionStorage`) and continues as above; a closed tab is gone — `/lovhe` shows a new code                                 | the teacher claims the new code and sends the same live game to it                                                                       | nothing lost: results are on the server and in the outbox of the tab that wrote them                   |

Both pages hold a Screen Wake Lock while a game is live, so neither the projector laptop nor the phone sleeps mid-game; the board enters full screen on «Başla», which also unlocks audio.

### Board in the browser

`resources/js/game/`: `contract.ts` (§2), `engine.ts` (pure state machine: rounds, judge, progress — no Phaser, tested with Vitest), `live.ts` (channel wrapper: whisper, acks, pings, epochs), `outbox.ts`, `scenes/` (one Phaser scene per mechanic; `pop` reuses `find`'s), `styles/` (item and slot styles, effects). React owns everything around the canvas (pairing card, pause, the student at the board, "not saved"); Phaser owns the canvas at a logical 1920×1080 with `Scale.FIT`. They talk through one typed bridge (`showRound`, `applyHit` in; `itemTouched` out). Phaser is imported dynamically so no other page downloads it.

As built in A6:

| Module                                                   | Does                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `engine.ts`                                              | `judge()` plus the state machine: `boardGame(state)`, `currentRound()` (the first round neither solved nor skipped), `applyHit(game, roundId, itemId)` (a hit naming any round but the current one is `stale`), `applySkip()`, `isOver()`, `tally()`. Pure; every change returns a new game.                                                                                                                                                                                                                                                                                                                                                                                                                            |
| `board.ts`                                               | `BoardController`, the one place the board decides anything; React renders its `view` (`useSyncExternalStore`) and the scene draws through the bridge. `load()` flushes this tab's stored outbox under its old epoch, then claims, then reads `/state`; `start()` («Başla»); `touch({roundId, itemId}, {source, eventId})` judges, calls `bridge.applyHit` in the same call stack, then queues the hit with the student at the board (a stale touch is played but never queued); `skip()`, `pause()`, `resume()`, `end()` (refused while answers are unsaved: the server never writes to a finished game); `setStudent()`, `nextStudent()`. After a solved round it waits for the effect (1.6 s) before the next round. |
| `bridge.ts`                                              | `BoardBridge`: `showRound(round, progress)`, `applyHit({roundId, itemId, status, solved})`, `setPaused()` in; `itemTouched({roundId, itemId})` out (`onItemTouched` to listen). It replays the last round to a scene that connects late.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `outbox.ts`                                              | `Outbox`: entries `{epoch, event}` in `sessionStorage` (`elifba.board.{game}.outbox`), at most 500 (a new event past that is refused, never an older one dropped, because the server judges each event after the ones before it), at most 50 consecutive entries of one epoch per request; backoff 1, 2, 4 … 30 s, or `Retry-After`; `retryNow()` on the browser's `online` event; an event that fails `BoardEvent` (event ids are `z.ulid()`, as `/sync` requires) is never queued; a 422 is narrowed to the single event the server refuses, which is dropped; a 409 stops it for good and forgets its entries (they can never be written).                                                                           |
| `http.ts`, `ulid.ts`, `palette.ts`                       | The board's `fetch` calls, parsed with the contract's schemas; ULID event ids; the board's colours (the pack's first palette colour at 8 % over white behind the canvas, as backdrops are drawn, §8).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `stage.ts`                                               | `mountStage()`: the only module that imports Phaser, reached only through the board page's `import()`. It awaits `document.fonts.load()` for Andika before any canvas text exists.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| `scenes/find.ts`, `styles/balloon.ts`, `styles/burst.ts` | The `find` scene, the `balloon` item style (rises in, floats, wobbles on a miss, drifts away when its round is over) and the `burst` effect (ring and shards in the item's colour).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |

- **Fencing between tabs.** Besides the 409, tabs of one browser announce a claim on a `BroadcastChannel` (`elifba.board`), so a second tab stops the first at once instead of at its next `/sync`.
- **Stars.** The board page's props carry `game.points` (round id → points of the rounds already solved); the controller adds the points `/sync` returns, keyed by round so a `duplicate` answer cannot count twice. The result screen shows their sum.
- **The page.** `games/board` has no layout and holds `forceLightTheme()` while mounted. «Başla» enters full screen, resumes the audio context and the page holds a Screen Wake Lock while playing (taken again on `visibilitychange`); `beforeunload` warns while the outbox holds anything; the result screen's links wait until it is empty.
- **Two tabs reloaded at once** (a Vite full reload, a restored browser session): a `claimed` message heard while a board is still loading is remembered, and once its own claim answers, the board stops if it heard a higher epoch (A7; before, such a board kept playing until its next `/sync`, and the phone, following the higher epoch, ignored it).

As built in A7 (the phone and the live link):

| Module                                                                      | Does                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| --------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `contract.ts`                                                               | The client events of protocol v1 as strict zod schemas (`ClientHello`, `ClientHit`, `ClientStudent`, `ClientControl`, `ClientPing`, `ClientState`, `ClientAck`, `SessionStatusEvent`), types inferred; `Pairing` for the join code's answer. TypeScript only: no PHP reads a client event, so they have no DTO or fixture (`session.status` is pinned by `SessionStatusBroadcastTest`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `live.ts`                                                                   | `LiveChannel` (whisper, listen, `session.status`, every successful subscription, socket state) — the only thing the module knows of the transport. `LiveBoard(channel, board)`: hits go to `board.touch(…, {source: 'remote', eventId})` once per `event_id`, the ack is sent before the state; `client-student` → `setStudent()`; `client-control` → `pause()`, `resume()`, `skip()`, `end()`, or the pairing card; `session.status` → `statusChanged()`; `client-state` on hello, on (re)subscribe, after a change (only when it differs) and every 5 s once a remote has been heard; `remote: none / connected / lost` (10 s without any remote event); `pairing` (shown on load and on «Pultu qoş» or the phone's `pairing`, hidden on hello). A replaced board says nothing. `LiveRemote(channel, deviceId)`: the phone's side of the rules above, `socket` (connected only once the channel is subscribed again), `board: waiting / connected / lost`, taps, `ackMs`; `ackStats()`. |
| `socket.ts`                                                                 | `connectEcho(authHeaders?)`: Echo + pusher-js from `VITE_REVERB_*` alone (`forceTLS` when the scheme is https; one port for ws and wss), `/broadcasting/auth` through `channelAuthorization.headersProvider`, so the headers are read at each (re)subscription — `authHeaders(auth)` from `http.ts`: the session's XSRF cookie by default, a guest board's bearer with `connectEcho({kind: 'bearer', token})`. `gameChannel(echo, gameId)` adapts `private-game.{id}` to `LiveChannel` (Echo's `whisper('hit')` is `client-hit` on the wire). Imported only by the board and the remote pages, so no other page loads pusher-js.                                                                                                                                                                                                                                                                                                                                                          |
| `board.ts`                                                                  | The 800 ms cross-source rule lives in `touch()` (direct touches never pass through `live.ts`): a touch from the other source on the same round and item within 800 ms of an accepted one returns `duplicate` — nothing played, nothing queued. `statusChanged(status)` applies a status the server announced (ignored while the board is changing it itself). `lastPoints` in the view.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `pages/games/board.tsx`, `components/games/pairing-card.tsx`                | The board connects only for a game that was live or paused when the page opened (and plays alone if Echo cannot be built). The pairing card (QR of `pairing.url`, the code as «170 045», `{host}/pult` to type it) sits under «Başla» before the game and in a corner during play; `POST /games/{game}/join-code` when it opens and again when the code expires. «Pultu qoş» in the header brings it back. The footer says «Pult qoşulub» or, after 10 s without a ping, «Pultla əlaqə kəsildi».                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `pages/games/remote.tsx`, `components/games/remote-items.tsx`               | Rounds from `GET /games/{game}/state` (read again when the board names a round the phone does not know); where the board stands from `client-state`. Items as buttons of at least 80 px in `font-board`: the answer marked «Cavab», a burst item done, a tap pending / correct / miss / not delivered. Who is at the board (the shared `StudentGrid`) and «Növbəti» in class order, pause / resume, skip and end in the lower half; the QR button asks the board for its pairing card. Banners: reconnecting (socket), board not connected (10 s without a state), connecting to the board, paused, press «Başla». «Oyunu bitir» with the board there sends `end`; without it, `PATCH /games/{game}/status` from the phone. Screen Wake Lock while the game is open; `?debug=1` shows `ack_ms`.                                                                                                                                                                                           |
| `pages/pult/index.tsx`                                                      | The code (6 digits, spaces allowed) and the teacher's live and paused games; with exactly one the server opens its remote at once, `?list=1` (the remote's back link) stays on the list.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `http.ts` (A7.3)                                                            | `BoardAuth`: `{kind: 'session'}` (the default) or `{kind: 'bearer', token, onRefused}`. `authHeaders(auth)` builds the headers of every board request and of `/broadcasting/auth`; a bearer request still sends the session cookie (`same-origin`), and the bearer wins (`auth:board,web`). Every 401 or 404 a bearer request gets is also reported to `onRefused`. Vitest: `http.test.ts`, `socket.test.ts` (Echo and pusher-js stubbed).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `guest-board.ts` (A7.3)                                                     | `GuestBoardClient`, the guest board's tab without React: `start()` (a stored board continues, otherwise `POST /guest-boards`), the poll and its cadence, `pollNow()` for `onRefused`, a 401 → forget the bearer and open a new board, `setSettle()` (the page flushes the old board before the next game or closing), `close()`, `notMine()`, `reopen()`; a token that is not the answered board's is refused. `guestBoardHttp()` parses `GuestBoard` / `OpenedGuestBoard` (`contract.ts`); the poll's `props` are the teacher board's page props, checked for shape. Vitest: `guest-board.test.ts`.                                                                                                                                                                                                                                                                                                                                                                                      |
| `components/games/board-screen.tsx`, `pages/lovhe.tsx` (A7.3)               | `BoardScreen` is the whole board of A6.2 and A7 (canvas, controller, outbox, `live.ts`), shared by `games/board` (`auth`: the session) and `lovhe` (`auth`: the bearer, plus `guest`: the teacher's name, «Mənim deyil — yeni kod», «Lövhəni bağla», «Burada aç» that remounts it, and its controller for `settle()`). A guest board has no pairing card. `lovhe` mounts one `BoardScreen` per game (keyed by the game) and shows the code (`PairingCard`), "waiting for a game", or «Lövhə bağlandı».                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| `pages/pult/guest-board.tsx`, `components/games/game-setup-form.tsx` (A7.3) | The phone's choice for a guest board, for an open board's code and for a claimed board's next game: the live games as big buttons, or a class and the shared setup form (also used by `games/setup`). The remote (`games/remote`) with `guestBoard` set offers «Lövhəni bağla» in its header and «Növbəti oyunu lövhəyə göndər» after the game.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `stage.ts` (A7.3)                                                           | One `AudioContext` per page, given to every stage (`audio.context`): Phaser closes a context it made itself when a stage is destroyed, while its "visible" handler touches the context 100 ms later — on the guest board, where a stage is destroyed and the next one mounted in the same page, that threw "Cannot suspend a closed AudioContext". A given context is only suspended, and «Başla» resumes it.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |

Checked in Chrome with `composer dev` (Reverb on wss): pairing by code, tap → burst and ack, student, next, pause, resume, skip, end from the phone with the board present, end over HTTP with the board gone; Reverb stopped mid-game and started again — the board kept playing by touch (its answers went over HTTP), the phone disabled its buttons, both reconnected without a reload once pusher-js retried (10–20 s after Reverb was back: pusher-js's own backoff), banners cleared, and every answer was stored once.

The guest board, checked in Chrome with `composer dev` (A7.3): `/lovhe` on `http://127.0.0.1:8000` — another host, so a cookie jar with no login, like a school computer (`REVERB_ALLOWED_ORIGINS=elifba.test,127.0.0.1` for that run only) — and the logged-in phone on `https://elifba.test`. The code and the QR of `https://elifba.test/pult/{code}`; the phone typed the code, set up a new game and the board loaded it without a reload, «Pult qoşulub», taps → bursts and acks; a reload of the tab continued the same game («Davam et», no new code); closing the tab and opening `/lovhe` again gave a new code, and the phone sent the same live game to it, which went on from its round; after the game, «Növbəti oyunu lövhəyə göndər» put a new game on the same board without a code (the old game: 404 for the bearer on `/state` and `/sync`); «Lövhəni bağla» on the phone mid-game → within 10 s the board showed a new code, and the phone sent the same game to it; «Mənim deyil — yeni kod» and «Lövhəni bağla» on the board. The secret was only in the tab's `sessionStorage` (not in `localStorage`, a cookie, the URL, the DOM or the page props). The tab's bearer, sent with `fetch` and no cookies: `/dashboard`, `/classes`, a class page, its rankings, another teacher's class, `/pult`, the shown game's `/board` and `/remote` → 401 (an HTML request: a redirect to login); the teacher's other game and another teacher's game on `/state` → 404; the shown game → 200; without the bearer → 401. The same browser logged in as the teacher, `/lovhe` on `elifba.test`: bearer + session on that teacher's other game → 404, the session alone → 200.

As built in A8.2 (item styles and effects, §2 "Item styles and effects"):

| Module                                                            | Does                                                                                                                                                                                                                                                                                                                         |
| ----------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `styles/registry.ts`                                              | `ITEM_STYLES`, `EFFECTS`, `itemStyle(key)` (null for a key it lacks), `effect(key)`: the one map from a pack's keys to code. Vitest: `styles/registry.test.ts` (against `tests/Fixtures/playable.json`; every layout stays on the board without overlap).                                                                    |
| `styles/types.ts`, `styles/item.ts`                               | `ItemStyle`, `ItemDrawing`, `ItemView`, `Effect`; `BoardItem`, the living item every style extends (hit area, idle tweens, wobble, `pop()`, `lift()`), and the shared `itemLabel()` (Andika, readable on its ground, shrunk to fit) and `shade()`.                                                                           |
| `styles/balloon.ts`, `wagon.ts`, `card.ts`, `bubble.ts`           | The four item styles: `draw()` (a still picture) and `create()` (its `BoardItem`), `layout()`, `stagger`; `wagon` also draws the rails as its `scenery()`.                                                                                                                                                                   |
| `styles/burst.ts`, `styles/sparkle.ts`                            | The two effects as `Effect.play(scene, view)`: `burst` pops the item and throws shards; `sparkle` lifts the item away whole under twinkling stars (§8).                                                                                                                                                                      |
| `scenes/find.ts`, `stage.ts`, `components/games/board-screen.tsx` | `mountStage({itemStyle})` takes `pack.style.item` and refuses a key the registry lacks; the scene lays out, creates and stagger-enters the items through the style and plays `round.effect`. The backdrop is loaded with `load.svg` at 1920×1080: as a plain image its viewBox-only SVG had no size and WebGL drew it black. |

Checked in Chrome on `https://elifba.test` (A8.2), one `find` game in each pack (animals and cities letters, train and symbols short words, fruit open syllables, balloons closed syllables): items enter, a miss wobbles, a correct hit bursts (balloons, train, animals, fruit) or sparkles (cities, symbols — the card lifts away whole), the rest leave and the next round comes in; Qız qalası behind the cities cards.

As built in A8 (A8.3: the build scenes, the slot styles, the phone's slots; §2 "Mechanics", "Item styles and effects"):

| Module                                                                                     | Does                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| ------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `scenes/board-scene.ts`                                                                    | `BoardScene`, what every scene shares: the pack's sounds and backdrop, its scenery drawn once, the bridge connection and `setPaused()` (input, tweens and the scene clock). `FindScene` and `BuildScene` extend it; `stage.ts` picks the scene by `game.mechanic` and refuses a build game without a slot style.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| `scenes/build.ts`, `build-syllable.ts`, `build-word.ts`, `build-sentence.ts`, `layouts.ts` | One Phaser scene per mechanic: three thin scenes over `BuildScene`, which holds the flow. The tiles wait in the lower band in the pack's item style (`layout(count, band)`), the slots stand above them in its slot style. A correct hit flies a still copy of the tile (`style.draw()`) along an arc into the next slot (420 ms; the slot is followed live, so a train still rolling in is reached); the tile stays, so a reused tile can fly again at once, and a miss wobbles it. When the last copy lands, the round's effect takes the copies and the slots show the unit whole (`slots.ts`); the tiles leave as soon as the round is solved, the slots and the picture when the next round comes in, which waits 650 ms for them to clear. `showRound` with progress draws the filled slots again, so a reloaded board resumes mid-round. `layouts.ts` holds each scene's bands; build_word's picture (`prompt.image`, rasterised at 640 px with `load.svg`, as backdrops are) stands at the top in a 400×364 frame — a third of the board's height — with no caption, so the word's text first appears when the round is solved. The prompt above the canvas (`BoardPrompt`) is unchanged: `show` for build_syllable and the text for all three; the tail is drawn after the last slot. |
| `styles/slot-wagon.ts`, `styles/slot-frame.ts`                                             | The slot styles of §2. Their geometry (`trainGeometry()`, `frameGeometry()`) is pure: Vitest (`styles/registry.test.ts`) checks both fit every build layout for 2–4 slots with and without a tail, and that every item style lays out up to 5 tiles inside its band.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `slots.ts`                                                                                 | `slotTexts(round, hitItemIds)` (each slot's text so far, null while empty) and `wholeText(round)` (letters and syllables run together, a sentence's words spaced and ended with its tail): the board's plate and the phone's slot row read the same answer. Joined, never cased. Vitest on the contract's build fixtures (`slots.test.ts`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| `components/games/remote-slots.tsx`                                                        | The phone, above a build round's tiles: the word's picture (96 px, alt «Sözün şəkli») or the syllable (`show`), then the slots filled so far from `client-state` progress, the next one marked, and the tail. The tile buttons are unchanged (at least 80 px, «Cavab» on the next tile, a reused tile never marked done).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |

`board.ts`, `engine.ts` and `live.ts` needed no change: `progress.hit_item_ids` are the filled slots. A same-source double tap on a reused tile («nə-nə») is two hits, and the wall and the phone on the same tile within 800 ms is one touch (`duplicate`, nothing played or queued); `board.test.ts` and `live.test.ts` cover both, and a reloaded round showing its filled slots.

Checked in Chrome on `https://elifba.test` (A8.3; Vite and `reverb:start`; the phone as a 390 px frame of `/pult` in a second tab, paired by code): each build mechanic in train (wagon slots), fruit or animals (frames) and symbols (sparkle) — nine games, played from the phone: taps, a miss in each, a reload mid-round in six (the filled slots came back), skips from the phone and the result screen on both devices. Reused tiles: «nə-nə» (train) and «xa-rı-bül-bül» (symbols) by a double tap on the phone, «NAN» (fruit) tile by tile; the wall and the phone on «o» of «otaq» 300 ms apart stored one answer and no miss. In the database every answer was stored once (answers = distinct event ids in all nine games) and the misses equalled the result screens'.

---

## 5. Scoring and rankings

**Decision.**

- **Points belong to a solved round**, credited to the student at the board when the round was solved: **3** with no wrong hit, **2** with one, **1** with more (a `pop` round has no wrong item, so it always gives 3). Never negative; nothing for a skipped round. A round solved with nobody at the board (whole class) stores its points but credits no one. The rule lives only in `Play\Support\PointsRule`.
- **Windows.** `config('elifba.timezone')` = `Asia/Baku` (UTC+4, no daylight saving). When a round is solved the server stores `played_on` = the Baku date of `solved_at` and `week_start` = the Monday of that date (ISO week). Rankings filter on these two columns only — no time-zone arithmetic in SQL.
- **Daily ranking** of a class for a date: the sum of `points` per active student over rounds with that `played_on`. **Weekly**: the same over `week_start`. `Rankings\Support\ClassRanking` computes it; nothing is stored.
- **Ties share a place, dense** (10, 10, 8, 7 → 1, 1, 2, 3; `Rankings\Support\DenseRank`). Within a place, names are listed in Azerbaijani alphabetical order (display only). No tie-breaker by time.
- **Who is ranked.** Students with more than 0 points get a place; active students with 0 are listed after them without a place ("hələ xal yoxdur"), in Azerbaijani alphabetical order; archived and deleted students never appear, and neither do rounds the whole class solved.
- **How it is computed.** `ClassRanking::for($class, PeriodData)` (or `day()` / `week()` with a date) runs one `GROUP BY student_id` with `SUM(points)` over the class's rounds with `played_on` = the day, or `week_start` = its Monday, and `student_id` not null. The sum is cast to `int` (engines return it as an int or a numeric string). It is merged in PHP with the class's active students and returned as `Rankings\Data\RankedStudentData` rows (student, points, place). A page receives per student only `id`, `display_name`, `avatar`, `points`, `place` and `place_label`.
- **Which ranking.** `Rankings\Data\PeriodData` reads `?period=day|week&date=YYYY-MM-DD` with its `rules()` (A10's podium reuses them). Both keys are optional: the day (`Rankings\Enums\Period`) and `SchoolCalendar::today()`. Any date selects its Monday–Sunday week. An unknown period, a malformed or impossible date, or a date after today in Baku is invalid. A GET page never answers with a validation error: it redirects to the same query without the invalid key (`PeriodData::valid()`), so its default applies and the URL always says what the page shows. The server also sends a date in the window before and in the window after (null while the window holds today); the page only puts them in links.
- **Labels.** The server formats the window in the interface language — "9 noyabr 2026", the week "9–15 noyabr 2026", "26 oktyabr – 1 noyabr 2026", "28 dekabr 2026 – 3 yanvar 2027" — and the place labels ("1-ci" / "1st"), §3.
- **The page.** `/classes/{class}/rankings` (`RankingController`, page `rankings/show`), linked from the class page: day / week, the window before and after, back to today. It opens for an archived class too, as the class page does; it only reads.
- **Podium** = places 1–3 (each may hold several tied students); empty when nobody has points.
- **Who sees what.** The full ranking (every student with points) is shown only on the teacher's own pages, on the laptop or the phone. The board never shows it: at the end of a game it shows the stars the class earned in that game and today's podium (places 1–3). The podium page is the one meant for the projector and the share card. No page labels a last place.
- The owner confirmed this scoring and visibility on 2026-11-09.

**Why.** Points on the round row give one indexed `GROUP BY` per ranking, keep history exact even if the rule or the time zone setting later changes, and need no ledger to keep in sync. Small, never-negative points reward finishing, not speed — the teacher, not the child, controls the order of turns, so speed and order are not fair tie-breakers. Dense ranking lets more children reach the podium, which is the point of a podium for seven-year-olds.

**Rejected.** A separate points ledger table: a second write to keep consistent for no query that needs it. Computing Baku dates in SQL (`AT TIME ZONE`): differs between SQLite and PostgreSQL. Competition ranking (1, 1, 3): leaves second place empty after a tie. Time bonuses: punish slower readers.

---

## 6. Share card

**Decision.** The podium page (`/classes/{class}/podium?period=day|week&date=YYYY-MM-DD`) renders a hidden 1080×1920 React node and turns it into a PNG in the browser with `modern-screenshot` (`domToBlob`, after `document.fonts.ready`). The PNG is made as soon as the page opens and shown as the preview, so «Paylaş» can call the Web Share API immediately inside the tap:

1. `navigator.canShare({ files: [file] })` → `navigator.share({ files: [file] })` — on Android and iOS this opens the share sheet, where WhatsApp offers "My status". No `text` is passed (some WhatsApp versions drop the file when text is present).
2. Otherwise (desktop, old browsers): download through an object URL, file name `elifba-{gunun|heftenin}-qalibleri-{date}.png` — never a name in the file name.

**Content** (and nothing else):

- title — "Günün qalibləri" or "Həftənin qalibləri" (in the teacher's interface language);
- the date or the week range, formatted by the server ("9 noyabr 2026", "9–15 noyabr 2026"; `PeriodData::label()`, §5);
- places 1–3: avatar, `display_name`, place label from the server (1-ci, 2-ci, 3-cü), points;
- a festive decoration from CC0 or own art (confetti from `canvas-confetti` on the live page; static shapes on the card);
- the Elifba wordmark and the domain.

Layout: content inside the WhatsApp status safe area (nothing important in the top 250 px or the bottom 300 px); fonts and pictures are same-origin files, so the capture never needs a third-party request.

**As built (A10).**

- **Server.** `Rankings\Support\Podium` takes places 1–3 from `ClassRanking` (no query of its own) as `Rankings\Data\PodiumPlaceData` (place, points, the tied students in Azerbaijani order). `PodiumController` (page `podium`, `routes/rankings.php`) reads the query as `RankingController` does and sends the podium for the teacher's page (ids, names, avatar keys) and, apart from it, `card` from `Rankings\Data\ShareCardData`: `title`, `label`, `places[] {place, place_label, points_label, students[] {display_name, avatar_url}}`, `wordmark` (`app.name`), `domain` (the host of `app.url`), `file_name` — or null when nobody has points. Every text is final ("6 xal", "1st"; plurals by `Language\Support\Plural`, the server twin of `useTranslation()`'s `{one}|{other}`). `avatar_url` is a path from the root (`/content/avatars/…`, `ContentAssets::path()`), so the picture loads from the page's own origin even when the server saw another host or scheme (a tunnel, a proxy); null draws a circle with the initial. The file name's date is the day, or the week's Monday. `PodiumPageTest` pins the card's exact recursive key set and that no id, class or teacher name, link, time or student off the podium is in it.
- **Browser** (`components/share`). `ShareCard` receives only `card` and draws it in one fixed light look (Andika, the medal colours of `place-colors.ts`, `CardDecoration`'s static shapes in the covered bands and margins). `SharePanel` renders it off-screen (`position: fixed` far left, never `display: none`, which leaves nothing to measure or capture), and on open, and again for another day or week, `makeShareImage` awaits `document.fonts.load()` of Andika 400/700 (it fails rather than capture a fallback face) and every `<img>`'s `decode()`, fits the places (`fitShareCard`: the first of nine densities, from one big row per child to six columns, that fits as it is; when none does — long names that wrap, a very large tie — the density whose names stay largest once scaled down; names are never clamped, so a tied class and 40-character names are always whole and inside the safe area), then `domToBlob` at 1080×1920, scale 1. The PNG becomes the preview's object URL, revoked when it is replaced or the page closes; the download reuses it. «Paylaş» calls `navigator.share({ files })` synchronously in the tap when `canShare({ files })` holds; closing the sheet (`AbortError`) is a cancel, any other refusal downloads. Without file sharing the button says «Şəkli yüklə» and downloads.
- **Live page.** `canvas-confetti` once per visit when the podium is not empty, with `disableForReducedMotion`. An empty podium shows an empty state, no card and no button. Linked from the class page and from the rankings page (same period and date).

**Why.** Rendering in the browser needs no image service, no queue and no server CPU, and the fonts and avatars are already on the page. The Web Share API with files is the only way a web page can hand an image to WhatsApp status.

**Rejected.** Server-side rendering (headless Chrome / Browsershot, or GD/Imagick): a browser binary in the production image, or hand-drawn layout with poor text shaping. Sharing a link instead of an image: the status would show a link preview, and the page would have to be public — a child's name behind a public URL.

---

## 7. Children's privacy

**Decision.** Children are data subjects who never use the app. Elifba stores the minimum, under one teacher, and makes deletion real.

| Rule                             | How                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Minimum data**                 | A student is `display_name` (first name; a surname initial only to tell two children apart) + `avatar` + class + order. No surname field, photo, birth date, gender, contact, ID number or login. Pasting "Əli Məmmədov" suggests "Əli" in the preview. Only when two children in the class share a first name does it suggest "Əli M." and "Əli H.". The teacher confirms before anything is stored, and the pasted surnames are never saved.                                                                                                                                                                                                                                                                                                                                                  |
| **No student login**             | Children never authenticate; nothing on the board or the phone asks a child for anything.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| **Teacher isolation**            | Route model binding for `{class}`, `{student}`, `{game}` and `{word}` (a teacher word, §3) resolves only rows owned by the current teacher (`App\Models\Concerns\BelongsToTeacher` overriding `resolveRouteBinding`), so another teacher's id is a 404 before any controller code; `{student}` is nested under `{class}` with `scopeBindings()`; policies (`SchoolClassPolicy`, `StudentPolicy`, `GameSessionPolicy`, `CustomWordPolicy`, `->can()` on every such route) are a second layer; ids carried in a body (the reorder) are checked by the action; channel callbacks check ownership; a guest board's bearer reaches only the game it shows. `tests/Feature/Classroom/TeacherIsolationTest.php` enumerates every route with these parameters and fails when one is not in its dataset. |
| **Deletion**                     | Delete a student → the row is deleted; their rounds and answers keep only `student_id = null` (anonymous class practice, no longer in rankings). Delete a class or the teacher account → everything under it is deleted (cascade). Archive a class or student → hidden, and deleted automatically after `elifba.retention_days` (400). Games older than 400 days are pruned with their rounds and answers.                                                                                                                                                                                                                                                                                                                                                                                      |
| **Never on a share card**        | Surname, school name or number, city or district, the class name, the teacher's name, photos, other students' names or scores, any link to a class, game or student, ids, exact times.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| **Never on the wire or in logs** | Client events carry ids only. Logs and exceptions never include `display_name` or a teacher word's text, which may be a child's name (log ids). No third-party analytics, fonts, CDNs or error trackers: every request a page makes goes to the app's own host.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| **Shared computers**             | Use a guest board (§4): the teacher never logs in on a shared computer, and the board keeps nothing once its tab is closed. A teacher board has a visible «Çıxış» (log out), and the login form's "remember me" is off by default.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| **Notice**                       | `/privacy` (az + en) states what is stored, why, for how long, how to delete it, and who operates the instance: `elifba.operator.name` and `elifba.operator.email` from `.env` (`ELIFBA_OPERATOR_NAME`, `ELIFBA_OPERATOR_EMAIL`), so every self-hosted instance names its own operator; the owner fills them for the public instance at O5. The same page has a short note for parents that a teacher can copy and send before using Elifba with a class.                                                                                                                                                                                                                                                                                                                                       |
| **Teacher's agreement**          | Registration requires ticking that the teacher has read the privacy note and uses Elifba with the school's permission (`users.terms_accepted_at`). Elifba does not collect parents' consent itself: that would mean storing parents' contacts, against the minimum-data rule. The school decides; the parent note informs.                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |

**Why.** The children are 6–10 years old and cannot consent; the teacher acts for the school. The less Elifba holds, the less can leak, and an app that stores only a first name and a cartoon animal per child is easy for a school to accept. Scoped binding makes isolation the default for every new route, instead of something each controller must remember.

**Rejected.** Student accounts or PINs: the children are too young, the teacher would manage passwords, and the product does not need it. Soft-delete only: "delete" that keeps the name is not deletion. Global policies without scoped binding: one forgotten `authorize()` leaks another teacher's class.

### Pasting a list

The teacher pastes a list of names (one per line) and sees a preview before anything is stored. `Classroom\Support\NameListParser` builds it; it writes nothing, and the pasted text (which holds surnames) is returned only to the teacher who pasted it, never stored or logged. The preview is `POST /classes/{class}/students/preview` answered with JSON — a validation error included — so the text is never in a URL and no redirect flashes it into the session (`text`, `names` and `display_name` are in the exception handler's `dontFlash`); the parser marks it `#[SensitiveParameter]` so a stack trace shows none of it; the page keeps it only in component state. Only the confirmed display names are posted to `AddStudents`.

**A display name** (`Classroom\Support\DisplayName`, the rule in `StudentData` and `AddStudentsData`) is words of the 32 letters separated by single spaces or hyphens, optionally ending in a surname initial with a dot ("Aysel M."), at most 40 characters, checked after `Az::normalize()`. Foreign names are written in the Azerbaijani alphabet ("Vilyam"), as Azerbaijani spelling does.

**Per line**, in this order:

1. **Columns.** A line with tabs (pasted from Excel or Word) keeps only the cells that contain letters and no digits, so number, date and ID columns fall away and "Məmmədov ⇥ Əli ⇥ Rəşid oğlu" becomes one name.
2. **Normalize** (`Az::normalize()`, §3), then **strip numbering and bullets** at the start: `1.`, `1)`, `(1)`, `№1`, `#1`, `1 -`, `-`, `–`, `•`, `*` and similar.
3. **Skip** a line with no letter left (blank, numbering only, a separator). It is not reported.
4. **Words.** A word with a digit is dropped (a date, an ID); punctuation around a word is trimmed; apostrophes of the old spelling are removed ("Mə'sumə" → "Məsumə"). A word with another character inside, or a letter outside the alphabet ("William", Cyrillic), makes the line **invalid**. A one-letter word is an initial.
5. **Patronymic.** One patronymic is removed: a father's name followed by `oğlu` / `qızı` (also typed `oglu`, `qizi`, `ogly`, `kızı`, `kyzy`), or one word ending in `-ovna`, `-evna`, `-oviç`, `-eviç`, `-ovich`, `-evich`. Whether it ended the line is remembered.
6. **Order.** With two or more names left, the first and the last are scored as surnames: **2** for `-ov`, `-ova`, `-ev`, `-eva` (so `-yev`, `-yeva`), `-oğlu` / `-oglu` (at least 5 letters); **1** for `-zadə` / `-zade`, and for `-lı`, `-li`, `-lu`, `-lü` after a consonant (at least 6 letters: "Kərimli", but not "Əli", "Vəli", "Nazlı", "Məmmədəli"); **0** otherwise. The higher score is the surname. On a tie a patronymic decides (at the end of the line → surname first, as registers write "Məmmədov Əli Rəşid oğlu"; in the middle → first name first). Lines still undecided follow the majority of the decided lines of the same paste, and first name first when there is none.
7. **First name** = the second name when read surname first, otherwise the first; a third name is ignored. **Surname** = the other end, or a lone initial ("Aysel M."). A line with no name left, or only a surname (score 2) with initials, is **invalid**; so is a first name longer than 37 characters (no room for an initial).
8. **Case.** The first name is title-cased (`Az::title()`): "QULİYEV İLKİN" → "İlkin".

**Across the list**, against the class's **active** students:

- **Duplicate**: the same full name (case-insensitive, after the steps above) as an earlier line. It carries the earlier line's suggestion and is not added.
- **Surname initial**: added ("Aysel M.", "Aysel H.") only when another distinct pasted line or an active student has the same first name, compared with `Az::lower()`; never otherwise.
- **Taken**: the suggestion (case-insensitively) equals another line's suggestion or an active student's name — two "Aysel M.", or a pasted "Əli" without a surname when the class has an Əli. The teacher edits it.

**Output**, one `Classroom\Data\PastedNameData` per reported line: `line` (1-based in the pasted text), `text` (the line as understood), `display_name` (the suggestion; null when invalid) and `flags` (`Classroom\Enums\PastedNameFlag`, in this order: `invalid`, `duplicate`, `surname_first`, `initial_added`, `in_class` (an active student has this first name — it may be the same child), `taken`). `addable` is true unless the line is `invalid`, `duplicate` or `taken`. The teacher edits and confirms; `AddStudents` stores only the confirmed display names and checks them again. Limits: a paste of at most 10,000 characters (`NameListData`), at most 50 names per `AddStudents` call (`AddStudentsData::MAX_NAMES`).

**Why.** Teachers copy names from the school register (surname first, with a patronymic), from Excel or from a chat. Suffix scores read both orders without a dictionary of names; the majority rule settles the lines suffixes cannot. Keeping only the first name is the minimum-data rule; the initial appears only where a class needs it.

**Rejected.** A dictionary of Azerbaijani first names: a data set to maintain and license, still wrong for rare names. Storing the full name and showing the first: the surname would be stored. Asking the teacher to pick the order for the whole list: one more step, and lists are often mixed.

---

## 8. Assets and licenses

**Decision.**

| Kind                                                           | Source                                                                                                                                                                                                                 | License                                                                            | Rule                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Avatars                                                        | Kenney, "Animal Pack Redux" (30 animals, the "round, outline" style), from Kenney's own upload on OpenGameArt                                                                                                          | CC0 1.0                                                                            | `content/assets/avatars/{key}.svg`: cut from the pack's vector sheet `Vector/roundOutline.svg` into one SVG per animal, shapes and colours unchanged, so an avatar stays sharp on a projector and on the share card. CC0, so safe on the share card with no attribution on the image                                                                                                                                                                                             |
| Sounds                                                         | Kenney, "Interface Sounds" 1.0, from Kenney's own upload on OpenGameArt                                                                                                                                                | CC0 1.0                                                                            | `content/assets/sounds/*.mp3`, converted from Kenney's OGG with ffmpeg (MP3 plays in every browser a classroom uses): `pop` ← `drop_002`, `chime` ← `glass_001`, `bong` ← `bong_001`, `success` ← `confirmation_004`. Every pack: `hit` = `pop` with the `burst` effect, `chime` with `sparkle`; `miss` = `bong`; `solved` = `success`                                                                                                                                           |
| Word pictures                                                  | OpenMoji 17.0.0, colour SVGs (npm package `openmoji`, unpacked outside the repository; not a dependency)                                                                                                               | CC BY-SA 4.0                                                                       | Copied unmodified into `content/assets/openmoji/` under their code-point names (`1F34E.svg`, `1F9D1-200D-1F3EB.svg`, OpenMoji's extras such as `E0C4.svg`), with OpenMoji's `LICENSE.txt`. A word gets one only when the emoji clearly shows that word: not a near-synonym, not two look-alike people, not a picture with text in it. Shown on the board and the phone; never on the share card                                                                                  |
| Pictures OpenMoji lacks, national and cultural symbols, cities | Own SVGs: words without a clear emoji; the `symbols` pack (buta, xarıbülbül, nar, tar, kəlağayı, xalça, the flag…); the `cities` pack as a map of Azerbaijan with a marker on the city, and Qız qalası as its backdrop | MIT, author "Elifba contributors", source `https://github.com/nijataliyev7/elifba` | Drawn for Elifba in OpenMoji's style (below), under `content/assets/{pack}/`                                                                                                                                                                                                                                                                                                                                                                                                     |
| Fonts                                                          | Andika 7.000 (SIL, designed for beginning readers) for text children read; Inter for the interface                                                                                                                     | SIL OFL 1.1                                                                        | Files committed in `resources/fonts/{andika,inter}/` with their OFL text (Andika: Regular and Bold woff2). They are loaded through the `fonts` option of `laravel-vite-plugin` with its `local()` provider and the `@fonts` Blade directive, the mechanism the precedent uses with `bunny()`. `local()` keeps the files in the repository and never calls a font service, at build time or at run time. Andika is not preloaded: only a page that uses `font-board` downloads it |

Kenney's art is used for avatars only and item styles are drawn in code (§2), so a pack's pictures never mix two looks: they are OpenMoji or own art drawn like OpenMoji. Backdrops are own art too.

- **Own art style.** Elifba's own SVGs follow OpenMoji's guidelines: the 72×72 grid (`viewBox="0 0 72 72"`, the subject inside the 4–68 safe area); flat fills from OpenMoji's palette (`data/color-palette.json` in the package: `#EA5A47` / `#D22F27` reds, `#FCEA2B` / `#F1B31C` yellows, `#B1CC33` / `#5C9E31` greens, `#92D3F5` / `#61B2E4` / `#1E50A0` blues, `#F4AA41` / `#E27022` oranges, `#A57939` / `#6A462F` browns, `#D0CFCE` / `#9B9B9A` / `#3F3F3F` greys, `#FFA7C0` / `#E67A94` pinks, `#B399C8` / `#8967AA` purples, `#781E32` wine, `#186648` dark green, white); a black (`#000`) outline of width 2 with round caps and joins on every shape (fine lines such as strings, and ornaments too small to outline, may be thinner or plain fills); no gradient, shadow or filter; no text, letter or digit in a picture; one subject, readable at 96 px on a projector. The files are plain SVG (the rule below), named after the word (`fruit/qreypfrut.svg`) and credited as MIT, author "Elifba contributors", source the repository URL. The State Flag keeps its official colours (below) instead of the palette. A hat is drawn on a face, as OpenMoji draws its hats (`papaq`).
- **City maps.** Each `cities` word is the same outline of Azerbaijan with a red pin whose tip is on the city, generated by `scripts/city-maps/generate.php` (its README has the steps) from Natural Earth's 1:10m admin-0 polygon of Azerbaijan (public domain, version 5.1.2): Nakhchivan and Karabakh inside the internationally recognised border, with no line between Karabakh and the rest; parts too small to see at 72×72 (the small exclaves, the Artsvashen enclave, the Caspian islands) are left out; equirectangular, simplified, fitted with every pin into the safe area. The points are in `scripts/city-maps/cities.csv` with a source per row: Natural Earth's 1:10m populated places where the city is there, otherwise the coordinates of the city's Wikipedia article. Nothing comes from OpenStreetMap (ODbL is not on the allow list). The Natural Earth download is never committed; the generator refuses a point outside the border.
- **Backdrops.** 16:9 (`viewBox="0 0 160 90"`), low contrast so cards stay readable: palette colours at low opacity over white, outlines in a darker palette colour at low opacity instead of black, the subject in the bottom fifth. No text, as for word pictures.
- **SVG safety.** Every `.svg` under `content/assets` is a plain drawing: no `<script>`, no `on…=` event attribute, no `<foreignObject>` and no `href` or `xlink:href` other than an in-file `#…` reference. Asset files are served from the app's own origin (`/content/…`) to children's screens, and an SVG opened on its own runs its scripts with that origin's rights. `ContentValidator` checks every file (§3).
- **`content/credits.json`** lists every asset file (or folder glob) with title, author, source URL and SPDX license, and `changes` when Elifba changed the files (English, like the titles); `ContentValidator`, run in the Pest suite, fails on any file under `content/assets` or `resources/fonts` without an entry, an entry that matches no file, or a license outside the allow list (`CC0-1.0`, `CC-BY-4.0`, `CC-BY-SA-4.0`, `OFL-1.1`, `MIT` — the cases of `Content\Enums\License`). Paths are written from the repository root; `*` matches within one folder and a trailing `/**` everything below it:

    ```json
    {
        "credits": [
            {
                "files": ["resources/fonts/inter/*"],
                "title": "Inter",
                "author": "The Inter Project Authors",
                "source": "https://github.com/rsms/inter",
                "license": "OFL-1.1"
            }
        ]
    }
    ```

- **`CREDITS.md`** is generated from it (`php artisan content:credits`, `Content\Actions\WriteCredits` with `Content\Support\CreditsDocument`), and a test fails when it is stale. The public `/credits` page shows the same file in az and en. Both carry OpenMoji's attribution sentence verbatim, the list of OpenMoji files we changed (the `changes` of share-alike entries; none so far) and the license statement of the READMEs.
- **What share-alike means here.** CC BY-SA 4.0 applies to the OpenMoji files and to any change we make to them: an edited OpenMoji SVG must stay CC BY-SA 4.0 and say what was changed. It does not reach the MIT code that displays them or our other assets — showing an image is not adapting it. The attribution OpenMoji asks for ("All emojis designed by OpenMoji – the open-source emoji and icon project. License: CC BY-SA 4.0") appears on `/credits` and in `CREDITS.md`, with a list of the files we changed. `LICENSE` stays plain MIT. The READMEs and `CREDITS.md` say that MIT covers the code and Elifba's own art, while `content/assets/openmoji/` (CC BY-SA 4.0) and the fonts (OFL 1.1) keep their own licenses and Kenney's avatars and sounds are CC0. Because a composed image that contains an OpenMoji would need the attribution on the image itself, the share card uses only CC0 and own art.
- **National symbols.** Only cultural symbols and the State Flag are used — never the State Emblem or the anthem. The flag is drawn to the official proportions and colours (three equal horizontal bands blue, red, green; white crescent and eight-pointed star in the red band; 1:2) and is never burst, distorted, cut or shown upside down. `symbols/bayraq.svg` follows the flag's construction sheet for the law of 5 February 1991 (as published on Wikimedia Commons; with h the flag height: the crescent's outer circle 3/10 h across, its inner circle 1/4 h across and 1/30 h to the right, the star 1/6 h across with an inner circle of 1/12 h, crescent and star spanning 2/5 h, centred) and the colours of the 2013 Rules for the Use of the State Flag (item 9.2: Pantone 306 C `#00B5E2`, Red 032 C `#EF3340`, 362 C `#509E2F`, also the `symbols` palette); its black frame lies outside the field and the crescent and star are not outlined, so nothing changes their proportions. The file's comment names these sources. The `symbols` pack therefore uses the gentle `sparkle` effect for every item, and its hit sound is the chime, not the pop.
- Nothing licensed NC (non-commercial), ND (no derivatives) or with an unknown license enters the repository. Downloads are unpacked outside the repository and only the files used are copied in.

**Why.** CC0 assets carry no obligations anywhere, which matters for an image that leaves the app. Keeping BY-SA files in their own folder makes the boundary visible to contributors. One picture style per pack (OpenMoji's) keeps a round readable: a child compares words, not drawing styles. Andika was designed for early literacy (clear a, g, I/l) and covers Ə ə Ğ ğ İ ı.

**Rejected.** OpenMoji for avatars: every share card would need an attribution line. Kenney's object packs for word pictures: they cover few of the words, and mixing them with OpenMoji would put two looks in one pack. Hot-linking assets from CDNs: a third-party request from a children's app, and broken boards when the CDN is blocked by a school network. OGG sounds: older Safari versions, still on school iPads and Macs, do not play them.

---

## 9. Internationalization

**Decision.**

- `APP_LOCALE=az`, `APP_FALLBACK_LOCALE=en`. Locales: `az` (default) and `en`, listed in `config/elifba.php` (`locales`).
- Interface strings live in `lang/az.json` and `lang/en.json`, keyed by dotted keys (`classes.create.title`) and shared with React as in the precedent (`App\Support\Translations`, a `translations` prop, `useTranslation()`); plurals use `{one}…|{other}…` (Azerbaijani keeps the noun singular after numbers, so its two forms are usually equal).
- Framework messages (validation, auth, passwords, pagination) are PHP files `lang/{az,en}/*.php`, copied once from `laravel-lang/lang` (MIT) and committed; the package is not a dependency.
- **Parity test** (`tests/Unit/TranslationsTest.php`, reused from the precedent with `az` as the base): every JSON file has exactly the keys of `az.json`, no empty values, identical `:placeholders`; the PHP files have the same keys in both languages; the offered locales equal the shipped files.
- **Locale resolution** (`SetLocale` middleware): the teacher's `users.locale` → the session's choice (switcher in the footer for guests) → `az`. Not `Accept-Language`: the product is Azerbaijani first. Carbon's locale follows.
- **Content is not interface.** Words, sentences and prompts are Azerbaijani in every locale (§3); pack and level titles carry their own `az`/`en` and are checked by `ContentValidator`, not by the parity test.
- Names are sorted by `Language\Support\AzCollator` (the 32-letter order) on the server; lists reach React already sorted.

**Why.** It is the owner's working pattern from the precedent, with az as the base so a missing Azerbaijani string fails CI first.

**Rejected.** PHP array files for all interface strings: harder to share with React. i18next or another client library: a second source of truth; the precedent's hook already does the job.

---

## 10. Hosting

**Decision.**

**Development.** Laravel Herd serves `elifba.test` (PHP 8.4; `herd secure` for HTTPS); `composer dev` runs Vite, Reverb (`php artisan reverb:start`, which `laravel/reverb` registers in `php artisan dev` itself) and the log tail. `composer setup` runs `reverb:install`, which appends a random `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` to `.env` (`.env.example` leaves them out on purpose: the command only adds keys that are missing). Until then the `reverb` broadcaster cannot be built, and `routes/channels.php` builds it on every boot, so `composer setup` runs `key:generate` and `reverb:install` with `BROADCAST_CONNECTION=null` (`@putenv`) and clears it before `migrate`. Database: SQLite (`database/database.sqlite`). Testing with a real phone needs the laptop's LAN address for both the app and Reverb — phones cannot resolve `elifba.test` — so `composer dev:lan` (`scripts/dev-lan.php`) finds the LAN IPv4 (or takes it as `composer dev:lan -- 192.168.1.20` or from `ELIFBA_LAN_IP`) and runs `artisan serve --no-reload` on `0.0.0.0:8000`, Reverb on `0.0.0.0:8080`, Vite (listening on every interface, HMR from the LAN host: `ELIFBA_LAN_HOST` in `vite.config.ts`) and the log tail under `concurrently`, with `APP_URL`, `REVERB_HOST` and `VITE_REVERB_HOST` set to the LAN address in those processes only. `.env` is never edited: Laravel's `.env` loader does not overwrite variables already set, and `--no-reload` keeps `artisan serve` from dropping them. Phones open `http://{ip}:8000/pult`. In LAN mode Vite does not look for a certificate (`detectTls: false`), Reverb is plain `ws` on the port `.env` gives it (`REVERB_SERVER_PORT`, else `REVERB_PORT`, else 8080), and nothing else changes.

**Local HTTPS** (A10: the Web Share API and `canShare({ files })` exist only in a secure context). `herd secure elifba` makes `https://elifba.test` (plain http redirects to it) and a certificate for that hostname. `.env` then has `APP_URL=https://elifba.test`, `REVERB_HOST=elifba.test`, `REVERB_SCHEME=https` and `REVERB_CA_BUNDLE` = Herd's CA (`~/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem`), with `VITE_REVERB_*` following `REVERB_*` (`.env.example` has the recipe). Nothing else changes in how the stack starts: `composer dev`'s `reverb:start` takes its hostname from `REVERB_HOST`, finds Herd's certificate for it and serves `wss`; the Vite plugin finds the same certificate and serves https on `elifba.test`; the app trusts Herd's CA when it broadcasts (`client_options.verify` in `config/broadcasting.php`, PHP's own bundle does not hold it); the allowed origin is still the host of `APP_URL`. A machine where another app already holds port 8080 sets `REVERB_SERVER_PORT` and `REVERB_PORT` to a free port. Phones test the share card on the HTTPS URL of `herd share`, with `composer dev` running: Expose finds the Vite dev server and tunnels it too (it rewrites `public/hot` while it shares), and rewrites only `Location` headers, never a page. URLs the server writes absolute (`asset()`, e.g. the page's own avatars) still name `elifba.test` there unless the app trusts the tunnel's `X-Forwarded-*` headers; the share card's pictures are paths from the root, so the card is unaffected.

**Production** (`docker-compose.yml`, `Dockerfile`, A11): one image built from the repository on `serversideup/php` (`8.4-fpm-nginx-bookworm`, a pinned `v5.x` tag; nginx + PHP-FPM, nginx on port 8080) with `intl` added (`pdo_pgsql` is in the image), the app without dev packages, and the assets built in a stage with Node 22 (`vp build` runs Wayfinder, which calls `php artisan wayfinder:generate`, so that stage has PHP, `vendor/` and Node; only `public/build` leaves it). `.dockerignore` is the only filter (the folder need not be a git repository): `.env`, `node_modules`, `vendor`, `public/{build,hot,content,storage}`, the dev fonts manifest, the generated Wayfinder files, `storage` and `bootstrap/cache` never enter the image. Only `app` runs the image's Laravel automations (`AUTORUN_ENABLED=true`): `storage:link`, `migrate --force` and `optimize`, which includes `content:cache` (§3). `reverb` and `scheduler` run with them off and start once `app` is healthy. Every image tag is pinned; Dependabot watches `Dockerfile` (`docker`) and `docker-compose.yml` (`docker-compose`).

| Service     | Image                      | Runs                                                                                                                                                          | Health check                                   |
| ----------- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| `proxy`     | `caddy:2.x-alpine`         | `docker/caddy/Caddyfile`: the site; WebSockets on `/app/*` → `reverb:8080`; `/apps`, `/apps/*` → 404; everything else → `app:8080`; the only published ports  | Caddy's admin API on `127.0.0.1:2019` (inside) |
| `app`       | the image (`elifba:local`) | nginx + PHP-FPM; the automations on start                                                                                                                     | the image's own: `/up` (`HEALTHCHECK_PATH`)    |
| `reverb`    | the image                  | `php artisan reverb:start --host=0.0.0.0 --port=8080`                                                                                                         | `healthcheck-reverb` (the process)             |
| `scheduler` | the image                  | `php artisan schedule:work` (§1: abandon, prune)                                                                                                              | `healthcheck-schedule` (the process)           |
| `db`        | `postgres:17.x`            | PostgreSQL 17, volume `db-data`; no published port                                                                                                            | `pg_isready`                                   |
| `backup`    | `postgres:17.x`            | `docker/backup/elifba-backup.sh nightly`: `pg_dump --format=custom` at `BACKUP_AT` (03:00) Baku time into volume `backups`, `BACKUP_KEEP_DAYS` (14) days kept | `pg_isready` against `db`                      |

The workers set `stop_signal: SIGTERM` (the image's `SIGQUIT` is for PHP-FPM). `docker compose exec backup elifba-backup now` makes a dump at once; the script's header has the `pg_restore` line.

**Configuration.** One `.env` in the repository's root, copied from `.env.production.example`, is read twice: by Compose for its `${…}` values and as the PHP containers' `env_file`. `scripts/docker-env.sh <domain> [https|http] [port]` writes it with fresh secrets from `openssl` (`APP_KEY`, `REVERB_APP_ID`/`KEY`/`SECRET`, `DB_PASSWORD`; no PHP on the host) and never overwrites one. The address is `ELIFBA_DOMAIN`, `ELIFBA_SCHEME` and `ELIFBA_PORT`: Caddy's site address is `{scheme}://{domain}:{port}`, `APP_URL` is the same, Reverb's allowed origin is its host, and the browser's socket uses all three (`VITE_REVERB_HOST/PORT/SCHEME`, with `VITE_REVERB_APP_KEY` = `REVERB_APP_KEY`). Those `VITE_*` are build arguments, baked into the assets (`socket.ts` is unchanged), so changing them needs `docker compose up -d --build`; the build fails when the key or host is empty, and Compose refuses to start without the domain, port, scheme or secrets. With `https` (port 443) Caddy gets and renews a Let's Encrypt certificate itself and redirects `ELIFBA_REDIRECT_PORT` (80) to it. With `http` it is a try-out without certificates, which leaves port 80 alone (`docker-env.sh` puts the unused redirect port on `ELIFBA_PORT` + 1): `localhost` on this machine, or the machine's LAN address so phones on the school Wi-Fi can open `/pult` (no Web Share there, §6). What follows from the services is set in `docker-compose.yml` and wins over `.env`: `APP_ENV=production`, `LOG_CHANNEL=stderr`, `DB_*` → `db`, `REVERB_HOST=reverb`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`, `QUEUE_CONNECTION=sync`, database sessions and cache. Mail is `log` (the mails land in `docker compose logs app`; the log transport writes at `debug`, so the example keeps `LOG_LEVEL=debug`) until the operator sets the SMTP lines, which teachers need to confirm their address.

**Behind the proxy.** The app sees plain http from Caddy. `bootstrap/app.php` trusts `X-Forwarded-For`, `-Host`, `-Port` and `-Proto` from the proxies in `config/trustedproxy.php` (`TRUSTED_PROXIES`), which is empty by default, so Herd, `composer dev`, `composer dev:lan` and `herd share` behave as before; `docker-compose.yml` sets the private ranges, since Caddy is the only peer that reaches `app` and it replaces any `X-Forwarded-*` a browser sends. Then `asset()`, redirects and the session cookie follow the browser's scheme: `SESSION_SECURE_COOKIE` stays unset, and Laravel marks the session and XSRF cookies `Secure` on https requests. nginx in the image keeps `REMOTE_ADDR` as the peer (`TRUSTED_PROXY=off`; the image's default `cloudflare` would take it from a `CF-Connecting-IP` header that any browser can send through Caddy).

The browser reaches Reverb through the proxy (`VITE_REVERB_HOST` = the public domain, port 443, `https`); the `app` and `scheduler` containers broadcast to `reverb:8080` on the internal network (`REVERB_HOST=reverb`, `REVERB_SCHEME=http`), so Reverb's HTTP API is never public.

**Smoke test.** `scripts/docker-smoke.sh` (the `docker` job in CI, §11) writes an http `.env` for `localhost:8088` when there is none, runs `docker compose up -d --build --wait` and checks: every service healthy; `/up` and the login page's built assets through Caddy, with no Vite dev server; a WebSocket handshake to `/app/{key}` from the app's origin (101); `/apps/*` answering 404 from outside while `app` reaches Reverb inside; the scheduler's four tasks; a backup dump that `pg_restore --list` reads; a row surviving `down` and `up`.

No queue worker and no Redis in v1: broadcasts are `ShouldBroadcastNow`, mail is sent synchronously, the share card is made in the browser. `QUEUE_CONNECTION=sync` in every environment, so anything a package queues still runs instead of waiting for a worker that does not exist. The starter kit's `composer dev` entry for `queue:listen` is removed (A2, `DevCommands::except('queue')`); Reverb is in `composer dev` since A7. Add a `queue` service the day something needs one; add Redis only when Reverb has to scale across servers.

**Database.** PostgreSQL 17 in production; SQLite in development and tests. Queries stay on the query builder with no engine-specific SQL (dates are computed in PHP, §5; names are sorted in PHP, §9). CI runs the Pest suite on both (§11). A self-hosting school may run SQLite in production — the same tests cover it.

**Why.** One domain for the app and Reverb removes CORS and mixed-content problems and lets phones reach the socket through port 443. PostgreSQL is the safer engine for a public instance with many classrooms writing at once (row locks, no single-writer file lock, `pg_dump`, a path to more servers). SQLite keeps `composer setup` free of services for contributors and makes tests fast.

**Rejected.** SQLite in production for the public instance: a single writer and a single host; moving data off it later is the hard migration this avoids. MySQL: no advantage here over PostgreSQL. A managed platform (Forge/Vapor/Cloud): the repository must stay self-hostable with plain `docker compose up`.

---

## 11. Repository layout, CI gate and rules for agents

### Layout

```
app/
├── Domain/
│   ├── Language/   Support/{Alphabet,Az,Syllabifier,AzCollator,Ordinal,DateLabel,Plural}.php
│   ├── Content/    Actions/{WriteContentCache,DeleteContentCache,WriteCredits,AddCustomWord,UpdateCustomWord,DeleteCustomWord,HideWord,ShowWord}.php Data/ Enums/{Unit,Mechanic,ItemStyle,SlotStyle,Effect,Sound,License,WordProblem}.php
│   │               Models/{CustomWord,HiddenWord}.php Policies/CustomWordPolicy.php
│   │               Support/{Avatars,ContentAssets,ContentRepository,ContentValidator,CreditsDocument}.php Console/ (content:validate, content:cache, content:clear, content:credits)
│   ├── Classroom/  Actions/ Models/ Data/ Enums/ Policies/ Support/{NameListParser,DisplayName,ClassLock}.php
│   ├── Play/       Actions/ Models/ Enums/ Data/ Events/ Exceptions/ Listeners/ Policies/ Support/{RoundJudge,PointsRule,SchoolCalendar,Playable,StartableGame,BoardState,BoardProps,PairingCode,GuestBoardGuard,GameChannel,Requester,Mechanics/*}.php
│   └── Rankings/   Enums/Period.php Data/{PeriodData,RankedStudentData,PodiumPlaceData,ShareCardData}.php Support/{ClassRanking,DenseRank,Podium}.php
├── Http/Controllers/  (thin: validate → action → Inertia/JSON)
├── Models/User.php    (the teacher, from the starter kit), Models/Concerns/BelongsToTeacher.php (scoped binding, §7)
├── Providers/ContentServiceProvider.php  (the repository, the content:* commands, optimize hooks)
└── Support/Translations.php
config/elifba.php      (timezone, locales, retention_days, pairing_code_ttl_minutes, guest_board_hours, abandon_after_hours, rounds, custom_words.max_length, custom_words.max_per_teacher, operator.name, operator.email)
content/               (§3)
scripts/city-maps/     generate.php + cities.csv: the cities pack's maps (§8)
docs/                  architecture.md, frontend.md (A2), glossary.md, decisions/
lang/                  az.json, en.json, {az,en}/*.php
resources/js/
├── pages/             classes, students, games/setup, games/board, games/remote, lovhe, pult/{index,guest-board}, rankings/show, podium, words/index, credits, privacy
├── components/ui      (shadcn/ui from the starter kit)
├── components/app     (the shared kit, as in the precedent)
├── components/{classes,students,games,rankings,share,words}
├── game/              contract.ts (+ .test.ts), engine.ts (+ .test.ts), live.ts, outbox.ts, scenes/, styles/
└── lib/
routes/web.php (+ classroom.php, play.php, rankings.php, settings.php, words.php), channels.php, console.php
tests/Feature, tests/Unit, tests/Fixtures/contract/, tests/Fixtures/content/ (a valid content root + broken.json), tests/Support/ (ContentFixture, Games)
```

Routes (names in English; `/pult` is the one Azerbaijani path because teachers type it):

| Route                                                                                                                                   | Purpose                                                                     |
| --------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| `/classes`, `POST /classes`, `/classes/{class}` (GET, PATCH, DELETE), `POST`/`DELETE /classes/{class}/archive` (archive / restore)      | classes; one class page with students, learned letters and settings         |
| `PUT /classes/{class}/learned-letters`                                                                                                  | learned letters (`UpdateLearnedLetters`)                                    |
| `POST /classes/{class}/students/preview`                                                                                                | the paste preview (`NameListParser`, JSON, writes nothing)                  |
| `POST /classes/{class}/students`                                                                                                        | add the confirmed names (`AddStudents`)                                     |
| `PUT /classes/{class}/students/order`                                                                                                   | reorder (`ReorderStudents`)                                                 |
| `/classes/{class}/students/{student}` (PATCH, DELETE), `POST`/`DELETE …/{student}/archive`                                              | edit, delete, archive / restore a student (scoped bindings)                 |
| `/classes/{class}/games/create`, `POST /classes/{class}/games`                                                                          | game setup → `StartGame`                                                    |
| `/games/{game}/board`, `POST /games/{game}/board/claim`, `POST /games/{game}/sync`, `GET /games/{game}/state`                           | the board (claim, sync, state: the teacher or the guest board's bearer, §4) |
| `POST /games/{game}/join-code`, `PATCH /games/{game}/status`                                                                            | pairing card (teacher), pause / resume / end (teacher or bearer)            |
| `/pult`, `/pult/{code}` (GET; POST claims a guest board and sends it a game), `/games/{game}/remote`                                    | the remote                                                                  |
| `/lovhe`, `POST /guest-boards`, `GET /guest-boards/{board}`, `DELETE /guest-boards/{board}`, `GET`/`PUT /guest-boards/{board}/game`     | the guest board; who reaches each route: §4                                 |
| `/classes/{class}/rankings`, `/classes/{class}/podium` (both `?period=day\|week&date=YYYY-MM-DD`, `PeriodData`)                         | rankings and the podium with the share card                                 |
| `/words` (`?pack={key}`), `POST /words`, `/words/{word}` (PATCH, DELETE), `POST`/`DELETE /words/hidden` (hide / show, word in the body) | the teacher's words per theme pack (§3 "Teacher words")                     |
| `/credits`, `/privacy`                                                                                                                  |                                                                             |

### The CI gate

`composer ci:check` is the one gate, locally and in CI:

1. the front-end lint and format check the starter kit ships (in the precedent `vp check`);
2. `tsc --noEmit`;
3. Vitest (`vp test`, `npm run test`) for `resources/js/game/*` pure modules: the contract fixtures against the zod schemas and the shared judge cases (A6), the engine's state machine, the outbox and the board controller (A6.2), the 800 ms rule (A7), the guest board's tab and bearer mode (A7.3);
4. Pint (`--test`);
5. PHPStan level 8 with Larastan;
6. Pest: unit and feature tests, including translation parity, `ContentValidator` over all packs, the syllable set, the contract fixtures, the judge cases, credits coverage and teacher isolation.

GitHub Actions (`.github/workflows/ci.yml`, actions pinned by SHA as in the precedent): job `ci` — PHP 8.4, Node 22, `composer setup`, `composer ci:check` on SQLite; job `pgsql` — a `postgres:17` service and `php artisan test` with `DB_CONNECTION=pgsql`; job `docker` — `scripts/docker-smoke.sh` against the Docker stack from the checkout (§10).

### Rules the `CLAUDE.md` will give later agents (A2 writes it)

- **Read first:** `docs/architecture.md` (this contract), `docs/glossary.md` (names), `docs/frontend.md` (UI rules). Change the contract first when the model changes.
- **Domains** `Language → Content → Classroom → Play → Rankings`; a domain uses only the ones before it.
- **Only Actions write.** Controllers, commands and channel callbacks validate, call one action and shape the response. Validation lives in `*Data::rules()` and is reused by every entry point.
- **Statuses** change only through `TransitionGameSession` (`GameSessionStatus::allowedTransitions()`); derived things (the current round, rankings, places) are never stored.
- **Facts are events after commit** (`GameSessionTransitioned`, `RoundSolved`); listeners never run inside the writing transaction.
- **The board is the only writer of results.** The phone never posts hits; it sends client events. Every result goes through `RecordBoardEvents`, idempotent by `event_id` and fenced by `board_epoch`.
- **Two board modes, one protocol.** A teacher board uses the teacher's session; a guest board uses its bearer, which reaches only the game it shows — never classes, rankings or other games. Codes come only from `PairingCode` and never authorize anything.
- **One judge, one points rule.** `RoundJudge` (mirrored by `engine.ts` against the shared fixtures) and `PointsRule` (PHP only). The round contract changes in the PHP DTOs, the zod schemas in `contract.ts` and the fixtures together; TypeScript types are inferred from the schemas, never written by hand.
- **Content is data.** Never hard-code words, letters, prompts or theme looks in PHP or TypeScript; add them to `content/` and run the validator. Text is cased, syllabified and ordinal-suffixed on the server (`Language`), never in TypeScript. Teachers' own and hidden words are rows (`custom_words`, `hidden_words`); Play reads a pack only through `TeacherPacks` wherever it builds or checks rounds, and a word rule lives once, in `WordRules`.
- **Children's privacy.** A student is a display name and an avatar. Do not add fields about children. Never put a student's name in a log, an exception, a broadcast or a URL, nor a teacher word in a log, an exception or a URL. New routes with `{class}`, `{student}` or `{game}` go into the isolation test. Nothing on the share card but §6's list.
- **No third-party requests** from pages: no CDNs, external fonts, analytics or trackers.
- **Assets** enter only with an entry in `content/credits.json` and an allowed license; OpenMoji files stay in `content/assets/openmoji/` and never on the share card; state symbols follow §8.
- **Two languages.** Every interface string goes into `lang/az.json` and `lang/en.json`; the parity test fails otherwise. Content stays Azerbaijani.
- **Database portability.** Query builder only; no engine-specific SQL; Baku dates come from `SchoolCalendar`. Tests must pass on SQLite and PostgreSQL.
- **Tests** use Pest; fake time with `travelTo` around midnight and Monday boundaries; never call a real Reverb server in a test: tests run the `reverb` broadcaster with test keys and `tests/Support/FakeReverb` (`$this->reverb->sent`, `->down()`) in place of its HTTP client; or fake the event.
- **Gate:** run `composer ci:check` before calling work done.

---

## What each step builds

- **A2 — Skeleton and CI gate.** `laravel new` with the React starter kit (built-in auth); `app/Domain/*` folders; `config/elifba.php`; `users.locale`; `lang/az.json` + `en.json` (+ framework PHP files) and the parity test with az as base; `SetLocale`; `QUEUE_CONNECTION=sync`, and `composer dev` running Vite + logs + `artisan serve` without the queue worker (Reverb joins it in A7, which installs Reverb); Inter loaded through the `local()` font provider, with no font CDN left from the starter kit; Pint, PHPStan 8 (Larastan), Pest, `tsc`, the kit's lint; `composer ci:check` and `.github/workflows/ci.yml` with the `ci` and `pgsql` jobs; README (az + en), LICENSE (MIT), CONTRIBUTING, `CLAUDE.md` with §11's rules, `docs/frontend.md` adapted from the precedent. Done when the login page opens in az and en and the gate is green.
- **A3 — Teachers, classes, students.** The `Language` minimum the classroom needs: `content/alphabet.json` (letters, vowels, look-alikes), `Alphabet`, `Az` (`upper`, `lower`, `title`, `normalize`) and `AzCollator`, with their tests; `ext-intl` required. `SchoolClass`, `Student`, the Classroom actions, paste-a-list with preview (`NameListParser`, §7: strips numbering, bullets, number columns, surnames and patronymics, reads surname-first registers, adds a surname initial only to tell two equal first names apart, flags duplicates), avatars auto-assigned from `content/avatars.json` (A3 creates it with keys only, read by a small `Content\Support\Avatars`; until A5 adds the pictures an avatar renders as a coloured circle), archive / restore / delete, the 32 learned-letter toggles, `BelongsToTeacher` scoped binding, policies, the route-enumerating isolation test; the registration checkbox (`users.terms_accepted_at`); `/privacy` with `elifba.operator.*` and the parent note.
- **A4 — Language core and content.** `Language` extended from A3's minimum: letter names and confusables in `alphabet.json`, `Syllabifier` (with `shape()`), `Ordinal`, with tests; `content/` structure, `Content\Enums` (`Mechanic` moved here from Play, §2), `Content\Data` (pack, word, sentence, level, prompt template, credit), `ContentRepository`, `ContentValidator` with one broken fixture per rule, `content:validate` / `content:cache` / `content:clear` in `optimize`, the `public/content` link (`storage:link` in `composer setup`), `credits.json` with its coverage check; `alphabet.json`, `levels.json`, `prompts.json`; six packs with ≥ 20 words and ≥ 10 sentences each (session 1: `fruit`; session 2: the other five); `content/tests/syllables.json` with ≥ 100 words.
- **A5 — Images and sounds.** Kenney avatars and sounds; OpenMoji word pictures (own folder); own SVGs in OpenMoji's style for the words OpenMoji lacks, the symbols pack (flag rules of §8) and the cities (a map with a marker); Andika and Inter self-hosted; every asset in `content/credits.json`, the generated `CREDITS.md` and `/credits`; the validator's picture check switched on. Every word of every pack has a picture; no asset without a license.
- **A6 — Board and the first mechanic.** `GameSession`, `GameRound`, `GameAnswer`, `GameSetupData`, `StartGame` with the `find` builder, the round contract (DTOs, zod schemas in `contract.ts`, fixtures, both fixture tests; `zod` added), `RoundJudge` + `engine.ts` with shared cases (Vitest through `vp test` joins the gate), `ClaimBoard`, `RecordBoardEvents` (`/sync`), `PointsRule`, `SchoolCalendar`, `TransitionGameSession`, the board page (Phaser scene for `find`, `balloon` style, `burst` effect, sounds (Kenney CC0 with credits entries if A5 has not landed), full screen, wake lock, outbox, reload/resume), touch and mouse play. Built in two sessions: the server side first (the tables, the contract and its fixtures, the judge on both sides, `Playable`, the `find` builder, the actions and the board endpoints, `routes/play.php`, with minimal `games/setup` and `games/board` pages), then A6.2, the board itself (Phaser, outbox, reload/resume, the setup picker).
- **A7 — Phone remote and guest board.** `PairingCode`, `IssueJoinCode`, the pairing card (QR + code), `/pult` and `/pult/{code}`; the guest board (`guest_boards`, `/lovhe`, `OpenGuestBoard`, `ClaimGuestBoard`, `ShowGameOnGuestBoard`, `CloseGuestBoard`, `PruneGuestBoards`, the `board` guard, the game setup on the phone); the channel callback and its tests (only the owner's session or the guest board showing that game can subscribe; a bearer cannot reach any other game or page), `live.ts` with the client-event protocol v1, the remote page (items as big buttons, the answer highlighted, student grid, next, pause, skip, end), acks, retries, pings, reconnect banners, `?debug=1` latency, `session.status` broadcast, Reverb installed and added to `composer dev`, `composer dev:lan`. Measured p95 `ack_ms` ≤ 300 ms.
- **A8 — Syllable train, word and sentence games.** Builders and scenes for `build_syllable`, `build_word`, `build_sentence`, with reusable tiles (`nə-nə`); `wagon` and `frame` slot styles; the remaining item styles (`wagon`, `card`, `bubble`) and the `sparkle` effect; every mechanic × every pack, all driven by the remote.
- **A9 — Points and rankings.** `Rankings\Support\ClassRanking` (daily, weekly) and `DenseRank`, `Rankings\Enums\Period`, `Rankings\Data\PeriodData` (the shared `?period&date` query, its labels and the windows before and after) and `RankedStudentData`; `Language\Support\DateLabel` and `Ordinal::label()` for English places; the rankings page `/classes/{class}/rankings` (`routes/rankings.php`, `RankingController`, `rankings/show`, `components/rankings`) with day / week, previous / next / today, linked from the class page; tests for midnight Baku, Sunday→Monday, dense ties in Azerbaijani order, archived and deleted students, whole-class rounds, other classes, invalid and future dates, both locales and isolation.
- **A10 — Podium and share card.** The podium page (projector-friendly, confetti), the 1080×1920 card, `modern-screenshot` capture, Web Share with files and the download fallback, the §6 content list enforced by a test of the props the card receives.
- **A11 — Docker and release prep.** Dockerfile on `serversideup/php`, `docker-compose.yml` (proxy, app, reverb, scheduler, db with backups), `.env.production.example` (with `ELIFBA_OPERATOR_NAME` / `ELIFBA_OPERATOR_EMAIL`), install section in both READMEs, issue and PR templates, screenshots. `docker compose up` on a clean machine serves the app with working Reverb.

## Open questions for the owner

None open. The owner answered the five questions A1 raised on 2026-11-09 by accepting the recommendations, and the sections above follow them:

| #   | Question              | Answer                                                                                                                                                                                                    | Where              |
| --- | --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------ |
| 1   | Letter order          | No textbook order: the teacher switches on the learned letters one by one                                                                                                                                 | §3 Learned letters |
| 2   | Voice                 | No recorded voice in v1; the teacher reads the prompt; `prompt.audio` stays null                                                                                                                          | §3 Voice           |
| 3   | Operator and consent  | Operator name and e-mail from `.env` on `/privacy` (filled for the public instance at O5); teachers accept the privacy note at registration; a parent note on `/privacy`; no consent collected in the app | §7                 |
| 4   | Shared computers      | A guest board claimed from the teacher's phone, next to the teacher board (A7)                                                                                                                            | §4 Pairing         |
| 5   | Points and visibility | 3 / 2 / 1 per solved round, never negative, none in whole-class mode; the full ranking only on the teacher's pages; the board shows only the podium                                                       | §5                 |
