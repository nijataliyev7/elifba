#!/usr/bin/env python3
"""
The «101» oracle (docs/architecture.md §12 "Domino (Y4–Y6)" → "The rules",
ADR 038 decision 58): the rules of «101» written a second time, in plain
Python, from ADR 038's text alone — decisions 15, 18, 33 (R1–R26) and 34
(the eleven readings) — by a session that was never shown the PHP judge.
No library plays these local rules, so the independence is the writer's.
The judge of a game played in a room is PHP's `DominoRules` and
`DominoLedger`; this script decides nothing about it, and neither CI, the
app nor an image runs it: the gate reads the committed fixtures.

    python3 scripts/domino-oracle/make.py              # writes tests/Fixtures/rooms/domino/{hands,ledger,games}.json, then checks cases.json and study.json
    python3 scripts/domino-oracle/make.py --out DIR    # writes the three files elsewhere
    python3 scripts/domino-oracle/make.py --check      # only replays cases.json and study.json

Plain Python 3 (3.8 or later), standard library only. It knows nothing of a
room's seed: every hand it writes sets its deal — each seat's seven tiles and
the boneyard in the order it is drawn — from its own fixed-seed source
(SplitMix64, below), so the same script gives the same bytes on every
machine. `cases.json` and `study.json` are written by hand and only read
here: `--check` (and every run) replays them and says where the oracle
differs.

Names are ADR 038's: a tile is its two numbers, the larger first, `6-5`
(decision 15); an end is `left` or `right`; endings, opener reasons and
ledger lines are decision 38's `RoomHandEnding`, `OpenerWhy` and
`LedgerLine` values. Where both ends show one number the judge lists the
left alone (decision 38), and so does this script.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "tests" / "Fixtures" / "rooms" / "domino"
CASES = OUT / "cases.json"
STUDY = OUT / "study.json"

SEED = 20261126
ABOUT = (
    "ADR 038 «101», written by scripts/domino-oracle/make.py from the ADR's text "
    "— never edit by hand; README.md beside the script has the format"
)

# --------------------------------------------------------------------------
# The source: SplitMix64, so no deal depends on Python's own generator.
# --------------------------------------------------------------------------

MASK = (1 << 64) - 1


class Source:
    """A fixed-seed source of numbers (SplitMix64) and the shuffles made from it."""

    def __init__(self, seed: int) -> None:
        self.state = seed & MASK

    def next64(self) -> int:
        self.state = (self.state + 0x9E3779B97F4A7C15) & MASK
        z = self.state
        z = ((z ^ (z >> 30)) * 0xBF58476D1CE4E5B9) & MASK
        z = ((z ^ (z >> 27)) * 0x94D049BB133111EB) & MASK
        return z ^ (z >> 31)

    def below(self, n: int) -> int:
        """A whole number in [0, n), without bias."""
        limit = (1 << 64) - ((1 << 64) % n)
        while True:
            x = self.next64()
            if x < limit:
                return x % n

    def chance(self, num: int, den: int) -> bool:
        return self.below(den) < num

    def pick(self, items: list):
        return items[self.below(len(items))]

    def shuffle(self, items: list) -> None:
        for i in range(len(items) - 1, 0, -1):
            j = self.below(i + 1)
            items[i], items[j] = items[j], items[i]


# --------------------------------------------------------------------------
# R1 — the tiles; decision 15 — their names.
# --------------------------------------------------------------------------

TILES = [f"{high}-{low}" for high in range(7) for low in range(high + 1)]


def numbers(tile: str) -> tuple[int, int]:
    high, low = tile.split("-")
    return int(high), int(low)


def tile_name(a: int, b: int) -> str:
    return f"{max(a, b)}-{min(a, b)}"


def pips(tile: str) -> int:
    a, b = numbers(tile)
    return a + b


def is_double(tile: str) -> bool:
    a, b = numbers(tile)
    return a == b


def hand_pips(tiles: list[str]) -> int:
    """R16: the sum of the tiles' pips — but a hand that holds `0-0` alone counts 10."""
    if tiles == ["0-0"]:
        return 10
    return sum(pips(t) for t in tiles)


# --------------------------------------------------------------------------
# R2, decision 10 — the tables, their seats and sides.
# --------------------------------------------------------------------------

SEATS = {"two": 2, "three": 3, "four": 4, "pairs": 4}
TABLES = list(SEATS)
WITH_BONEYARD = ("two", "three")


def side_of(table: str, seat: int) -> int:
    """A side is a seat at the first three tables; at `pairs` seats 0 and 2 against 1 and 3."""
    return seat % 2 if table == "pairs" else seat


