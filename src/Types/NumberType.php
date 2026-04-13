<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

final readonly class NumberType implements Type
{
    public function describe(): string
    {
        return 'number';
    }
}
