<?php

use App\Domain\Content\Data\VoiceData;
use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Data\VoiceSettingsData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Language\Support\Alphabet;

/*
 * VoiceClips (docs/architecture.md §3 "Voice", ADR 015): what each prompt
 * says aloud, the hash that names its clip and the clips built-in content
 * needs. A clip says what the adult would read, never a stitched word, and
 * a clip nobody made is no URL.
 */

function voiceWith(array $change = []): VoiceClips
{
    $voice = app(ContentRepository::class)->voice();

    return new VoiceClips(new VoiceData(
        $change['version'] ?? $voice->version,
        $change['settings'] ?? $voice->settings,
        $voice->styles,
        $voice->letters,
        $voice->numbers,
        $change['respell'] ?? $voice->respell,
        $voice->praise,
        $voice->retry,
        $voice->credit,
        $change['clips'] ?? [],
    ));
}

function promptLine(Mechanic $mechanic, Unit $unit, ?string $target, ?string $pack = null): ?VoiceLineData
{
    $content = app(ContentRepository::class);

    return voiceWith()->prompt($content->prompt($mechanic, $unit), $target, $pack === null ? null : $content->pack($pack));
}

it('says what the adult would read: the prompt without « », a letter as its sound, a number by its name', function (Mechanic $mechanic, Unit $unit, ?string $target, ?string $pack, string $text): void {
    expect(promptLine($mechanic, $unit, $target, $pack)?->text)->toBe($text);
})->with([
    'a consonant as its sound, with ı' => [Mechanic::Find, Unit::Letter, 'letter:b', null, '[playful] Bı hərfini tap!'],
    'r as rı, never er' => [Mechanic::Find, Unit::Letter, 'letter:r', null, '[playful] Rı hərfini tap!'],
    'a vowel as itself' => [Mechanic::Find, Unit::Letter, 'letter:i', null, '[playful] İ hərfini tap!'],
    'ə' => [Mechanic::Find, Unit::Letter, 'letter:ə', null, '[playful] Ə hərfini tap!'],
    'a syllable as written' => [Mechanic::Find, Unit::Syllable, 'syllable:ğa', 'fruit', '[playful] Ğa hecasını tap!'],
    'build a syllable' => [Mechanic::BuildSyllable, Unit::Syllable, 'syllable:ba', 'fruit', '[playful] Ba hecasını qur!'],
    'a word as the pack writes it' => [Mechanic::Find, Unit::Word, 'word:bakı', 'cities', '[playful] Bakı sözünü tap!'],
    'a picture' => [Mechanic::Find, Unit::Picture, 'word:dovşan', 'animals', '[playful] Dovşan hansıdır?'],
    'a number by its name' => [Mechanic::Find, Unit::Number, 'number:5', 'numbers', '[playful] Beş rəqəmini tap!'],
    'ten' => [Mechanic::Find, Unit::Number, 'number:10', 'numbers', '[playful] On rəqəmini tap!'],
    'a thing counted' => [Mechanic::Count, Unit::Number, 'word:alma', 'fruit', '[curious] Neçə alma var?'],
    'pop says only the instruction' => [Mechanic::Pop, Unit::Letter, 'letter:a', null, '[playful] Hər hərfi oxu və vur!'],
    'build_word says only the instruction' => [Mechanic::BuildWord, Unit::Word, 'word:alma', 'fruit', '[playful] Sözü hecalardan yığ!'],
    'build_sentence says only the instruction' => [Mechanic::BuildSentence, Unit::Sentence, null, 'fruit', '[playful] Sözlərdən cümlə qur!'],
]);

it('says nothing for a number without a name', function (): void {
    expect(promptLine(Mechanic::Find, Unit::Number, 'number:11', 'numbers'))->toBeNull();
});

