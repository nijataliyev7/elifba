#!/usr/bin/env python3
"""
The oracle of Russian draughts (docs/architecture.md §12 "Board games",
ADR 034 decisions 32–33): an independent library, pydraughts 0.6.7 (MIT),
answers what the game's rules allow, and this script writes its answers
as the fixtures `DraughtsRulesTest` reads. The judge of a game played here
is PHP's `DraughtsRules`; the library decides nothing about it, and
neither CI nor the app runs Python.

    python3 scripts/draughts-oracle/make.py              # writes tests/Fixtures/rooms/draughts/{perft,positions,games}.json
    python3 scripts/draughts-oracle/make.py --out DIR    # writes them elsewhere
    python3 scripts/draughts-oracle/make.py --env DIR    # keeps the library's environment in DIR between runs

The library's wheel is downloaded from PyPI and checked against its SHA-256
before it is installed into a virtual environment of its own — a temporary
directory, removed after the run, unless `--env` names one. Plain Python 3
(3.8 or later) and, the first time, the network. Seeded throughout: the
same library gives the same bytes.

Squares: the library numbers the 32 dark squares; this script names them
through the library's own FEN (a position with one piece on the numbered
square, read back in the variant's FEN, which writes `a1`–`h8`), never
through a table of its own. A move is written as the app writes it
(`DraughtsNotation::text()`): the start and every landing, `-` for a quiet
move and `:` for a capture.

Where the library and the official text disagree on a move, the text
decides: `overrides.json` beside this script gives the official answer
for that position, this script writes it in place of the library's, and
README.md here records the case with its article. The library's draws are not
the official ones, so no draw is taken from it: `draws.json` is written
by hand.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import random
import shutil
import subprocess
import sys
import tempfile
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "tests" / "Fixtures" / "rooms" / "draughts"
# The official answer where the library is overruled, written by hand.
OVERRIDES = Path(__file__).resolve().parent / "overrides.json"

VERSION = "0.6.7"
WHEEL = "pydraughts-0.6.7-py3-none-any.whl"
WHEEL_URL = (
    "https://files.pythonhosted.org/packages/a6/43/"
    "249a34530f590dd041c3825fb2b4e67345c1821f93bfdb60652340b59408/" + WHEEL
)
WHEEL_SHA256 = "60ac89bbd00642ecf19dd981e9ccd4f86d3c0036f9c8d655213c4b7e1f8e7087"
VARIANT = "russian"
ABOUT = f"pydraughts {VERSION} (MIT), variant {VARIANT}; written by scripts/draughts-oracle/make.py — never edit by hand"

# perft from the starting position, to depth 7 (ADR 034 decision 32).
START_DEPTH = 7
# perft from every other position goes as deep as stays under this many
# move sequences, so that Pest runs the whole file in seconds.
PERFT_LEAVES = 12000
PERFT_MAX_DEPTH = 6

SEED = 20261120
# Random games for positions.json: every position reached, until at least
# this many distinct ones (ADR 034 decision 32 asks for 1000).
RANDOM_POSITIONS = 1200
# A random game stops here if nobody has won by then: the library's draws
# are not the official ones, and kings can shuffle for ever.
MAX_PLIES = 240
# Seeded games written move by move to games.json.
GAMES = 12
# Positions scattered at random — up to eight pieces a side on any dark
# squares, about half of them kings — where long kings' captures are the
# rule rather than the exception, as random play seldom makes them.
SCATTERED = 600
# Positions reached by random play that perft.json also counts from: some
# with kings on the board, some with a capture of several pieces to make.
PERFT_KINGS = 8
PERFT_CAPTURES = 4

# Positions written by hand: each line of ADR 034 decision 4's table, the
# official text's examples of the «Turkish strike» (6.1.5.17, figures 5
# and 6, rebuilt from their text) and the contributor's twelve cases. The
# library answers each; `perft` says whether perft.json counts from it.
NAMED = [
    ("start", "6.1.1, 6.1.3.1, 6.1.4.1", "W:Wa1,a3,b2,c1,c3,d2,e1,e3,f2,g1,g3,h2:Ba7,b6,b8,c7,d6,d8,e7,f6,f8,g7,h6,h8", False),
    ("a white man steps forward only", "6.1.4.3", "W:Wc3:Bf6", True),
    ("a black man steps forward only", "6.1.4.3", "B:Wc3:Bf6", True),
    ("a man steps onto the last row and is crowned", "6.1.4.4", "W:Wc7:Ba1", True),
    ("a king moves along a diagonal, never over its own piece", "6.1.4.5", "W:WKd4,f6:Bb8", True),
    ("capturing is compulsory", "6.1.5.1, 6.1.5.3", "W:Wc3,g3:Bd4", True),
    ("a man captures backwards", "6.1.5.3", "W:Wc5:Bd4", True),
    ("a king captures at a distance and lands on any free square", "6.1.5.2", "W:WKa1:Bd4", True),
    ("the choice among captures is free, whatever their number", "6.1.5.14", "W:Wc3:Bb4,d4,d6,f6", True),
    ("a capture goes on to its end", "6.1.5.5", "W:Wb2:Bc3,c5", True),
    ("a king lands where it can go on capturing", "6.1.5.6, IDF 4.6", "W:WKa1:Bd4,e7", True),
    ("a king takes two pieces on one diagonal, landing between them as it likes", "6.1.5.6", "W:WKa1:Bc3,f6", True),
    ("a king's capture goes on to its end", "6.1.5.6", "W:WKh2:Bf4,c5,e7,b6", True),
    ("a man crowned in a capture goes on as a king", "6.1.5.15", "W:Wb6:Bc7,f6", True),
    ("a man crowned by its last capture ends there a king", "6.1.5.15", "W:Wb6:Bc7,h4", True),
    ("a man crowned by a step stops there", "6.1.5.16", "W:Wc7:Ba7,e7", True),
    ("the king crowned by a step captures next move", "6.1.5.16", "W:WKd8:Bb6,e7", True),
    ("a man goes round a square and back to where it started", "6.1.5.9", "W:Wc3:Bb4,b6,d4,d6", True),
    ("a king never jumps a taken piece twice", "6.1.5.9, 6.1.5.12", "W:WKb2:Bc3,c5,e3,e5", True),
    ("the Turkish strike, figure 5: the king stops on d4", "6.1.5.17", "W:WKe1:Bb6,c3,c5,e5,e7", True),
    ("the Turkish strike, figure 6: the new king stops on c3", "6.1.5.17", "B:Wa1,b2,b4,d2,d4,f2,f4:Ba5,c5,c7,e7,g7", True),
    ("figure 6, the reply after a5:c3:e1:g3:e5:c3", "6.1.5.17", "W:Wa1,b2:BKc3,c5,c7,e7,g7", True),
    ("a long capture by a king", "6.1.5.6", "W:WKa1:Bb2,b4,b6,d6,f6,f4,d2", True),
    ("every piece blocked: no move", "6.1.7", "W:Wa1:Bb2,c3", False),
    ("no piece left: no move", "6.1.7", "W:W:Bd4", False),
    ("kings and men in the middle game", "6.1.4–6.1.5", "W:WKc1,e3,g3,b2,d4:BKh8,c7,e5,f6,h6,b6", True),
    ("kings on both sides, captures both ways", "6.1.5.2, 6.1.5.6", "B:WKa3,Kh2,c5,e5:BKb8,Kg1,d6,f6,g7", True),
]


# ── the bootstrap: a virtual environment with the pinned wheel ──────────

def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1 << 16), b""):
            digest.update(block)
    return digest.hexdigest()


def environment(env: Path) -> Path:
    """The environment's Python, with pydraughts installed from the checked wheel."""
    python = env / "bin" / "python"
    wheel = env / "wheel" / WHEEL

    if not wheel.exists() or sha256(wheel) != WHEEL_SHA256:
        wheel.parent.mkdir(parents=True, exist_ok=True)
        print(f"downloading {WHEEL}", file=sys.stderr)
        with urllib.request.urlopen(WHEEL_URL, timeout=60) as response:
            wheel.write_bytes(response.read())

    if sha256(wheel) != WHEEL_SHA256:
        wheel.unlink()
        sys.exit(f"{WHEEL} does not match its SHA-256; nothing was installed")

    if not python.exists():
        subprocess.run([sys.executable, "-m", "venv", str(env)], check=True)

    requirement = env / "requirements.txt"
    requirement.write_text(f"pydraughts=={VERSION} --hash=sha256:{WHEEL_SHA256}\n")
    # --no-deps: the library's one dependency serves only its engines.
    subprocess.run(
        [str(python), "-m", "pip", "install", "--quiet", "--disable-pip-version-check", "--no-deps",
         "--no-index", "--require-hashes", "--find-links", str(wheel.parent), "-r", str(requirement)],
        check=True,
    )
    return python


