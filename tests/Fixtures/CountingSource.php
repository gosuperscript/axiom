<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fixtures;

use Superscript\Axiom\Source;

/** A serializable description; its counter deliberately lives elsewhere. */
final readonly class CountingSource implements Source
{
    public function __construct(public int|float $value) {}

    public function children(): iterable
    {
        return [];
    }
}
