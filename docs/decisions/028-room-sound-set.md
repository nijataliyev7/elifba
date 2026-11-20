# 028 — A room game's second sound set: a countdown, a running-out clock, and every cue chosen by ear

- **Status:** Accepted, 2026-11-18 (the owner's decisions of that day; every cue chosen by ear at an audition, "As chosen" below). Built on the branch `feat/rooms-sound-set`; the owner's listening to a whole live game with the screen is still to come, and a cue changed after it is a new audition of that cue, not a new decision. **Amended 2026-11-19** (the owner's decision after playing on elifba.io with phones only): in a room without a screen every phone plays the room's cues too — "Amended 2026-11-19" below; decision 3's and "As chosen"'s «no clock on a phone» now hold only where the room has its screen.
- **Amended by:** [034](034-board-games-draughts.md) (2026-11-20) — decision 1: twenty cues, with `move`, `capture`, `crown` (the room's) and `offer` (a phone's) for a board game, made by `make.py` under decisions 9–12; `sounds.json` version 3. `clock` also ends at a match's `flag_ms`; (2026-11-21, its "Amended 2026-11-21") — `move`, `capture` and `crown` as the owner chose them by ear, `move` a Freesound recording; `sounds.json` version 5; no `tap` in a match
- **Amended:** 2026-11-21 — decisions 9, 10 and 12: Freesound is a source, a sound pinned by its id, its preview and its SHA-256; a recorded touch may stay under the set's loudness ("Amended 2026-11-21 — Freesound" below)
- **Sections:** architecture.md §8 (the assets table), §12 "Room games", "Room content", "Guard tests"; docs/frontend.md rule 15 "Sound"; `scripts/room-sounds/README.md`
- **Amends:** [025](025-room-motion-sound-and-final.md) "Sound": decision 11's list of the screen's moments (the countdown's seconds become one countdown, the soft tick of the last 3 s a clock of the last 5 s, and three moments are sounded that were silent) and its "As built" items 1 (the cues), 2 (a long cue, and a cue that stops when its moment ends) and 7 (the files: no longer only our own synthesis; their size). Decisions 12–14 — a phone's own quiet cues, the mute, nothing audible about right or wrong before the reveal, no voice, speech, vibration or music bed — and every other part of 025 hold as written.
- **Why it is hard to reverse:** a room hears these sounds together and learns them as the game's: the countdown says "it starts", the clock says "hurry". Changing them again re-teaches every room, and a recording that entered under the wrong license cannot be taken back from pages that already served it.

## Context

ADR 025 gave the room games thirteen short cues, synthesised by `scripts/room-sounds/make.py` in one evening (commit `a48e56c`): a 180 ms pip on each second of the countdown, an 80 ms wood-block tick in each of the last three seconds of an answer, a chime, a roll and a brass fanfare made of sine harmonics. They are correct — they follow the state and never tell right from wrong — but they sound like a prototype. The owner asked on 2026-11-18 that a room game sound professional — a countdown into the game, a clock that makes the last seconds of an answer exciting, the other cues made again — and decided, not to be reopened:

1. **The sources are mixed:** a richer synthesis of our own and recordings under a free license.
2. **The owner chooses each cue by ear** from candidates; nothing is built that was not chosen.
3. **The countdown and the clock stay sound effects:** no spoken countdown, no voice, no new voice clip. (A separate step says the winner's name after the fanfare with the existing name clips; nothing here touches names or voice.)

Two things constrain the design, as in 025. Sound follows the state and changes no timing: `countdown_ms` (3 s), `reveal_ms` (3 s), the levels' answer times (10–50 s), the final's timeline (`finalTimeline()`) and the time bar stay as they are. And a page may open, reload or fall asleep in the middle of any moment, while the server alone keeps the time: a device knows a moment's start and end only from the state and its estimate of the server's clock (`serverClock()`).

## Decision

### The cues

1. **Sixteen cues at most**, each one file of `content/rooms/sounds.json` and one `Content\Enums\RoomSound`, each played by one speaker (`RoomSound::speaker()`). The screen — the room's speaker — plays the room's moments; a phone plays only its own quiet cues (025 decision 12). A cue proposed below that the owner declines at the audition is left out, and its moment stays silent.

    | Cue         | Device | When                                                                                         | Was                           |
    | ----------- | ------ | -------------------------------------------------------------------------------------------- | ----------------------------- |
    | `countdown` | screen | the countdown before round 0: one file that ends at `opens_ms`                               | `count`, a pip per second     |
    | `go`        | screen | round 0 opens (its study or its question), in place of `study` or `question`                 | new                           |
    | `study`     | screen | a later round's study opens (memory games)                                                   | `study`                       |
    | `question`  | screen | a later round's question opens, and round 0's after its study                                | `question`                    |
    | `clock`     | screen | the last 5 s of an answer: one file that ends at `bell_ms`                                   | `tick`, a tick in each of 3 s |
    | `close`     | screen | the round closes and the reveal opens, the same whatever anyone answered                     | `close`                       |
    | `ranking`   | screen | the reveal's ranking comes in (`REVEAL_MS.ranking` after)                                    | `ranking`                     |
    | `place`     | screen | the final's third and second places open (`finalTimeline().opensAt`), one hit each           | new                           |
    | `roll`      | screen | the final: one file that ends where the first place opens (`winnerAt`)                       | `roll`                        |
    | `fanfare`   | screen | the final's first place opens (`winnerAt`)                                                   | `fanfare`                     |
    | `join`      | screen | between games (no live game): a player the state did not list before appears; once per state | new                           |
    | `tap`       | phone  | the viewer's tap that adds an item, the same for every item                                  | `tap`                         |
    | `undo`      | phone  | «Geri», or a cell tapped again                                                               | `undo`                        |
    | `sent`      | phone  | the state says `answered` within the answer phase: one sound for every answer                | `sent`                        |
    | `right`     | phone  | the reveal, `reveal.mine.correct`                                                            | `right`                       |
    | `wrong`     | phone  | the reveal, `reveal.mine` not correct                                                        | `wrong`                       |

    The names change where the thing changed: `count` was a pip and `countdown` is a three-second file; `tick` was a tick and `clock` is a five-second one. Still not sounded: the count of answers, the study's covering, a reveal without the viewer's answer, a host who does not play, the places after the third, and the final on a phone.

2. **The countdown** is one file of 3 s — the length of `countdown_ms` — with a beat on each second the screen shows (3, 2, 1) and a build into the round. It ends at `opens_ms` and holds no "go" of its own: the game's start is `go`, played when round 0 opens, as every moment's cue is played, when the page hears the state of that moment. Round 0 opens with `go` instead of its `study` or `question`; a memory game's round 0 then still hears `question` when its study ends, and every later round opens with `study` or `question` as before.

3. **The clock** is one file of 5 s (`CLOCK_MS`) that ends at `bell_ms`: the last 5 s of every answer, whatever the level, the round or anyone's answer. Its last 3 s are its most urgent, as the time bar turns red (`URGENT_MS` stays 3 s and stays the bar's). It never reacts to an answer — the same file plays whoever has answered — and only the round's close stops it: when everybody present has answered, the round closes early for the whole room (ADR 021), its moment ends, and the clock stops with it. It is no music bed: one file, once per round, never looped. It plays on the screen only (025 decisions 11–12); "As chosen" records the owner's answer about a phone in a room played without a screen.

4. **The final** sounds as it opens: `place` when the third and the second places open (a place that players share is one group and one hit), `roll` ending where the first place opens, and `fanfare` from that moment. The roll is as long as the stretch from the third place to the first on the screen (`PACE.screen.second + PACE.screen.first`, 2.5 s), so it begins as the third opens and builds under the second's hit; with fewer places it starts from its middle. The fanfare starts at `finalTimeline().winnerAt`, and its length is written in `scripts/room-sounds/README.md` (the step that says the winner's name places it after the fanfare). Nobody scored: no place, no roll, no fanfare.

5. **`join`** is the screen's only lobby sound: when a state lists a player the previous state did not, while no game is live (before the first game and between games), the screen plays one `join` — one for the state, however many joined in it. Never during a game, never on a phone, never for a player leaving or coming back (`present`), and never a name: the cue says that someone joined, not who.

### Long cues and the server's clock

6. **A long cue is placed on the server's clock.** `countdown`, `clock` and `roll` are scheduled to end at their moment — `opens_ms`, `bell_ms`, `winnerAt` — from the estimated server clock (`serverClock()`), never the device's. A page that hears its moment late starts the file from its middle by exactly how late it is — never faster, never squeezed — and plays nothing when less than `MIN_TAIL_MS` (250 ms) of it is left. The clock is a beat of the answer, heard ahead (`bell_ms − now ≤ CLOCK_MS + LEAD_MS`, `LEAD_MS` 500 ms, more than the page's 200 ms listening step), so it is scheduled to the sample rather than at the next listening step; a page opened during an answer with more than 5 s left hears it, one opened within its last 5 s does not.

7. **A cue stops when its moment ends.** Every cue belongs to the moment it was played for (the game, the round and its phase, `momentKey()`). When the page hears another moment — the round closed early, the host ended the game or closed the room, a new game began — every cue of the old one stops at once: one not yet started is dropped, one still sounding fades out within `STOP_MS` (40 ms, so it does not click). When the page is hidden, everything stops and nothing plays until it is shown again; nothing it missed is played then. The phone's own taps follow the same rule.

8. **Nothing else of 025 changes:** a cue plays only for a moment the page saw begin, and a reload, a late join or a state fetched again replays nothing (`roomCues()`'s first ear plays nothing); a moment heard more than `STALE_MS` after it began is joined silently; the screen's cues never read `reveal.mine`; a phone reads it only in the reveal.

### Sources, files and limits

9. **Recordings only under CC0-1.0 or CC-BY-4.0**, the license read on the source's own page, from a download that needs no login. Nothing CC-BY-SA, nothing under a site's own "royalty-free" terms (Pixabay, Mixkit, Zapsplat and the like), nothing of unknown license. Our own synthesis stays MIT.

10. **Every file can be made again.** `scripts/room-sounds/make.py` holds every recipe: it downloads each recording from a URL pinned to one version (a commit, a file's revision), checks its SHA-256, and trims, fades, pitches, mixes and levels it with ffmpeg and standard-library Python; the synthesis is seeded. Recordings are never committed: they are cached outside the repository. `scripts/room-sounds/README.md` lists each source (author, page, license, pinned URL) and what was done to it.

11. **Credits per source.** `content/credits.json` has one entry per source — its author, its page, its license and, in `changes`, what `make.py` does to it — listing the files that use it by name; a file mixed from a recording and our synthesis is listed under both.

12. **One loudness, a larger limit.** The set keeps one loudness, −20 LUFS while it sounds (a cue shorter than `LOOP_SECONDS` is measured played back to back, a longer one over its own length), true peak at most −1 dBFS. A cue that builds — the countdown, the clock, the roll — is levelled by its last second, the climax that leads into the next cue, so it never ends louder than the cue it leads into. A look-ahead limiter whose delay is compensated (so no cue moves in time) holds sample peaks at −3 dBFS while a cue reaches its loudness. The screen plays at full level, a phone at half (`SPEAKER_GAIN`). Files stay mono MP3 at 96 kb/s. `ContentValidator` allows a room sound up to 64 KB (`MAX_ROOM_SOUND_BYTES`, for the 5 s clock) and a speaker's whole set up to 320 KB for the screen and 64 KB for a phone (`MAX_ROOM_SPEAKER_BYTES`), so a phone that joins still fetches only a few short files.

## Consequences

- The room hears one designed start, a clock that rises in every answer's last seconds, and a final that builds to its first place; a phone stays as quiet as before.
- A screen fetches about 200–300 KB of sound once its sound is unlocked, instead of about 100 KB.
- Regenerating the set needs the network once (the pinned recordings) and ffmpeg; the files themselves never change unless a number in `make.py` or a pinned source does.
- A long cue on a page that is late or sleeps loses its beginning, and stops when its moment ends; that is the price of never playing a moment out of time.

## Alternatives rejected

- **A spoken countdown** ("üç, iki, bir") or a voiced «Başla»: the owner's decision 3; it would also bring voice into rooms, which 025 keeps out.
- **A tick played at each second** (025's way) for the clock: each tick lands at the next listening step, up to 200 ms late and uneven; one file on the server's clock is exact and can rise as it runs.
- **A clock for the whole answer** or a music bed under the rounds: 10–50 s of sound in every round tires a room; 025 rejected a bed, and the last 5 s carry the tension.
- **A clock that changes when the viewer or the room answers:** it would tell something about answers before the reveal.
- **Recordings from sites with their own licenses or behind a login:** the license is not one §8 allows, or cannot be read and checked by anyone who regenerates the files.
- **Committing the source recordings:** megabytes of WAV for a few seconds of sound; a pinned URL and a checksum reproduce them.

## What would make us revisit

A room that finds the clock too tense or too frequent (a shorter `CLOCK_MS`, or a mute for it alone), rooms played without a screen often enough that phones should carry the countdown and the clock, or a source whose pinned URL disappears (then its recording is replaced, re-auditioned and credited again).

## As chosen (2026-11-18)

**The audition.** Three candidates for each of the sixteen cues — forty-eight in all, levelled alike — were made outside the repository (`~/elifba-shots/room-sounds-audition/`): our own synthesis, and recordings of the VS Chamber Orchestra: Community Edition (VSCO-2-CE: CC0-1.0, read in the repository's `LICENSE`, downloaded without a login from commit `440300901dfe`). One self-contained page (no network) played each candidate alone, beside the first set's file where there was one («İndiki»), and in a game's order with the chosen candidates: the lobby's joins → the countdown → `go`; a question → the clock → the bell → the ranking; the same round closed early, the clock stopping; a memory round; a final of five players; a phone's taps, «sent» and verdict at half level. Its «Seçimlərimi kopyala» gave the owner's choice as text.

**The owner's choice** (the page's text, pasted by the owner into another session of the project and passed on to this one): every cue chosen; the three new moments — `go`, `place`, `join` — all kept, none declined; `ranking` and `sent` kept from the first set («İndiki»); and **no clock on a phone**, even in a room played without a screen (decision 3 stands). A recommended set noted while the choice was pending (orchestral throughout) was replaced by it.

| Cue         | Device | When                                               | Made of                                                                                                                                  | Source, license    | Length | Size    |
| ----------- | ------ | -------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ------------------ | ------ | ------- |
| `countdown` | screen | the countdown before round 0, ending at `opens_ms` | a marimba G5 over a low C4 on each second, a glockenspiel run climbing through the last second, in a small room                          | VSCO-2-CE, CC0-1.0 | 3.00 s | 35.8 KB |
| `go`        | screen | round 0 opens                                      | a cymbal crash, a timpani C2, a bass drum and a short trumpet chord C4–E4–G4–C5                                                          | VSCO-2-CE, CC0-1.0 | 1.50 s | 18.4 KB |
| `study`     | screen | a later round's study opens                        | a harp C5–E5–G5–C6, quickly up                                                                                                           | VSCO-2-CE, CC0-1.0 | 1.00 s | 12.6 KB |
| `question`  | screen | a question opens (round 0's after its study)       | a quick zip 600 → 1200 Hz into a marimba-like D6, in a small room                                                                        | our synthesis, MIT | 0.55 s | 7.3 KB  |
| `clock`     | screen | the last 5 s of an answer, ending at `bell_ms`     | tick–tock on two log drums twice a second, four times a second in the last 3 s, over a soft bass-drum pulse and a cello tremolo swelling | VSCO-2-CE, CC0-1.0 | 5.00 s | 59.4 KB |
| `close`     | screen | the round closes, whatever anyone answered         | a tubular bell, once, over a soft timpani                                                                                                | VSCO-2-CE, CC0-1.0 | 1.30 s | 15.9 KB |
| `ranking`   | screen | the reveal's ranking comes in                      | the first set's six plucks up C5–C6 over a breath of noise, unchanged                                                                    | our synthesis, MIT | 0.45 s | 6.1 KB  |
| `place`     | screen | the final's third and second places open           | an impact: a kick falling 110 → 38 Hz, a burst of noise, a small room                                                                    | our synthesis, MIT | 0.80 s | 10.1 KB |
| `roll`      | screen | the final, ending where the first place opens      | a snare roll and a timpani roll swelling together                                                                                        | VSCO-2-CE, CC0-1.0 | 2.50 s | 30.0 KB |
| `fanfare`   | screen | the first place opens (`winnerAt`)                 | trumpets ta-ta-ta on G4 into a held C major chord (trumpets G4–C5–E5, horn and trombone C4), a cymbal crash and a timpani                | VSCO-2-CE, CC0-1.0 | 2.30 s | 27.9 KB |
| `join`      | screen | between games, a player appears                    | a soft bubble pop, 380 → 1150 Hz                                                                                                         | our synthesis, MIT | 0.14 s | 2.4 KB  |
| `tap`       | phone  | the viewer's tap                                   | a clave, softly                                                                                                                          | VSCO-2-CE, CC0-1.0 | 0.08 s | 1.8 KB  |
| `undo`      | phone  | «Geri», or a cell tapped again                     | a soft «tock» played backwards                                                                                                           | our synthesis, MIT | 0.07 s | 1.5 KB  |
| `sent`      | phone  | the state says `answered`                          | the first set's airy swoosh, unchanged                                                                                                   | our synthesis, MIT | 0.24 s | 3.7 KB  |
| `right`     | phone  | the reveal, `reveal.mine.correct`                  | a harp C5–E5–G5, up                                                                                                                      | VSCO-2-CE, CC0-1.0 | 0.70 s | 8.9 KB  |
| `wrong`     | phone  | the reveal, `reveal.mine` not correct              | two round notes falling, E4 then C4                                                                                                      | our synthesis, MIT | 0.45 s | 6.1 KB  |

The screen's set holds 225.9 KB, a phone's 22.0 KB. Every file is made by `scripts/room-sounds/make.py` byte for byte as auditioned; `ranking` and `sent` by the first set's own recipe and levelling, so their files did not change. `content/rooms/sounds.json` is version 2. The fanfare lasts 2.30 s from `winnerAt` (`scripts/room-sounds/README.md`).

## Amended 2026-11-19 — a room without a screen

**Why.** Played on elifba.io with phones only, the countdown, the start, the clock and the final were never heard: the screen is the room's only speaker, and no screen was open. The owner decided: "ekransız otaqda telefon da geri sayım və saat çalsın, ümumiyyətlə böyük ekranda çalan bütün səslər" — in a room without a screen a phone plays every sound the screen would.

1. **A room has its screen while the screen reads.** The big screen reads the state at least every `elifba.rooms.screen.beat_ms` (10 s) while it is shown, and asks nothing while hidden; `RecordScreenRead` keeps its last read in `rooms.screen_seen_ms` (Unix ms, at most once a second, without the room's lock, the revision, a signal, `updated_at` or `last_activity_at`). A room whose screen has not read within three beats (`RoomScreen::goneMs()`, 30 s) — never opened, closed or hidden — has none. Derived, never stored: the state's `room.screen` (version 4).
2. **A phone carries the room without one.** While its latest state says `room.screen` false in an open room, a phone plays the room's cues as the screen would — the countdown, `go`, a study and a question opening, the clock, the close and the ranking, the final's places, roll and fanfare at the pace of the phone's own final, and `join` between games — beside its own (`carriesRoom()`, `roomCues()`), at a phone's level (`SPEAKER_GAIN.phone`) and under the phone's own mute. The winner's cheer comes with them, after the phone's own fanfare (ADR 030 as amended 2026-11-19). Every rule of the cues holds as written: once per moment the page saw begin, placed on the server's clock, stopped with their moment and when the page is hidden; nothing tells right from wrong before the reveal.
3. **The files.** A phone's page gets both sets in `sounds`; it fetches its own five at the first gesture and the room's eleven only once it carries the room (`carry()`), so a phone in a room with a screen still fetches only a few short files. Each speaker's limit in `ContentValidator` stands.
4. **With a screen nothing changes:** the screen is the room's speaker and a phone plays only its own quiet cues, the clock never on a phone. A screen that comes on mid-game takes over from the next moment a phone reads; one that goes is noticed within three beats.

**Rejected.** A host's switch «Səslər telefonda» (one more thing to remember for what the room can tell); a per-device toggle (every player would have to find it); the phone always carrying the room (ten phones and a screen out of step in one room).

## Amended 2026-11-21 — Freesound

**Why.** For the draughts `move` the owner chose by ear a real recording of a wooden chess piece on a wooden board (ADR 034 "Amended 2026-11-21"); VSCO-2-CE has no such recording, and the synthesised ones were turned down.

1. **Decision 9.** Freesound (<https://freesound.org>) is a source: a sound whose own page names CC0-1.0 or CC-BY-4.0 (never CC-BY-3.0, CC-BY-NC or Sampling+), downloaded without a login as the site's own preview (`https://cdn.freesound.org/previews/<first digits of the id>/<id>_<user id>-hq.mp3`).
2. **Decision 10.** A sound on Freesound is never replaced — a new upload is a new sound with a new id — so `make.py`'s `FREESOUND` table pins each one by its preview's file name and its SHA-256, as `VSCO` pins a file by its commit; the recording is cached outside the repository (`~/.cache/elifba/room-sounds/freesound/`) and the recipe names the exact samples it cuts.
3. **Decision 11.** Each Freesound sound is a source of its own in `content/credits.json`: its author's name on Freesound, its page, its license, and in `changes` what `make.py` does to it.
4. **Decision 12.** A recorded touch — all contact — may stay under −20 LUFS when the −3 dBFS limiter would change it to get there; `make.py` and the README say how far under (the draughts `move`: −21.1 LUFS).
