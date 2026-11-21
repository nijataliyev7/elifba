<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomVoiceStanding;

/**
 * A player in a room's state (docs/architecture.md §12 "The state",
 * "Adults' privacy"; zod `RoomPlayer`): the row's id — the only id of a
 * player a device gets — the account's name as it is now, null for a row
 * without an account («Oyunçu»), whether the player is in the room now and
 * whether it is the host. Never an account id or an address. `voice` is
 * the player's standing in the room's live voice (C3, ADR 031) — exactly
 * for the host's row and the present players' while the room's voice is
 * on, null otherwise; never whether they are in it, their microphone or
 * whether they speak. `ready` is whether the player's «Hazıram» between
 * games counts (ADR 035 decision 6, `RoomReady::counts()`): false for the
 * host's row, an absent player and in a closed room.
 *
 * @phpstan-type RoomPlayerArray array{id: string, name: string|null, present: bool, host: bool, voice: string|null, ready: bool}
 */
final readonly class RoomPlayerData
{
    public function __construct(
        public string $id,
        public ?string $name,
        public bool $present,
        public bool $host,
        public ?RoomVoiceStanding $voice = null,
        public bool $ready = false,
    ) {}

    /**
     * @param  RoomPlayerArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['name'], $data['present'], $data['host'], $data['voice'] === null ? null : RoomVoiceStanding::from($data['voice']), $data['ready']);
    }

    /**
     * @return RoomPlayerArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'present' => $this->present,
            'host' => $this->host,
            'voice' => $this->voice?->value,
            'ready' => $this->ready,
        ];
    }
}
