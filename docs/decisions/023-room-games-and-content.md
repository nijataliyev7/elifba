# 023 — Room games: a domain of their own, mechanics as code, content as data, our own word list

- **Status:** Proposed, 2026-11-17 (roadmap step B1; accepted when the owner approves the design in B1-O; built in B3, B5 and B6)
- **Amended by:** [034](034-board-games-draughts.md) (2026-11-20) — decisions 2, 3 and 6: a room game has a kind, `race` or `board` (`RoomGameKind`); `RoomFamily` gains `board` and `RoomMechanic` `draughts`; a board game's level is its clock and it builds no rounds; decision 7: `levels.json` holds the board levels, with their own validator rules
- **Amended by:** [037](037-anagram-clue-shuffle-letter.md) (2026-11-21) — decisions 4 and 7: an anagram's question carries its word's clue from `content/rooms/clues.json` (one per word of the list, checked by the validator) and a bounded list of the tiles' orders, none reading an accepted spelling; decision 4 again: a buyer gets the solution's first tiles before the reveal — the one exception. Decision 8 holds: a clue is content, Azerbaijani in every locale
- **Amended by:** [038](038-domino-101.md) (2026-11-26, domino «101») — decisions 2, 3 and 6: a room game has a third kind, `domino` (`RoomGameKind`); `RoomFamily` gains `domino` and `RoomMechanic` `domino_101`; a domino game's level is a turn's time (`turn_ms`) and it builds no rounds.
- **Sections:** architecture.md §1, §12 "Room games" and "Room content"
- **Amends:** [011](011-children-without-a-class.md) decision 1 and architecture D1: a seventh domain, `Rooms`, at the end of the order. The rule "a game is for a class or for one child" (011 decision 3) is unchanged, and this record says why a room game is not a third case of it. [003](003-game-model-and-round-contract.md) and [004](004-content-as-versioned-files.md) are followed, in a contract and a folder of their own.
- **Why it is hard to reverse:** the tables a room game lives in, the contract a phone receives and the folder contributors write content into are all chosen here; and a word list, once shipped, carries its license with every copy of the repository.

## Context

The owner fixed the first game families (2026-11-17): memory («Yaddaş»), logic and sequences («Məntiq və ardıcıllıq») and word games in Azerbaijani («Söz oyunları»); no quick arithmetic in the first stage; no age groups — a room chooses a **difficulty**.

A children's game is `mechanic × theme pack × level`, built by `Play` into `game_sessions` and `game_rounds` for a class or a child (`GamePlayers`), judged on the board and on the server from a round that carries its solution, and scored 3 / 2 / 1 for one child at a time. A room game is answered by everyone at once, judged only by the server from a solution the devices never get, timed, and scored per answer ([021](021-room-race-protocol.md), [022](022-room-scoring-and-history.md)).

Two homes were compared.

- **(a) A third kind of `GamePlayers`** — a game "for a room" in Play's tables. Every invariant of Play would get a second meaning: the board as the one writer, the solution in the payload, one credited child per round, `PointsRule`. Every reader of a game — the catalog, the board, the remote, `/pult`, rankings, pruning, the guest board's bearer — would have to remember the third kind, and a forgotten check would put an adults' game on a classroom wall or a child in a ranking.
- **(b) A domain of its own**, with its own tables, contract, judge and points. More code once; afterwards nothing of the children's side can reach a room, by construction.

## Decision