def side_count(table: str) -> int:
    return 2 if table == "pairs" else SEATS[table]


def seats_of(table: str, side: int) -> list[int]:
    return [s for s in range(SEATS[table]) if side_of(table, s) == side]


def deal_from(source: Source, table: str) -> dict:
    """One try: the 28 tiles shuffled, seven to seat 0, the next seven to seat 1 …, the rest the boneyard in draw order."""
    tiles = list(TILES)
    source.shuffle(tiles)
    n = SEATS[table]
    return {"seats": [tiles[7 * s: 7 * s + 7] for s in range(n)], "boneyard": tiles[7 * n:]}


def thrown(seats: list[list[str]]) -> list[dict]:
    """
    R4: why a deal is dealt again — a seat with five or more doubles, or with
    five or more of the six tiles that pair one number with another (the
    reading of decision 34: the double is not counted either way). Empty
    when the deal stands. The boneyard is no seat's.
    """
    why = []
    for seat, tiles in enumerate(seats):
        doubles = sum(1 for t in tiles if is_double(t))
        if doubles >= 5:
            why.append({"seat": seat, "doubles": doubles})
        for number in range(7):
            count = sum(1 for t in tiles if not is_double(t) and number in numbers(t))
            if count >= 5:
                why.append({"seat": seat, "number": number, "tiles": count})
    return why


def dealt(source: Source, table: str) -> tuple[dict, list[dict]]:
    """A deal that stands, and the tries R4 threw before it (never shown: decision 34)."""
    tries = []
    while True:
        deal = deal_from(source, table)
        why = thrown(deal["seats"])
        if not why:
            return deal, tries
        tries.append({"deal": deal, "why": why})


# --------------------------------------------------------------------------
# R3, R5–R19 — one hand.
# --------------------------------------------------------------------------


class Illegal(Exception):
    pass


