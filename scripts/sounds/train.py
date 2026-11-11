#!/usr/bin/env python3
"""
The train pack's `arrive` and `leave` sounds (docs/architecture.md §3
"Sounds", §8): a friendly toy steam train, synthesised here and nowhere
downloaded. Plain Python 3 (standard library only) renders each sound to
WAV; ffmpeg levels it and writes the mono MP3 the board plays.

    python3 scripts/sounds/train.py            # writes content/assets/sounds/train-*.mp3
    python3 scripts/sounds/train.py --out DIR  # writes them elsewhere, to listen first

The numbers to retune are the constants below; scripts/sounds/README.md
says which does what.
"""

import argparse
import math
import random
import re
import shutil
import struct
import subprocess
import sys
import tempfile
import wave
from pathlib import Path

RATE = 44100
ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "content" / "assets" / "sounds"

# Loudness of both files (EBU R128 integrated): below the celebrations
# (`bells` and the rest sit at about -13.5 LUFS), since `leave` plays under
# `bells` when a round is solved.
LOUDNESS = -20.0
# The highest true peak a file may have after levelling.
MAX_TRUE_PEAK = -3.0
# The same noise every run, so the files only change when a number does.
SEED = 2026

# The whistle: three pipes sounding a major chord (D5, F#5, A5), the toy
# «tu-tuu»: a short note, a gap, a longer one.
WHISTLE_CHORD = (587.33, 739.99, 880.00)
WHISTLE_NOTES = ((0.02, 0.13), (0.21, 0.40))  # (start, length) in seconds
WHISTLE_GAIN = 0.32

# A chuff is a puff of steam: noise through a low "body" band and a high
# "hiss" band, each with its own decay.
BODY_BAND = (380.0, 1.1)  # (centre Hz, Q)
HISS_BAND = (2300.0, 0.9)
BODY_DECAY = 0.045  # seconds
HISS_DECAY = 0.060
CHUFF_ATTACK = 0.007  # a soft puff, not a click
# Four chuffs to a turn of the wheels; the first of each four is the strongest.
ACCENTS = (1.0, 0.68, 0.84, 0.62)
# The wheels' click on a rail joint, every second chuff.
CLACK_GAIN = 0.10

# Arriving: the chuffs start quick and slow down as the train rolls to a
# stop (the wagons roll in for 1.3 s, Cubic.easeOut), then a soft hiss.
ARRIVE_LENGTH = 1.45
ARRIVE_FIRST_GAP = 0.105
ARRIVE_SLOWING = 1.09  # each gap this many times the one before
ARRIVE_LAST_CHUFF = 1.20
ARRIVE_GAINS = (0.90, 0.45)  # first chuff, last chuff
ARRIVE_HISS = (1.08, 0.24)  # (start, gain) of the steam let off at the stop

# Leaving: a puff of steam, then chuffs that speed up and fade away (the
# wagons roll off for 1.1 s, Cubic.easeIn).
LEAVE_LENGTH = 1.25
LEAVE_PUFF_GAIN = 0.20
LEAVE_FIRST_CHUFF = 0.07
LEAVE_FIRST_GAP = 0.22
LEAVE_QUICKENING = 0.87  # each gap this many times the one before
LEAVE_SHORTEST_GAP = 0.075
LEAVE_LAST_CHUFF = 1.12
LEAVE_GAINS = (0.55, 0.85)  # first chuff, loudest
LEAVE_FADE_FROM = 0.55  # seconds: from here the train is going away

FADE_OUT = 0.015  # seconds at the very end of each file
# A gentle soft clip (tanh) on the mix: rounds off the puffs' peaks, so the
# files reach their loudness with room under 0 dBFS. 0 = none.
SOFTEN = 1.6


def bandpass(signal, centre, q):
    """An RBJ band-pass biquad (0 dB peak gain)."""
    w0 = 2 * math.pi * centre / RATE
    alpha = math.sin(w0) / (2 * q)
    a0 = 1 + alpha
    b0, b2 = alpha / a0, -alpha / a0
    a1, a2 = -2 * math.cos(w0) / a0, (1 - alpha) / a0
    out = [0.0] * len(signal)
    x1 = x2 = y1 = y2 = 0.0

    for i, x in enumerate(signal):
        y = b0 * x + b2 * x2 - a1 * y1 - a2 * y2
        out[i] = y
        x2, x1 = x1, x
        y2, y1 = y1, y

    return out


def noise(length, rng):
    return [rng.uniform(-1.0, 1.0) for _ in range(int(length * RATE))]


