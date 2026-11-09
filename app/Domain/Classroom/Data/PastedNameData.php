<?php

namespace App\Domain\Classroom\Data;

use App\Domain\Classroom\Enums\PastedNameFlag;

/**
 * One line of the paste preview. `text` is the line as understood (numbering,
 * bullets and number columns removed) and may hold a surname: it goes back to
 * the teacher who pasted it and is never stored or logged.
 */
final readonly class PastedNameData
{
    /**
     * @param  int  $line  1-based line number in the pasted text
     * @param  string|null  $display_name  the suggestion; null when the line is invalid
     * @param  list<PastedNameFlag>  $flags
     */
    public function __construct(
        public int $line,
        public string $text,
        public ?string $display_name,
        public array $flags,
    ) {}

    public function has(PastedNameFlag $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /**
     * Whether the suggestion can be added as it is (the preview pre-selects it).
     */
    public function addable(): bool
    {
        foreach ($this->flags as $flag) {
            if ($flag->blocksAdding()) {
                return false;
            }
        }

        return $this->display_name !== null;
    }

    /**
     * @return array{line: int, text: string, display_name: string|null, flags: list<string>, addable: bool}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'text' => $this->text,
            'display_name' => $this->display_name,
            'flags' => array_map(static fn (PastedNameFlag $flag): string => $flag->value, $this->flags),
            'addable' => $this->addable(),
        ];
    }
}