class Hand:
    """
    One hand folded from its deal and its acts. `open_sides` says whose
    accounts are open when it starts (R6–R8); `right` is the seat with the
    right to open under R8 and why — the hand before decides it, so a
    hand on its own is told it.
    """

    def __init__(self, table: str, deal: dict, open_sides: list[bool], right: tuple[int, str] | None = None) -> None:
        self.table = table
        self.n = SEATS[table]
        assert len(deal["seats"]) == self.n and all(len(s) == 7 for s in deal["seats"])
        everything = [t for s in deal["seats"] for t in s] + list(deal["boneyard"])
        assert sorted(everything) == sorted(TILES), "a deal holds the 28 tiles once"
        self.hands = [list(s) for s in deal["seats"]]
        self.boneyard = list(deal["boneyard"])
        self.open = list(open_sides)
        self.any_open = any(open_sides)
        self.line: list[list] = []  # [tile, left number, right number], from the left end to the right
        self.ends: tuple[int, int] | None = None
        self.last: int | None = None  # the seat that made the last play
        self.ending: str | None = None
        self.by: int | None = None
        self.then: list[dict] = []
        self.odd = False  # R12: a single tile was drawn (three left)
        self.shut = False  # R13: a seat passed for the closed boneyard while it held more than two
        self.passes = 0
        self.drawn = 0
        if self.any_open:
            # R8: the seat with the right opens with any tile it holds.
            assert right is not None, "R8 needs the seat with the right to open"
            self.right: tuple[int, str] | None = (right[0], right[1])
            self.turn, self.allowed = right[0], None
            self.opener = {"seat": right[0], "why": right[1]}
            self.may_yield = table == "pairs"  # R9
        else:
            self.right = None
            seat, why, tile = self.lowest()
            self.turn, self.allowed = seat, tile
            self.opener = {"seat": seat, "why": why}
            self.may_yield = False  # R9: under R6 and R7 nobody gives
        self.yielded = False

    # R6, R7
    def lowest(self) -> tuple[int, str, str]:
        for number in range(1, 7):  # `0-0` is not counted
            double = f"{number}-{number}"
            for seat in range(self.n):
                if double in self.hands[seat]:
                    return seat, "lowest_double", double
        # R7 (decision 34): the fewest pips, then the smaller larger number; nobody draws for it.
        held = [(pips(t), numbers(t)[0], t, seat) for seat in range(self.n) for t in self.hands[seat]]
        _, _, tile, seat = min(held)
        return seat, "lowest_tile", tile

    def fits(self, tile: str) -> bool:
        left, right = self.ends
        return left in numbers(tile) or right in numbers(tile)

    def holds_fit(self, seat: int) -> bool:
        return any(self.fits(t) for t in self.hands[seat])

    def closed(self) -> bool:
        """R13 (decision 34): for each open number the six tiles that pair it with another number all lie in the line."""
        if self.ends is None or self.table not in WITH_BONEYARD:
            return False
        on_line = {entry[0] for entry in self.line}
        for number in set(self.ends):
            for other in range(7):
                if other != number and tile_name(number, other) not in on_line:
                    return False
        return True

    def legal(self) -> list[list]:
        """The plays of the seat to move: [tile, ends], in the order the seat holds its tiles."""
        if self.ending is not None:
            return []
        hand = self.hands[self.turn]
        if not self.line:
            tiles = hand if self.allowed is None else [self.allowed]
            return [[t, []] for t in tiles]
        left, right = self.ends
        out = []
        for t in hand:
            a, b = numbers(t)
            ends = []
            if left in (a, b):
                ends.append("left")
            if right in (a, b) and right != left:  # both ends one number: the left alone
                ends.append("right")
            if ends:
                out.append([t, ends])
        return out

    def can_yield(self) -> bool:
        return self.ending is None and self.may_yield and not self.yielded and not self.line

    def give(self) -> None:
        """R9 «Sən aç»: the seat with the right gives the opening to its partner, who cannot give it back."""
        if not self.can_yield():
            raise Illegal("yield")
        self.yielded = True
        self.may_yield = False
        self.turn = (self.turn + 2) % 4
        self.opener = {"seat": self.turn, "why": "given"}
        self.then = []

    def play(self, tile: str, end: str | None = None) -> None:
        options = {t: ends for t, ends in self.legal()}
        if tile not in options:
            raise Illegal(tile)
        if self.line:
            if end not in options[tile]:
                raise Illegal(f"{tile} {end}")
        elif end is not None:
            raise Illegal(f"{tile} {end}: a hand's first tile has no end")
        seat = self.turn
        self.hands[seat].remove(tile)
        self.may_yield = False
        a, b = numbers(tile)
        if not self.line:
            # The first tile lies as its name is written: the larger number at the left end.
            self.line = [[tile, a, b]]
            self.ends = (a, b)
        elif end == "left":
            left, right = self.ends
            other = b if a == left else a
            self.line.insert(0, [tile, other, left])
            self.ends = (other, right)
        else:
            left, right = self.ends
            other = b if a == right else a
            self.line.append([tile, right, other])
            self.ends = (left, other)
        self.last = seat
        self.then = []
        if not self.hands[seat]:
            self.end("finished", seat)  # R15
            return
        if len(self.line) == 1 and self.table == "pairs":
            # R5: neither opponent of the first tile's player holds a tile that fits it.
            opponents = [(seat + 1) % 4, (seat + 3) % 4]
            if not any(self.holds_fit(o) for o in opponents):
                self.end("void", seat)
                return
        self.follow(seat)

    def follow(self, seat: int) -> None:
        """Decision 18: draws and passes follow by themselves, round the table, to a seat that can play or to a blocked hand."""
        current = seat
        passed = 0
        while True:
            current = (current + 1) % self.n
            drew: list[str] = []
            while not self.holds_fit(current) and len(self.boneyard) > 2 and not self.closed():
                take = 2 if len(self.boneyard) - 2 >= 2 else 1  # R12: one when two would leave fewer than two
                if take == 1:
                    self.odd = True
                drew += self.boneyard[:take]
                self.hands[current] += self.boneyard[:take]
                del self.boneyard[:take]
            self.drawn += len(drew)
            if self.holds_fit(current):
                if drew:
                    self.then.append({"seat": current, "drew": drew, "passed": False})
                self.turn = current
                return
            # R14
            if not drew and len(self.boneyard) > 2 and self.closed():
                self.shut = True
            self.then.append({"seat": current, "drew": drew, "passed": True})
            self.passes += 1
            passed += 1
            if passed == self.n:
                self.end("blocked", self.last)  # R15: no seat can play and none may draw
                return

    def end(self, ending: str, by: int) -> None:
        self.ending = ending
        self.by = by
        self.turn = None
        if ending == "blocked":
            counts = self.counts()
            low = min(c["pips"] for c in counts)
            if sum(1 for c in counts if c["pips"] == low) > 1:
                self.ending = "tied"  # R19

    def left(self) -> list[dict]:
        return [{"seat": s, "tiles": list(self.hands[s]), "pips": hand_pips(self.hands[s])} for s in range(self.n)]

    def counts(self) -> list[dict]:
        each = [hand_pips(h) for h in self.hands]
        return [{"side": side, "pips": sum(each[s] for s in seats_of(self.table, side))} for side in range(side_count(self.table))]

    def winner_and_gain(self) -> tuple[int | None, int]:
        counts = self.counts()
        if self.ending == "finished":
            # R17: the finisher's side gains every other side's pips, a partner's not counted.
            winner = side_of(self.table, self.by)
        elif self.ending == "blocked":
            # R18: the one side with the lowest count gains the counts of all the others.
            winner = min(counts, key=lambda c: c["pips"])["side"]
        else:
            return None, 0  # tied (R19) or void (R5)
        return winner, sum(c["pips"] for c in counts if c["side"] != winner)

    def pot(self) -> int:
        """R19: the pips of all seats, for the bank."""
        return sum(c["pips"] for c in self.counts())

    def fewest(self, winner: int | None) -> int:
        """R8(b) and decision 34: the seat with the fewest pips; ties — the winning side first, then turn order from the last player."""
        each = [hand_pips(h) for h in self.hands]
        low = min(each)
        tied = [s for s in range(self.n) if each[s] == low]
        ours = [s for s in tied if side_of(self.table, s) == winner]
        if ours:
            tied = ours
        for k in range(self.n):
            seat = (self.last + k) % self.n
            if seat in tied:
                return seat
        raise AssertionError

    def next_opener(self) -> dict:
        """Who opens the next hand (R6–R8); seat null while the next deal decides it."""
        winner, gain = self.winner_and_gain()
        open_after = self.any_open or (winner is not None and gain >= 13)
        if not open_after:
            return {"seat": None, "why": "lowest_double"}
        if self.ending == "finished":
            return {"seat": self.by, "why": "finished"}
        if self.ending == "blocked":
            return {"seat": self.fewest(winner), "why": "fewest"}
        if self.ending == "tied":
            return {"seat": self.by, "why": "blocker"}
        return {"seat": self.right[0], "why": "again"}  # void: the seat with the right to open it

    def result(self) -> dict:
        winner, gain = self.winner_and_gain()
        return {
            "ending": self.ending,
            "by": self.by,
            "winner": winner,
            "left": self.left(),
            "counts": self.counts(),
            "gain": gain,
            "next": self.next_opener(),
        }

    def ledger_result(self) -> dict:
        winner, gain = self.winner_and_gain()
        out = {"ending": self.ending, "winner": winner, "gain": gain}
        if self.ending == "tied":
            out["pot"] = self.pot()
        return out


