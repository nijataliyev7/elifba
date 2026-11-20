# Room sounds

`content/assets/rooms/sounds/*.mp3` are the sounds of a room game (docs/architecture.md §12 "Room content", ADR 025 "Sound", ADR 028, ADR 034 decision 31): the big screen's — the room's speaker — and a phone's quiet cues of its own. The owner chose the first sixteen by ear on 2026-11-18 from three candidates (ADR 028 "As chosen"); a board game's four — `move`, `capture`, `crown` and `offer` — were taken on 2026-11-20 from three candidates each under the owner's standing rule ("Draughts (D4, 2026-11-20)" below), and `move`, `capture` and `crown` were made again on 2026-11-21 as chosen from the draughts variant page ("Draughts: as chosen (2026-11-21)" below). `make.py` makes every file: nine from recordings of the **VS Chamber Orchestra: Community Edition** (VSCO-2-CE, CC0-1.0), eleven by our own synthesis (MIT). `content/rooms/sounds.json` names one file per cue, `content/credits.json` credits each source, and the page decides when each plays (`resources/js/components/rooms/sound-cues.ts`).

| File            | Device | Plays                                                      | Made of                                                                                                                        | Length | Size    |
| --------------- | ------ | ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ | ------ | ------- |
| `countdown.mp3` | screen | the countdown before round 0, placed to end at `opens_ms`  | VSCO marimba G5 and C5 (an octave down) on each second, the glockenspiel pentatonic run through the last 0.95 s, a small room  | 3.00 s | 35.8 KB |
| `go.mp3`        | screen | round 0 opens                                              | VSCO crash, timpani (C♯2 down to C2), bass drum, staccato trumpets C4–E4–G4–C5                                                 | 1.50 s | 18.4 KB |
| `study.mp3`     | screen | a later round's study opens                                | VSCO harp C5–E5–G5 and B5 (up to C6), 55 ms apart                                                                              | 1.00 s | 12.6 KB |
| `question.mp3`  | screen | a question opens                                           | ours: a 600 → 1200 Hz zip into a marimba-like D6, a small room                                                                 | 0.55 s | 7.3 KB  |
| `clock.mp3`     | screen | the last 5 s of an answer, placed to end at `bell_ms`      | VSCO log drums high/low every 0.5 s, every 0.25 s from 2 s; a soft bass drum each 0.5 s from 2 s; a cello tremolo C3 swelling  | 5.00 s | 59.4 KB |
| `close.mp3`     | screen | the round closes, whatever anyone answered                 | VSCO tubular bell G4 over a soft timpani                                                                                       | 1.30 s | 15.9 KB |
| `ranking.mp3`   | screen | the reveal's ranking comes in                              | ours, the first set's: six plucks C5–C6 over a breath of noise                                                                 | 0.45 s | 6.1 KB  |
| `place.mp3`     | screen | the final's third and second places open                   | ours: a kick 110 → 38 Hz, a burst of low noise, a small room                                                                   | 0.80 s | 10.1 KB |
| `roll.mp3`      | screen | the final, placed to end where the first place opens       | VSCO snare roll and timpani roll, swelling 22 and 27 dB                                                                        | 2.50 s | 30.0 KB |
| `fanfare.mp3`   | screen | the final's first place opens — **2.30 s** from `winnerAt` | VSCO trumpets: G4 ×3 (staccato), then G4, C5, E5 held, with horn and trombone C4, crash and timpani                            | 2.30 s | 27.9 KB |
| `join.mp3`      | screen | between games, a player appears                            | ours: a bubble pop, 380 → 1150 Hz                                                                                              | 0.14 s | 2.4 KB  |
| `move.mp3`      | screen | a board game: a move the page saw arrive                   | ours, as chosen: a wooden piece set down on a wooden board — sixteen board modes 320–2800 Hz, a 1.2 ms contact, a 160 Hz table | 0.13 s | 2.1 KB  |
| `capture.mp3`   | screen | a board game: a move that took pieces, in place of `move`  | ours, as chosen: the move's touch, then a lower, fuller landing 90 ms later — the board's modes 230–2000 Hz, a 125 Hz table    | 0.26 s | 3.7 KB  |
| `crown.mp3`     | screen | a board game: that move crowned a man, after its sound     | ours, as chosen: a piece set on the man, as a board crowns by hand — two small wood touches 700–3200 Hz, 24 ms apart           | 0.14 s | 2.4 KB  |
| `tap.mp3`       | phone  | the viewer's tap, the same for every item                  | VSCO claves, low-passed at 6 kHz                                                                                               | 0.08 s | 1.8 KB  |
| `undo.mp3`      | phone  | «Geri», or a cell tapped again                             | ours: a 1450 → 1050 Hz «tock» played backwards                                                                                 | 0.07 s | 1.5 KB  |
| `sent.mp3`      | phone  | the viewer's answer is in, the same for every answer       | ours, the first set's: noise rising through a band, 700 → 3600 Hz, and a soft click                                            | 0.24 s | 3.7 KB  |
| `right.mp3`     | phone  | from the reveal: the viewer's answer was right             | VSCO harp C5–E5–G5, 60 ms apart                                                                                                | 0.70 s | 8.9 KB  |
| `wrong.mp3`     | phone  | from the reveal: the viewer's answer was wrong             | ours: E4 then C4, round and low, never a buzzer                                                                                | 0.45 s | 6.1 KB  |
| `offer.mp3`     | phone  | a board game: a draw offer arrives at the viewer           | ours: two soft vibraphone-like notes up a fourth, E5 then A5, 110 ms apart, a small room                                       | 0.36 s | 4.9 KB  |