def bootstrap(args: argparse.Namespace) -> None:
    keep = args.env is not None
    env = Path(args.env).resolve() if keep else Path(tempfile.mkdtemp(prefix="draughts-oracle-"))
    try:
        python = environment(env)
        # -I: the environment's library only, nothing from the current directory.
        subprocess.run([str(python), "-I", str(Path(__file__).resolve()), "--inside", "--out", str(Path(args.out).resolve())],
                       check=True)
    finally:
        if not keep:
            shutil.rmtree(env, ignore_errors=True)


# ── inside the environment: the library answers ─────────────────────────

def generate(out: Path) -> None:
    import draughts
    from draughts import Board

    if draughts.__version__ != VERSION:
        sys.exit(f"pydraughts {draughts.__version__} is not {VERSION}")

    def piece_square(fen: str) -> str:
        return fen.split(":")[1][1:]

    # The name of each numbered square, read back from the library's FEN.
    names = {}
    for number in range(1, 33):
        names[number] = piece_square(Board(variant=VARIANT, fen=f"W:W{number}:B").fen)
    if len(set(names.values())) != 32:
        sys.exit("the library's squares are not 32 distinct names")

    def fen_squares(fen: str, color: str) -> set:
        part = next(p for p in fen.split(":")[1:] if p.startswith(color))
        return {s.lstrip("K") for s in part[1:].split(",") if s}

    def legal(board) -> dict:
        """Every legal move as its line, with the squares it took."""
        moves = {}
        mover = "W" if board.fen.startswith("W") else "B"
        other = "B" if mover == "W" else "W"
        for move in board.legal_moves():
            path = [names[s] for s in move.steps_move]
            captured = [names[s] for s in move.captures]
            line = (":" if captured else "-").join(path)
            if line in moves:
                sys.exit(f"the library gives {line} twice in {board.fen}")
            # The squares read through the FEN agree with the move's numbers.
            after = board.copy()
            after.push(move)
            if path[-1] not in fen_squares(after.fen, mover) or set(captured) & fen_squares(after.fen, other):
                sys.exit(f"{line} in {board.fen} does not land where its FEN says")
            moves[line] = captured
        return dict(sorted(moves.items()))

    def perft(board, depth: int) -> int:
        moves = unchanged(board).legal_moves()
        if depth == 1:
            return len(moves)
        count = 0
        for move in moves:
            board.push(move)
            count += perft(board, depth - 1)
            board.pop()
        return count

    def counts(board, max_depth: int, limit) -> list:
        """perft at each depth, as deep as stays under `limit` sequences."""
        found = []
        for depth in range(1, max_depth + 1):
            # The next depth would grow by about the last depth's factor.
            if limit is not None and len(found) >= 2 and found[-1] * found[-1] > limit * max(found[-2], 1):
                break
            count = perft(board, depth)
            if limit is not None and count > limit:
                break
            found.append(count)
            if count == 0:
                break
        return found

    official = {entry["fen"]: entry["moves"] for entry in json.loads(OVERRIDES.read_text())["positions"]}

    def answer(board) -> dict:
        """The legal moves: the official text's where the library is overruled."""
        return official[board.fen] if board.fen in official else legal(board)

    def unchanged(board):
        """A board perft or a game plays on, which an override would make wrong."""
        if board.fen in official:
            sys.exit(f"{board.fen} is overruled: perft.json and games.json would need the official answer too")
        return board

    rng = random.Random(SEED)

    # Seeded random games: positions.json's positions and games.json's games.
    seen = {}
    games = []
    game_number = 0
    while len(seen) < RANDOM_POSITIONS or len(games) < GAMES:
        game_number += 1
        board = Board(variant=VARIANT)
        lines, fens = [], []
        while True:
            if board.fen not in seen and len(seen) < RANDOM_POSITIONS:
                seen[board.fen] = answer(board)
            moves = unchanged(board).legal_moves()
            if not moves or len(lines) == MAX_PLIES:
                break
            move = rng.choice(moves)
            path = [names[s] for s in move.steps_move]
            lines.append((":" if move.captures else "-").join(path))
            board.push(move)
            fens.append(board.fen)
        if len(games) < GAMES:
            games.append({"game": game_number, "moves": lines, "fens": fens, "end": "no_moves" if not board.legal_moves() else "unfinished"})

    def placed(chosen: list, last_rank: str) -> str:
        """Pieces on the chosen squares, about half of them kings; a man
        never stands on its own last row, where it would be a king."""
        return ",".join(("K" if rng.random() < 0.5 or square[1] == last_rank else "") + square for square in chosen)

    scattered = []
    squares = list(names.values())
    while len(scattered) < SCATTERED:
        rng.shuffle(squares)
        white, black = rng.randint(1, 8), rng.randint(1, 8)
        fen = f"{rng.choice('WB')}:W{placed(squares[:white], '8')}:B{placed(squares[white:white + black], '1')}"
        board = Board(variant=VARIANT, fen=fen)
        scattered.append({"fen": board.fen, "moves": answer(board)})

    named = []
    for name, article, fen, _ in NAMED:
        board = Board(variant=VARIANT, fen=fen)
        named.append({"name": name, "article": article, "fen": board.fen, "moves": answer(board)})

    def spread(items: list, count: int) -> list:
        return [items[index * len(items) // count] for index in range(count)] if len(items) > count else items

    def pieces(fen: str) -> int:
        return len(fen_squares(fen, "W")) + len(fen_squares(fen, "B"))

    kingly = [fen for fen in seen if fen.count("K") >= 2 and pieces(fen) >= 6]
    capturing = [fen for fen, moves in seen.items() if any(line.count(":") >= 2 for line in moves) and fen not in kingly]
    perft_random = [(f"random play, kings on the board, {index}", fen) for index, fen in enumerate(spread(kingly, PERFT_KINGS), start=1)]
    perft_random += [(f"random play, a capture of several pieces, {index}", fen) for index, fen in enumerate(spread(capturing, PERFT_CAPTURES), start=1)]

    start = Board(variant=VARIANT)
    perft_positions = []
    for name, article, fen, counted in NAMED:
        if counted:
            board = Board(variant=VARIANT, fen=fen)
            perft_positions.append({"name": name, "fen": board.fen, "counts": counts(board, PERFT_MAX_DEPTH, PERFT_LEAVES)})
    for name, fen in perft_random:
        board = Board(variant=VARIANT, fen=fen)
        perft_positions.append({"name": name, "fen": board.fen, "counts": counts(board, PERFT_MAX_DEPTH, PERFT_LEAVES)})

    write(out / "perft.json", {
        "about": ABOUT,
        "start": {"fen": start.fen, "counts": counts(start, START_DEPTH, None)},
        "positions": perft_positions,
    }, "positions")
    write(out / "positions.json", {
        "about": ABOUT,
        "seed": SEED,
        "named": named,
        "random": [{"fen": fen, "moves": moves} for fen, moves in seen.items()],
        "scattered": scattered,
    }, "named", "random", "scattered")
    write(out / "games.json", {"about": ABOUT, "seed": SEED, "games": games}, "games")


def write(path: Path, data: dict, *lists: str) -> None:
    """JSON with one entry of each named list on a line, so a diff shows what changed."""
    def compact(value) -> str:
        return json.dumps(value, ensure_ascii=False, separators=(", ", ": "))

    lines = ["{"]
    keys = list(data)
    for index, key in enumerate(keys):
        comma = "," if index < len(keys) - 1 else ""
        if key in lists:
            items = data[key]
            lines.append(f"    {json.dumps(key)}: [")
            for position, item in enumerate(items):
                lines.append(f"        {compact(item)}{',' if position < len(items) - 1 else ''}")
            lines.append(f"    ]{comma}")
        else:
            lines.append(f"    {json.dumps(key)}: {compact(data[key])}{comma}")
    lines.append("}")
    path.write_text("\n".join(lines) + "\n")
    print(f"{path}: {path.stat().st_size / 1024:.1f} KB", file=sys.stderr)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--out", default=str(OUT), help="where the fixtures go (default: tests/Fixtures/rooms/draughts)")
    parser.add_argument("--env", default=None, help="keep the library's virtual environment here between runs")
    parser.add_argument("--inside", action="store_true", help=argparse.SUPPRESS)
    args = parser.parse_args()

    if args.inside:
        Path(args.out).mkdir(parents=True, exist_ok=True)
        generate(Path(args.out))
    else:
        bootstrap(args)


if __name__ == "__main__":
    main()
