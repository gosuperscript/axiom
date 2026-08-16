<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use InvalidArgumentException;

/** A root symbol followed by zero or more structural record properties. */
final readonly class ReferencePath
{
    /** @var non-empty-list<string> */
    public array $segments;

    public function __construct(string $root, string ...$properties)
    {
        if ($root === '' || array_any($properties, static fn(string $property): bool => $property === '')) {
            throw new InvalidArgumentException('A reference path contains only non-empty names.');
        }

        if (str_contains($root, '.') || array_any($properties, static fn(string $property): bool => str_contains($property, '.'))) {
            throw new InvalidArgumentException('A reference path segment cannot contain a dot. Dots describe structural access between segments.');
        }

        /** @var non-empty-list<string> $segments */
        $segments = [$root, ...$properties];
        $this->segments = $segments;
    }

    public function root(): string
    {
        return $this->segments[0];
    }

    /** @return list<string> */
    public function properties(): array
    {
        /** @var list<string> $properties */
        $properties = array_slice($this->segments, 1);

        return $properties;
    }

    public function isRoot(): bool
    {
        return count($this->segments) === 1;
    }

    public function append(string $property): self
    {
        return new self($this->root(), ...[...$this->properties(), $property]);
    }

    /** A collision-free key used only to deduplicate paths. */
    public function key(): string
    {
        return serialize($this->segments);
    }

    public function describe(): string
    {
        return implode('.', $this->segments);
    }
}
