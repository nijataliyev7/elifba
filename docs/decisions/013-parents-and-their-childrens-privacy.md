# 013 — Parents act for their own children: agreements per role and the privacy notice

- **Status:** Accepted, 2026-11-11 (roadmap step R1; built in R2 and R3)
- **Sections:** architecture.md §7
- **Amends:** architecture.md §7 "Teacher's agreement" (one sentence at registration becomes a privacy-note tick at registration and a sentence per role); [001](001-students-without-accounts.md) decision 1's list of what is never stored now covers a parent's child too.
- **Why it is hard to reverse:** what an account agreed to, and when, is recorded per role and cannot be asked again in retrospect; and a field once collected about a child cannot be taken back from backups and exports.

## Context

A teacher uses Elifba for a school: the children are someone else's, the school permits the use, and parents are informed by a note. A parent uses Elifba for their own child: nobody else has to permit it, and the parent is the one who may decide for the child. The registration sentence — «Məxfilik qeydini oxumuşam və Elifba-dan məktəbimin icazəsi ilə istifadə edirəm» — is untrue for a parent. The owner asked for the agreement text of each role and a parent section on `/privacy`, and CLAUDE.md's rule stands: a child is a display name and an avatar.

## Decision

1. **Registration asks for the privacy note only,** for every account, by the form and by the Google completion page: «[Məxfilik qeydini] oxumuşam.» / "I have read the [privacy note]." The moment stays in `users.terms_accepted_at`.
2. **Each role has its own sentence, accepted when the role is taken** (ADR 010: the `/role` page, and Settings → «Rollar» for the second role). `GrantRole` refuses a role without it and stamps the moment:
    - teacher — `users.teacher_agreed_at`: «Elifba-dan məktəbimin icazəsi ilə istifadə edirəm.» / "I use Elifba with my school's permission."
    - parent — `users.parent_agreed_at`: «Əlavə etdiyim uşaqların valideyni və ya qanuni nümayəndəsiyəm.» / "I am the parent or legal guardian of the children I add."
3. **A child record is a first name and an avatar,** plus what the games need to run: the order among the parent's children and the learned letters. No surname or initial, photo, date of birth, age, gender, school, class, contact or login — and no field "for choosing games": the parent picks the level. `Family\Data\ChildData` uses the display-name rule of §7 without the surname initial.
4. **The parent role is for a family.** At most `elifba.children.max` (10) children per account, so a class cannot be run under the parent sentence, without the school.
5. **No link to school.** A parent's child and a teacher's student are separate records that nothing joins (ADR 011). A teacher never sees what a child did at home, and a parent never sees a class.
6. **No comparison and nothing leaves the account.** No ranking, podium or share card for a parent's children (ADR 011). A child's name reaches a device only through the parent's authenticated pages and the board props of that parent's game; never a log, an exception, a broadcast or a URL — the rules of §7, which `{child}` (a ULID) and `display_name` already follow.
7. **Deletion.** The parent deletes a child on the child's page: the row, the games, the rounds and the answers go at once. Deleting the account deletes everything. Games older than `elifba.retention_days` are pruned as for classes.
8. **`/privacy` gains a section for parents** (az and en), next to what it says for teachers: what is stored for a parent's account and for a child, why (to show whose game it is and the child's stars), that only the parent's account sees it, that nothing is compared or shared, how to delete it and how long games are kept. The introduction names both uses. The existing note that a teacher copies for the parents of a class stays, under a title that says so («Sinif valideynləri üçün qeyd»). When Google sign-in is on, the Google paragraph speaks of the account, not of the teacher.

## Consequences

- The record shows which sentence an account accepted and when, per role; existing teachers keep the moment of their registration sentence as `teacher_agreed_at`.
- A new user ticks twice, once at registration and once at the role: each tick says one thing.
- Elifba still collects no consent from anyone but the account holder: the parent is the holder.
- Age-based defaults are impossible by design; the setup form orders the games (pictures first for parents) instead.
- A parent who wants more than ten children's records is told the limit and pointed to the teacher role.

## Alternatives rejected

- **One sentence for both roles** («…məktəbimin icazəsi ilə və ya öz uşaqlarım üçün…»): each account would agree to something that does not apply to it.
- **Only `terms_accepted_at`, with the role sentence validated and not recorded:** the second role is taken months later, and the record would not say that its sentence was ever accepted.
- **Timestamps on the role pivot:** `model_has_roles` is the package's table; an agreement is a fact about the account.
- **Verifying parenthood** (documents, a child's ID): far more data than the product holds about anyone, for a first name and a cartoon animal.
- **A year of birth on the child:** see decision 3.
- **Letting a teacher invite parents to see a child's school results:** it links the two records and makes Elifba a channel between school and home; not asked for.

## What would make us revisit

A legal requirement for verifiable parental consent in a country where an instance runs, children using Elifba alone on their own device, or any feature that joins a child's school record with the home one.
