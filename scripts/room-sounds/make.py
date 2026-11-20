#!/usr/bin/env python3
"""
The sounds of a room game (docs/architecture.md §12 "Room content", ADR
025 "Sound", ADR 028; a board game's four, ADR 034 decision 31): the
screen's — the room's speaker — and a phone's quiet cues of its own. Each
file is made here from recordings of the VS Chamber Orchestra: Community
Edition (CC0-1.0, downloaded from a pinned commit and checked by
SHA-256) and from our own synthesis (MIT); ffmpeg levels the set to one
loudness and writes the mono MP3 files a room page plays. Plain Python 3
(standard library only) and ffmpeg.

    python3 scripts/room-sounds/make.py            # writes content/assets/rooms/sounds/*.mp3
    python3 scripts/room-sounds/make.py --out DIR  # writes them elsewhere, to listen first
    python3 scripts/room-sounds/make.py --only clock fanfare

The recordings are cached outside the repository (`--cache`, by default
~/.cache/elifba/room-sounds) and never committed. scripts/room-sounds/README.md
says what each cue is made of. No voice, no speech, no music bed and no
loop: each file is one event, played once for its moment.
"""

import argparse
import hashlib
import math
import random
import re
import shutil
import struct
import subprocess
import sys
import tempfile
import urllib.parse
import urllib.request
import wave
from array import array
from pathlib import Path

RATE = 44100
ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "content" / "assets" / "rooms" / "sounds"
CACHE = Path.home() / ".cache" / "elifba" / "room-sounds"

# One loudness for the whole set (ADR 028 decision 12): EBU R128 integrated
# loudness "while it sounds" — a cue shorter than LOOP_SECONDS measured
# played back to back, a longer one over itself — and a cue that builds
# (BUILDS) by its last second, the climax that leads into the next cue. The
# page sets how loud a device plays them (a phone at half the screen's
# level), never the files.
LOUDNESS = -20.0
LOOP_SECONDS = 4.0
BUILDS = {"countdown": 1.0, "clock": 1.0, "roll": 1.0}
# The highest true peak a file may have after levelling.
MAX_TRUE_PEAK = -1.0
# A look-ahead limiter (its delay compensated, so a cue's timing stays) holds
# the sample peaks here while the cue reaches LOUDNESS.
LIMIT_DB = -3.0
# The last few milliseconds of every file fade to silence (no click).
FADE_OUT = 0.006

