<?php

namespace App\Mail\Data;

use JsonSerializable;

final readonly class Address implements JsonSerializable
{
    public function __construct(
        public string $address,
        public ?string $name = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self($data['address'] ?? '', $data['name'] ?? null);
    }

    /** @return list<self> */
    public static function listFromArray(?array $rows): array
    {
        return array_values(array_map(self::fromArray(...), $rows ?? []));
    }

    public function jsonSerialize(): array
    {
        return ['address' => $this->address, 'name' => $this->name];
    }

    public function __toString(): string
    {
        return $this->name ? sprintf('%s <%s>', $this->name, $this->address) : $this->address;
    }
}
