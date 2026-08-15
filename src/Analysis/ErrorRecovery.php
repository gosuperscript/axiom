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

    private readonly References $references;

    /** @param list<string> $poisoned Definition names that lie on a cycle. */
    public function __construct(array $poisoned = [])
    {
        $this->references = new References();

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
     * Keep what one attempt read, accumulating across every attempt a
     * diagnosis makes — including the reads of attempts whose nodes were
     * thrown away. That lifetime is why the set is this object's own and not
     * shared with the {@see CompilationRecorder} that collected the names:
     * a recorder belongs to one node of one attempt and dies with it.
     *
     * @param list<string> $keys
     */
    public function record(array $keys): void
    {
        $this->references->record($keys);
    }

    /** @return list<string> Every symbol the attempts resolved or tried to, in first-read order. */
    public function references(): array
    {
        return $this->references->all();
    }
}
