<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

final class FunctionRegistry
{
    /** @var array<string, FunctionEntry> */
    private array $functions = [];

    /**
     * @param list<FunctionParam> $params
     * @param \Closure(mixed ...): mixed $factory
     */
    public function register(string $name, array $params, \Closure $factory): void
    {
        $this->functions[$name] = new FunctionEntry($name, $params, $factory);
    }

    public function resolve(string $name): ?FunctionEntry
    {
        return $this->functions[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->functions[$name]);
    }

    /**
     * @return array<string, FunctionEntry>
     */
    public function all(): array
    {
        return $this->functions;
    }
}
