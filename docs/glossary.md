# Glossary

Azerbaijani terms used by teachers and in the interface, the English words used in docs, and the names used in code. Code, comments, docs and identifiers are English; the interface is Azerbaijani first (`lang/az.json`) with English (`lang/en.json`). When a new concept appears, add it here first.

## People and places

| Azərbaycanca                          | English            | Code                                                                     | Notes                                                     |
| ------------------------------------- | ------------------ | ------------------------------------------------------------------------ | --------------------------------------------------------- |
| müəllim                               | teacher            | `App\Models\User` (`users`)                                              | The only account type. Owns classes, students and games.  |
| sinif                                 | class              | `Classroom\Models\SchoolClass` (`school_classes`, route param `{class}`) | `class` is reserved in PHP. "1A sinfi".                   |
| şagird                                | student            | `Classroom\Models\Student` (`students`)                                  | No account, no login (ADR 001).                           |
| ad                                    | display name       | `students.display_name`                                                  | First name; surname initial only to tell two apart.       |
| avatar                                | avatar             | `students.avatar`, `content/avatars.json`                                | Key of a CC0 animal picture.                              |
| arxiv / arxivləmək                    | archive            | `archived_at`, `ArchiveClass`, `ArchiveStudent`                          | Hidden, deleted automatically after the retention period. |
| silmək                                | delete             | `DeleteClass`, `DeleteStudent`                                           | Permanent.                                                |
| ad siyahısı                           | name list (pasted) | `AddStudents`                                                            | One name per line.                                        |
| önizləmə (siyahının)                  | paste preview      | `NameListParser`, `PastedNameData`, `PastedNameFlag`                     | Suggests display names; writes nothing (architecture §7). |
| soyad                                 | surname            | —                                                                        | Never stored; only its initial, when needed.              |
| ata adı (… oğlu / … qızı)             | patronymic         | —                                                                        | Removed by the paste preview; never stored.               |
| sinif rəhbəri, ibtidai sinif müəllimi | class teacher      | —                                                                        | Usually one per class for grades 1–4.                     |

## Language and content

