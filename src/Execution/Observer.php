<?php

declare(strict_types=1);

namespace Superscript\Axiom\Execution;

interface Observer
{
    public function observe(Event $event): void;
}
