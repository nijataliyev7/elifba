# 011 — Parents' children without a class, and home games

- **Status:** Accepted, 2026-11-11 (roadmap step R1; built in R3 and R4)
- **Sections:** architecture.md §0, §1, §2 "Game setup", §4, §5, §7
- **Amends:** [001 students without accounts](001-students-without-accounts.md): its "what would make us revisit" — parents — has happened. A student stays exactly what 001 says; this record adds a second kind of child record next to it.
- **Why it is hard to reverse:** `game_sessions` and `game_rounds` stop requiring a class, and parents' children, their games and their stars are stored in a new table and two new columns. Merging children into classes later, or the other way round, means rewriting ownership for rows that hold children's names.

## Context

A parent wants to play with their own children at home: a 3–6 year old who cannot read yet (picture games, ADR 012) and a 6–10 year old who plays the reading games. There is no class, no school and no other child to compare with. The owner decided: the parent adds their children and plays without creating a class; each child earns stars and the parent sees progress per child; there is no ranking and no podium between siblings.

Play and Rankings are bound to a class today: `game_sessions.school_class_id` and `game_rounds.school_class_id` are required, setup reads the class's learned letters, the board credits a student of the class, and `ClassRanking` groups by class.

Two models were compared.

- **(a) A hidden "family" class.** `school_classes.kind` = `class` | `family`; a parent gets one hidden class and their children are its `students`. Play would not change. But every reader of classes would have to remember the kind: the class list, the pult, the rankings and podium routes (a sibling ranking would exist at `/classes/{class}/rankings` unless each route refuses it), pruning, the class page. Learned letters belong to the class, and siblings of 6 and 9 need their own. And the word "class" would be untrue in the code, the glossary and the privacy notice.
- **(b) Children as their own rows, and a game that is for a class or for a child.** More change in Play once; afterwards a home game cannot reach a ranking by construction, and each table says what it holds.

## Decision

1. **(b).** A new domain `Family` — `Language → Content → Classroom → Family → Play → Rankings` — owns `children` (`Family\Models\Child`, route parameter `{child}`): `id` (ULID), `user_id` (the parent, cascade), `display_name` (a first name, the display-name rule of §7), `avatar`, `learned_letters` (null = all 32), `position`, timestamps. Nothing else about a child (ADR 013). Actions: `AddChild`, `UpdateChild`, `UpdateChildLetters`, `ReorderChildren`, `DeleteChild`.
2. **A parent's child and a teacher's student are never linked,** even when they are the same boy or girl on the same instance. There is no shared id, no lookup by name, and neither account can see the other's record.
3. **A game is for a class or for one child.** `game_sessions.school_class_id` becomes nullable and `game_sessions.child_id` (nullable, cascade) is added; exactly one of them is set, by `StartGame`. `game_rounds.school_class_id` becomes nullable and `game_rounds.child_id` is added, copied from the game for star queries as the class id is for rankings. Play reads "who the game is for" through one value object, `Play\Data\GamePlayers` (`forClass()`, `forChild()`, `of($game)`): the owner, the learned letters and the class or the child. `GameSetupData::rules()`, `StartGame`, `GameSetupOptions`, `PultChoices`, `GuestBoardGameData` and `BoardProps` take it instead of a `SchoolClass`.
4. **At the board in a home game: the game's child, always.** A home game is started for one child (`/children/{child}/games/create`). There is no student grid and no "whole class": every solved round is credited to that child through `game_rounds.child_id`, set when the game starts. `RecordBoardEvents` never reads a `student_id` sent for a home game; `game_rounds.student_id` and `game_answers.student_id` stay null. Siblings take turns by each playing their own game.
5. **Learned letters belong to the child** (`children.learned_letters`, the same 32 toggles as a class). They filter the reading levels of that child's games; picture and number levels do not read them (ADR 012).
6. **Stars are the points of the child's solved rounds,** derived and never stored (§5): `Rankings\Support\ChildStars` sums `game_rounds.points` by `child_id` for today, this week and in all, on the Baku dates the rounds already carry. `PointsRule` is unchanged. The parent sees them on the child's page; the child sees the stars of the game and their total on the board's result screen. No page orders children by stars, gives a place or compares siblings; the list of children shows each child's stars of today only. There is no podium and no share card for a child.
7. **Isolation as for classes.** `{child}` binds only the current account's children (the binding trait, renamed `App\Models\Concerns\BelongsToOwner`), `Family\Policies\ChildPolicy` is the second layer, the routes are in the parent area (ADR 010), and `{child}` joins the parameters `TeacherIsolationTest` enumerates. A display name is unique among one parent's children, compared with Azerbaijani casing under a lock on the parent's `users` row.
8. **At most `elifba.children.max` (10) children per parent.** A family fits; a class does not, so the parent role cannot be used to run a class without the school's permission (ADR 013).
9. **Deletion is real, and there is no archive.** Deleting a child deletes the row and, by cascade, the child's games, rounds and answers: the stars are gone. Deleting the account deletes the children. Home games older than `elifba.retention_days` are pruned like every game, so "in all" means the last 400 days.
10. **Boards and pairing do not change.** A game belongs to its owner's account whatever it is for, so the owner's board, the join code, `/pult`, the guest board and its bearer, the channel and the client-event protocol work as they are; `PultChoices` offers a parent their children where it offers a teacher their classes. A home board does not open the pairing card by itself — one device is the usual case at home — and «Pultu qoş» brings it.

## Consequences

- Rankings and the podium cannot show a parent's child: their queries filter on `school_class_id`, which a home round does not have, and no route under `/children` reaches them.
- Play no longer assumes a class anywhere; a third thing to play for later (a small group, a club) is one more constructor of `GamePlayers`.
- Existing games keep their class; the migration only loosens two columns and adds two.
- A parent with both roles keeps school and home apart: a child who is also in their class is two records.
- A deleted child takes their history with them, unlike a deleted student, whose rounds stay as anonymous class practice: at home there is no class to keep practising.
- The trait rename touches the four models that use it; `TeacherIsolationTest` keeps its path and name.

## Alternatives rejected

- **A hidden family class (a):** above.
- **One table for students and children** (`students.school_class_id` nullable plus an owner column): two ownership paths in the table that holds children's names, and every class query would have to exclude the rows without a class.
- **Several children in one home game with a "who is playing" grid:** siblings differ in age and learned letters, so one setup does not fit both; and it brings back turn-taking and comparison in one room.
- **Archive for children:** a class ends with its school year; a parent's child does not. Delete is enough, and one state fewer holds a child's name.
- **Stars in a stored counter:** a second write to keep right, against "derived things are never stored".
- **A date of birth or an age on the child to pick games:** a field about a child that the parent's own choice of level replaces (ADR 013).

## What would make us revisit

Two parents sharing the same children, a teacher sending homework to a parent's child (which would link the two records and need a new privacy notice), or children playing together at home in a way that needs turns.