it('names a clip by the hash of its text in this voice, so another voice, setting or context makes another file', function (): void {
    $voice = voiceWith();
    $settings = app(ContentRepository::class)->voice()->settings;
    $other = static fn (array $changes): VoiceClips => voiceWith(['settings' => new VoiceSettingsData(...[
        'provider' => $settings->provider,
        'model' => $settings->model,
        'voiceId' => $settings->voiceId,
        'voiceName' => $settings->voiceName,
        'language' => $settings->language,
        'stability' => $settings->stability,
        'similarity' => $settings->similarity,
        'previousText' => $settings->previousText,
        'nextText' => $settings->nextText,
        ...$changes,
    ])]);
    $text = '[playful] Dovşan hansıdır?';

    expect($voice->hash($text))->toMatch('/^[0-9a-f]{16}$/')
        ->toBe(voiceWith()->hash($text))
        ->not->toBe($voice->hash('[curious] Dovşan hansıdır?'))
        ->not->toBe($other(['voiceId' => 'anotherVoice123'])->hash($text))
        ->not->toBe($other(['model' => 'eleven_v5'])->hash($text))
        ->not->toBe($other(['stability' => 0.5])->hash($text))
        // The unspoken context changes how the phrase sounds, so it is part of the name.
        ->not->toBe($other(['previousText' => 'Salam!'])->hash($text))
        // The name is a label: the id is the voice.
        ->toBe($other(['voiceName' => 'Nigar'])->hash($text));
});

it('gives a phrase its respelling, under the hash of what the voice is given', function (): void {
    $respelled = voiceWith(['respell' => ['Əla!' => 'Əəla!']]);
    $line = array_values(array_filter($respelled->praise(), static fn (VoiceLineData $line): bool => $line->phrase === 'Əla!'))[0];

    expect($line->text)->toBe('[cheerfully] Əəla!')
        ->and($line->hash)->toBe($respelled->hash('[cheerfully] Əəla!'));
});

it('has a URL only for a clip the list holds, versioned like every content URL', function (): void {
    $line = promptLine(Mechanic::Find, Unit::Picture, 'word:dovşan', 'animals');

    expect($line)->not->toBeNull()
        ->and(voiceWith()->url($line))->toBeNull()
        ->and(voiceWith(['clips' => [$line->hash => $line->text], 'version' => 3])->url($line))
        ->toBe(url("/content/voice/{$line->hash}.mp3").'?v=3');
});

it('lists every clip built-in content can ask for, once, and no other', function (): void {
    $content = app(ContentRepository::class);
    $needed = voiceWith()->needed($content);
    $groups = [];

    foreach ($needed as $hash => $line) {
        expect((string) $hash)->toBe($line->hash)
            ->and($line->text)->not->toContain('«')->not->toContain('{x}');
        $groups[$line->group][] = $line->phrase;
    }

    $counted = [];

    foreach ($content->packs() as $pack) {
        if ($pack->lists(Unit::Number)) {
            array_push($counted, ...array_map(static fn (WordData $word): string => $word->text, $pack->pictured()));
        }
    }

    expect($groups['find.letter'])->toHaveCount(Alphabet::count())
        ->and($groups['find.number'])->toHaveCount(10)
        ->and($groups['count.number'])->toHaveCount(count(array_unique($counted)))
        ->and($groups['praise'])->toBe(['Doğru cavab!', 'Afərin!', 'Əla!', 'Çox gözəl!', 'Möhtəşəm!', 'Mükəmməl!', 'Bravo!', 'Super!', 'Çox yaxşı!', 'Lap düz!', 'Belə davam et!'])
        ->and($groups['retry'])->toBe(['Bir də cəhd et!', 'Yenidən yoxla!', 'Tələsmə, yaxşı bax!', 'Sən bacararsan!', 'Narahat olma, bir də sına!', 'Yox, bu deyil, bir də bax!']);

    foreach (['pop.letter', 'pop.syllable', 'pop.word', 'pop.picture', 'pop.number', 'build_word.word', 'build_sentence.sentence'] as $instruction) {
        expect($groups[$instruction])->toHaveCount(1, $instruction);
    }

    // build_syllable builds syllables of two or more letters.
    foreach ($groups['build_syllable.syllable'] as $phrase) {
        expect(mb_strlen(explode(' ', $phrase)[0]))->toBeGreaterThanOrEqual(2, $phrase);
    }

    expect(count($groups['build_syllable.syllable']))->toBeLessThan(count($groups['find.syllable']));
});
