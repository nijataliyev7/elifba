# Train sounds

`content/assets/sounds/train-arrive.mp3` and `train-leave.mp3` are the `train` pack's `arrive` and `leave` sounds (docs/architecture.md §3 "Sounds", §8): a friendly toy steam train, synthesised by `train.py` and downloaded from nowhere. They are Elifba's own, MIT, credited in `content/credits.json` with the celebrations.

## Regenerate

You need Python 3 (standard library only) and ffmpeg with `libmp3lame` (`brew install ffmpeg`).

1. Listen first, outside the repository:

    ```sh
    python3 scripts/sounds/train.py --out /tmp/train-sounds
    ```

2. Write the files the board plays:

    ```sh
    python3 scripts/sounds/train.py
    ```

    It prints each file's length, loudness and true peak, and stops when a peak is above `MAX_TRUE_PEAK`. The noise is seeded, so the same numbers give the same files.

3. Bump the `train` pack's `version` (its sound URLs carry it, so open boards and caches fetch the new files), then run `php artisan content:validate`.

## What each number does

Everything to retune is a constant at the top of `train.py`.

- **Loudness.** `LOUDNESS` (−20 LUFS, EBU R128 integrated) sets both files. The celebrations (`bells`, `marimba`, `harp`, `kalimba`) and the jingles sit at about −13.5 LUFS; the train stays well below them, because `leave` plays under `bells` when a round is solved. Louder or quieter: change this one number.
- **Whistle («tu-tuu»).** `WHISTLE_CHORD` (D5, F♯5, A5: three pipes, a major chord, so it sounds like a toy and not a real locomotive), `WHISTLE_NOTES` (when each of the two notes starts and how long it lasts) and `WHISTLE_GAIN` (how loud it is against the chuffs). Only `train-arrive` whistles.
- **Chuffs.** Each puff of steam is noise through a low band (`BODY_BAND`, the "ch" body) and a high band (`HISS_BAND`), with `BODY_DECAY`, `HISS_DECAY` and `CHUFF_ATTACK`. `ACCENTS` makes every fourth chuff the strongest, as a turning wheel does; `CLACK_GAIN` is the wheels' tick on a rail joint (0 for none).
- **Arriving** (`ARRIVE_*`, 1.45 s): the chuffs start `ARRIVE_FIRST_GAP` apart and each gap is `ARRIVE_SLOWING` times the one before, so the train slows down as the wagons roll in (1.3 s, `Cubic.easeOut`); at `ARRIVE_HISS` it lets off steam.
- **Leaving** (`LEAVE_*`, 1.25 s): a puff of steam, then chuffs whose gaps shrink by `LEAVE_QUICKENING` down to `LEAVE_SHORTEST_GAP`, louder at first and fading from `LEAVE_FADE_FROM` as the train goes away (the wagons roll off in 1.1 s, `Cubic.easeIn`).
- **Shape.** `SOFTEN` rounds off the puffs' peaks so the files reach their loudness with room under 0 dBFS; `FADE_OUT` is the last few milliseconds of each file.

## Method

Additive and subtractive synthesis at 44.1 kHz, mono: the whistle is three sine pipes with their second and third harmonics, a short upward scoop into each note, a 5.5 Hz vibrato and a breath of band-passed noise; the chuffs are seeded white noise through RBJ band-pass biquads, shaped by a quick rise and an exponential fall; the clack is a damped two-tone ring over a low thump. The mix is peak-normalised, soft-clipped (`tanh`), written as 16-bit WAV, levelled by ffmpeg's `ebur128` measurement and encoded as 96 kb/s mono MP3 without tags, as the other sounds are.
