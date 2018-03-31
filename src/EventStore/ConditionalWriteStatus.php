<?php

declare(strict_types=1);

namespace Prooph\EventStore;

class ConditionalWriteStatus
{
    public const OPTIONS = [
        'Succeeded' => 0,
        'VersionMismatch' => 1,
        'StreamDeleted' => 2,
    ];

    public const Succeeded = 0;
    public const VersionMismatch = 1;
    public const StreamDeleted = 2;

    private $name;
    private $value;

    private function __construct(string $name)
    {
        $this->name = $name;
        $this->value = self::OPTIONS[$name];
    }

    public static function succeeded(): self
    {
        return new self('Succeeded');
    }

    public static function versionMismatch(): self
    {
        return new self('VersionMismatch');
    }

    public static function streamDeleted(): self
    {
        return new self('StreamDeleted');
    }

    public static function byName(string $value): self
    {
        if (! isset(self::OPTIONS[$value])) {
            throw new \InvalidArgumentException('Unknown enum name given');
        }

        return self::{$value}();
    }

    public static function byValue($value): self
    {
        foreach (self::OPTIONS as $name => $v) {
            if ($v === $value) {
                return self::{$name}();
            }
        }

        throw new \InvalidArgumentException('Unknown enum value given');
    }

    public function equals(ConditionalWriteStatus $other): bool
    {
        return get_class($this) === get_class($other) && $this->name === $other->name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value()
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
