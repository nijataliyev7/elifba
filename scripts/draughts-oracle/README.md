# The draughts oracle

`make.py` asks an independent library what the rules of Russian draughts allow and writes its answers as the fixtures `tests/Unit/Rooms/Draughts/DraughtsRulesTest.php` reads (docs/architecture.md §12 "Board games", ADR 034 decisions 32–33). The judge of a game played in a room is PHP's `Rooms\Support\Draughts\DraughtsRules`; the library decides nothing about it. Neither CI, the app nor an image runs Python or names the library: only this script does, and the gate reads the committed files.

The library is **pydraughts 0.6.7** (MIT, <https://github.com/AttackingOrDefending/pydraughts>), variant `russian`, from its wheel `pydraughts-0.6.7-py3-none-any.whl`, SHA-256 `60ac89bbd00642ecf19dd981e9ccd4f86d3c0036f9c8d655213c4b7e1f8e7087`. Its one dependency, `msl-loadlib`, serves only its engines and is not installed.

| File (`tests/Fixtures/rooms/draughts/`) | Holds                                                                                                                                                                                                                                                    | Made by                |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- |
| `perft.json`                            | the number of move sequences from the start at depths 1–7, and from 36 other positions as deep as stays under 12 000 sequences: the positions written for the rules and twelve reached by random play — eight with kings, four with a capture of several | `make.py`              |
| `positions.json`                        | every legal move, with the pieces each takes, of 27 positions written for the rules (`named`, each with its article), 1 200 positions reached by seeded random play (`random`) and 600 scattered at random, about half the pieces kings (`scattered`)    | `make.py`              |
| `games.json`                            | twelve seeded random games, move by move, with the position after each move, to the move that leaves the other side without a move                                                                                                                       | `make.py`              |
| `draws.json`                            | the draws the server declares, a case at each boundary of ADR 034 decision 16, each with its article                                                                                                                                                     | by hand, from the text |

`make.py` writes its three files an entry a line, so a diff shows what changed; `vite.config.ts` keeps the formatter off them, as off `CREDITS.md`. Never edit them by hand: change the script and run it.

## Regenerate

You need Python 3.8 or later (standard library only) and, the first time, the network.

```sh
python3 scripts/draughts-oracle/make.py                  # writes tests/Fixtures/rooms/draughts/{perft,positions,games}.json
python3 scripts/draughts-oracle/make.py --out /tmp/oracle # writes them elsewhere
python3 scripts/draughts-oracle/make.py --env ~/.cache/elifba/draughts-oracle   # keeps the environment between runs
```

The script downloads the wheel, checks its SHA-256 — a file that does not match stops the run before anything is installed — and installs it with `pip --no-index --require-hashes` into a virtual environment of its own, a temporary directory removed after the run unless `--env` names one. It then runs itself in that environment with `python -I`. It is seeded (`SEED`): the same library gives the same bytes. A run takes about three minutes, most of it perft 7 from the start. Then run `php artisan test --compact tests/Unit/Rooms/Draughts` and, for perft 7, `php artisan test --compact --group=slow`.

**Squares.** The library numbers the 32 dark squares 1–32. The script names them through the library's own FEN — a position with one piece on the numbered square, read back in the variant's FEN, which writes `a1`–`h8` — never through a table of its own, and for every move it checks that the piece stands on the last square the FEN names and the taken pieces are gone. A move is written as the app writes it (`DraughtsNotation::text()`): the start and every landing, `-` for a quiet move and `:` for a capture.

## The counts

From the starting position (`perft.json`'s `start`, ADR 034 decision 32); the PHP judge gives the same, depth 7 in the group `slow`:

| Depth | 1   | 2   | 3   | 4     | 5     | 6      | 7       |
| ----- | --- | --- | --- | ----- | ----- | ------ | ------- |
| Moves | 7   | 49  | 302 | 1 469 | 7 482 | 37 986 | 190 146 |

## Where the library and the official text disagree

The official text is «Правила вида спорта "шашки"» (the order of the Ministry of Sport of Russia of 17 March 2022, no. 201), part 6.1 and articles 5.4; where they disagree, the text decides (ADR 034 decision 33).

**Moves: no disagreement.** On every position of the fixtures — 27 written for the rules, among them the text's own examples of the «Turkish strike» (6.1.5.17, figures 5 and 6, rebuilt from their words, whose answers `e1:a5:d8:f6:d4`, `a5:c3:e1:g3:e5:c3` and `b2:d4:b6:d8:f6:h8` the library gives too), 1 200 from random play and 600 scattered — on 36 perft trees and on twelve games, the library's legal moves and the pieces each takes are the PHP judge's and the text's; so were 3 000 more scattered positions tried on 2026-11-20 outside the fixtures. `overrides.json` is therefore empty. A disagreement found later goes there — the position as the library's FEN, the official moves and the article — and here, with both answers; the script writes the official moves into `positions.json` and stops if the position lies on a perft tree or in a seeded game, whose counts would need the official answer too.

**Draws: the library's are not the official ones**, so no draw is taken from it and `draws.json` is written by hand (ADR 034 decision 16). Read from its `Game.is_draw()` for the variant `russian`:

| The library                                                                                                         | The official text                                                                                                                                              |
| ------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| three or more kings, and nothing else, against a lone king: 30 plies of king moves without a capture, by both sides | three kings and more pieces, men among them, against a lone king: the stronger side's 15th move since the balance was set, whatever moved (6.1.8.3–4)          |
| 30 plies of king moves without a capture end any game, whatever the material                                        | only with 4–5 pieces on the board (15 moves) or 6–7 (25 moves) (6.1.8.8); the IDF's rule for any material is not the text's                                    |
| equal kings and equal pieces on both sides, 4–5 or 6–7 pieces **a side**: 60 or 120 plies since the last capture    | both sides with a king, 2–3, 4–5 or 6–7 pieces **on the board**: 5, 30 or 60 moves since the last capture **or crowning** (6.1.8.6)                            |
| three pieces with a king among them, off the main road, against a lone king on it: 10 plies since the last capture  | any three pieces, three men too, off the main road, against a lone king on it: the stronger side's 5th move in a row (6.1.8.5)                                 |
| two pieces with a king against a lone king: 10 plies since the last capture                                         | no such rule; two kings or a king and a man against a lone king fall under 6.1.8.6, 2–3 pieces, only when both sides have a king                               |
| declares every draw by itself, the same position for the third time included                                        | the arbiter may (5.4.13.6), and the server does, as ADR 034 decision 16 says; the third repetition with the same side to move (5.4.13.1–2) is the same in both |

Nothing here was taken from the library: `draws.json` names an article for each case, and its boundaries are the text's numbers.
