<?php

declare(strict_types=1);

namespace Superscript\Axiom\Execution;

use Throwable;

final readonly class Threw implements Event
{
    public function __construct(
        public Node $node,
        public Throwable $exception,
    ) {}
}