def add_envelope(envelope, start, gain, decay, attack=0.004, tail=6.0):
    """A quick rise and an exponential fall, added into `envelope` at `start`."""
    first = int(start * RATE)
    last = min(len(envelope), first + int(decay * tail * RATE))

    for i in range(max(0, first), last):
        t = (i - first) / RATE
        envelope[i] += gain * (1 - math.exp(-t / attack)) * math.exp(-t / decay)


def chuffs(length, times, gains, rng, hiss_weight=None):
    """The chuffs at `times` with `gains`; `hiss_weight(t)` dulls the far ones."""
    body = bandpass(noise(length, rng), *BODY_BAND)
    hiss = bandpass(noise(length, rng), *HISS_BAND)
    body_env = [0.0] * len(body)
    hiss_env = [0.0] * len(hiss)

    for index, (start, gain) in enumerate(zip(times, gains)):
        accent = ACCENTS[index % len(ACCENTS)]
        weight = 1.0 if hiss_weight is None else hiss_weight(start)
        add_envelope(body_env, start, gain * accent, BODY_DECAY, attack=CHUFF_ATTACK)
        add_envelope(hiss_env, start, 0.8 * gain * accent * weight, HISS_DECAY, attack=CHUFF_ATTACK)

    out = [b * be * 2.2 + h * he * 1.6 for b, be, h, he in zip(body, body_env, hiss, hiss_env)]

    for index, (start, gain) in enumerate(zip(times, gains)):
        if index % 2 == 1:
            clack(out, start + 0.012, CLACK_GAIN * gain)

    return out


def clack(out, start, gain):
    """A short metallic tick of the wheels on a rail joint."""
    first = int(start * RATE)

    for i in range(max(0, first), min(len(out), first + int(0.03 * RATE))):
        t = (i - first) / RATE
        ring = math.sin(2 * math.pi * 2100 * t) + 0.6 * math.sin(2 * math.pi * 3350 * t)
        thump = math.sin(2 * math.pi * 140 * t) * math.exp(-t / 0.012)
        out[i] += gain * (ring * math.exp(-t / 0.005) + 0.8 * thump)


def steam(length, start, gain, rng, rise=0.03, decay=0.22):
    """Steam let off: a soft hiss."""
    hiss = bandpass(noise(length, rng), 3200.0, 0.7)
    envelope = [0.0] * len(hiss)
    add_envelope(envelope, start, gain, decay, attack=rise, tail=5.0)

    return [h * e * 2.0 for h, e in zip(hiss, envelope)]


def whistle(length, rng):
    """«tu-tuu»: three pipes with a breathy onset and a little vibrato."""
    out = [0.0] * int(length * RATE)
    breath = bandpass(noise(length, rng), WHISTLE_CHORD[1] * 1.5, 2.0)

    for start, note in WHISTLE_NOTES:
        first = int(start * RATE)
        count = int(note * RATE)
        phases = [0.0] * len(WHISTLE_CHORD)

        for n in range(count):
            i = first + n

            if i >= len(out):
                break

            t = n / RATE
            # The pitch scoops up into the note, as steam fills the pipes.
            scoop = 1 - 0.035 * math.exp(-t / 0.025)
            vibrato = 1 + 0.004 * math.sin(2 * math.pi * 5.5 * t)
            rise = min(1.0, t / 0.025)
            fall = min(1.0, (note - t) / (0.05 if note < 0.2 else 0.11))
            envelope = rise * max(0.0, fall)
            sample = 0.0

            for p, pitch in enumerate(WHISTLE_CHORD):
                phases[p] += 2 * math.pi * pitch * scoop * vibrato / RATE
                sample += math.sin(phases[p]) + 0.28 * math.sin(2 * phases[p]) + 0.08 * math.sin(3 * phases[p])

            out[i] += WHISTLE_GAIN * envelope * (sample / len(WHISTLE_CHORD) + 0.35 * breath[i])

    return out


def mix(*tracks):
    return [sum(samples) for samples in zip(*tracks)]


def arrive(rng):
    times, gap, t = [], ARRIVE_FIRST_GAP, 0.03

    while t <= ARRIVE_LAST_CHUFF:
        times.append(t)
        t += gap
        gap *= ARRIVE_SLOWING

    first, last = ARRIVE_GAINS
    gains = [first + (last - first) * i / max(1, len(times) - 1) for i in range(len(times))]

    return mix(
        chuffs(ARRIVE_LENGTH, times, gains, rng),
        whistle(ARRIVE_LENGTH, rng),
        steam(ARRIVE_LENGTH, ARRIVE_HISS[0], ARRIVE_HISS[1], rng),
    )