# --------------------------------------------------------------------------
# R20–R26 — the account between hands.
# --------------------------------------------------------------------------

TARGET = 101
OPENS = 13
TRIES = 3


class Ledger:
    def __init__(self, sides: int) -> None:
        self.sides = [{"balance": 0, "open": False, "waiting": 0, "tries": 0} for _ in range(sides)]  # R20
        self.bank = 0
        self.over = False
        self.winner: int | None = None
        self.dry = False

    def apply(self, result: dict) -> list[list]:
        """One hand's result; the lines of the account in the order the oracle writes them (README: the order is not a rule)."""
        assert not self.over, "a game over takes no hand"
        lines: list[list] = []
        if result.get("ending") == "void":
            return lines  # R5: nothing is counted
        if result["winner"] is None:
            self.bank += result["pot"]  # R19, R24: a tie adds to the bank
            lines.append([None, "bank_added", result["pot"]])
            return lines
        side, gain = result["winner"], result["gain"]
        w = self.sides[side]
        if w["open"]:
            w["balance"] += gain  # R20
            lines.append([side, "gained", gain])
        elif gain >= OPENS:
            # R21: its waiting points and the gain go to its balance, and nothing waits any more.
            w["open"] = True
            lines.append([side, "opened", gain])
            if w["waiting"]:
                lines.append([side, "released", w["waiting"]])
            w["balance"] += gain + w["waiting"]
            w["waiting"] = 0
            w["tries"] = 0
        else:
            # R22: one try more; at the third the waiting points burn, the third gain with them.
            w["waiting"] += gain
            w["tries"] += 1
            lines.append([side, "waited", gain])
            if w["tries"] == TRIES:
                lines.append([side, "burnt_third", w["waiting"]])
                w["waiting"] = 0
                w["tries"] = 0
        if self.bank:
            # R24: the bank goes to a winner whose account is open or opens now, and burns otherwise.
            if w["open"]:
                w["balance"] += self.bank
                lines.append([side, "bank_taken", self.bank])
            else:
                lines.append([None, "bank_burnt", self.bank])
            self.bank = 0
        if w["open"]:
            # R23: a win of an open account burns the waiting points of every closed one.
            for other, s in enumerate(self.sides):
                if other != side and not s["open"] and s["waiting"]:
                    lines.append([other, "burnt_rival", s["waiting"]])
                    s["waiting"] = 0
        if w["balance"] >= TARGET:
            # R25, R26
            self.over = True
            self.winner = side
            self.dry = all(s["balance"] == 0 for i, s in enumerate(self.sides) if i != side)
        return lines

    def snapshot(self, lines: list[list]) -> dict:
        return {
            "sides": [dict(s) for s in self.sides],
            "bank": self.bank,
            "lines": [{"side": s, "line": line, "points": p} for s, line, p in lines],
            "over": self.over,
            "winner": self.winner,
            "dry": self.dry,
        }