# ── the recordings ───────────────────────────────────────────────────────
# VS Chamber Orchestra: Community Edition, recorded by Sam Gossner and Simon
# Dalzell, CC0-1.0 (https://github.com/sgossner/VSCO-2-CE, its LICENSE file),
# at one commit. Each file: its path in the repository and its SHA-256.
VSCO_COMMIT = "440300901dfe9275fd84e0b7763af1f8443ae62e"
VSCO_URL = "https://raw.githubusercontent.com/sgossner/VSCO-2-CE/{commit}/{path}"
VSCO = {
    "timpani-c#2": ("Percussion/Timpani/Timpani3_Hit_v3_rr1_Sum.wav", "46cd2ba3798f9e3a6be515b13dbc37d2ef65c82afb0d0e9e34121e4524bfcd3a"),
    "timpani-c#2-soft": ("Percussion/Timpani/Timpani3_Hit_v1_rr1_Sum.wav", "00319863ade67b49ec64566eecc801ac7c95e101860b7bb3263f25c7d6757392"),
    "timpani-roll": ("Percussion/Timpani/Rolls/Timpani2_Roll_v3_rr1_Sum.wav", "3f78dc5077ed03ea6bc1c125e27be8882b8260ba37ec379da06b80334c7661e7"),
    "snare-roll": ("Percussion/Snare2-rollSN_v5_rr1_Sum.wav", "44f7ea2e542b518b62b64c69ca542935c877dd90545f7cf2a95ba6d6ce7d5267"),
    "crash": ("Percussion/cymbal-crash1_ff_rr1.wav", "3b848fe459fcb398a78d77fe6c3f78e6b693bedeaeecbd029eeff6799452b98f"),
    "bass-drum": ("Percussion/BDrumNewhit_v5_rr1_Sum.wav", "642aaf3375eaff1307973f7de3ccbf74d18e71fb3cf9412e021b08fc3bf1981c"),
    "bass-drum-soft": ("Percussion/BDrumNewhit_v3_rr1_Sum.wav", "a2d8829e8b2b59e8afb12fa396b01758827059339c2a84ad066d17b36c82d1ff"),
    "tubular-bell": ("Percussion/TB_hit_G4_v4_rr1.wav", "de3df68f1dd86e71819de9c3a8284d5b5b11650e6f1c37483f505f86aa07cf68"),
    "log-drum-high": ("Percussion/LogDrumHi_MedM_v2_rr1_Sum.wav", "8c4f61b36a13a8559f1777845ee9795bec52e07d2e86f05d50097bee6abd08b0"),
    "log-drum-low": ("Percussion/LogDrumLo_MedM_v2_rr1_Sum.wav", "609a9ae5441a007371e0dc3120d7e6d4ad5d23073f5f4fb3f16d0dc08823dfc4"),
    "claves": ("Percussion/Claves1_Hit_v1_rr1_Sum.wav", "569812aca160bf35d71ac16e678f5528b2093ece086300cd97a0064e17d23f20"),
    "glock-run": ("Miscellania Raw/Misc 2/glock_glisses/glock_fx_up_pentatonic_med_01.wav", "8ca17e7741ce803ca6e2d685bacc888e31ef694b9eb1c787a33f910d896faf7d"),
    "marimba-c5": ("Percussion/Marimba/Marimba_hit_Outrigger_C4_loud_01.wav", "4af539d47a452d7fad26e171b1c8adcca272cee97191f040d192680462c880b8"),
    "marimba-g5": ("Percussion/Marimba/Marimba_hit_Outrigger_G4_loud_01.wav", "36f39f9f24789001add2b4c440defb7641178b0e8449c5cb7f1960bccaf0aaaf"),
    "harp-c5": ("Strings/Harp/KSHarp_C5_mf.wav", "7c8b862f6fd3f1442ed87537af976d84bda8c05a5b4900e8907d570b5202a517"),
    "harp-e5": ("Strings/Harp/KSHarp_E5_mf.wav", "95dd9ddb0bd15bee7687906e00f25aafcaa43020cc9501f0e8b6cabf0e9a53ec"),
    "harp-g5": ("Strings/Harp/KSHarp_G5_mf.wav", "f2004dfc617997fbabc6f849add2af06f4f859361c44a8fca0f993dc7f999540"),
    "harp-b5": ("Strings/Harp/KSHarp_B5_mf.wav", "f2757b674c6b7301bed52e17a1ae3e3d34d628b3f7edc5773ebb25798d2541f7"),
    "cello-tremolo": ("Strings/Cello Section/trem/trem_C3_v2_1.wav", "286d477dc610d62495de161a7561281ca12f0020e869295b2ffe1bcb36c9aab8"),
    "trumpet-c4-short": ("Brass/Trumpet/stac/Sum_SHTrumpet_stac_C3_v2_rr1.wav", "dcd3d370fe299ca76b222066d7facd0034a549a105051ed474f8dd1f478f1afa"),
    "trumpet-d#4-short": ("Brass/Trumpet/stac/Sum_SHTrumpet_stac_D#3_v2_rr1.wav", "f032aa47913409ff845bdd521ced7f7275c83642edc94447f71cd0c4141af170"),
    "trumpet-g4-short": ("Brass/Trumpet/stac/Sum_SHTrumpet_stac_G3_v2_rr1.wav", "1819a3fab5c2c6ffb1eb76f7d3880886db4e4a3a713f8c323cdc10ad1332a128"),
    "trumpet-a#4-short": ("Brass/Trumpet/stac/Sum_SHTrumpet_stac_A#3_v2_rr1.wav", "d8cbce4381ae171e4ba19963da5c9d8c5a140164775e0c5ca0ec5afcdadd1d57"),
    "trumpet-g4": ("Brass/Trumpet/sus/Sum_SHTrumpet_sus_G3_v3_rr1.wav", "e916631d4ef5c7d2afa356b76cb0d3a18ba2702d1229535edf4fb6d73c2e521c"),
    "trumpet-a#4": ("Brass/Trumpet/sus/Sum_SHTrumpet_sus_A#3_v3_rr1.wav", "c931621fae92e22cc73ee43924c0d30ee629764bb37c30cc427b59411198461d"),
    "trumpet-d5": ("Brass/Trumpet/sus/Sum_SHTrumpet_sus_D4_v3_rr1.wav", "71d77fc5c3c43b9453f17293c6039bf8554dec6581ca15c14c45893f8bc57566"),
    "horn-c4": ("Brass/F Horn/sus/MOHorn_sus_C3_v3_1.wav", "75156ce041d9c60e4de0099b4c6d81cafffd34f45be7bca3e6fa7ebff0eadb80"),
    "trombone-c4": ("Brass/Tenor Trombone/sus/tenortbn_sus_C3_v3_1.wav", "ba3760e74edf6c25f96a0deb526bf87eba2bf74be94ba76bf31d0d6592efe687"),
}

FFMPEG = shutil.which("ffmpeg") or "/opt/homebrew/bin/ffmpeg"
FFPROBE = shutil.which("ffprobe") or "/opt/homebrew/bin/ffprobe"


def seconds(n):
    return int(round(n * RATE))


def silence(length):
    return [0.0] * seconds(length)


# ── recordings ───────────────────────────────────────────────────────────

_recordings = {}
_cache_dir = CACHE


def fetch(name):
    """The cached recording `name`, downloaded once from its pinned URL and checked by SHA-256."""
    path, digest = VSCO[name]
    target = _cache_dir / "vsco" / VSCO_COMMIT[:12] / path
    if not target.exists():
        target.parent.mkdir(parents=True, exist_ok=True)
        url = VSCO_URL.format(commit=VSCO_COMMIT, path=urllib.parse.quote(path))
        print(f"downloading {path}", flush=True)
        with urllib.request.urlopen(url, timeout=120) as response:
            data = response.read()
        if hashlib.sha256(data).hexdigest() != digest:
            sys.exit(f"{path}: the download does not match its SHA-256; nothing was written.")
        target.write_bytes(data)
    elif hashlib.sha256(target.read_bytes()).hexdigest() != digest:
        sys.exit(f"{target}: the cached file does not match its SHA-256; delete it and run again.")
    return target


