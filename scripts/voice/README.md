# The board's voice

The clips under `content/assets/voice/` are the board's voice (docs/architecture.md §3 "Voice", ADR 015): every prompt of built-in content, the praise, the retry phrases, the greeting and the end phrases, made once with ElevenLabs (Eleven v4) in the voice «Nigar – Ad Read» and committed. From V2 (ADR 016) the first names of the built-in name list, `content/names.json`, are voiced the same way into `content/voice-names/` — outside `content/assets`, so no name clip is ever a public file. An instance never needs a key: these steps are only for whoever adds content that should be voiced.

`php artisan content:voice` makes the clips that have no file yet and nothing else, so nothing is paid for twice. It reads only `content/`, never the database: no student's or child's name and no teacher word can reach the provider; the names it sends are the list's.

## Before you start

- **A paid plan.** Audio from ElevenLabs' free plan is non-commercial and must carry ElevenLabs' name in its title, so it never enters this repository. Starter is enough: about 24,000 characters voiced everything at V1, about 9,600 credits (Eleven v4 through the API took 0.4 credits a character).
- **The key.** Create one under Developers → API Keys with only **Text to Speech** set to "Access" (and a credit limit if you like); the command needs nothing else. Put `ELEVENLABS_API_KEY=…` in your shell or in the gitignored `.env` (paste the key itself: copying a command after the key replaces it in the clipboard), never in `.env.example`, and do not run `php artisan config:cache` while it is there. The command never prints it. Delete the key when the clips are made.
- **ffmpeg** with `libmp3lame` (`brew install ffmpeg`).
- **The voice id.** `voice.json` names the voice by `voice_id`. When it is empty, `php artisan content:voice --dry-run` lists the account's voices named like `voice_name`; copy the id in.

## Make the clips

1. See what a run would make and send, without calling anything:

    ```sh
    php artisan content:voice --dry-run
    ```

    It prints, per template (`find.picture`, `count.number`…), the clips content needs, those already made and those this run makes, with the characters it sends (what the plan counts), and the credits entry the folder gets with its first clip.

2. Make a sample and listen to it:

    ```sh
    php artisan content:voice --only=find.picture,praise,retry --limit=10
    ```

    It asks before it calls the API (`--force` does not). Every run ends with a page to listen to the clips on, outside the repository (the system temp folder, or `--review=path`), the new ones first.

3. Make the rest: `php artisan content:voice`. A run that stops half way keeps what it made: after an error from the API (a network error, a quota) it lists the files at once; after Ctrl-C the next run lists them. Either way the next run makes only the rest.

4. Listen to every new clip. For a bad one, either delete its file (`content/assets/voice/{hash}.mp3`; the page shows the hash) and run the command again for another take, or give the phrase a respelling in `voice.json` (`spoken.respell`: the phrase → the text the voice is given; «Ğı hərfini tap!» is sent as «ğğı hərfini tap!», which the voice says as «ğı»). To choose among takes, make a few in the scratch folder with the same settings and copy the one you like to `content/assets/voice/{hash}.mp3`: the next run (or `--prune`) lists it. A respelling changes the text, so the clip gets a new hash and the old one is left over.

5. Remove the clips nothing needs any more (a respelled phrase, a word that left a pack, a changed setting): `php artisan content:voice --prune`. Nothing else ever deletes a clip.

6. Run `php artisan content:validate` (it says how many prompts and names are still silent), then commit `content/assets/voice/`, `content/voice-clips.json`, and, after the first run, `content/credits.json` and `CREDITS.md`.

## The names (V2, ADR 016)

The name clips are the group `name`: one clip per name of `content/names.json`, the name alone with `voice.json`'s `names.style` (`[excited] Nihat!`). The owner reviews the list before its clips are made, then:

1. `php artisan content:voice --dry-run --only=name` — the names to make and the characters they send (15,554 for the 924 names of the first list; about 33,000 for 2,000); `--dry-run --only=greeting,finish,finish_class` the same for the three phrases said with a name.
2. `php artisan content:voice --only=name --limit=20` — a first sample, to hear the name before «Afərin!», «Salam!» and «Əla oynadın!» (the order the owner chose by ear on that sample, ADR 016 decision 4) and tune the pause.
3. `php artisan content:voice --only=name,greeting,finish,finish_class` — the rest. Listen to every name on the review page (its own section); a bad one is deleted from `content/voice-names/{hash}.mp3` and made again, or given a respelling in `spoken.respell` (`"Nihat!": "…"`).
4. Run `php artisan content:validate` (it counts the names still silent; the greeting and end phrases need a clip since `ContentValidator::NAME_PHRASE_CLIPS_REQUIRED` was switched on with the first clips, 2026-11-12), and commit the list, `content/voice-names/`, the phrases' clips, `content/voice-clips.json`, `content/credits.json` (the first name clip adds the entry for `content/voice-names/*` from `voice.json`'s `names.credit`) and `CREDITS.md` together.

A name that leaves the list keeps its clip until `--prune`. Nothing about a student or a child ever deletes a clip: a name clip is list content.

## What a clip is

- **The text.** What the adult would read: the prompt without « », a capital first letter, a letter as its sound from `spoken.letters` (`Bı hərfini tap!`, `Rı hərfini tap!`), a number by its name in `voice.json` (`Beş rəqəmini tap!`), a word as the pack writes it. Always the whole phrase, never a word stitched to a template. The provider gets it after the template's audio tag from `voice.json`'s `styles`: `[playful] Dovşan hansıdır?`.
- **The context.** Around every phrase the provider is also given `voice.json`'s `context` (`previous_text`, `next_text`): Azerbaijani sentences it never says and does not bill. A phrase as short as «Pişik hansıdır?» sent alone came out with a Turkish accent; with the context it is Azerbaijani (the owner compared five variants by ear, November 2026).
- **The name.** The first 16 hex digits of the SHA-256 of the provider, the model, the voice id, the language, Stability, Similarity, the context and that text. Change any of them in `voice.json` and every clip needs making again: the old files stay until `--prune`, and `content:validate` fails on them until then.
- **The sound.** ElevenLabs' `mp3_44100_128`, trimmed of silence at both ends, levelled to −16 LUFS (EBU R128) with a limiter at −1.5 dBFS, mono MP3 at 64 kb/s without tags (`VoiceLeveler`). The constants are at the top of `app/Domain/Content/Support/VoiceLeveler.php`; changing them changes no hash, so re-level existing clips by deleting and remaking them.
- **The licence.** MIT, author "Elifba contributors", one credits entry for `content/assets/voice/*` that says the voice is synthetic, in which voice and in which month it was made (ADR 015, decision 10). When you make clips in a later month, add that month to the entry's `changes` and run `php artisan content:credits`.

## The voice has an end date

«Nigar – Ad Read» was removed from ElevenLabs' Voice Library by its owner; accounts that saved it keep it for a notice period of about a year from November 2026. Clips made before then stay usable. After it, new content stays silent until every clip is made again in another voice: set the new `voice_id` and `voice_name`, run the command, listen, then `--prune` (ADR 015, "Consequences").