# --------------------------------------------------------------------------
# Playing hands and games with seeded random legal plays.
# --------------------------------------------------------------------------


def turn_record(hand: Hand, seat: int, legal: list, may_yield: bool, act: list) -> dict:
    return {
        "seat": seat,
        "legal": legal,
        "may_yield": may_yield,
        "act": act,
        "then": hand.then,
        "line": [list(entry) for entry in hand.line],
        "ends": list(hand.ends) if hand.ends else [],
        "boneyard": len(hand.boneyard),
        "closed": hand.closed(),
    }


def play_out(hand: Hand, source: Source, give: tuple[int, int] = (1, 3), full: bool = True) -> list[dict]:
    """Seeded random legal plays to the hand's end; at `pairs` the seat with the right gives with the chance `give`."""
    turns = []
    while hand.ending is None:
        seat = hand.turn
        legal = hand.legal()
        may_yield = hand.can_yield()
        if may_yield and source.chance(*give):
            act = ["yield"]
            hand.give()
        else:
            choices = [[t, e] for t, ends in legal for e in (ends or [None])]
            tile, end = source.pick(choices)
            act = ["play", tile] if end is None else ["play", tile, end]
            hand.play(tile, end)
        record = turn_record(hand, seat, legal, may_yield, act)
        if not full:
            del record["line"]
        turns.append(record)
    return turns


def shows(hand: Hand, turns: list[dict]) -> list[str]:
    """What a hand shows, for a reader and for picking hands: rule ids and plain words."""
    out = [{"lowest_double": "R6", "lowest_tile": "R7"}.get(hand.opener["why"], "R8")]
    if any(t["act"] == ["yield"] for t in turns):
        out.append("R9")
    if hand.drawn:
        out.append("R12")
    if hand.odd:
        out.append("R12 odd tile")
    if hand.shut:
        out.append("R13")
    if hand.passes:
        out.append("R14")
    out.append({"finished": "R17", "blocked": "R18", "tied": "R19", "void": "R5"}[hand.ending])
    if any(h == ["0-0"] for h in hand.hands):
        out.append("R16 lone 0-0")
    return out


def hand_record(ident: str, table: str, deal: dict, open_sides: list[bool], right, source: Source) -> tuple[dict, Hand]:
    hand = Hand(table, deal, open_sides, right)
    turns = play_out(hand, source)
    record = {
        "id": ident,
        "table": table,
        "open": open_sides,
        "right": None if right is None else {"seat": right[0], "why": right[1]},
        "deal": deal,
        "opener": hand.opener,
        "turns": turns,
        "end": hand.result(),
    }
    record["shows"] = shows(hand, turns)
    return record, hand


def random_context(source: Source, table: str, later: bool) -> tuple[list[bool], tuple[int, str] | None]:
    sides = side_count(table)
    if not later:
        return [False] * sides, None
    open_sides = [source.chance(1, 2) for _ in range(sides)]
    if not any(open_sides):
        open_sides[source.below(sides)] = True
    right = (source.below(SEATS[table]), source.pick(["finished", "fewest", "blocker", "again"]))
    return open_sides, right


