# 018 — A student's name as the teacher writes it

- **Status:** Accepted, 2026-11-13 (the owner's decision of that day). Supersedes decisions 1 and 7 of [001](001-students-without-accounts.md) for a class's students only; a parent's child keeps a first name ([013](013-parents-and-their-childrens-privacy.md) stands).
- **Sections:** architecture.md §1 (`students.display_name`), §3 "Voice", §6 (the share card), §7 ("Minimum data", "Pasting a list", "A display name")
- **Why it is hard to reverse:** surnames and patronymics enter the database, the backups and every page that shows a student; taking them out again means rewriting every row and the public privacy page, and copies already made stay.

## Context

Until now a student was a first name, with a surname initial added only when two children shared a first name («Nihat A.»), and the paste flow dropped surnames and patronymics. In use the owner found the initials wrong: a class may hold two «Nihat Əliyev», the teacher wants to tell them apart as the school does, by the surname or the patronymic, and a teacher who typed «Nicat mp» on the board to tell two Nicats apart got a name the voice left silent. The board's voice only needs the first name, and the first word of a name the teacher writes is the first name.

## Decision

1. **A student's display name is the name as the teacher writes it**: the first name, then whatever follows it — a surname, a patronymic, an initial, letters the teacher chose («Nihat Əliyev Vəli oğlu», «Nihat Əliyev», «Nicat mp», «Aysel M.»). The display name rule (`Classroom\Support\DisplayName`) keeps its shape (letters of the alphabet, words, at most 40 characters) and no longer refuses a surname or a patronymic for a student. Still no other field: no photo, birth date, gender, contact or ID.
2. **The paste flow keeps the full name.** Numbering, bullets, columns without a name and words with digits are still dropped; the words are cased (`Az::title()`, the patronymic's «oğlu» / «qızı» lowercase); a list written surname first, as in a school register, is turned first name first («Məmmədov Əli Rəşid oğlu» → «Əli Məmmədov Rəşid oğlu», flagged «Əvvəldə soyad yazılıb»); the patronymic is dropped only when the full name would pass 40 characters. **No initial is added any more.**
3. **The same name is a warning, never a rewrite.** Two students may share a first name («Nihat Əliyev», «Nihat Həsənov»): the preview marks it («Sinifdə eyni adlı şagird var») and adds both. The same full name twice is refused, as before, and the warning asks for the patronymic or another word to tell them apart.
4. **The voice says the first name** (ADR 016 as amended the same day): the first word, the rest taken as the surname, unless the first word is a surname written first.
5. **Shown in full** on the class page, the board, the remote, the rankings and the podium (the teacher's own screens and the classroom wall). **The share card carries the first name only** (`PersonName::firstName()`), as §6 always required: it leaves the class.
6. **The privacy page and the parents' note say so**: a student's name is kept as the teacher writes it, a surname and a patronymic included when the teacher writes them; still nothing else.

## Consequences

- Existing rows keep their names («Nihat A.»); a teacher may rename them.
- A school that accepted Elifba for "a first name and a cartoon animal per child" now reads a different privacy page: the teacher decides how much of the name to write.
- `PastedNameFlag::InitialAdded` and the students' surname refusal are gone; the children's rule (`new DisplayName(initial: false, surname: false)`) is unchanged.

## Rejected

- **Initials to tell children apart** (001 decision 7): the owner found them wrong in class; the school tells children apart by the surname or the patronymic.
- **Storing the full name and showing the first name everywhere**: the owner chose the full name on the teacher's screens and the wall.