def channels(path):
    result = subprocess.run(
        [FFPROBE, "-v", "error", "-show_entries", "stream=channels", "-of", "csv=p=0", str(path)],
        capture_output=True, text=True, check=True,
    )
    return int(result.stdout.strip().splitlines()[0])


def decode(path, semitones=0.0):
    """A recording as mono floats at RATE; `semitones` pitches it (and so speeds it) by resampling."""
    filters = ["pan=mono|c0=0.5*c0+0.5*c1"] if channels(path) == 2 else []
    if semitones:
        filters += [f"asetrate={RATE * 2 ** (semitones / 12):.4f}", f"aresample={RATE}"]
    command = [FFMPEG, "-hide_banner", "-loglevel", "error", "-i", str(path)]
    if filters:
        command += ["-af", ",".join(filters)]
    command += ["-ac", "1", "-ar", str(RATE), "-f", "f32le", "-"]
    data = array("f")
    data.frombytes(subprocess.run(command, capture_output=True, check=True).stdout)
    return data.tolist()


def onset(samples, threshold_db=-36.0, back=0.002):
    """Where a recording starts: the first sample above `threshold_db` of its peak, less `back` s."""
    top = peak(samples)
    limit = top * 10 ** (threshold_db / 20)
    for i, s in enumerate(samples):
        if abs(s) >= limit:
            return max(0, i - seconds(back))
    return 0


def rec(name, semitones=0.0, start=None):
    """A recording from its onset (or from `start` s), peak-normalised, so a hit placed at t lands at t."""
    key = (name, semitones, start)
    if key not in _recordings:
        x = decode(fetch(name), semitones)
        x = x[seconds(start):] if start is not None else x[onset(x):]
        _recordings[key] = normalized(x)
    return list(_recordings[key])


# ── shape ────────────────────────────────────────────────────────────────


def peak(samples):
    return max((abs(s) for s in samples), default=0.0) or 1e-9


def normalized(samples):
    top = peak(samples)
    return [s / top for s in samples]


def fade_out(samples, length):
    n = min(len(samples), seconds(length))
    out = list(samples)
    for i in range(n):
        out[len(out) - n + i] *= (n - i) / n
    return out


def fade_in(samples, length):
    n = min(len(samples), seconds(length))
    out = list(samples)
    for i in range(n):
        out[i] *= i / n
    return out


def crop(samples, length, fade=0.02):
    """The first `length` s, fading out over the last `fade` s."""
    return fade_out(samples[: seconds(length)], fade)


def ramp(samples, points):
    """Multiplies by a gain curve [(t, dB), ...], linear in dB between its points."""
    out = list(samples)
    points = sorted(points)
    j = 0
    for i in range(len(out)):
        t = i / RATE
        while j < len(points) - 2 and t > points[j + 1][0]:
            j += 1
        (t0, g0), (t1, g1) = points[j], points[min(j + 1, len(points) - 1)]
        if t <= t0:
            g = g0
        elif t >= t1:
            g = g1
        else:
            g = g0 + (g1 - g0) * (t - t0) / (t1 - t0)
        out[i] *= 10 ** (g / 20)
    return out


def envelope(t, attack, decay):
    """A quick rise and an exponential fall."""
    return (1 - math.exp(-t / attack)) * math.exp(-t / decay) if t >= 0 else 0.0


def shaped(samples, attack, decay):
    return [s * envelope(i / RATE, attack, decay) for i, s in enumerate(samples)]


def place(out, at, signal, gain=1.0):
    """Adds `signal` into `out` from `at` s, growing `out` if needed."""
    first = seconds(at)
    need = first + len(signal)
    if need > len(out):
        out.extend([0.0] * (need - len(out)))
    for i, s in enumerate(signal):
        if first + i >= 0:
            out[first + i] += gain * s
    return out


# ── filters and noise ────────────────────────────────────────────────────


def biquad(signal, coefficients):
    """A biquad; `coefficients` is fixed or a function of the sample index."""
    out = [0.0] * len(signal)
    x1 = x2 = y1 = y2 = 0.0
    fixed = None if callable(coefficients) else coefficients
    for i, x in enumerate(signal):
        b0, b1, b2, a1, a2 = fixed or coefficients(i)
        y = b0 * x + b1 * x1 + b2 * x2 - a1 * y1 - a2 * y2
        out[i] = y
        x2, x1 = x1, x
        y2, y1 = y1, y
    return out


def rbj(kind, freq, q):
    """An RBJ biquad's coefficients: a low-, high- or band-pass (0 dB peak)."""
    w0 = 2 * math.pi * min(freq, RATE * 0.45) / RATE
    cos, sin = math.cos(w0), math.sin(w0)
    alpha = sin / (2 * q)
    if kind == "low":
        b0, b1, b2 = (1 - cos) / 2, 1 - cos, (1 - cos) / 2
    elif kind == "high":
        b0, b1, b2 = (1 + cos) / 2, -(1 + cos), (1 + cos) / 2
    else:
        b0, b1, b2 = alpha, 0.0, -alpha
    a0, a1, a2 = 1 + alpha, -2 * cos, 1 - alpha
    return b0 / a0, b1 / a0, b2 / a0, a1 / a0, a2 / a0


