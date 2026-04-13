<?php

declare(strict_types=1);

namespace Superscript\Axiom\Values;

interface Value
{
    public function unwrap(): mixed;
}
