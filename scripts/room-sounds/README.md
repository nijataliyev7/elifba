# Room sounds

`content/assets/rooms/sounds/*.mp3` are the sounds of a room game (docs/architecture.md §12 "Room content", ADR 025 "Sound", ADR 028, ADR 034 decision 31): the big screen's — the room's speaker — and a phone's quiet cues of its own. The owner chose the first sixteen by ear on 2026-11-18 from three candidates (ADR 028 "As chosen"); a board game's four — `move`, `capture`, `crown` and `offer` — were taken on 2026-11-20 from three candidates each under the owner's standing rule ("Draughts (D4, 2026-11-20)" below), and the owner chose `move`, `capture` and `crown` by ear on 2026-11-21 ("Draughts: as chosen (2026-11-21)" below); a domino game's five — `tile`, `draw`, `knock`, `hand` and `turn` — the owner chose by ear on Y3's variant page on 2026-11-26 ("Domino «101»: as chosen (2026-11-27)" below). `make.py` makes every file: nine from recordings of the **VS Chamber Orchestra: Community Edition** (VSCO-2-CE, CC0-1.0), five from **Freesound** recordings (CC0-1.0), eleven by our own synthesis (MIT). `content/rooms/sounds.json` names one file per cue, `content/credits.json` credits each source, and the page decides when each plays (`resources/js/components/rooms/sound-cues.ts`).