def lowpass(signal, freq, q=0.707):
    return biquad(signal, rbj("low", freq, q))


def highpass(signal, freq, q=0.707):
    return biquad(signal, rbj("high", freq, q))


def bandpass(signal, freq, q=1.0):
    return biquad(signal, rbj("band", freq, q))


def sweep(signal, kind, start, end, q=0.707):
    """A filter whose frequency moves exponentially from `start` to `end` over the signal."""
    n = max(1, len(signal) - 1)
    cache = {}

    def at(i):
        step = i // 32  # coefficients every 32 samples are smooth enough
        if step not in cache:
            cache[step] = rbj(kind, start * (end / start) ** (min(i, n) / n), q)
        return cache[step]

    return biquad(signal, at)


def noise(length, rng):
    return [rng.uniform(-1.0, 1.0) for _ in range(seconds(length))]


def rng(name):
    """The same noise every run, so the files only change when a number does."""
    return random.Random(f"2026-room-{name}")


# ── synthesis ────────────────────────────────────────────────────────────


def note(name):
    """'A4' → Hz (A4 = 440)."""
    m = re.fullmatch(r"([A-G])([#b]?)(-?\d)", name)
    steps = {"C": -9, "D": -7, "E": -5, "F": -4, "G": -2, "A": 0, "B": 2}[m.group(1)]
    steps += {"#": 1, "b": -1, "": 0}[m.group(2)]
    steps += 12 * (int(m.group(3)) - 4)
    return 440.0 * 2 ** (steps / 12)


def tone(freq, length, attack=0.003, decay=0.2, partials=((1.0, 1.0, 1.0),)):
    """Additive sine partials (ratio, gain, decay as a share of `decay`), each with its own decay."""
    out = [0.0] * seconds(length)
    two_pi = 2 * math.pi
    for ratio, gain, share in partials:
        f = freq * ratio
        d = max(decay * share, 1e-4)
        w = two_pi * f / RATE
        for i in range(len(out)):
            t = i / RATE
            out[i] += gain * math.sin(w * i) * (1 - math.exp(-t / attack)) * math.exp(-t / d)
    return out


def glide(length, start, end, time, attack=0.002, decay=0.2, partials=((1.0, 1.0),)):
    """A tone whose pitch moves from `start` to `end` over about `time`, then holds."""
    out = []
    phase = 0.0
    for i in range(seconds(length)):
        t = i / RATE
        freq = end + (start - end) * math.exp(-t / max(time / 4, 1e-6))
        phase += 2 * math.pi * freq / RATE
        out.append(sum(g * math.sin(r * phase) for r, g in partials) * envelope(t, attack, decay))
    return out


def kick(length, start, end, sweep_time, decay, click, r):
    """A drum's body: a sine falling from `start` to `end` Hz, with a short click of noise."""
    out = glide(length, start, end, sweep_time, attack=0.0008, decay=decay)
    place(out, 0.0, shaped(highpass(noise(0.01, r), 2000), 0.0002, 0.002), click)
    return out


def reverb(signal, size, wet, damp=0.35, predelay=0.012):
    """A small room: a mono Freeverb (eight damped combs, four all-passes) over the dry signal, cut to its length."""
    combs = [1116, 1188, 1277, 1356, 1422, 1491, 1557, 1617]
    allpasses = [556, 441, 341, 225]
    feedback = 0.7 + 0.28 * size
    n = len(signal)
    pre = seconds(predelay)
    delayed = [0.0] * pre + signal[: n - pre]
    acc = [0.0] * n
    for length in combs:
        buf = [0.0] * length
        index = 0
        store = 0.0
        for i in range(n):
            out = buf[index]
            store = out * (1 - damp) + store * damp
            buf[index] = delayed[i] * 0.015 + store * feedback
            acc[i] += out
            index += 1
            if index == length:
                index = 0
    for length in allpasses:
        buf = [0.0] * length
        index = 0
        for i in range(n):
            b = buf[index]
            x = acc[i]
            acc[i] = -x + b
            buf[index] = x + b * 0.5
            index += 1
            if index == length:
                index = 0
    return [signal[i] + wet * acc[i] for i in range(n)]


MARIMBA = ((1.0, 1.0, 1.0), (3.9, 0.22, 0.25), (2.0, 0.12, 0.6), (9.2, 0.03, 0.08))
ROUND_NOTE = ((1.0, 1.0, 1.0), (2.0, 0.18, 0.5), (3.0, 0.04, 0.3))
# A board game's offer (ADR 034 decision 31): a vibraphone bar's partials.
VIBES = ((1.0, 1.0, 1.0), (4.0, 0.12, 0.25), (10.0, 0.02, 0.08))


