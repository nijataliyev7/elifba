# 007 — Teacher words: own words per theme pack, built-in words hidden per teacher

- **Status:** Accepted, 2026-11-10
- **Sections:** architecture.md §1, §2, §3
- **Builds on:** [004 content as versioned files](004-content-as-versioned-files.md), which foresaw "a new `custom_words` table merged by `ContentRepository` (additive)".
- **Why it is hard to reverse:** teacher words are rows that teachers type and keep for a school year; once they exist, moving them to another shape (per class, into files, with pictures) means a data migration, and a teacher who loses words they typed loses trust in the app.

## Context

The six theme packs are the same for every teacher (ADR 004). Teachers asked to play with the words their class is reading this week, which no pack can predict, and to leave out a built-in word that does not suit their class (a word the class has not met, a fruit that does not grow in their region). The owner decided (2026-11-10):

1. Built-in words stay the same for everyone. A teacher can hide a built-in word in their own games and show it again; other teachers are not affected.
2. A teacher adds words to an existing theme pack they choose, and can edit and delete them. The words belong to the teacher, so they apply to all of that teacher's classes, not to one class.
3. Teacher words have no picture: no uploads and no picker. They play in `find` and in the syllable games, never in `build_word`.
4. The teacher may fix a word's syllable split with an override; otherwise `Syllabifier` splits it.

## Decision

1. **Two tables in the Content domain**, both owned by the teacher (`user_id`, cascade): `custom_words` (a teacher's own word in one pack: `text`, an optional `syllables` override, and `word_key` = `word:{lower}` for a unique index per teacher and pack) and `hidden_words` (a built-in word a teacher has hidden: pack and `word_key`). Built-in content stays in files, read only by `ContentRepository`.
2. **One merge, in one place:** `Content\Support\TeacherPacks` gives a pack as one teacher plays it — the built-in words minus the ones the teacher hid, followed by the teacher's own words — as the same `PackData` and `WordData` that the files give. A teacher word whose lowercase equals a built-in word of the pack is dropped, hidden or not, so when a later pack version adds a word a teacher already has, the built-in one (with its picture) wins.
3. **Play reads only the teacher's pack** wherever rounds are built or checked: the setup page's options, `GameSetupData::rules()`, `StartGame` and the round builders, which take the pack as an argument. The board's look (`BoardProps`) and the list of packs stay global.
4. **One word checker** (`Content\Support\WordRules`) holds the rules a pack word follows — alphabet letters only, lowercase or a capitalised proper noun, a vowel, an override that joins back with one vowel per part — and both `ContentValidator` (for files) and `CustomWordData` (for teachers) use it. Teacher input is normalized first (`Az::normalize`: phone keyboards type Cyrillic ә); files must already be normalized.
5. **Rounds stay snapshots.** A teacher word reaches the board only inside `game_rounds.payload`, with the key `word:{lower}`, like any word. The round contract does not change.

## Consequences

- Each teacher can shape the vocabulary of every pack without a pull request, and no teacher's change reaches another teacher.
- Teacher words are not reviewed by the owner (O2). The checker keeps them to the alphabet and to the levels' syllable counts; the meaning and spelling are the teacher's responsibility, as with the names they type.
- A teacher word may be a child's name. It is treated like a display name: never in a log, an exception message or a URL (§7); routes address a teacher word by its ULID and a hidden word by pack and word in the request body.
- Hiding words can leave a level with too little content; the setup page and `GameSetupData::rules()` then give the same `too_few_*` reason they give for unlearned letters.
- `game_sessions.pack_version` still records the built-in pack version; a game started from a teacher's pack is reproducible from its snapshots, not from its seed.

## Alternatives rejected

- **Teacher words in the pack files:** every teacher word would need a pull request and would reach every teacher.
- **Words per class:** a teacher with two classes in the same grade would type every word twice; the learned-letters filter already narrows each class.
- **Deleting built-in words per teacher (or copying the pack per teacher):** a copy would stop receiving pack updates and fixes; hiding keeps the built-in pack the single source and is undone with one click.
- **Pictures for teacher words (uploads or an OpenMoji picker):** uploads bring storage, moderation and licensing (§8) for photos teachers may not own; a picker cannot cover arbitrary words. Without a picture a word cannot be a `build_word` question, which is the only place pictures are used.
- **A merge in every Play reader:** the setup page would offer a game that `StartGame` could not build as soon as two readers disagreed.

## What would make us revisit

Teachers asking to share their words with colleagues or a school, a demand for pictures in `build_word`, or sentences written by teachers.
