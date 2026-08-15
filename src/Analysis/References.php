<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

/**
 * @internal The symbols something read, in the order it first met them.
 *
 * A name already recorded holds its place, so recording is idempotent and
 * the order is the order of first read — which is what a caller reading a
 * reference list expects to see, and what makes two attempts over the same
 * expression report the same list.
 *
 * Two things accumulate reads and they keep their own instances of this,
 * because their lifetimes differ: a {@see CompilationRecorder} belongs to one
 * node of one attempt and dies with it, while an {@see ErrorRecovery}
 * accumulates across every attempt a diagnosis makes, including the reads of
 * attempts whose nodes were thrown away. Only how the names are held is
 * shared.
 */
final class References
{
    /** @var array<string, string> */
    private array $names = [];

    /** @param list<string> $names */
    public function record(array $names): void
    {
        foreach ($names as $name) {
            $this->names[$name] = $name;
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_values($this->names);
    }
}