def make_hands() -> dict:
    deals = []
    source = Source(SEED * 10 + 1)
    for table in TABLES:
        wanted = {"stands": 2, "doubles": 2, "number": 2}
        while any(wanted.values()):
            deal = deal_from(source, table)
            why = thrown(deal["seats"])
            kind = "stands" if not why else ("doubles" if "doubles" in why[0] else "number")
            if wanted[kind]:
                wanted[kind] -= 1
                deals.append({"table": table, "deal": deal, "verdict": "stands" if not why else "thrown", "why": why})

    hands = []
    source = Source(SEED * 10 + 2)
    for table in TABLES:
        for later in (False, True):
            for k in range(4):
                deal, _ = dealt(source, table)
                open_sides, right = random_context(source, table, later)
                ident = f"{table}-{'later' if later else 'first'}-{k + 1}"
                record, _ = hand_record(ident, table, deal, open_sides, right, source)
                hands.append(record)

    # Hands picked for what they show, from a source of their own, the first found of each.
    wanted = {
        "two": ["R7", "R12", "R13", "R18", "R19", "R16 lone 0-0"],
        "three": ["R7", "R12 odd tile", "R13", "R18", "R19", "R16 lone 0-0"],
        "four": ["R18", "R19", "R16 lone 0-0"],
        "pairs": ["R5", "R5 later", "R9", "R18", "R19", "R16 lone 0-0"],
    }
    source = Source(SEED * 10 + 3)
    for table in TABLES:
        for feature in wanted[table]:
            later = feature in ("R5 later", "R9") or (feature not in ("R5", "R7") and source.chance(1, 2))
            for attempt in range(1, 2_000_001):
                deal, _ = dealt(source, table)
                if feature == "R7" and any(f"{d}-{d}" in s for s in deal["seats"] for d in range(1, 7)):
                    continue  # most deals hold a double: no need to play them
                open_sides, right = random_context(source, table, later)
                record, hand = hand_record(f"{table}-{feature}", table, deal, open_sides, right, source)
                found = record["shows"]
                if feature == "R5 later":
                    hit = "R5" in found and "R8" in found
                elif feature == "R9":
                    hit = "R9" in found and hand.ending != "void"
                else:
                    hit = feature in found
                if hit:
                    record["id"] = f"{table}-{feature.replace(' ', '-').lower()}"
                    hands.append(record)
                    break
            else:
                raise SystemExit(f"no hand at {table} shows {feature}")
    return {"about": ABOUT, "seed": SEED, "deals": deals, "hands": hands}


def random_result(source: Source, sides: int) -> dict:
    roll = source.below(100)
    if roll < 8:
        return {"ending": "tied", "winner": None, "gain": 0, "pot": 10 + source.below(70)}
    if roll < 12:
        return {"ending": "void", "winner": None, "gain": 0}
    winner = source.below(sides)
    gain = 1 + source.below(12) if source.chance(1, 2) else 13 + source.below(48)
    return {"ending": source.pick(["finished", "blocked"]), "winner": winner, "gain": gain}


def make_ledger() -> dict:
    source = Source(SEED * 10 + 4)
    sequences = []
    for sides, count in ((2, 10), (3, 6), (4, 6)):
        # The first `count - 1` sequences as they come, then the first that ends the other way (dry or not).
        kept: list[dict] = []
        while len(kept) < count:
            ledger = Ledger(sides)
            results, after = [], []
            while not ledger.over:
                result = random_result(source, sides)
                lines = ledger.apply(result)
                results.append(result)
                after.append(ledger.snapshot(lines))
                assert len(results) < 200
            ends = {s["after"][-1]["dry"] for s in kept}
            if len(kept) < count - 1 or len(ends | {ledger.dry}) == 2:
                kept.append({"id": f"sides-{sides}-{len(kept) + 1}", "sides": sides, "results": results, "after": after})
        sequences += kept
    seen = {l["line"] for s in sequences for a in s["after"] for l in a["lines"]}
    every = {"gained", "opened", "released", "waited", "burnt_rival", "burnt_third", "bank_added", "bank_taken", "bank_burnt"}
    assert seen == every, every - seen
    assert {s["after"][-1]["dry"] for s in sequences} == {True, False}
    return {"about": ABOUT, "seed": SEED, "sequences": sequences}


def make_games() -> dict:
    source = Source(SEED * 10 + 5)
    games = []
    for table in TABLES:
        for k in range(3):
            ledger = Ledger(side_count(table))
            right = None
            hands = []
            while not ledger.over:
                deal, tries = dealt(source, table)
                open_sides = [s["open"] for s in ledger.sides]
                hand = Hand(table, deal, open_sides, right if any(open_sides) else None)
                turns = play_out(hand, source, full=False)
                end = hand.result()
                end["line"] = [list(entry) for entry in hand.line]
                lines = ledger.apply(hand.ledger_result())
                hands.append({
                    "hand": len(hands) + 1,
                    "thrown": [t["why"] for t in tries],
                    "deal": deal,
                    "open": open_sides,
                    "right": None if not any(open_sides) else {"seat": right[0], "why": right[1]},
                    "opener": hand.opener,
                    "turns": turns,
                    "end": end,
                    "account": ledger.snapshot(lines),
                })
                nxt = end["next"]
                right = None if nxt["seat"] is None else (nxt["seat"], nxt["why"])
                assert len(hands) < 80
            games.append({
                "game": f"{table}-{k + 1}",
                "table": table,
                "hands": hands,
                "winner": ledger.winner,
                "dry": ledger.dry,
            })
    return {"about": ABOUT, "seed": SEED, "games": games}