The screen's set holds 234.2 KB (at most 320 KB), a phone's 26.9 KB (at most 64 KB); no file above 64 KB (`ContentValidator`). A board game's `move`, `capture` and `crown` hold 8.3 KB together (at most 24 KB) and `offer` 4.9 KB (at most 8 KB), ADR 034 decision 31. No voice, no speech, no music bed and no loop: each file is one event, played once for its moment; the clock is the same for everyone, and `sent` says nothing of right or wrong.

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

What `make.py` does to a recording: mixes it to mono at 44.1 kHz; cuts it from its onset (the first sample above −36 dB of its peak, less 2 ms) or from a fixed point in a roll; normalises its peak; pitches some by resampling (`semitones`, which also shortens them a little); crops and fades it; shapes its level over time (`ramp`, in dB); filters some (low- and high-pass); layers them, some in a small synthetic room (`reverb`, a Freeverb); then levels and encodes as below.

## Levelling

- **Loudness.** `LOUDNESS` (−20 LUFS) for every file, by ffmpeg's `ebur128`. A cue shorter than `LOOP_SECONDS` (4 s) is measured played back to back, "while it sounds". A cue that builds (`BUILDS`: countdown, clock, roll) is measured by its last second, looped — its climax, which leads into the next cue — so it never ends louder than what follows it.
- **Peaks.** A look-ahead limiter (`alimiter`, its delay compensated so no cue moves in time) holds sample peaks at `LIMIT_DB` (−3 dBFS) while a cue reaches its loudness; a true peak above `MAX_TRUE_PEAK` (−1 dBFS) after encoding lowers the limiter by 1 dB and tries again.
- **Encoding.** 16-bit mono WAV peak-normalised to −6 dBFS with a 6 ms fade at its end, then 96 kb/s mono MP3 without tags, up to four passes until the loudness is within 0.05 LU.
- **Kept from the first set.** `ranking` and `sent` are the first set's files (ADR 025): their recipes, their seeded noise and their levelling (three passes, no limiter) are kept as `a48e56c` made them, so the files do not change.
- **On the device.** How loud a device plays them is the page's (`SPEAKER_GAIN` in `resources/js/rooms/sound.ts`: the screen at full level, a phone at half), never the files'.

## The set as made (2026-11-18; a board game's four 2026-11-20, three of them again 2026-11-21)

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
| `move.mp3`      | 130 ms  | 2.1 KB  | −20.0 LUFS                   | −3.4 dBFS |
| `capture.mp3`   | 260 ms  | 3.7 KB  | −20.1 LUFS                   | −3.4 dBFS |
| `crown.mp3`     | 140 ms  | 2.4 KB  | −20.2 LUFS                   | −3.3 dBFS |
| `tap.mp3`       | 80 ms   | 1.8 KB  | −20.3 LUFS                   | −6.6 dBFS |
| `undo.mp3`      | 70 ms   | 1.5 KB  | −20.0 LUFS                   | −8.2 dBFS |
| `sent.mp3`      | 240 ms  | 3.7 KB  | −20.0 LUFS                   | −4.1 dBFS |
| `right.mp3`     | 700 ms  | 8.9 KB  | −20.0 LUFS                   | −6.7 dBFS |
| `wrong.mp3`     | 450 ms  | 6.1 KB  | −20.0 LUFS                   | −9.0 dBFS |
| `offer.mp3`     | 360 ms  | 4.9 KB  | −20.0 LUFS                   | −9.5 dBFS |

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

