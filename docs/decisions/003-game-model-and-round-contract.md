# 003 — Game model: mechanic × theme pack × level, with server-generated round snapshots

- **Status:** Accepted, 2026-11-09 (roadmap step A1)
- **Sections:** architecture.md §2
- **Why it is hard to reverse:** the round snapshot is stored in `game_rounds.payload` for every game ever played and is the shape the board, the phone and the server exchange. Changing the model means migrating stored rounds and rewriting the scenes, the remote and the judge together.

## Context

The product needs games for four units (letters, syllables, words, sentences) in six themes (train, balloons, animals, fruit, our cities, national symbols), at several difficulty levels, controlled from a phone and recorded on the server. The owner wants teachers to contribute content, and new themes should not need a developer. The board, the phone and the server must always agree on what is on the wall.

## Decision

1. A game is **one mechanic × one theme pack × one level**.
    - **Mechanic** (code): `find`, `build_syllable`, `build_word`, `build_sentence` — a round builder in PHP (`Play\Support\Mechanics\*`) and a Phaser scene. The `Mechanic` enum itself is `Content\Enums\Mechanic`, because levels and prompts (content) name it and Content may not use Play.
    - **Theme pack** (data, `content/packs/{key}.json` + `content/assets/{key}/`): titles, item style, slot style, effect, backdrop, palette, sounds, words with pictures, sentences. Styles and effects are chosen from closed lists implemented in code (`balloon`, `wagon`, `card`, `bubble`; `wagon`, `frame`; `burst`, `sparkle`).
    - **Level** (data, `content/levels.json`): unit, content filter (syllable shapes, syllable or word counts), allowed mechanics, number of items.
2. **The server generates every round** of a game when it starts (seeded), stores each as an immutable snapshot, and sends the snapshots to the board and the phone. Clients never generate rounds.
3. **The round contract** is `RoundData` (+ `PromptData`, `RoundItemData`, `SolutionData`) in PHP and the zod schema `Round` in `resources/js/game/contract.ts`, with `HitData` / `Hit`, `AnswerResultData` / `AnswerResult` and `GameState`. TypeScript types are inferred from the schemas. JSON keys are snake_case. Both sides are pinned to `tests/Fixtures/contract/*.json`: a Pest round-trip (`fromArray($fixture)->toArray()` equals the fixture) and a Vitest parse with strict schemas, so a missing or extra key fails on either side.
4. A solution is `{order: any | sequence, item_ids}`. Item texts are unique within a round, so a phone tap names exactly one board item. In a `sequence` round items are reusable tiles, and `item_ids` may repeat an id (`nə-nə` = `["a", "a"]`). The judge is mirrored in PHP (`RoundJudge`, authoritative) and TypeScript (`engine.ts`), both tested on `tests/Fixtures/contract/judge.json`.
5. **Adding a theme** is a JSON file, pictures and credits entries — no code, migration or translation key. Adding a style, effect or mechanic is code.

## Consequences

- 4 mechanics × 6 packs × 6 levels come from one engine; A8 adds three mechanics that immediately work in all six packs.
- Old games, answers and rankings stay valid when a pack changes (snapshots plus `pack_version`).
- A reloaded board resumes exactly: the server has every round and every result.
- The contract has three places to change together (PHP DTOs, the zod schemas, the fixtures); the two fixture tests make a forgotten one fail CI. The same schemas validate client events on the board, so `zod` is a runtime dependency of the board and the remote.
- Rounds are fixed at start: a game cannot adapt its difficulty mid-game in v1.

## Alternatives rejected

- **One Phaser game per theme:** six code bases that drift apart; every new theme needs a developer.
- **Rounds generated on the board** (or on both sides from a shared seed): the server could not judge or resume, and the generator would exist in two languages.
- **Generated TypeScript types from PHP** (e.g. a transformer package): another code generation step whose Laravel 13 support would have to be checked, and it gives no runtime validation of client events.
- **A type-only check** (`fixture satisfies Round` under `tsc`): TypeScript widens the strings of an imported JSON file to `string`, so every literal union fails and an extra key is never caught.
- **Unique items with no reuse in build rounds:** words such as `ba-ba`, `nə-nə` and syllables such as `nən` could not be built. Duplicate items with the same text would make a phone tap ambiguous.
- **Free-form theme scripts** (packs that ship JavaScript): arbitrary code from content contributions.

## What would make us revisit

A need for adaptive rounds (difficulty that follows the child), which would turn round generation into a per-round server call, or for mechanics that do not fit "items + optional slots + a solution".