# --------------------------------------------------------------------------
# Replaying the hand-written files.
# --------------------------------------------------------------------------


def act_on(hand: Hand, act: list) -> None:
    if act[0] == "yield":
        hand.give()
    elif act[0] == "play":
        hand.play(act[1], act[2] if len(act) > 2 else None)
    else:
        raise Illegal(str(act))


def same(a, b) -> bool:
    return json.dumps(a, sort_keys=True) == json.dumps(b, sort_keys=True)


def check_hand_case(case: dict) -> list[str]:
    errors = []
    right = case.get("right")
    hand = Hand(case["table"], case["deal"], case.get("open", [False] * side_count(case["table"])),
                None if right is None else (right["seat"], right["why"]))
    states = {0: snapshot(hand)}
    for index, act in enumerate(case["acts"]):
        try:
            act_on(hand, act)
        except Illegal as e:
            errors.append(f"act {index + 1} {act} refused ({e})")
            return errors
        states[index + 1] = snapshot(hand)
    for check in case["checks"]:
        state = states[check["after"]]
        for key, want in check.items():
            if key == "after":
                continue
            got = state.get(key)
            if key == "legal":
                got = sorted(got or [])
                want = sorted(want)
            if key == "end" and got is not None:
                got = {k: v for k, v in got.items() if k in want}
            if not same(got, want):
                errors.append(f"after {check['after']}: {key} is {json.dumps(got, ensure_ascii=False)}, the case says {json.dumps(want, ensure_ascii=False)}")
    return errors


def snapshot(hand: Hand) -> dict:
    return {
        "turn": hand.turn,
        "legal": hand.legal() if hand.ending is None else None,
        "may_yield": hand.can_yield(),
        "opener": dict(hand.opener),
        "then": list(hand.then),
        "line": [list(e) for e in hand.line],
        "ends": list(hand.ends) if hand.ends else [],
        "boneyard": len(hand.boneyard),
        "closed": hand.closed(),
        "end": hand.result() if hand.ending else None,
    }


def check_ledger_case(case: dict) -> list[str]:
    errors = []
    ledger = Ledger(case["sides"])
    for index, (result, want) in enumerate(zip(case["results"], case["after"])):
        lines = ledger.apply(result)
        got = ledger.snapshot(lines)
        got = {k: v for k, v in got.items() if k in want}
        if not same(got, want):
            errors.append(f"after hand {index + 1}: {json.dumps(got, ensure_ascii=False)} — the case says {json.dumps(want, ensure_ascii=False)}")
    return errors


def check_cases(path: Path) -> int:
    data = json.loads(path.read_text())
    failed = 0
    rules = set()
    readings: set[int] = set()
    for case in data["cases"]:
        kind = case["kind"]
        errors: list[str] = []
        if kind == "tiles":
            if case["tiles"] != TILES:
                errors.append("tiles")
            if case["pips"] != [pips(t) for t in TILES]:
                errors.append("pips")
            if case["doubles"] != [t for t in TILES if is_double(t)]:
                errors.append("doubles")
            if case["total"] != sum(pips(t) for t in TILES):
                errors.append("total")
        elif kind == "tables":
            for t in case["tables"]:
                want = {
                    "table": t["table"],
                    "seats": SEATS[t["table"]],
                    "hand": 7,
                    "boneyard": 28 - 7 * SEATS[t["table"]],
                    "sides": [seats_of(t["table"], s) for s in range(side_count(t["table"]))],
                }
                if not same(t, want):
                    errors.append(f"{t['table']}: {want}")
        elif kind == "deal":
            why = thrown(case["deal"]["seats"])
            verdict = "thrown" if why else "stands"
            if verdict != case["verdict"] or not same(why, case["why"]):
                errors.append(f"verdict {verdict} {why}")
        elif kind == "deals":
            used = next(i for i, d in enumerate(case["tries"]) if not thrown(d["seats"]))
            if used != case["used"]:
                errors.append(f"used try {used}")
        elif kind == "hand":
            errors = check_hand_case(case)
        elif kind == "ledger":
            errors = check_ledger_case(case)
        else:
            errors.append(f"unknown kind {kind}")
        rules.add(case["rule"])
        if case.get("reading"):
            readings.add(case["reading"])
        if errors:
            failed += 1
            print(f"cases.json · {case['rule']} · {case['name']}:", file=sys.stderr)
            for e in errors:
                print(f"    {e}", file=sys.stderr)
    missing = [f"ADR 038 R{n}" for n in range(1, 27) if f"ADR 038 R{n}" not in rules]
    unread = [n for n in range(1, 12) if n not in readings]
    count = sum(1 for c in data["cases"] if c.get("reading"))
    print(f"cases.json: {len(data['cases'])} cases, {count} of them readings, {failed} differ; "
          f"rules without a case: {missing or 'none'}; rows of decision 34 without one: {unread or 'none'}", file=sys.stderr)
    return failed + len(missing) + len(unread)


