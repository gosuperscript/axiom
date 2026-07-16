<?php

declare(strict_types=1);

namespace Superscript\Axiom\Execution;

use Superscript\Axiom\Types\Type;

/**
 * The source-level identity of one compiled evaluation boundary.
 */
final readonly class Node
{
    public function __construct(
        public string $sourceType,
        public Type $returns,
    ) {}
}