Played on elifba.io, the owner asked for a move that sounds like a real piece put down on a wooden board, calm and pleasant to the ear (ADR 034 "As built (D6)" item 4). A variant page outside the repository — a private Artifact with the board, its pieces and their motion — played today's file and three candidates of each of `move`, `capture` and `crown` beside a sample game at `moveCues()`' real timing, each made by `make.py`'s own parts and levelling and measured as D4 measured them. The owner had saved no choice when the build began, so its recommended line was taken under the owner's standing rule (ADR 034 "Amended 2026-11-21"); all three are our synthesis — `board()` (a board's dense, seeded, inharmonic modes, loudest near a centre, a soft contact, a table under it; no mode rings on, so no pitch) and `stack()` (a piece set on another) — and replace D4's `wood()`, `WOOD` and `GLOCK`. The files are the auditioned candidates byte for byte (SHA-256 below). `sounds.json` is version 4, so every URL changes (`?v=4`) and no device keeps the old files.

| Cue       | Candidates (made, measured, played on the variant page)                                                                                                                                                 | As chosen, and why                                                                                                                                                                                                                                                                                                                                                                                          |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `move`    | A «Taxta lövhə»: ours, sixteen board modes 320–2800 Hz and a 160 Hz table; B «Keçəli daş»: ours, a felt-bottomed piece, low-passed at 2 kHz; C «Qeyd + taxta»: a VSCO wood click over A's board         | A: a soft contact (attack 4 ms, D4's 1 ms) that falls 20 dB in 25 ms and 40 dB in 59 ms (D4: 47 and 88) with nothing ringing — the strongest frequency moves frame to frame, where D4's held 818 Hz — centred at 744 Hz between the clock's tick and the tap, 2 % above 1.6 kHz; B's centroid (496 Hz) sat on the clock's tick and read dull; C was almost A, with a recording's room under a hundred moves |
| `capture` | A «Tuk–tuk»: ours, A's touch and a lower, fuller landing 90 ms later; B «Tuk + süpürmə»: A's touch and the taken piece swept off (noise 1.6 → 0.6 kHz); C «Qeyd: iki toxunuş»: A with recorded contacts | A: two onsets 90 ms apart at the move's loudness and true peak, told apart from a move by its rhythm and a third of its energy below 400 Hz, never by being louder; B was the most physical but 1 LU quieter and noise under a hundred moves; C was A with a recording's room                                                                                                                               |
| `crown`   | A «Daş daşın üstünə»: ours, the crowning piece set on the man, two touches 24 ms apart; B «Qeyd: daş üstünə»: the VSCO wood click twice; C: A and one small bell G6 in a small room                     | A: brighter than the move (centroid 1.28 kHz), 140 ms, −40 dB in 67 ms, nothing ringing — played 120 ms after the landing, the ear hears «tuk … tık-tık», a piece crowned by hand rather than a flourish; B was a woodblock's one pitch (96 % in one band); C rang to −40 dB at 287 ms, the nearest to D4's bells                                                                                           |

| File          | D4 (2026-11-20)                                           | As chosen (2026-11-21)                                    | SHA-256                                                            |
| ------------- | --------------------------------------------------------- | --------------------------------------------------------- | ------------------------------------------------------------------ |
| `move.mp3`    | 110 ms, 2.1 KB, −20.0 LUFS, −3.4 dBFS; centroid 791 Hz    | 130 ms, 2.1 KB, −20.0 LUFS, −3.4 dBFS; centroid 744 Hz    | `924b646681bef62d02d763863ec3cea1297dff4e1d290b01efa8ea027632a102` |
| `capture.mp3` | 240 ms, 3.7 KB, −20.1 LUFS, −3.4 dBFS; onsets 70 ms apart | 260 ms, 3.7 KB, −20.1 LUFS, −3.4 dBFS; onsets 90 ms apart | `158194a7cc32e07b6265393a38fb3e7850cb8c4929e5abe66189bea4edcb27a2` |
| `crown.mp3`   | 500 ms, 6.7 KB, −20.0 LUFS, −10.4 dBFS; centroid 2.05 kHz | 140 ms, 2.4 KB, −20.2 LUFS, −3.3 dBFS; centroid 1.28 kHz  | `7a64f9c1227743675221c324af26cf8abd3b29e133203ba4fd336e9d7eb18e9d` |

Looked at and not used: Kenney's _Impact Sounds_ and _Casino Audio_ (CC0 on their own pages) — their download is a signed link minted per request, not a URL pinned to one version, so they fail ADR 028 decision 10; Wikimedia Commons held no piece or board recording; VSCO's own wood clicks (the pinned commit, `VSCO 1 Percussion/varWood/wood_click_pp_{1,2,3}.wav`) were the recorded candidates C and B and were not taken, so `VSCO` names no new file. The phone's own «lift» cue the variant page proposed — a small wood touch in place of `tap` when a seat lifts a piece — was not taken: `tap` stays, and a new cue would be a `RoomSound` case and a line of ADR 034. `moveCues()` places the three as before: `move` or `capture` as the piece lands (`slideOf().ms` after the arrival), `crown` 120 ms after it.