def end_of(hand: Hand, covers: int | None) -> str | None:
    """A study play names the open number it covers; the end is the one that shows it, the left where both do."""
    if covers is None:
        return None
    left, right = hand.ends
    if left == covers:
        return "left"
    if right == covers:
        return "right"
    raise Illegal(f"no end shows {covers}")


def check_study(path: Path) -> int:
    data = json.loads(path.read_text())
    differences = 0
    replayed = 0
    for game in data["games"]:
        table = game["table"]
        ledger = Ledger(side_count(table))
        right = None
        for h in game["hands"]:
            notes = []
            open_sides = [s["open"] for s in ledger.sides]
            hand = Hand(table, h["deal"], open_sides, right if any(open_sides) else None)
            pending: list[int] = []
            for row in h["rows"]:
                seat, kind = row[0], row[1]
                if kind == "pass":
                    if not pending or pending[0] != seat:
                        notes.append(f"row {row}: the oracle has {pending[:1] or 'no pass'} here")
                        break
                    pending.pop(0)
                    continue
                if pending:
                    notes.append(f"row {row}: the oracle passes {pending} first")
                    break
                if hand.turn != seat:
                    notes.append(f"row {row}: the oracle's seat to move is {hand.turn}")
                    break
                try:
                    if kind == "yield":
                        hand.give()
                    else:
                        hand.play(row[2], end_of(hand, row[3] if len(row) > 3 else None))
                except Illegal as e:
                    notes.append(f"row {row}: refused ({e})")
                    break
                pending = [e["seat"] for e in hand.then if e["passed"]]
            app = h["app"]
            if not notes:
                if hand.ending is None:
                    notes.append("the hand is not over after its rows")
                else:
                    res = hand.result()
                    lines = ledger.apply(hand.ledger_result())
                    gains = [0] * side_count(table)
                    if res["winner"] is not None:
                        gains[res["winner"]] = res["gain"]
                    got = {
                        "opener": hand.opener["seat"],
                        "ending": hand.ending,
                        "hand": gains,
                        "account": [s["balance"] for s in ledger.sides],
                    }
                    for key, value in got.items():
                        if key in app and app[key] != value:
                            notes.append(f"{key}: the app {app[key]}, the oracle {value}")
                    nxt = res["next"]
                    right = None if nxt["seat"] is None else (nxt["seat"], nxt["why"])
            replayed += 1
            if notes:
                differences += 1
                print(f"study.json · {h['id']}:", file=sys.stderr)
                for n in notes:
                    print(f"    {n}", file=sys.stderr)
    for sequence in data["results"]:
        ledger = Ledger(sequence["sides"])
        for index, item in enumerate(sequence["hands"]):
            ledger.apply(item["result"])
            want = item["app"]
            got = {
                "account": [s["balance"] for s in ledger.sides],
                "waiting": [s["waiting"] for s in ledger.sides],
                "bank": ledger.bank,
                "over": ledger.over,
                "dry": ledger.dry,
            }
            wrong = {k: (want[k], got[k]) for k in want if k in got and want[k] != got[k]}
            if wrong:
                differences += 1
                print(f"study.json · {sequence['id']} hand {index + 1}: {wrong}", file=sys.stderr)
    count = sum(len(s["hands"]) for s in data["results"])
    print(f"study.json: {replayed} hands replayed, {count} results folded, {differences} differ", file=sys.stderr)
    return differences


# --------------------------------------------------------------------------


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
    parser.add_argument("--out", default=str(OUT), help="where the generated fixtures go (default: tests/Fixtures/rooms/domino)")
    parser.add_argument("--check", action="store_true", help="only replay cases.json and study.json")
    args = parser.parse_args()

    if not args.check:
        out = Path(args.out)
        out.mkdir(parents=True, exist_ok=True)
        write(out / "hands.json", make_hands(), "deals", "hands")
        write(out / "ledger.json", make_ledger(), "sequences")
        write(out / "games.json", make_games(), "games")

    failed = 0
    if CASES.exists():
        failed += check_cases(CASES)
    if STUDY.exists():
        failed += check_study(STUDY)
    if failed:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
