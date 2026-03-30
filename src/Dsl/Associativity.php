<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

enum Associativity: string
{
    case Left = 'left';
    case Right = 'right';
}
