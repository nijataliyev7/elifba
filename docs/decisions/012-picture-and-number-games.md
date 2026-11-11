# 012 — Picture and number games for children who cannot read yet

- **Status:** Accepted, 2026-11-11 (roadmap step R1; built in R5 and R6)
- **Sections:** architecture.md §2, §3
- **Amends:** [003 game model and round contract](003-game-model-and-round-contract.md): two units, one mechanic, one item style and one prompt key are added; the model (mechanic × theme pack × level, server-built snapshots, one judge) is unchanged.
- **Superseded in part by:** [015 voice](015-voice.md) (2026-11-11), for the voice only: "there is no recorded voice" in the context and the rejected "Recorded prompts or speech synthesis" below. The board will say the prompt in a prerecorded synthetic voice; everything else here stands.
- **Why it is hard to reverse:** the round contract gains a key that every stored snapshot from then on carries, `Unit` and `Mechanic` gain cases that stored games name, and packs gain a required key. Taking any of them back means migrating stored rounds.

## Context

Every game so far asks a child to read: the items are letters, syllables, words and sentences. The owner decided (2026-11-11) that 3–6 year olds play too: animals, fruit, colours, the numbers 1–10 and counting. The parent — or a grade-1 teacher, since these games are open to both roles — says the prompt aloud («Pişik hansıdır?») and the child taps the picture. There is no recorded voice (§3 "Voice" stays). Numbers cover two things: recognising a digit and counting («Neçə alma var?»: N pictures, the child taps the digit). Colours are plain coloured shapes drawn as our own SVGs.

What is new is what an item _is_ — a picture, a number — far more than how a round is solved: «Pişik hansıdır?» is solved exactly like «"Ə" hərfini tap!». Only counting has a round of its own shape: a question made of N pictures above the answers.

## Decision

1. **Two new units, on the mechanics there are.** `Unit::Picture` (`picture`) — an item is a word of the pack shown as its picture — and `Unit::Number` (`number`) — an item is a number shown as its digits. `find` and `pop` support both. `Unit::reads()` is true for the four reading units and false for these two: learned letters, the letter case, chosen letters and «Yalnız mənim sözlərim» apply only where it is true.
2. **One new mechanic, `count`** (`Mechanic::Count`, «Say»), for the number unit only: the prompt shows one picture of the pack N times, the items are numbers, and the one item that says N solves the round (`any`, one item, no slots). It has its own builder (`CountRoundBuilder`) and scene (`scenes/count.ts`), because the board draws a question band over the items.
3. **Items.**
    - A picture item: `text` is the word as the pack writes it, `image` the URL of its picture, `content_key` `word:{lower}`. The board draws the picture alone, with no text; the phone shows the picture with the word under it, for the adult.
    - A number item: `text` is the digits ("7", "10"), `image` null, `content_key` `number:{n}`.
    - Item texts stay unique within a round.
4. **Prompts need no suffix.** The templates keep the rule of §3 (`{x}` only as `«{x}»`, nothing attached), in forms where the word stays in its dictionary form: `«{x}» hansıdır?` (find × picture: «pişik» hansıdır?, «qırmızı» hansıdır?), `Hər şəklin adını de və vur!` (pop × picture), `«{x}» rəqəmini tap!` (find × number), `Hər rəqəmi de və vur!` (pop × number), `Neçə «{x}» var?` (count). A picture prompt shows the word (`show`) for the adult and never the picture; a count prompt shows the picture and never the number.
5. **The round contract gains one key: `prompt.count`** (`PromptData::$count`, `int|null`): how many times the board draws `prompt.image`. It is N in a `count` round and null everywhere else. The PHP DTO, the zod schema and every fixture change together, with new fixtures `round.find-picture`, `round.find-number` and `round.count`. A snapshot stored before the key is read as `count: null`; stored rounds are not rewritten. `Mechanic` gains `count` and `Unit` gains `picture` and `number` on both sides.
6. **The judge does not change.** `RoundJudge` and `engine.ts` already judge an `any` round with one or with all of its items; `judge.json` gains a `count` case to pin it. `PointsRule` does not change either: 3, 2 or 1 stars.
7. **Levels are data.** A picture level has no filter and takes the pack's words that have a picture. A number level has `numbers: {min, max}` (within 1–10) and makes its items from that range. The starting set: `pictures-easy` (2 items) and `pictures` (4 items), both `find` and `pop`; `numbers-5` (1–5, 3 items) and `numbers-10` (1–10, 4 items), each `find`, `pop` and `count`. Decoys: other pictures of the pack at random; for a number, the nearest numbers first.
8. **A pack lists the units it has content for:** `units`, a required key of every pack. A level is offered with a pack only when the pack lists the level's unit; `Playable::reason()` answers `pack_level` otherwise, and the setup page does not list the pair at all. The four reading units come together or not at all, and the bar of §3 (20 words, 10 sentences, 6 items per level) applies to a pack that lists them; a pack that lists `picture` needs at least 6 words with a picture, one that lists `number` at least 4 (the things counted). The six packs there are list the reading units; `animals` and `fruit` also list `picture` and `number`: their words are what is named and what is counted.
9. **Colours and numbers are packs** (R6, data only).
    - `colors`: `units: ["picture"]`, item style `picture`. Eleven words: qırmızı, sarı, yaşıl, göy, narıncı, bənövşəyi, çəhrayı, qəhvəyi, qara, ağ, boz. Each picture is the same plain shape filled with that colour, own SVG (§8), so only the colour tells two items apart. «mavi» is left out: a round may show any two words of a pack, and a small child cannot tell it from «göy». The hues come from §8's palette and every pair is checked on a projector.
    - `numbers`: `units: ["number"]`, its words things to count, with pictures. The digits themselves come from the level's range, so no picture ever holds a digit (§8's rule for own art stays).