def leave(rng):
    times, gap, t = [], LEAVE_FIRST_GAP, LEAVE_FIRST_CHUFF

    while t <= LEAVE_LAST_CHUFF:
        times.append(t)
        t += gap
        gap = max(LEAVE_SHORTEST_GAP, gap * LEAVE_QUICKENING)

    def away(at):
        """1 until the train starts going away, then down to about a fifth."""
        if at <= LEAVE_FADE_FROM:
            return 1.0

        return math.exp(-(at - LEAVE_FADE_FROM) / 0.38)

    first, loudest = LEAVE_GAINS
    gains = []

    for at in times:
        build = first + (loudest - first) * min(1.0, at / LEAVE_FADE_FROM)
        gains.append(build * away(at))

    return mix(
        chuffs(LEAVE_LENGTH, times, gains, rng, hiss_weight=away),
        steam(LEAVE_LENGTH, 0.0, LEAVE_PUFF_GAIN, rng, rise=0.012, decay=0.12),
    )


def write_wav(path, samples):
    peak = max(abs(s) for s in samples) or 1.0
    fade = int(FADE_OUT * RATE)
    frames = bytearray()

    for i, s in enumerate(samples):
        s /= peak

        if SOFTEN > 0:
            s = math.tanh(SOFTEN * s) / math.tanh(SOFTEN)

        left = len(samples) - i

        if left <= fade:
            s *= left / fade

        # -6 dBFS; ffmpeg sets the loudness after.
        frames += struct.pack("<h", int(max(-1.0, min(1.0, 0.5 * s)) * 32767))

    with wave.open(str(path), "wb") as file:
        file.setnchannels(1)
        file.setsampwidth(2)
        file.setframerate(RATE)
        file.writeframes(bytes(frames))


def loudness(ffmpeg, path):
    """(integrated LUFS, true peak dBFS) of a file, by ffmpeg's ebur128."""
    result = subprocess.run(
        [ffmpeg, "-hide_banner", "-nostats", "-i", str(path), "-af", "ebur128=peak=true", "-f", "null", "-"],
        capture_output=True,
        text=True,
        check=True,
    )
    summary = result.stderr.split("Summary:")[-1]
    integrated = re.search(r"I:\s+(-?[\d.]+) LUFS", summary)
    peak = re.search(r"Peak:\s+(-?[\d.]+) dBFS", summary)

    if integrated is None or peak is None:
        sys.exit(f"Cannot read the loudness of {path}.")

    return float(integrated.group(1)), float(peak.group(1))


def encode(ffmpeg, wav, mp3):
    measured, _ = loudness(ffmpeg, wav)
    gain = LOUDNESS - measured

    # Twice: the second pass corrects what the MP3 encoder moved.
    for _ in range(2):
        subprocess.run(
            [
                ffmpeg, "-hide_banner", "-loglevel", "error", "-y", "-i", str(wav),
                "-af", f"volume={gain:.2f}dB",
                "-ac", "1", "-ar", str(RATE), "-c:a", "libmp3lame", "-b:a", "96k",
                "-map_metadata", "-1", "-id3v2_version", "0", "-write_id3v1", "0",
                "-fflags", "+bitexact", "-flags:a", "+bitexact",
                str(mp3),
            ],
            check=True,
        )
        integrated, peak = loudness(ffmpeg, mp3)
        gain += LOUDNESS - integrated

    if peak > MAX_TRUE_PEAK:
        sys.exit(f"{mp3.name}: true peak {peak} dBFS is above {MAX_TRUE_PEAK}; lower LOUDNESS.")

    return integrated, peak


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--out", type=Path, default=OUT, help="folder to write the MP3 files into")
    args = parser.parse_args()
    ffmpeg = shutil.which("ffmpeg") or "/opt/homebrew/bin/ffmpeg"

    if not Path(ffmpeg).exists():
        sys.exit("ffmpeg is needed (brew install ffmpeg).")

    args.out.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory() as temp:
        for name, render in (("train-arrive", arrive), ("train-leave", leave)):
            samples = render(random.Random(f"{SEED}-{name}"))
            wav = Path(temp) / f"{name}.wav"
            mp3 = args.out / f"{name}.mp3"
            write_wav(wav, samples)
            integrated, peak = encode(ffmpeg, wav, mp3)
            print(f"{mp3}: {len(samples) / RATE:.2f} s, {integrated} LUFS, true peak {peak} dBFS")


if __name__ == "__main__":
    main()
