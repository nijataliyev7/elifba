# 004 — Content is versioned files, processed by the server's Language domain

- **Status:** Accepted, 2026-11-09 (roadmap step A1)
- **Sections:** architecture.md §3
- **Why it is hard to reverse:** content keys (`letter:ə`, `word:alma`, `sentence:fruit:alma-qirmizidir`) are stored in every answer, pack versions in every game, and the contributor workflow (pull requests reviewed by a teacher) is built on files. Moving content into tables later means a sync or migration path and a different review process.

## Context

Elifba's content — the 32-letter alphabet, six theme packs with words, pictures and sentences, levels and child-facing prompts — is small (hundreds of entries), must be checked by a teacher (roadmap step O2), and must be correct Azerbaijani: İ/i and I/ı casing, Ə/ə as U+018F/U+0259, and syllables as a first-grade textbook teaches them. Syllables of every word are needed for syllable and word games.

## Decision

1. **Files are the source of truth:** `content/alphabet.json`, `levels.json`, `prompts.json`, `avatars.json`, `credits.json`, `packs/*.json`, `assets/**`, and the syllable test set `tests/syllables.json`. Nothing is seeded into tables.
2. **One reader:** `Content\Support\ContentRepository`. In production it reads `bootstrap/cache/content.php`, a compiled PHP array written by `content:cache` (registered with `optimize`, kept in memory by OPcache like `config:cache`). Without that file (development, tests) it reads the JSON files directly, memoized per request. `content:validate` reports problems, and `ContentValidator` runs in the Pest suite over every pack.
3. **Versioning:** each pack has an integer `version`, bumped on every change; games record it; asset URLs carry it; stored round snapshots make old games independent of current files.
4. **Assets** are served from `content/assets` through a `filesystems.links` entry (`public/content`); the JSON files are not public.
5. **Language rules live once, on the server:** `Az::upper/lower` (explicit i↔İ, ı↔I), `Az::normalize` (NFC; Cyrillic Ә/ә and Ǝ → Ə/ə), `Syllabifier` (one vowel per syllable; between vowels: 0 consonants → split between them, 1 → before it, 2+ → before the last; leading and trailing clusters stay with the first and last syllable), per-word `syllables` overrides that must join back to the word, `AzCollator` (32-letter order) and `Ordinal` (1-ci, 3-cü, 6-cı…). TypeScript never cases, splits or suffixes content.
6. **Child-facing text is Azerbaijani in every interface language**; prompt templates wrap inserted text in «» and never attach a suffix to it.

## Consequences

- Teachers and contributors change content with a pull request whose diff the owner can review; CI rejects letters outside the alphabet, broken overrides, missing pictures and missing credits.
- Deploys need no seeding step; tests read exactly what production reads.
- There is no admin panel for content in v1. Teacher-authored words came as foreseen, additively: `custom_words` and `hidden_words` tables merged per teacher by `Content\Support\TeacherPacks` next to `ContentRepository`, which still reads only the files ([007 teacher words](007-teacher-words.md)).
- The syllabifier is right for native words; disputed or loanword splits are fixed by overrides, not by changing the algorithm.

## Alternatives rejected

- **JSON packs seeded into tables:** two sources of truth, a deploy step that can be forgotten, drift between environments, and no query that needs SQL.
- **An admin content editor:** no admin role exists, and edits would bypass review.
- **A hyphenation dictionary (TeX/Hunspell patterns):** line-break hyphenation differs from textbook syllables, and the quality and license of an Azerbaijani pattern file are unclear.
- **Casing and syllabification in the browser as well:** a second implementation to keep identical.

## What would make us revisit

Thousands of words, per-school content, or content edited by non-developers at a pace pull requests cannot follow.