1. **(b). A seventh domain, `Rooms`, last in the order:** `Language → Content → Classroom → Family → Play → Rankings → Rooms`. Being last, nothing may use it. It uses `Language`, `Content` and the pure `Rankings\Support\DenseRank`, and — by a rule stricter than the order, checked by `RoomsApartTest` — **nothing of `Classroom`, `Family` or `Play`**. A room game is a `room_games` row and never a `game_sessions` row; `GamePlayers` keeps its two cases.
2. **A room game is `mechanic × difficulty`**, with a number of rounds. There is no theme pack: a room is not dressed.
    - **Mechanic** — code: a builder in `Rooms\Support\Mechanics\*`, an answer shape for the judge, a React component in `resources/js/components/rooms/mechanics/*`. `Content\Enums\RoomMechanic` lists them and `Content\Enums\RoomFamily` groups them (in `Content`, as `Mechanic` is, because the level file names them).
    - **Difficulty** — data: `content/rooms/levels.json`, one entry per mechanic and difficulty 1–4 (shown as «Asan», «Orta», «Çətin», «Çox çətin»): the times and the mechanic's own numbers.
    - `Rooms\Support\RoomPlayable` lists the mechanics the pages can draw; `RoomGameSetupData::rules()` offers only a level whose mechanic is in it, so content may run ahead of code.
3. **The first mechanics**, two per family:

    | Family (`RoomFamily`)          | Mechanic (`RoomMechanic`) | What happens                                                                                 | Answer     | Built in |
    | ------------------------------ | ------------------------- | -------------------------------------------------------------------------------------------- | ---------- | -------- |
    | `memory` «Yaddaş»              | `recall_order`            | a row of digits, letters, words or shapes is shown, then hidden; tap them in the order shown | `sequence` | B3       |
    |                                | `recall_cells`            | cells of a grid light up, then go dark; tap the cells that were lit                          | `set`      | B4       |
    | `logic` «Məntiq və ardıcıllıq» | `next_in_sequence`        | a row of numbers follows a rule; choose what comes next                                      | `choice`   | B5       |
    |                                | `odd_one_out`             | one of the numbers or words does not belong with the others; choose it                       | `choice`   | B5       |
    | `words` «Söz oyunları»         | `anagram`                 | the letters of a word, shuffled; tap them into a word                                        | `sequence` | B6       |
    |                                | `spelling`                | one word written four ways (e/ə, k/q, ı/i…); choose the right spelling                       | `choice`   | B6       |

4. **Three answer shapes, one judge.** `choice` (one item id), `set` (item ids, any order) and `sequence` (item ids in order, compared by what the items say, so two tiles with the same letter are interchangeable and an anagram accepts every listed word its letters spell). `Rooms\Support\RoomJudge` judges the three in PHP against the stored solution. **A player never types**: every answer is made of the round's own item ids, so no free text from a player is stored, shown or logged.
5. **A contract of its own**, kept on both sides like the children's: PHP DTOs in `Rooms\Data\*`, zod schemas in `resources/js/rooms/contract.ts` from which the TypeScript types are inferred, and fixtures in `tests/Fixtures/rooms/` that both sides read. It has no solution type on the TypeScript side: a fixture test fails if a payload a device may receive holds one. `contract.ts` of the board, `RoundData` and `tests/Fixtures/contract/` do not change.
6. **Rounds are built by the server when the game starts and stored as snapshots** (`room_rounds.payload`, `room_rounds.solution`, with the game's `seed` and the content's version), as ADR 003 does and for the same reasons.
7. **Content is data, in `content/rooms/`**, read by `ContentRepository` and checked by `ContentValidator` (`content:validate`), each rule with a broken fixture:
    - `levels.json` — per level: a known mechanic, a difficulty 1–4, one level per pair and every difficulty for a playable mechanic; `answer_ms` 5 000–60 000; `study_ms` above 0 for memory and 0 otherwise; the mechanic's numbers inside what its builder can build (a row of at most 12, a grid of at most 6 × 6 with fewer lit cells than cells, known sequence rules, word lengths the list can fill).
    - `words.json` (B6) — the adults' word list: lowercase common words of 3–12 letters of the alphabet (`WordRules`), unique by `Az::lower`, none equal to a first name of `content/names.json`; enough words for every word level; for `spelling`, a word must hold a letter that has a confusable.
    - `categories.json` (B5) — groups for `odd_one_out`: at least 8 words each, every word in exactly one group, so the odd one is never arguable.
    - `shapes.json` and `content/assets/rooms/` (B4) — plain shapes of our own for memory rounds, each with its credit.
    - The sequence **rules** (`arithmetic`, `geometric`, `alternating`, `squares`, `fibonacci`, `interleaved`) are code; which of them a difficulty uses, and in which range, is data.
