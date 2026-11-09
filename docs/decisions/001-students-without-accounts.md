# 001 — Students without accounts: a first name and an avatar, owned by one teacher

- **Status:** Accepted, 2026-11-09 (roadmap step A1)
- **Sections:** architecture.md §1 (tables), §7 (privacy)
- **Why it is hard to reverse:** every table, route and page is built around "a student is a row a teacher owns". Adding student logins or more personal fields later changes the legal footing of the whole product; removing data once collected cannot be undone for copies that already left (exports, share cards, backups).

## Context

Elifba is used by primary-school teachers with children aged 6–10. The children never touch the teacher's account: they touch the wall, and the teacher taps the phone. The teacher needs to tell children apart on the remote ("who is at the board"), in the rankings and on the podium image posted to WhatsApp status. Nothing else about a child is needed for the product to work. The children cannot consent; the teacher acts for the school, and the school may have to justify every field it lets a third-party service hold.

## Decision

1. A **student** (`students`) is: `display_name` (a first name, with a surname initial only when two children share a first name), `avatar` (a key from a fixed CC0 set), the class, the order in the class, and `archived_at`. There is no field for surname, photo, birth date, gender, contact, ID number, username, password or PIN — and none may be added without superseding this record.
2. **No student login.** Children never authenticate and nothing asks a child for input beyond touching the wall or a touch screen.
3. **One owner.** A class belongs to exactly one teacher (`school_classes.user_id`); a student belongs to exactly one class. There is no sharing between teachers in v1.
4. **Isolation by construction.** Route model binding for `{class}`, `{student}` and `{game}` resolves only rows owned by the logged-in teacher (`BelongsToTeacher::resolveRouteBinding`), so a foreign id is a 404 before any controller code runs. Policies are a second layer; broadcast channel callbacks check ownership; a test enumerates every route with these parameters and fails if one is missing from the isolation dataset.
5. **Deletion is real.** Deleting a student deletes the row; rounds and answers keep `student_id = null` and become anonymous class practice (out of every ranking). Deleting a class or the teacher's account deletes everything under it. Archived classes and students, and games, are deleted automatically after `elifba.retention_days` (400).
6. **Names stay inside.** Names reach devices only through authenticated HTTP responses. They never appear in client events, broadcasts, URLs, file names, logs or exceptions. The share card shows only first names and avatars of places 1–3 (architecture.md §6).
7. **Pasting a list** strips numbering and surnames: "Əli Məmmədov" becomes "Əli". A surname initial ("Əli M.", "Əli H.") is suggested only when two children in the class share a first name. The teacher confirms what is stored, and pasted surnames are never saved.

## Consequences

- A school can accept Elifba on the basis that it holds a first name and a cartoon animal per child.
- Features that need a child's identity across classes or years (a child moving to another teacher, a parent view, per-child homework) are out of scope until this record is superseded.
- Every new route with a class, student or game parameter is isolated automatically, and the test catches a route that bypasses binding.
- Anonymised history stays useful for class-level statistics ("which letters are hard") after children are deleted.

## Alternatives rejected

- **Student accounts or picture PINs** (as in some classroom apps): children of this age cannot manage credentials, the teacher would become a password help desk, and the product works without them.
- **Full names and more profile fields** "for later": data collected without a use is a liability and makes the privacy notice harder for schools to accept.
- **Soft delete only:** a deleted child's name would still be in the database and backups.
- **Policies without scoped binding:** a single forgotten `authorize()` call would leak another teacher's class.

## What would make us revisit

A real need for parents or children to see their own progress, or a ministry/school requirement for a different identity model. Either supersedes this record with a new one, including a migration and a new privacy notice.
