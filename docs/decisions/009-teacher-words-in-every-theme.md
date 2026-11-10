# 009 — Teacher words play in every theme

- **Status:** Accepted, 2026-11-10
- **Sections:** architecture.md §1, §3
- **Amends:** [007 teacher words](007-teacher-words.md), decision 2 ("a teacher adds words to an existing theme pack they choose") and decision 1's unique index per pack.
- **Why it is hard to reverse:** `custom_words` loses its `pack` column and a teacher's copies of one word in several packs are merged into one row. Going back would mean asking every teacher which theme each word belongs to.

## Context

ADR 007 put each teacher word into one theme pack. Teachers then asked why a word they typed under «Meyvələr» did not play under «Qatar» or «Şarlar», and typed the same word into several themes to get it. A teacher word has no picture, so a theme gives it nothing but its look: the balloon, the wagon, the card it is drawn on. The owner decided (2026-11-10) that a teacher's words play in every theme, and that the words page shows which levels each word feeds, so a teacher can see why «Qısa hecalar» is still short.

## Decision

1. **A teacher word belongs to the teacher, not to a pack.** `custom_words` has no `pack`; the unique index is `(user_id, word_key)`. `TeacherPacks` merges all of a teacher's words into every pack: the pack's built-in words without the hidden ones, then the teacher's words. The merge rule of ADR 007 stays: in a pack that has the word built in, the built-in word (with its picture) plays and the teacher's copy is dropped, in that pack only.
2. **No `built_in` refusal.** A word that is built in in some pack is still a useful teacher word in the others, so `CustomWordData` no longer refuses it; the words page says in which themes the built-in word plays instead. `taken` now means "already one of this teacher's words", in any case.
3. **The migration keeps one row per teacher and word:** the earliest (`created_at`, then `id`), with its syllable override; the later copies are deleted. Started games are unaffected: they keep their round snapshots.
4. **Hiding stays per pack.** Built-in words belong to packs (ADR 004), so `hidden_words` keeps its `pack`, and the words page keeps the theme tabs for the built-in words only.
5. **The words page shows each teacher word's levels:** for every word and syllable level of `content/levels.json`, what the word gives it (the word itself, or the syllables that fit the level's shapes), from `LevelData::itemsFrom()`, and how many distinct items all the teacher's words give each level. Level titles come from the content, never from code.

## Consequences

- A teacher types a word once and sees it in every theme; «Yalnız mənim sözlərim» (`own_words`) plays the same words in every theme.
- `elifba.custom_words.max_per_teacher` (200) now counts distinct words, so it effectively grows for a teacher who had copied words across themes.
- A teacher who wanted a word in one theme only cannot have that any more; hiding applies to built-in words only.
- `UpdateCustomWord` no longer moves a word between packs; the edit dialog loses its theme field.

## Alternatives rejected

- **"Add to every theme" as a button that writes one row per pack:** six rows per word, the cap and the uniqueness count copies, and every edit or delete must touch all of them.
- **Own words from all packs only in «Yalnız mənim sözlərim»:** the same word would play in every theme in one mode and in one theme in the other.
- **Words per class:** rejected in ADR 007 for the same reasons.

## What would make us revisit

Teachers asking for a word in one theme only, or teacher words with pictures (which would tie a word to a look again).