| Azərbaycanca                   | English               | Code                                                                     | Notes                                                                                               |
| ------------------------------ | --------------------- | ------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------- |
| əlifba                         | alphabet              | `Language\Support\Alphabet`, `content/alphabet.json`                     | 32 letters. Also the product name, written `elifba` in code and URLs.                               |
| hərf                           | letter                | `Unit::Letter`, content key `letter:{lower}`                             |                                                                                                     |
| böyük hərf / kiçik hərf        | uppercase / lowercase | `LetterCase::Upper` / `LetterCase::Lower`, `Az::upper()` / `Az::lower()` | İ↔i, I↔ı.                                                                                           |
| sait                           | vowel                 | `Alphabet::isVowel()`                                                    | a, e, ə, ı, i, o, ö, u, ü.                                                                          |
| hərfin adı                     | letter name           | `Alphabet::name()`, `name` in `alphabet.json`                            | a, be, ce, çe … el, em, en, er … ze.                                                                |
| oxşar hərflər                  | confusable letters    | `Alphabet::confusables()`, `confusables` in `alphabet.json`              | e/ə, i/ı, o/ö, u/ü, s/ş, c/ç, g/ğ, k/q, b/d…; letter decoys come from here first.                   |
| samit                          | consonant             |                                                                          | The other 23; `y` is always a consonant.                                                            |
| heca                           | syllable              | `Unit::Syllable`, content key `syllable:{lower}`                         |                                                                                                     |
| heca bölgüsü / hecalara bölmək | syllabification       | `Language\Support\Syllabifier`                                           | Per-word override: `syllables` in a pack.                                                           |
| hecanın quruluşu               | syllable shape        | `Syllabifier::shape()`, `shapes` in `levels.json`                        | V, CV, VC, CVC, VCC, CVCC (vowel / consonant).                                                      |
| söz                            | word                  | `Unit::Word`, content key `word:{lower}`                                 |                                                                                                     |
| cümlə                          | sentence              | `Unit::Sentence`, content key `sentence:{pack}:{id}`                     |                                                                                                     |
| durğu işarəsi                  | punctuation           | `prompt.tail`                                                            | Fixed after the last slot in sentence rounds.                                                       |
| öyrənilmiş hərflər             | learned letters       | `school_classes.learned_letters`                                         | 32 toggles on the class page; filters every level. No textbook order is built in.                   |
| sıra sayı (1-ci, 2-ci, 3-cü)   | ordinal               | `Language\Support\Ordinal`, `place_label`                                | Vowel harmony; built on the server.                                                                 |
| tarix (9 noyabr 2026)          | date label            | `Language\Support\DateLabel`                                             | Server-formatted in the interface language; a week is "9–15 noyabr 2026".                           |
| məzmun                         | content               | `Content` domain, `content/`                                             | Versioned files (ADR 004).                                                                          |
| məzmun paketi, tema            | theme pack, theme     | `Content\Data\PackData`, `content/packs/{key}.json`                      | Look + vocabulary.                                                                                  |
| müəllimin sözü, öz sözlərim    | teacher word          | `Content\Models\CustomWord` (`custom_words`), `AddCustomWord`            | A teacher's own word in a pack, for all their classes; no picture, never in `build_word` (ADR 007). |
| sözü gizlətmək / göstərmək     | hide / show a word    | `Content\Models\HiddenWord` (`hidden_words`), `HideWord`, `ShowWord`     | A built-in word left out of one teacher's games only.                                               |
| müəllimin paketi               | teacher's pack        | `Content\Support\TeacherPacks`                                           | A pack as one teacher plays it: built-in words minus hidden ones, plus their own.                   |
| səviyyə                        | level                 | `Content\Data\LevelData`, `content/levels.json`                          | Unit, filter, mechanics, items, decoys.                                                             |
| tapşırıq mətni                 | prompt                | `PromptData` / `Round['prompt']`                                         | Child-facing, always Azerbaijani.                                                                   |
| tapşırıq şablonu               | prompt template       | `Content\Data\PromptTemplateData`, `content/prompts.json`                | One per mechanic × unit; `«{x}» hecasını tap!`.                                                     |
| müəlliflik qeydi, lisenziya    | credit, license       | `Content\Data\CreditData`, `Content\Enums\License`                       | SPDX: CC0-1.0, CC-BY-4.0, CC-BY-SA-4.0, OFL-1.1, MIT.                                               |

### Theme packs

| Azərbaycanca  | English          | Pack key   | Default style                 |
| ------------- | ---------------- | ---------- | ----------------------------- |
| qatar         | train            | `train`    | `wagon` items and slots       |
| şar(lar)      | balloons         | `balloons` | `balloon`                     |
| heyvanlar     | animals          | `animals`  | `animal`, `sparkle` effect    |
| meyvələr      | fruit            | `fruit`    | `fruit`                       |
| şəhərlərimiz  | our cities       | `cities`   | `card`                        |
| milli rəmzlər | national symbols | `symbols`  | `card`, `sparkle` effect only |

## Games

