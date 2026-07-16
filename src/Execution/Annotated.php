<?php

declare(strict_types=1);

namespace Superscript\Axiom\Execution;

final readonly class Annotated implements Event
{
    public function __construct(
        public Node $node,
        public string $key,
        public mixed $value,
    ) {}
}