10. **One new item style, `picture`** (`ItemStyle::Picture`, `styles/picture.ts`): a rounded tile in the pack's palette with the item's picture, or its text when it has none. A round of the picture unit is always drawn in it, whatever the pack's own style; a pack may also name it as its style (`colors` does). Number items are drawn in the pack's style, as letters are.
11. **What does not apply.** Learned letters never filter a picture or number level, so a class that has learned three letters can play them. A teacher word has no picture, so it never appears in a picture or count round; a built-in word a teacher hid is gone from them as from every level. The games are open to both roles: no level is tied to a role, and the setup form only orders the groups — pictures and numbers first in the parent area, reading first in the teacher area.
12. **The phone.** The remote shows a picture item as its picture with the word and marks the answer «Cavab» as always; above a `count` round's items it shows the picture and the number of copies. The protocol does not change.

## Consequences

- A pre-reader's game and a reader's game run through the same builder base, judge, sync, points and stars; nothing in Play or on the board knows the child's age.
- R5 ships the levels and templates with the code, playing in `animals` and `fruit`; R6 then adds the colours and the numbers theme without a line of PHP or TypeScript, which is the test of "content is data".
- Every stored round from R5 on has `prompt.count`; older ones are read without it.
- A theme no longer has to be a reading theme: `colors` has no sentences and eleven words.
- Because the word is written under the picture on the phone and in the prompt, an older sibling can read the answer; that is the adult's screen, and the board shows no text on a picture item.
- The picture of a `find` round at a reading level is still forbidden (§2 "The `find` builder"): there the child must read, here the child must know the thing.

## Alternatives rejected

- **A `picture_find` mechanic:** the rules of the round are `find`'s; a second builder, scene and set of judge cases would repeat them for a difference that is only what an item is — which is what a unit says.
- **A flag on word levels (`"pictures": true`):** the unit `word` would mean two things, and learned letters, the letter case and own words would each need an exception.
- **Counting as a `find` round with `prompt.count` and no mechanic:** the board picks its scene by the mechanic, and the builder deals two decks (what is counted, how many); hiding that behind `find` would put an `if` in both.
- **The counted pictures as items, or as a list of image URLs:** N identical items break "item texts are unique", and a list repeats one URL up to ten times.
- **Digits as pictures in a pack:** a digit in an SVG is text the server cannot case, check or reuse, and §8 keeps text out of pictures.
- **Numbers offered in every pack:** «Neçə "Bakı" var?» is not a question; a pack says what can be counted in it.
- **«mavi» and «göy» both, with a rule that keeps them out of one round:** a builder rule for one pair of words, when leaving one word out of the data does the same.
- **Prompts with case suffixes («Qırmızını tap!»):** vowel harmony would have to be computed for every word; `hansıdır?` needs none.
- **Recorded prompts or speech synthesis:** §3 "Voice" — the adult is the voice. Audio stays a later content step through `prompt.audio`.
- **Levels by age (3–4, 5–6):** an age is a fact about a child (ADR 013); the adult picks the level.

## What would make us revisit

Recorded voice (then a picture round could be played by a child alone), numbers beyond 10 or sums (a new mechanic with its own round shape), or packs whose pictures need a caption on the board.