| Azərbaycanca                 | English                         | Code                                                              | Notes                                                                                                                                  |
| ---------------------------- | ------------------------------- | ----------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| oyun                         | game (one played session)       | `Play\Models\GameSession` (`game_sessions`, route param `{game}`) |                                                                                                                                        |
| oyun növü, mexanika          | mechanic                        | `Content\Enums\Mechanic`                                          | `find`, `pop`, `build_syllable`, `build_word`, `build_sentence`; in Content because levels and prompts name it.                        |
| Tap! / Şarı partlat          | find                            | `Mechanic::Find`                                                  | "Şarı partlat" = find with the balloons pack.                                                                                          |
| Oxu və vur                   | read and tap (pop)              | `Mechanic::Pop`, `PopRoundBuilder`                                | For beginners: no target, every item is right; the child reads one aloud and hits it, it bursts; the next set comes when all are gone. |
| Heca qur (heca qatarı)       | build a syllable                | `Mechanic::BuildSyllable`                                         | Letters into wagons.                                                                                                                   |
| Sözü yığ                     | build a word                    | `Mechanic::BuildWord`                                             | Syllables into slots.                                                                                                                  |
| Cümlə qur                    | build a sentence                | `Mechanic::BuildSentence`                                         | Words into slots.                                                                                                                      |
| raund, tur                   | round                           | `Play\Models\GameRound`, `RoundData` / `Round`                    | Stored as a snapshot.                                                                                                                  |
| element                      | item                            | `RoundItemData` / `Round['items']`                                | One thing on the board.                                                                                                                |
| kart (hərf, heca, söz kartı) | tile                            | an item of a `sequence` round                                     | Reusable: `nə-nə` uses the `nə` tile twice.                                                                                            |
| hədəf                        | target                          | `SolutionData::item_ids`                                          |                                                                                                                                        |
| yanıltıcı                    | decoy                           | —                                                                 | Wrong items; confusable letters first.                                                                                                 |
| yuva, vaqon                  | slot, wagon                     | `Round['slots']`, slot style `wagon` / `frame`                    | Build mechanics.                                                                                                                       |
| həll                         | solution                        | `SolutionData`                                                    | `any` or `sequence`.                                                                                                                   |
| vuruş, toxunuş               | hit                             | `HitData` / `Hit`, `game_answers`                                 | From the phone (`remote`) or the board (`board`).                                                                                      |
| cavab                        | answer                          | `Play\Models\GameAnswer`                                          | A stored hit, judged correct or not.                                                                                                   |
| səhv                         | miss                            | `game_rounds.misses`, status `miss`                               |                                                                                                                                        |
| keçmək (raundu)              | skip                            | `skip` board event, `skipped_at`                                  |                                                                                                                                        |
| partlayış / parıltı          | burst / sparkle                 | `Effect::Burst` / `Effect::Sparkle`                               | State symbols are never burst.                                                                                                         |
| hakim                        | judge                           | `Play\Support\RoundJudge`, `engine.ts`                            |                                                                                                                                        |
| oyunun qurulması             | game setup                      | `Play\Data\GameSetupData`, page `games/setup`                     | Mechanic × pack × level, rounds, letter case, letters.                                                                                 |
| lövhənin çəkə bildiyi        | playable (what the board draws) | `Play\Support\Playable`                                           | Mechanics, item styles, slot styles and effects the board implements; A6: find, balloon, burst.                                        |
| nəticə (hadisənin)           | answer result                   | `AnswerResultData` / `AnswerResult`, `AnswerStatus`               | `correct`, `miss`, `skipped`, `duplicate`, `stale`.                                                                                    |
| lövhə dövrü                  | board epoch                     | `game_sessions.board_epoch`, `ClaimBoard`                         | Fencing token: a board with an older one may not write.                                                                                |
| məktəb təqvimi               | school calendar                 | `Play\Support\SchoolCalendar`                                     | Baku dates: `played_on`, Monday `week_start`.                                                                                          |
| fasilə                       | pause                           | `GameSessionStatus::Paused`                                       |                                                                                                                                        |
| bitdi / tərk edildi          | finished / abandoned            | `GameSessionStatus::Finished` / `Abandoned`                       |                                                                                                                                        |

## Devices and live play

