<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fixtures;

/**
 * A deliberately small range value object standing in for a package's
 * interval or band type. Its bounds may be written as int or float, so
 * 1..5 and 1.0..5 are two objects denoting the same band.
 */
final readonly class Band
{
    public function __construct(
        public int|float $lower,
        public int|float $upper,
    ) {}
}
