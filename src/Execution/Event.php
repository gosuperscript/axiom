<?php

declare(strict_types=1);

namespace Superscript\Axiom\Execution;

interface Event
{
    public Node $node { get; }
}
