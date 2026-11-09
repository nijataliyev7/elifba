<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\License;

/**
 * Who made a set of asset files and under which license
 * (`content/credits.json`, docs/architecture.md §8). `files` are paths from
 * the repository root (`content/assets/fruit/alma.svg`,
 * `resources/fonts/inter/*`); `*` matches within one folder and a trailing
 * `/**` matches everything below a folder. `changes` says what Elifba changed
 * in the files, if anything (§8: a changed share-alike file must say so).
 */
final readonly class CreditData
{
    /**
     * @param  list<string>  $files
     */
    public function __construct(
        public array $files,
        public string $title,
        public string $author,
        public string $source,
        public License $license,
        public ?string $changes = null,
    ) {}

    /**
     * @param  array{files: list<string>, title: string, author: string, source: string, license: string, changes?: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['files'], $data['title'], $data['author'], $data['source'], License::from($data['license']), $data['changes'] ?? null);
    }

    /**
     * The entry as `/credits` shows it.
     *
     * @return array{title: string, author: string, source: string, license: array{id: string, title: string, url: string}, files: list<string>, changes: string|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'author' => $this->author,
            'source' => $this->source,
            'license' => ['id' => $this->license->value, 'title' => $this->license->title(), 'url' => $this->license->url()],
            'files' => $this->files,
            'changes' => $this->changes,
        ];
    }

    public function covers(string $path): bool
    {
        foreach ($this->files as $pattern) {
            if (self::matches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $pattern, string $path): bool
    {
        if (str_ends_with($pattern, '/**')) {
            return str_starts_with($path, substr($pattern, 0, -2));
        }

        return fnmatch($pattern, $path, FNM_PATHNAME);
    }
}
