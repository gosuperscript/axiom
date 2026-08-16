<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

/**
 * A corpus written out by hand: the shape a test or a small fixture set
 * takes. A host with a real corpus streams it from storage instead.
 */
final readonly class ArrayBindingsCorpus implements BindingsCorpus
{
    /** @param array<string, array<string, mixed>> $cases */
    public function __construct(private array $cases) {}

    /** @return iterable<string, array<string, mixed>> */
    public function cases(): iterable
    {
        return $this->cases;
    }
}
