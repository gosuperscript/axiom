<?php

declare(strict_types=1);

namespace Superscript\Axiom\Execution;

use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

final readonly class Exited implements Event
{
    /** @param Result<Option<mixed>, Throwable> $result */
    public function __construct(
        public Node $node,
        public Result $result,
    ) {}
}
