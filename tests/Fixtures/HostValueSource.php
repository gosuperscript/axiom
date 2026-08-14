<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fixtures;

use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;

/** A data-only host source used to exercise a source compiler's trust boundary. */
final readonly class HostValueSource implements Source
{
    public function __construct(
        public Type $claims,
        public mixed $value,
    ) {}

    public function children(): iterable
    {
        return [];
    }
}
