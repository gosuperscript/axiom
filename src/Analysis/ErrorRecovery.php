<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

/**
 * @internal The state one {@see Diagnosis} carries across its compilation
 * attempts: which nodes are known broken, which definitions are unusable,
 * and every symbol the attempts touched.
 *
 * A compiler handed one of these treats a quarantined path as already
 * failed — it compiles to {@see \Superscript\Axiom\Types\ErrorType} without
 * being visited — and a poisoned definition name the same way. Both are how
 * the next attempt gets past a failure the previous one stopped at.
 */
final class ErrorRecovery
{
    /** @var array<string, true> */
    private array $quarantined = [];

    /** @var array<string, true> */
    private array $poisoned = [];

    /** @var array<string, string> */
    private array $references = [];

    /** @param list<string> $poisoned Definition names that lie on a cycle. */
    public function __construct(array $poisoned = [])
    {
        foreach ($poisoned as $key) {
            $this->poisoned[$key] = true;
        }
    }

    public function isQuarantined(string $path): bool
    {
        return $this->quarantined[$path] ?? false;
    }

    public function quarantine(string $path): void
    {
        $this->quarantined[$path] = true;
    }

    /**
     * Does a quarantined node sit below this one? A refusal made at such a
     * node is a consequence of the failure already reported below it, not a
     * second fault.
     *
     * Paths nest as prefixes, and this is only ever asked about a node that
     * has just refused — which a quarantined node cannot do — so a prefix
     * match is a node strictly below.
     */
    public function quarantinedBelow(string $path): bool
    {
        return array_any(
            array_keys($this->quarantined),
            static fn(string $quarantined) => str_starts_with($quarantined, $path),
        );
    }

    /** A definition on a cycle: unusable, and already reported as a property of the graph. */
    public function isPoisoned(string $key): bool
    {
        return $this->poisoned[$key] ?? false;
    }

    public function reference(string $key): void
    {
        $this->references[$key] = $key;
    }

    /** @param list<string> $keys */
    public function record(array $keys): void
    {
        foreach ($keys as $key) {
            $this->references[$key] = $key;
        }
    }

    /** @return list<string> Every symbol the attempts resolved or tried to, in first-read order. */
    public function references(): array
    {
        return array_values($this->references);
    }
}