def board(r, low, high, modes, length, contact, decay_low, decay_high, centre=900.0, width=0.7, body=0.0, body_hz=150.0, body_decay=0.015, lp=3500.0):
    """A wooden board touched by a piece (our synthesis): `modes` inharmonic modes spread between `low` and
    `high` Hz (seeded, one in each slot of a log scale, so no two beat), loudest near `centre` Hz (a log-normal
    weight of `width`), each dying faster as it rises; rising over `contact` s — a soft contact, never a click;
    a short body of the table under it; low-passed twice at `lp`. No mode rings on: no pitch."""
    out = [0.0] * seconds(length)
    for k in range(modes):
        x = (k + r.uniform(0.3, 0.7)) / modes
        f = low * (high / low) ** x
        d = decay_low * (decay_high / decay_low) ** x
        g = math.exp(-(math.log(f / centre) ** 2) / (2 * width ** 2)) * r.uniform(0.6, 1.0)
        place(out, 0.0, tone(f, length, attack=contact, decay=d), g)
    if body:
        place(out, 0.0, tone(body_hz, length, attack=contact * 1.5, decay=body_decay), body)
    return fade_out(lowpass(lowpass(out, lp), lp), min(0.04, length / 3))


def stack(r, gap=0.024):
    """A piece set on another: a smaller, brighter wood-on-wood touch (modes 700–3200 Hz, no table) and the
    piece settling `gap` s later, softer."""
    out = silence(0.14)
    one = board(r, 700.0, 3200.0, 12, 0.10, contact=0.0010, decay_low=0.014, decay_high=0.005, centre=1500.0, width=0.5, lp=4200.0)
    place(out, 0.0, one, 1.0)
    place(out, gap, one, 0.4)
    return out


# ── the screen: the room's moments ───────────────────────────────────────


def countdown():
    """3.0 s ending at opens_ms: a marimba G5 over a low C4 on each second, and a glockenspiel run climbing into the start."""
    length = 3.0
    out = silence(length)
    for at, gain in ((0.0, 0.7), (1.0, 0.8), (2.0, 0.9)):
        place(out, at, crop(rec("marimba-g5"), 0.7, 0.2), gain)
        place(out, at, crop(rec("marimba-c5", semitones=-12), 0.6, 0.2), 0.45 * gain)
    run = rec("glock-run")[: seconds(0.95)]
    run = ramp(run, [(0.0, -14.0), (0.95, 0.0)])
    place(out, length - 0.95, run, 0.55)
    out = reverb(out[: seconds(length)], size=0.55, wet=0.2)
    return fade_out(out[: seconds(length)], 0.03)


def go():
    """Round 0 opens: a cymbal crash, a timpani C2, a bass drum and a short trumpet chord C4–E4–G4–C5."""
    length = 1.5
    out = silence(length)
    place(out, 0.0, crop(rec("crash"), 1.5, 0.6), 0.5)
    place(out, 0.0, crop(rec("timpani-c#2", semitones=-1.34), 1.5, 0.6), 0.9)
    place(out, 0.0, crop(rec("bass-drum"), 0.6, 0.3), 0.5)
    for name, shift in (("trumpet-c4-short", 0.0), ("trumpet-d#4-short", 1.0), ("trumpet-g4-short", 0.0), ("trumpet-a#4-short", 2.0)):
        place(out, 0.0, crop(rec(name, semitones=shift), 0.8, 0.3), 0.35)
    return fade_out(out[: seconds(length)], 0.2)


def study():
    """A later round's study opens: a harp C5–E5–G5–C6, quickly up."""
    length = 1.0
    out = silence(length)
    for n, (name, shift) in enumerate((("harp-c5", 0.0), ("harp-e5", 0.0), ("harp-g5", 0.0), ("harp-b5", 1.0))):
        place(out, n * 0.055, crop(rec(name, semitones=shift), length, 0.3), 0.55 + 0.1 * n)
    return fade_out(out[: seconds(length)], 0.3)


def question():
    """A question opens (our synthesis): a quick zip from 600 to 1200 Hz into a marimba-like D6, in a small room."""
    length = 0.55
    out = silence(length)
    place(out, 0.0, glide(0.06, 600.0, 1200.0, 0.06, attack=0.004, decay=0.05), 0.3)
    place(out, 0.048, tone(note("D6"), length - 0.05, attack=0.002, decay=0.15, partials=MARIMBA), 0.9)
    out = reverb(out, size=0.45, wet=0.18)
    return fade_out(out[: seconds(length)], 0.1)


def clock():
    """5.0 s ending at bell_ms: tick–tock on log drums twice a second, four times in the last 3 s, over a low pulse and a cello tremolo."""
    length = 5.0
    out = silence(length)
    high = crop(highpass(rec("log-drum-high"), 300), 0.18, 0.08)
    low = crop(highpass(rec("log-drum-low"), 200), 0.18, 0.08)
    beats = [i * 0.5 for i in range(4)] + [2.0 + i * 0.25 for i in range(12)]
    for n, at in enumerate(beats):
        urgency = 0.0 if at < 2.0 else (at - 2.0) / 3.0
        place(out, at, high if n % 2 == 0 else low, 0.6 + 0.4 * urgency)
    pulse = lowpass(crop(rec("bass-drum-soft"), 0.35, 0.15), 180)
    for k in range(6):
        place(out, 2.0 + k * 0.5, pulse, 0.3 + 0.08 * k)
    tremolo = rec("cello-tremolo", start=0.6)[: seconds(3.0)]
    tremolo = ramp(tremolo, [(0.0, -48.0), (1.5, -24.0), (3.0, -8.0)])
    place(out, 2.0, tremolo, 0.6)
    return fade_out(out[: seconds(length)], 0.03)