| Azərbaycanca             | English                        | Code                                                                                                       | Notes                                                                                   |
| ------------------------ | ------------------------------ | ---------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| lövhə                    | board                          | page `games/board`, `ClaimBoard`, `board_epoch`                                                            | The projected screen; the only writer of results (ADR 002).                             |
| müəllim lövhəsi          | teacher board                  | `/games/{game}/board`                                                                                      | A board on the teacher's own laptop, using the teacher's session.                       |
| qonaq lövhə              | guest board                    | `Play\Models\GuestBoard` (`guest_boards`), `/lovhe`, `board` guard                                         | A board on a shared computer, claimed from the teacher's phone; no teacher login on it. |
| lövhəni bağla            | close the board                | `CloseGuestBoard`                                                                                          | Ends a guest board; nothing stays on the computer.                                      |
| proyektor rejimi         | projector (full-screen) mode   | the board page                                                                                             |                                                                                         |
| pult                     | remote                         | page `games/remote`, `/pult`                                                                               | The teacher's phone.                                                                    |
| qoşulma kodu             | pairing code                   | `Play\Support\PairingCode`: `game_sessions.join_code` (teacher board) or `guest_boards.code` (guest board) | 6 digits, one code space; identifies, never authorizes.                                 |
| QR kod                   | QR code                        | `qrcode.react`                                                                                             | Encodes `/pult/{code}`.                                                                 |
| qoşulmaq                 | pair, join                     | `/pult/{code}`                                                                                             |                                                                                         |
| lövhədə kimdir           | at the board (current student) | `client-student`, `HitData::student_id`                                                                    | null = whole class, no points.                                                          |
| bütün sinif              | whole class                    | `student_id = null`                                                                                        |                                                                                         |
| kanal                    | channel                        | `private-game.{gameId}`                                                                                    |                                                                                         |
| siqnal (pultdan lövhəyə) | client event (whisper)         | `client-hit`, `client-state`, …                                                                            | Protocol v1, architecture.md §4.                                                        |
| növbə (göndərilməmiş)    | outbox                         | `resources/js/game/outbox.ts`                                                                              | Unsaved board events.                                                                   |
| yenidən qoşulma          | reconnect                      |                                                                                                            |                                                                                         |

## Scores and sharing

| Azərbaycanca                         | English                   | Code                                                                                | Notes                                                                                             |
| ------------------------------------ | ------------------------- | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| xal                                  | points                    | `game_rounds.points`, `PointsRule`                                                  | 3 / 2 / 1 per solved round.                                                                       |
| ulduz                                | star                      | —                                                                                   | How points are drawn for children.                                                                |
| reytinq                              | ranking                   | `Rankings\Support\ClassRanking`, page `rankings/show` (`/classes/{class}/rankings`) | Derived, never stored; only on the teacher's pages.                                               |
| günlük / həftəlik                    | daily / weekly            | `Rankings\Enums\Period` (`day`, `week`); `played_on` / `week_start`                 | Baku dates; week starts Monday.                                                                   |
| dövr (gün / həftə)                   | period (which ranking)    | `Rankings\Data\PeriodData`, `?period=day\|week&date=YYYY-MM-DD`                     | Default: the day, today in Baku; any date selects its Monday–Sunday week. Shared with the podium. |
| yer (1-ci yer)                       | place                     | `place`, `place_label` (`Ordinal::label()`)                                         | Dense: ties share a place. Label "1-ci" in az, "1st" in en.                                       |
| hələ xal yoxdur                      | no points yet             | `place` = null                                                                      | Active students with 0 points, listed after the places, without one.                              |
| bərabər xal                          | tie                       | `Rankings\Support\DenseRank`                                                        | 10, 10, 8, 7 → 1, 1, 2, 3; names in Azerbaijani order inside a place.                             |
| qaliblər, kürsü                      | podium                    | `Rankings\Support\Podium`, page `podium`                                            | Places 1–3.                                                                                       |
| Günün qalibləri / Həftənin qalibləri | winners of the day / week | `lang` keys                                                                         | Share card titles.                                                                                |
| paylaşım şəkli                       | share card                | `Rankings\Data\ShareCardData` (the `card` prop), `components/share`                 | 1080×1920 PNG made in the browser.                                                                |
| paylaşmaq / yükləmək                 | share / download          | Web Share API / `<a download>`                                                      |                                                                                                   |
| WhatsApp statusu                     | WhatsApp status           | —                                                                                   | The main destination of the share card.                                                           |

## Project

| Azərbaycanca        | English               | Code                                             | Notes                                             |
| ------------------- | --------------------- | ------------------------------------------------ | ------------------------------------------------- |
| domen (kod)         | domain                | `app/Domain/*`                                   | Language → Content → Classroom → Play → Rankings. |
| əməliyyat           | action                | `*/Actions/*`                                    | The only code that writes.                        |
| qərar qeydi         | decision record (ADR) | `docs/decisions/NNN-*.md`                        |                                                   |
| müəlliflik qeydləri | credits               | `content/credits.json`, `CREDITS.md`, `/credits` |                                                   |
| məxfilik            | privacy               | `/privacy`                                       |                                                   |
| saxlama müddəti     | retention period      | `elifba.retention_days`                          | 400 days.                                         |
