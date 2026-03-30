<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

use RuntimeException;
use Superscript\Axiom\Types\Type;

final class TypeRegistry
{
    /** @var array<string, \Closure(mixed ...): Type> */
    private array $factories = [];

    /**
     * @param \Closure(mixed ...): Type $factory
     */
    public function register(string $keyword, \Closure $factory): void
    {
        $this->factories[$keyword] = $factory;
    }

    /**
     * @return Type
     */
    public function resolve(string $keyword, mixed ...$args): Type
    {
        if (!isset($this->factories[$keyword])) {
            throw new RuntimeException("Unknown type '{$keyword}'.");
        }

        return ($this->factories[$keyword])(...$args);
    }

    public function has(string $keyword): bool
    {
        return isset($this->factories[$keyword]);
    }

    /**
     * @return array<string, \Closure(mixed ...): Type>
     */
    public function all(): array
    {
        return $this->factories;
    }
}