def close():
    """The round closes, whatever anyone answered: a tubular bell, once, over a soft timpani."""
    length = 1.3
    out = silence(length)
    place(out, 0.0, crop(rec("tubular-bell"), length, 0.5), 0.8)
    place(out, 0.0, crop(rec("timpani-c#2-soft"), 1.0, 0.4), 0.35)
    return fade_out(out[: seconds(length)], 0.35)


def place_cue():
    """The final's third and second places open (our synthesis): a deep kick, a burst of noise and a short room."""
    length = 0.8
    r = rng("place-b")
    out = silence(length)
    place(out, 0.0, kick(0.7, 110.0, 38.0, 0.05, 0.25, 0.5, r), 0.9)
    place(out, 0.0, shaped(lowpass(noise(0.4, r), 2500), 0.001, 0.08), 0.4)
    out = reverb(out, size=0.7, wet=0.3)
    return fade_out(out[: seconds(length)], 0.25)


def roll():
    """2.5 s ending where the first place opens: a snare roll and a timpani roll swelling together."""
    length = 2.5
    out = silence(length)
    snare = ramp(rec("snare-roll", start=0.6)[: seconds(length)], [(0.0, -22.0), (length, 0.0)])
    timpani = ramp(rec("timpani-roll", start=0.6)[: seconds(length)], [(0.0, -30.0), (length, -3.0)])
    place(out, 0.0, snare, 0.6)
    place(out, 0.0, timpani, 0.6)
    return fade_out(fade_in(out[: seconds(length)], 0.05), 0.02)


def fanfare():
    """The first place opens: trumpets ta-ta-ta on G4 into a held C major chord, with horn, trombone, timpani and cymbal."""
    length = 2.3
    out = silence(length)
    for at in (0.0, 0.12, 0.24):
        place(out, at, crop(rec("trumpet-g4-short"), 0.14, 0.03), 0.7)
    chord_at = 0.36
    held = length - chord_at
    for name, shift, gain in (("trumpet-a#4", 2.0, 0.36), ("trumpet-d5", 2.0, 0.3), ("trumpet-g4", 0.0, 0.3)):
        place(out, chord_at, fade_out(rec(name, semitones=shift)[: seconds(held)], 0.5), gain)
    place(out, chord_at, fade_out(rec("horn-c4", start=0.0)[: seconds(held)], 0.5), 0.25)
    place(out, chord_at, fade_out(rec("trombone-c4", start=0.0)[: seconds(held)], 0.5), 0.2)
    place(out, chord_at, crop(rec("crash"), held, 0.6), 0.3)
    place(out, chord_at, crop(rec("timpani-c#2", semitones=-1.34), held, 0.6), 0.45)
    return fade_out(out[: seconds(length)], 0.3)


def join():
    """A player joins between games (our synthesis): a soft bubble pop, 380 → 1150 Hz."""
    out = glide(0.14, 380.0, 1150.0, 0.035, attack=0.0015, decay=0.04, partials=((1.0, 1.0), (2.0, 0.1)))
    return fade_out(out, 0.02)


# ── the screen: a board game's moments (ADR 034 decision 31) ─────────────


def move():
    """A move the page saw arrive (our synthesis, «Taxta lövhə», as chosen 2026-11-21): a wooden piece set down on
    a wooden board — sixteen board modes 320–2800 Hz, loudest near 1.15 kHz, a 1.2 ms contact, the table under it
    at 160 Hz; −40 dB inside 70 ms, nothing ringing."""
    return board(rng("move-board"), 320.0, 2800.0, 16, 0.13, contact=0.0012, decay_low=0.018, decay_high=0.006, centre=1150.0, body=0.3, body_hz=160.0)


def capture():
    """A move that took pieces (our synthesis, «Tuk–tuk», as chosen 2026-11-21): the move's touch, then a lower,
    fuller landing 90 ms later (the board's modes 0.8×, the table louder) — two onsets, the second no louder than
    a move."""
    r = rng("capture-two")
    out = silence(0.26)
    place(out, 0.0, move(), 0.55)
    place(out, 0.09, board(r, 230.0, 2000.0, 16, 0.15, contact=0.0018, decay_low=0.026, decay_high=0.007, centre=720.0, body=0.5, body_hz=125.0, body_decay=0.02), 0.95)
    return fade_out(out[: seconds(0.26)], 0.04)


def crown():
    """A man becomes a king, after the move's sound (our synthesis, «Daş daşın üstünə», as chosen 2026-11-21): the
    crowning piece set on the man, as a board crowns by hand — two touches 24 ms apart, brighter than the move,
    nothing ringing."""
    return fade_out(stack(rng("crown-stack"))[: seconds(0.14)], 0.03)


# ── a phone's quiet cues ─────────────────────────────────────────────────


def tap():
    """The viewer's tap, the same for every item: a clave, softly."""
    return crop(lowpass(rec("claves"), 6000), 0.08, 0.03)


def undo():
    """A tap taken back (our synthesis): a soft «tock», 1450 → 1050 Hz, played backwards and softened."""
    r = rng("tap-a")
    body = glide(0.07, 1450.0, 1050.0, 0.02, attack=0.0008, decay=0.014, partials=((1.0, 1.0), (2.0, 0.1)))
    click = shaped(bandpass(noise(0.07, r), 3200.0, 1.2), 0.0005, 0.003)
    tock = fade_out(place(body, 0.0, click, 0.2), 0.01)
    return fade_out(lowpass(tock[::-1], 3000), 0.005)


