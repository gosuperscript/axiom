<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

/**
 * @internal The state one {@see Diagnosis} carries across its compilation
 * attempts: which nodes are known broken, and every symbol the attempts
 * touched.
 *
 * A compiler handed one of these treats a quarantined path as already
 * failed — it compiles to a failed node without being visited — which is how
 * the next attempt gets past a failure the previous one stopped at.
 */
final class ErrorRecovery
{
    /** @var array<string, true> */
    private array $quarantined = [];

    private readonly References $references;

    public function __construct()
    {
        $this->references = new References();
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
     * Keep what one attempt read, accumulating across every attempt a
     * diagnosis makes — including the reads of attempts whose nodes were
     * thrown away. That lifetime is why the set is this object's own and not
     * shared with the {@see CompilationRecorder} that collected the names:
     * a recorder belongs to one node of one attempt and dies with it.
     *
     * @param list<\Superscript\Axiom\ReferencePath> $paths
     */
    public function record(array $paths): void
    {
        $this->references->record($paths);
    }

    /** @return list<\Superscript\Axiom\ReferencePath> Every path the attempts resolved or tried to, in first-read order. */
    public function references(): array
    {
        return $this->references->all();
    }
}