| File            | Device | Plays                                                      | Made of                                                                                                                       | Length | Size    |
| --------------- | ------ | ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- | ------ | ------- |
| `countdown.mp3` | screen | the countdown before round 0, placed to end at `opens_ms`  | VSCO marimba G5 and C5 (an octave down) on each second, the glockenspiel pentatonic run through the last 0.95 s, a small room | 3.00 s | 35.8 KB |
| `go.mp3`        | screen | round 0 opens                                              | VSCO crash, timpani (C♯2 down to C2), bass drum, staccato trumpets C4–E4–G4–C5                                                | 1.50 s | 18.4 KB |
| `study.mp3`     | screen | a later round's study opens                                | VSCO harp C5–E5–G5 and B5 (up to C6), 55 ms apart                                                                             | 1.00 s | 12.6 KB |
| `question.mp3`  | screen | a question opens                                           | ours: a 600 → 1200 Hz zip into a marimba-like D6, a small room                                                                | 0.55 s | 7.3 KB  |
| `clock.mp3`     | screen | the last 5 s of an answer, placed to end at `bell_ms`      | VSCO log drums high/low every 0.5 s, every 0.25 s from 2 s; a soft bass drum each 0.5 s from 2 s; a cello tremolo C3 swelling | 5.00 s | 59.4 KB |
| `close.mp3`     | screen | the round closes, whatever anyone answered                 | VSCO tubular bell G4 over a soft timpani                                                                                      | 1.30 s | 15.9 KB |
| `ranking.mp3`   | screen | the reveal's ranking comes in                              | ours, the first set's: six plucks C5–C6 over a breath of noise                                                                | 0.45 s | 6.1 KB  |
| `place.mp3`     | screen | the final's third and second places open                   | ours: a kick 110 → 38 Hz, a burst of low noise, a small room                                                                  | 0.80 s | 10.1 KB |
| `roll.mp3`      | screen | the final, placed to end where the first place opens       | VSCO snare roll and timpani roll, swelling 22 and 27 dB                                                                       | 2.50 s | 30.0 KB |
| `fanfare.mp3`   | screen | the final's first place opens — **2.30 s** from `winnerAt` | VSCO trumpets: G4 ×3 (staccato), then G4, C5, E5 held, with horn and trombone C4, crash and timpani                           | 2.30 s | 27.9 KB |
| `join.mp3`      | screen | between games, a player appears                            | ours: a bubble pop, 380 → 1150 Hz                                                                                             | 0.14 s | 2.4 KB  |
| `move.mp3`      | screen | a board game: a move the page saw arrive                   | JJTaynos's recording (Freesound 733932): a wooden chess piece set down on a wooden board, one touch                           | 0.12 s | 2.1 KB  |
| `capture.mp3`   | screen | a board game: a move that took pieces, in place of `move`  | ours (D4's): the move's knock, then a lower, fuller one from 700 Hz 70 ms later over a short thud 130 → 62 Hz                 | 0.24 s | 3.7 KB  |
| `crown.mp3`     | screen | a board game: that move crowned a man, after its sound     | ours: a piece set on the man — two small wood touches 24 ms apart — and one small bell G6, a small room                       | 0.36 s | 4.9 KB  |
| `tile.mp3`      | screen | a domino game: a play the page saw arrive, a double's too  | Macif's recording (Freesound 329099): one domino put on a table                                                               | 0.17 s | 2.8 KB  |
| `draw.mp3`      | screen | a domino game: a seat drew from the boneyard               | UnycodeAdmin's recording (Freesound 705348): a tile taken up from the table                                                   | 0.45 s | 6.1 KB  |
| `knock.mp3`     | screen | a domino game: a seat passed                               | Yoshicakes77's recording (Freesound 645927): two knuckle knocks on a wooden table                                             | 0.30 s | 4.3 KB  |
| `hand.mp3`      | screen | a domino game: a hand ended and its sheet rises            | RobertWulfman's recording (Freesound 220839): dominoes shuffled about on a table                                              | 1.20 s | 14.7 KB |
| `tap.mp3`       | phone  | the viewer's tap, the same for every item                  | VSCO claves, low-passed at 6 kHz                                                                                              | 0.08 s | 1.8 KB  |
| `undo.mp3`      | phone  | «Geri», or a cell tapped again                             | ours: a 1450 → 1050 Hz «tock» played backwards                                                                                | 0.07 s | 1.5 KB  |
| `sent.mp3`      | phone  | the viewer's answer is in, the same for every answer       | ours, the first set's: noise rising through a band, 700 → 3600 Hz, and a soft click                                           | 0.24 s | 3.7 KB  |
| `right.mp3`     | phone  | from the reveal: the viewer's answer was right             | VSCO harp C5–E5–G5, 60 ms apart                                                                                               | 0.70 s | 8.9 KB  |
| `wrong.mp3`     | phone  | from the reveal: the viewer's answer was wrong             | ours: E4 then C4, round and low, never a buzzer                                                                               | 0.45 s | 6.1 KB  |
| `offer.mp3`     | phone  | a board game: a draw offer arrives at the viewer           | ours: two soft vibraphone-like notes up a fourth, E5 then A5, 110 ms apart, a small room                                      | 0.36 s | 4.9 KB  |
| `turn.mp3`      | phone  | a domino game: the viewer's own turn begins                | ours: a fingertip taps a tile twice — two small high touches 110 ms apart                                                     | 0.19 s | 3.1 KB  |

The screen's set holds 264.5 KB (at most 320 KB), a phone's 30.0 KB (at most 64 KB); no file above 64 KB (`ContentValidator`). A board game's `move`, `capture` and `crown` hold 10.7 KB together (at most 24 KB) and `offer` 4.9 KB (at most 8 KB), ADR 034 decision 31. No voice, no speech, no music bed and no loop: each file is one event, played once for its moment; the clock is the same for everyone, and `sent` says nothing of right or wrong.

The fanfare's length is `FANFARE_MS` in `resources/js/components/rooms/sound-cues.ts`: the screen's cheer — the winner's first name, then «Əla oynadın!» — starts there, after the fanfare (ADR 030). A new fanfare of another length changes that number in the same change. The cheer is not a room sound: the name list's clip (`content/voice-names/`) and the board's phrase (`content/assets/voice/`), ADR 016, never made here.

## Regenerate

You need Python 3 (standard library only), ffmpeg with `libmp3lame` (`brew install ffmpeg`) and, the first time, the network.

1. Listen first, outside the repository:

    ```sh
    python3 scripts/room-sounds/make.py --out /tmp/room-sounds
    ```

    The first run downloads the recordings it uses (about 45 MB) from VSCO-2-CE at commit `440300901dfe9275fd84e0b7763af1f8443ae62e` into `~/.cache/elifba/room-sounds` (`--cache` moves it) and checks each against its SHA-256 in `VSCO`; a file that does not match stops the run, and nothing of it is written. Recordings are never committed.

2. Write the files the pages play (`--only clock fanfare` makes only those):

    ```sh
    python3 scripts/room-sounds/make.py
    ```

    It prints each file's length, size, loudness and true peak. The synthesis is seeded and the MP3 encoder runs bit-exact, so the same numbers, recordings and ffmpeg give the same bytes.

3. Bump `version` in `content/rooms/sounds.json` (the URLs carry it, so open pages and caches fetch the new files), run `php artisan content:validate`, and `php artisan content:credits` when a source changed.

A cue changed by ear is auditioned again before it is committed (ADR 028 decision 1).

## Sources

**VS Chamber Orchestra: Community Edition** — recorded by Sam Gossner and Simon Dalzell (Versilian Studios), sample cutting by Elan Hickler (Soundemote); <https://github.com/sgossner/VSCO-2-CE>, CC0-1.0 (its `LICENSE`). `VSCO` in `make.py` names each file used, its path in the repository and its SHA-256: timpani hits (Timpani3 v1 and v3) and roll (Timpani2), snare roll (Snare2 rollSN v5), crash cymbal (crash1 ff), bass drum (BDrumNewhit v3, v5), tubular bell (G4), log drums (high and low, v2), claves, glockenspiel run (pentatonic, medium), marimba (the C4 and G4 samples, sounding C5 and G5), harp (C5, E5, G5, B5), cello section tremolo (C3), trumpet staccato (C3, D♯3, G3, A♯3) and sustained (G3, A♯3, D4), French horn (C3) and tenor trombone (C3). The library names its trumpets, horn, trombone and marimba an octave lower than they sound; the table above names what sounds.

**Freesound** — <https://freesound.org>, each sound under the license its own page names (ADR 028 as amended 2026-11-21). `FREESOUND` in `make.py` names each sound by its preview's file name and its SHA-256; the preview downloads without a login from `https://cdn.freesound.org/previews/<first digits of the id>/<file>`, and a sound on Freesound is never replaced (a new upload is a new id). Five so far: «Chess Pieces Move (Far)» by JJTaynos, <https://freesound.org/people/JJTaynos/sounds/733932/>, CC0-1.0 — `733932_15803939-hq.mp3`, SHA-256 `fe7c7d8db858edea34360ae2a79b70d6e4610e4598e15d52ad42f00208b08663` (mono, 48 kHz) — for `move`; and for a domino game, each CC0-1.0 by its own page: «Domino pieces 1» by Macif, <https://freesound.org/people/Macif/sounds/329099/> — `329099_3035766-hq.mp3`, `3dfdeabd0ef01cc12d3af6f10142c5e07ce9e4480e0c075d14db24bcc57375cb` — for `tile`; «domino_draw.mp3» by UnycodeAdmin, <https://freesound.org/people/UnycodeAdmin/sounds/705348/> — `705348_15297425-hq.mp3`, `1af8a11e4211e2321b201f19e5d04befe191cf774ef387145bb414360d488fcb` — for `draw`; «knocking.wav» by Yoshicakes77, <https://freesound.org/people/Yoshicakes77/sounds/645927/> — `645927_12983992-hq.mp3`, `dfa1c44a727a5dd711b060f22bb79a462748554beed6b884587fcf7421bc136b` — for `knock`; «Shuffling Dominos» by RobertWulfman, <https://freesound.org/people/RobertWulfman/sounds/220839/> — `220839_3908792-hq.mp3`, `854fb251baceffb38a794009fb216a28566f6207d44e6c3fe7ca2627f82d2314` — for `hand`.

What `make.py` does to a recording: mixes it to mono at 44.1 kHz; cuts it from its onset (the first sample above −36 dB of its peak, less 2 ms) or from a fixed point in a roll; normalises its peak; pitches some by resampling (`semitones`, which also shortens them a little); crops and fades it; shapes its level over time (`ramp`, in dB); filters some (low- and high-pass); layers them, some in a small synthetic room (`reverb`, a Freeverb); then levels and encodes as below.

## Levelling

- **Loudness.** `LOUDNESS` (−20 LUFS) for every file but a recorded touch the limiter would change to get there (`move`, −21.1 LUFS, "Draughts: as chosen" below; `tile` −20.7 and `knock` −20.6, "Domino «101»: as chosen" below), by ffmpeg's `ebur128`. A cue shorter than `LOOP_SECONDS` (4 s) is measured played back to back, "while it sounds". A cue that builds (`BUILDS`: countdown, clock, roll) is measured by its last second, looped — its climax, which leads into the next cue — so it never ends louder than what follows it.
- **Peaks.** A look-ahead limiter (`alimiter`, its delay compensated so no cue moves in time) holds sample peaks at `LIMIT_DB` (−3 dBFS) while a cue reaches its loudness; a true peak above `MAX_TRUE_PEAK` (−1 dBFS) after encoding lowers the limiter by 1 dB and tries again.
- **Encoding.** 16-bit mono WAV peak-normalised to −6 dBFS with a 6 ms fade at its end, then 96 kb/s mono MP3 without tags, up to four passes until the loudness is within 0.05 LU.
- **Kept from the first set.** `ranking` and `sent` are the first set's files (ADR 025): their recipes, their seeded noise and their levelling (three passes, no limiter) are kept as `a48e56c` made them, so the files do not change.
- **On the device.** How loud a device plays them is the page's (`SPEAKER_GAIN` in `resources/js/rooms/sound.ts`: the screen at full level, a phone at half), never the files'.

## The set as made (2026-11-18; a board game's four 2026-11-20, three of them again 2026-11-21; a domino game's five 2026-11-27)

| File            | Length  | Size    | Loudness                     | True peak |
| --------------- | ------- | ------- | ---------------------------- | --------- |
| `countdown.mp3` | 3000 ms | 35.8 KB | −20.0 LUFS (its last second) | −3.3 dBFS |
| `go.mp3`        | 1500 ms | 18.4 KB | −20.0 LUFS                   | −3.1 dBFS |
| `study.mp3`     | 1000 ms | 12.6 KB | −20.0 LUFS                   | −6.8 dBFS |
| `question.mp3`  | 550 ms  | 7.3 KB  | −20.0 LUFS                   | −7.3 dBFS |
| `clock.mp3`     | 5000 ms | 59.4 KB | −20.0 LUFS (its last second) | −3.4 dBFS |
| `close.mp3`     | 1300 ms | 15.9 KB | −20.0 LUFS                   | −3.2 dBFS |
| `ranking.mp3`   | 450 ms  | 6.1 KB  | −20.0 LUFS                   | −6.2 dBFS |
| `place.mp3`     | 800 ms  | 10.1 KB | −20.0 LUFS                   | −3.4 dBFS |
| `roll.mp3`      | 2500 ms | 30.0 KB | −20.0 LUFS (its last second) | −5.8 dBFS |
| `fanfare.mp3`   | 2300 ms | 27.9 KB | −20.0 LUFS                   | −6.8 dBFS |
| `join.mp3`      | 140 ms  | 2.4 KB  | −20.0 LUFS                   | −9.4 dBFS |
| `move.mp3`      | 122 ms  | 2.1 KB  | −21.1 LUFS                   | −3.3 dBFS |
| `capture.mp3`   | 240 ms  | 3.7 KB  | −20.1 LUFS                   | −3.4 dBFS |
| `crown.mp3`     | 360 ms  | 4.9 KB  | −20.2 LUFS                   | −3.4 dBFS |
| `tap.mp3`       | 80 ms   | 1.8 KB  | −20.3 LUFS                   | −6.6 dBFS |
| `undo.mp3`      | 70 ms   | 1.5 KB  | −20.0 LUFS                   | −8.2 dBFS |
| `sent.mp3`      | 240 ms  | 3.7 KB  | −20.0 LUFS                   | −4.1 dBFS |
| `right.mp3`     | 700 ms  | 8.9 KB  | −20.0 LUFS                   | −6.7 dBFS |
| `wrong.mp3`     | 450 ms  | 6.1 KB  | −20.0 LUFS                   | −9.0 dBFS |
| `offer.mp3`     | 360 ms  | 4.9 KB  | −20.0 LUFS                   | −9.5 dBFS |
| `tile.mp3`      | 170 ms  | 2.8 KB  | −20.7 LUFS                   | −2.7 dBFS |
| `draw.mp3`      | 450 ms  | 6.1 KB  | −20.0 LUFS                   | −3.2 dBFS |
| `knock.mp3`     | 300 ms  | 4.3 KB  | −20.6 LUFS                   | −3.4 dBFS |
| `hand.mp3`      | 1200 ms | 14.7 KB | −20.1 LUFS                   | −2.7 dBFS |
| `turn.mp3`      | 190 ms  | 3.1 KB  | −20.0 LUFS                   | −3.3 dBFS |

## Draughts (D4, 2026-11-20)

ADR 034 decision 31 adds four cues for a board game. No one had listened to them then: under the owner's standing rule this step made three candidates of each outside the repository, with `make.py`'s own parts and levelling, compared them by their numbers — length, size, loudness, true peak, the envelope every 10 ms, the spectrum's centroid and bands, the strongest frequency every 15 ms — and by their waveforms and spectrograms, and kept its recommended one. A match's cues sound among the set's: the clock's log-drum tick is near 500 Hz and stops at a move, a phone's clave `tap` is near 2 kHz, and the same players hear the countdown's marimba and `wrong`'s falling round notes.

| Cue       | Candidates                                                                                                                                                                                   | Kept, and why                                                                                                                                                                                                                                                                                                                        |
| --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `move`    | a: VSCO claves nine semitones down, dried; b: ours, damped wooden modes, a board's body and a click; c: VSCO high log drum, its strike only                                                  | b: a dry knock centred near 0.8 kHz — between the clock's tick and the tap — that falls 20 dB in 55 ms with nothing ringing after it, so a hundred of them never tire; a was the tap's own clave a little lower, with the recording's room coming back at 40 ms; c was the clock's own tick at 495 Hz                                |
| `capture` | a: ours, the move's knock and a lower, fuller one 70 ms later over a low thud; b: VSCO claves twice over a soft bass drum; c: ours, one heavier knock with a thud and a small knock after it | a: two onsets 70 ms apart, plain in its envelope, and a quarter of its energy below 150 Hz where the move has none — the move's board struck harder, never mistaken for it; b was the tap's clave again; c had one onset and read as a louder move                                                                                   |
| `crown`   | a: ours, four small bells up C6–E6–G6–C7; b: VSCO marimba pitched up to C6–E6–G6; c: VSCO glockenspiel run, 0.45 s from 1 s into it                                                          | a: bright (centroid 1.9 kHz), rising step by step in its pitch track, a true peak of −10.4 dBFS and half a second — a flourish, not the final's fanfare; b was the countdown's marimba with 9 % of its energy above 6 kHz from being pitched up 19 semitones; c did not climb within its window and stopped in the middle of the run |
| `offer`   | a: ours, two round notes up a fourth, A5 then D6; b: ours, two soft vibraphone-like notes up a fourth, E5 then A5; c: VSCO marimba G5 then C6                                                | b: a slow 8 ms attack, a crest of 10 dB and all its energy between 400 Hz and 1 kHz, rising, in a timbre no other cue has — a notice, never an alarm; a was `wrong`'s own round notes turned upward; c opened on the countdown's very marimba G5                                                                                     |

The synthesised candidates moved once after their first numbers, before the choice: the wooden modes from 520 to 820 Hz (520 Hz sat on the clock's tick), the crown from G5–G6 up to C6–C7, and the vibraphone from C6–E6 down to E5–A5 (off the crown's notes); the move's modes then decay in 22 ms, not 16, so the limiter lets it reach −20.0 LUFS. All four are our synthesis, credited with the rest of it. A cue the owner changes by ear later is a new audition of that cue (ADR 028). `move`, `capture` and `crown` were auditioned again the next day, below; the table above is D4's.

## Draughts: as chosen (2026-11-21)

Played on elifba.io, the owner asked for a move that sounds like a real piece put down on a wooden board, calm and pleasant to the ear (ADR 034 "As built (D6)" item 4). A first variant page offered three synthesised candidates of each cue; with no choice saved, its recommended ones (our `board()` and `stack()`) were built and deployed, and the owner turned them down: «səs efektlərini bəyənmədim … mən seçim səsləri». A second page (a private Artifact, made outside the repository) played real recordings — eight families from Freesound, CC0-1.0 or CC-BY-4.0, each the same piece on the same board giving a move (one clean touch), a capture (a light touch and a fuller landing 95 ms later) and a crown (the touch four semitones up, twice 26 ms apart) — beside D4's and the turned-down syntheses, at one loudness, alone, six in a row and in a sample game at `moveCues()`' timing. The owner chose `move=b; capture=s0; crown=z`, and no `tap` in a match.

| Cue       | As chosen                                                                                                                                                                                                                         | Length, size, loudness, true peak     | SHA-256                                                            |
| --------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------- | ------------------------------------------------------------------ |
| `move`    | «b»: JJTaynos's recording (Freesound 733932, CC0-1.0), a wooden chess piece set down on a wooden board a little way from the microphone — one touch from sample 633 336 to 638 707 (14.36 s), below 70 Hz and above 9 kHz removed | 122 ms, 2.1 KB, −21.1 LUFS, −3.3 dBFS | `7614b2b72994727428588d95038b5594739100b255d058a1abd2f129aad80723` |
| `capture` | «s0»: D4's synthesis again — `wood()` twice, 70 ms apart, over a low thud                                                                                                                                                         | 240 ms, 3.7 KB, −20.1 LUFS, −3.4 dBFS | `d08722584189020ca3239106d4bacd3a8f9e531e133844153321d73892706edb` |
| `crown`   | «z»: ours — `stack()`, a piece set on the man, two small wood touches 24 ms apart, then one small bell G6 (the `GLOCK` partials), softly, in a small room                                                                         | 360 ms, 4.9 KB, −20.2 LUFS, −3.4 dBFS | `1f5c6e057eadf68ac9e3bd45af99a9fed3909f736555a538113a5d745b04359a` |

- `capture` is the D4 file byte for byte and `crown` the auditioned file byte for byte. `move` is `make.py`'s recipe of the auditioned cut (its filters are two biquads each, where the page's were SciPy's): the two differ by a residual 50 dB under the signal.
- `move` stays at −21.1 LUFS, 1 LU under the set (ADR 028 decision 12 as amended 2026-11-21): a recorded touch is all contact, and the −3 dBFS limiter changed the touch itself (a residual of −18 dB) when it was pushed to −20.
- No `tap` in a match: a seat's lift and landing are silent (`tapSquare()` in `components/rooms/draughts/taps.ts`); a tap that takes the path back and «Geri» play `undo`. Every other room game keeps `tap`; its candidates (a light wooden touch, the move's board smaller, a fingertip, a small drop, a wooden touch of ours, and «Səssiz») wait on the same page for the owner's ear.
- `sounds.json` is version 5, so no device keeps the earlier files.

