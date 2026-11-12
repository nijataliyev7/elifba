<?php

namespace App\Domain\Content\Data;

/**
 * `content/names.json`, the built-in name list (docs/architecture.md §3
 * "Voice", ADR 016): the first names the board can say, each voiced once
 * by `php artisan content:voice` into a clip of its own. Our own selection
 * (MIT), seeded from the Wikidata query its `sources` name; every name one
 * word of the alphabet, title-cased, in Azerbaijani order. Not the list a
 * teacher pastes (`Classroom\Data\NameListData`): nothing here is about
 * any student or child.
 */
final readonly class NameListData
{
    /**
     * @param  list<string>  $sources  https URLs the list was compiled from
     * @param  list<string>  $names  «Nihat», as the clip says it
     */
    public function __construct(
        public int $version,
        public array $sources,
        public array $names,
    ) {}

    /**
     * @param  array{version: int, sources: list<string>, names: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['version'], $data['sources'], $data['names']);
    }
}