def right():
    """From the reveal, the viewer's answer was right: a harp C5–E5–G5, up."""
    length = 0.7
    out = silence(length)
    for n, name in enumerate(("harp-c5", "harp-e5", "harp-g5")):
        place(out, n * 0.06, crop(rec(name), length, 0.3), 0.6 + 0.1 * n)
    return fade_out(out[: seconds(length)], 0.2)


def wrong():
    """From the reveal, the viewer's answer was wrong (our synthesis): two round notes falling, E4 then C4 — never a buzzer."""
    length = 0.45
    out = silence(length)
    for f, at in ((note("E4"), 0.0), (note("C4"), 0.12)):
        place(out, at, tone(f, length - at, attack=0.006, decay=0.11, partials=ROUND_NOTE), 0.9)
    return fade_out(out[: seconds(length)], 0.08)


def offer():
    """A draw offer arrives at the viewer (our synthesis): two soft vibraphone-like notes up a fourth, E5 then A5 — never an alarm."""
    length = 0.36
    out = silence(length)
    for at, name, gain in ((0.0, "E5", 0.7), (0.11, "A5", 0.85)):
        place(out, at, tone(note(name), length - at, attack=0.008, decay=0.13, partials=VIBES), gain)
    out = reverb(out, size=0.35, wet=0.12)
    return fade_out(out[: seconds(length)], 0.08)


# ── kept from the first set (ADR 025), as the owner chose ────────────────
# `ranking` and `sent` are the first set's files: their numbers, their
# seeded noise and their encoding (three passes, no limiter) are kept here
# exactly as `a48e56c` made them, so the files do not change.

FIRST_SET = {"ranking", "sent"}


def first_silence(length):
    return [0.0] * int(length * RATE)


def first_place(out, at, signal, gain=1.0):
    first = int(at * RATE)
    for i, s in enumerate(signal):
        if 0 <= first + i < len(out):
            out[first + i] += gain * s
    return out


def first_noise(length, r):
    return [r.uniform(-1.0, 1.0) for _ in range(int(length * RATE))]


def first_bell(freq, length, decay, partials, attack):
    out = []
    for i in range(int(length * RATE)):
        t = i / RATE
        out.append(sum(gain * math.sin(2 * math.pi * freq * ratio * t) * envelope(t, attack, decay * share) for ratio, gain, share in partials))
    return out


def ranking():
    """The ranking comes in (our synthesis, the first set's): six plucks up C5–D5–E5–G5–A5–C6 over a breath of noise."""
    r = random.Random("2026-ranking")
    length, step, decay = 0.45, 0.035, 0.07
    out = first_silence(length)
    pluck = ((1.0, 1.0, 1.0), (2.0, 0.35, 0.5), (3.0, 0.12, 0.3))
    for n, freq in enumerate((523.25, 587.33, 659.26, 783.99, 880.00, 1046.50)):
        at = n * step
        first_place(out, at, first_bell(freq, length - at, decay, pluck, 0.001), 0.45 + 0.08 * n)
    breath = sweep(first_noise(length, r), "band", 900.0, 4000.0, 1.0)
    breath = [s * envelope(i / RATE, 0.08, 0.12) for i, s in enumerate(breath)]
    return first_place(out, 0.0, breath, 0.25)


def sent():
    """The answer is in, the same for every answer (our synthesis, the first set's): an airy swoosh rising through a band, a soft click; no pitch."""
    r = random.Random("2026-sent")
    length, rise = 0.24, 0.09
    air = sweep(first_noise(length, r), "band", 700.0, 3600.0, 1.6)
    out = []
    for i, s in enumerate(air):
        t = i / RATE
        out.append(s * ((t / rise) ** 2 if t < rise else math.exp(-(t - rise) / 0.035)))
    click = shaped(bandpass(first_noise(0.03, r), 2600.0, 2.0), 0.0005, 0.004)
    return first_place(out, 0.17, click, 0.35)


# file name → how it is made; the screen's first, then the phone's.
CUES = {
    "countdown": countdown,
    "go": go,
    "study": study,
    "question": question,
    "clock": clock,
    "close": close,
    "ranking": ranking,
    "place": place_cue,
    "roll": roll,
    "fanfare": fanfare,
    "join": join,
    "move": move,
    "capture": capture,
    "crown": crown,
    "tap": tap,
    "undo": undo,
    "sent": sent,
    "right": right,
    "wrong": wrong,
    "offer": offer,
}


# ── levelling and encoding ───────────────────────────────────────────────


def write_wav(path, samples, fade=None):
    """16-bit mono WAV at −6 dBFS peak, fading out over its last FADE_OUT s (the first set's cues: `fade` samples)."""
    top = peak(samples)
    fade = seconds(FADE_OUT) if fade is None else fade
    frames = bytearray()
    for i, s in enumerate(samples):
        s /= top
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


def duration(path):
    result = subprocess.run(
        [FFPROBE, "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", str(path)],
        capture_output=True, text=True, check=True,
    )
    return float(result.stdout.strip())


