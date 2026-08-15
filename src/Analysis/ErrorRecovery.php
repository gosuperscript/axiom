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

    /** @var array<string, true> */
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

    /** A definition on a cycle: unusable, and already reported as a property of the graph. */
    public function isPoisoned(string $key): bool
    {
        return $this->poisoned[$key] ?? false;
    }

    /**
     * Keep what one attempt read. A name already kept holds its place, so
     * the order is the order the first attempt to read a name met it.
     *
     * @param list<string> $keys
     */
    public function record(array $keys): void
    {
        foreach ($keys as $key) {
            $this->references[$key] = true;
        }
    }

    /** @return list<string> Every symbol the attempts resolved or tried to, in first-read order. */
    public function references(): array
    {
        return array_keys($this->references);
    }
}