## Domino «101»: as chosen (2026-11-27)

ADR 038 decision 53 left a domino game's six slots to the owner's ear, and nothing was built for a slot before the choice. Y3's variant page (a private Artifact, made outside the repository) offered each slot real recordings from Freesound (CC0-1.0, 19 recordings in all, each preview pinned by SHA-256), our own syntheses, the room's existing cues and «none», alone and in hands played at the page's timing; every candidate was this script's own recipe, levelled and encoded by `encode()`. The owner saved `qoy=a; qosa=eyni; pas=a; bazar=a; elsonu=b; novbe=s2` on 2026-11-26 (ADR 038 "Amended 2026-11-27 (Y6)", decision 90): «qoşa» is «eyni» — a double plays `tile` — so five files are made, not six.

| Cue     | As chosen                                                                                                                          | Length, size, loudness, true peak       | SHA-256                                                            |
| ------- | ---------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------- | ------------------------------------------------------------------ |
| `tile`  | «qoy» a: Macif, «Domino pieces 1» (Freesound 329099) — one put-down, 170 ms from 16.707 s                                          | 170 ms, 2.8 KB, −20.7 LUFS, −2.7 dBFS   | `7381b78668dc66e5268e368c690623fc4926888a1f29144295aad15c0f376406` |
| `draw`  | «bazar» a: UnycodeAdmin, «domino_draw» (705348) — 0.30–0.75 s, an 80 ms fade out                                                   | 450 ms, 6.1 KB, −20.0 LUFS, −3.2 dBFS   | `3052b3b97b6ba10a6ee93139c9bfe846f818f4c549677e216b567736d1e5dfa1` |
| `knock` | «pas» a: Yoshicakes77, «knocking» (645927) — two knocks as they fell, 300 ms from 0.695 s, a 50 ms fade out                        | 300 ms, 4.3 KB, −20.6 LUFS, −3.4 dBFS   | `3ad55fb22d35c70ceedcd7b808226389e5518e0a8bfe3568a951643595a5585d` |
| `hand`  | «əl sonu» b: RobertWulfman, «Shuffling Dominos» (220839) — 1.2 s from 1.77 s, a 50 ms fade in and a 350 ms fade out                | 1200 ms, 14.7 KB, −20.1 LUFS, −2.7 dBFS | `029ab0fe1147ef8997f2b9629796453870b05a84538140e5ae0665bba6482917` |
| `turn`  | «növbə» s2: ours — `tile()` (sixteen modes 600–4200 Hz, loudest near 2.8 kHz, no table under it), 80 ms, twice 110 ms apart at 0.7 | 190 ms, 3.1 KB, −20.0 LUFS, −3.3 dBFS   | `037218a4c83b8c8592985a45606744a2a9bfd8679cd2515c937331341b90a987` |

- Every file is the auditioned one byte for byte: the recipes are the page's own text (`cut()`, `twice()` and `tile()` with them), and two runs of `make.py` give the same bytes.
- Each recording is cut below 70 Hz and above 9 kHz (a fourth-order Butterworth each, settled over the half second before the cut), faded in over 1 ms and out over 35 ms unless the table says otherwise, then levelled with the set. `tile` and `knock` stay under −20 LUFS as `move` does: a recorded touch is all contact.
- `tile`, `draw`, `knock` and `hand` are the room's (the screen, or every phone in a room without one); `turn` is the seat to move's own phone's, with a screen too. When each plays is `roomCues()`', timed by the turn's one timeline (`turnSteps()` in `resources/js/components/rooms/domino/turn.ts`).
- `sounds.json` is version 6.
