<?php

declare(strict_types=1);

namespace Superscript\Axiom\Execution;

final readonly class Entered implements Event
{
    public function __construct(public Node $node) {}
}