8. **What a round says is interface text, what it shows is content.** The instruction of a mechanic («Eyni ardıcıllıqla toxunun») is a `lang/` key per mechanic in both languages, since an adult reads the interface in theirs. Letters, words and categories are content and stay Azerbaijani in every locale. `content/prompts.json` is not used.
9. **From `Language`:** `Alphabet` (the letters, the vowels, the confusable pairs that make `spelling`'s wrong answers), `Az` (casing and normalization; text is cased on the server), `AzCollator` (names inside a place), `Ordinal::label()` (place labels), `DateLabel` (a past room's date), `Plural`. From `Content`: `WordRules`, the repository and the validator.
10. **The word list is our own.** `content/rooms/words.json` is written in this repository under MIT, like the theme packs, with a credits entry "Elifba contributors": it starts from the words the packs already hold (281, of which 201 have five letters or more) and what contributors add, and the owner reviews it as other content (B7-O). The first stage needs only a **closed** list — an anagram's word and a spelling's word come from the list — so a few hundred reviewed words are enough.
    - Another source is copied only if its license is one `Content\Enums\License` allows, with its own credits entry. Checked on 2026-11-17: the Hunspell and Aspell dictionaries for Azerbaijani are distributed as GPL or MPL 2.0 — neither is in the list, so they are not copied; Wiktionary's text is CC BY-SA 4.0 — allowed, share-alike, so a list taken from it would stay in its own folder (`content/rooms/wiktionary/`, §8). B6 uses it only if the own list proves too small.
    - Never: the state orthographic dictionary, or any list without a stated license.
11. **No Phaser, no sound and no voice in a room** in the first stage: the pages are React on a phone. `content/voice*` and the speech rules of ADR 015 and 016 are not involved. _Amended by [025](025-room-motion-sound-and-final.md) (2026-11-18): Phaser and voice stay out; a game's moments get designed motion and a final with confetti, and sound comes with the screen as the room's speaker and quiet cues of a phone's own._
12. **No text promises a benefit.** The area and its games are named by what one does («Otaqlar», «Yaddaş», «Məntiq və ardıcıllıq», «Söz oyunları»); no interface text, landing text or README says that a game improves memory, intelligence, attention or health, and nothing computes a "level" of a person ([022](022-room-scoring-and-history.md) decision 8).

## Consequences

- Adding a room mechanic is a builder, an entry in the judge's shape if it needs a new one, a component and four level entries; adding a difficulty or a word is data.
- Two round contracts live side by side, each with its fixtures. That is deliberate: they answer different questions (who judges, who may see the solution).
- A valid Azerbaijani word that is not in the list is "wrong" in an anagram only when it uses exactly the round's letters; the fix is one line of data.
- English-speaking players read the instructions in English and play Azerbaijani words.

## Alternatives rejected

- **A third `GamePlayers`** — see Context (a).
- **`Rooms` between `Content` and `Classroom`** (it needs nothing after `Content`): the children's domains would then be allowed to use it.
- **Theme packs for rooms:** a look nobody asked for, and the packs' pictures are drawn for six-year-olds.
- **The children's `Mechanic` enum, `levels.json` and `prompts.json` extended:** the catalog, `Playable` and the setup read them; one wrong filter would offer «Anaqram» to a first grade.
- **An open word game** («hərflərdən istənilən söz»): it is only fair with a dictionary that knows nearly every word, and no such list with an allowed license is in hand. Left as an open question.
- **Quick arithmetic:** not in the first stage (the owner).
- **Typed answers:** free text to store, moderate and keep out of logs.
- **Age groups:** dropped by the owner; difficulty does the work.

## What would make us revisit

A full Azerbaijani word list under an allowed license (it opens the open word game), a mechanic that cannot be judged from item ids, or a wish to play a room game alone (a practice mode would be a new decision about what is kept for one account).