def loudness(path, tail=None):
    """(LUFS while it sounds, true peak dBFS) by ffmpeg's ebur128: a cue shorter than LOOP_SECONDS
    looped to it, a longer one over itself; with `tail`, its last `tail` s looped."""
    length = duration(path)
    if tail is not None:
        loops = math.ceil(LOOP_SECONDS / tail)
        chain = f"atrim=start={max(0.0, length - tail):.4f},asetpts=N/SR/TB,aloop=loop={loops}:size={seconds(tail)},ebur128"
        command = [FFMPEG, "-hide_banner", "-nostats", "-i", str(path), "-af", chain]
    elif length < LOOP_SECONDS:
        command = [FFMPEG, "-hide_banner", "-nostats", "-stream_loop", "-1", "-t", str(LOOP_SECONDS), "-i", str(path), "-af", "ebur128"]
    else:
        command = [FFMPEG, "-hide_banner", "-nostats", "-i", str(path), "-af", "ebur128"]
    looped = subprocess.run(command + ["-f", "null", "-"], capture_output=True, text=True, check=True)
    once = subprocess.run(
        [FFMPEG, "-hide_banner", "-nostats", "-i", str(path), "-af", "ebur128=peak=true", "-f", "null", "-"],
        capture_output=True, text=True, check=True,
    )
    integrated = re.search(r"I:\s+(-?[\d.]+) LUFS", looped.stderr.split("Summary:")[-1])
    true_peak = re.search(r"Peak:\s+(-?[\d.]+) dBFS", once.stderr.split("Summary:")[-1])
    if integrated is None or true_peak is None:
        sys.exit(f"Cannot read the loudness of {path}.")
    return float(integrated.group(1)), float(true_peak.group(1))


def write_mp3(wav, mp3, chain):
    """A 96 kb/s mono MP3 without tags, the same bytes for the same input."""
    subprocess.run(
        [
            FFMPEG, "-hide_banner", "-loglevel", "error", "-y", "-i", str(wav),
            "-af", chain,
            "-ac", "1", "-ar", str(RATE), "-c:a", "libmp3lame", "-b:a", "96k",
            "-map_metadata", "-1", "-id3v2_version", "0", "-write_id3v1", "0",
            "-fflags", "+bitexact", "-flags:a", "+bitexact",
            str(mp3),
        ],
        check=True,
    )


def encode(wav, mp3, tail=None, limit_db=LIMIT_DB):
    """Levels `wav` to LOUDNESS and writes the MP3; a few passes correct what the encoder moves.
    Where the true peak still passes MAX_TRUE_PEAK, the limiter goes lower."""
    measured, _ = loudness(wav, tail)
    gain = LOUDNESS - measured
    integrated = true_peak = None
    for _ in range(4):
        limiter = f"alimiter=limit={10 ** (limit_db / 20):.4f}:attack=1:release=60:level=0:latency=1"
        write_mp3(wav, mp3, f"volume={gain:.2f}dB,{limiter}")
        integrated, true_peak = loudness(mp3, tail)
        step = LOUDNESS - integrated
        gain += step
        if abs(step) < 0.05:
            break
    if true_peak > MAX_TRUE_PEAK:
        if limit_db < -6:
            sys.exit(f"{mp3.name}: true peak {true_peak} dBFS is above {MAX_TRUE_PEAK}.")
        return encode(wav, mp3, tail, limit_db - 1.0)
    return integrated, true_peak


def encode_first(wav, mp3):
    """The first set's levelling, for the cues kept from it: three passes and no limiter."""
    measured, _ = loudness(wav)
    gain = LOUDNESS - measured
    integrated = true_peak = None
    for _ in range(3):
        write_mp3(wav, mp3, f"volume={gain:.2f}dB")
        integrated, true_peak = loudness(mp3)
        gain += LOUDNESS - integrated
    if true_peak > MAX_TRUE_PEAK:
        sys.exit(f"{mp3.name}: true peak {true_peak} dBFS is above {MAX_TRUE_PEAK}.")
    return integrated, true_peak


def main():
    global _cache_dir
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--out", type=Path, default=OUT, help="folder to write the MP3 files into")
    parser.add_argument("--cache", type=Path, default=CACHE, help="folder the recordings are downloaded into")
    parser.add_argument("--only", nargs="*", choices=sorted(CUES), help="make only these cues")
    args = parser.parse_args()
    _cache_dir = args.cache

    if not Path(FFMPEG).exists() or not Path(FFPROBE).exists():
        sys.exit("ffmpeg is needed (brew install ffmpeg).")

    args.out.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory() as temp:
        for name, render in CUES.items():
            if args.only and name not in args.only:
                continue
            samples = render()
            wav = Path(temp) / f"{name}.wav"
            mp3 = args.out / f"{name}.mp3"
            write_wav(wav, samples, int(FADE_OUT * RATE) if name in FIRST_SET else None)
            tail = BUILDS.get(name)
            integrated, true_peak = encode_first(wav, mp3) if name in FIRST_SET else encode(wav, mp3, tail)
            measured = "its last second" if tail else "while it sounds"
            print(
                f"{mp3.name:<14} {duration(mp3) * 1000:5.0f} ms, {mp3.stat().st_size / 1024:4.1f} KB, "
                f"{integrated} LUFS ({measured}), true peak {true_peak} dBFS",
                flush=True,
            )


if __name__ == "__main__":
    main()
