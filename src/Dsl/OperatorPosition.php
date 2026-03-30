<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

enum OperatorPosition: string
{
    case Prefix = 'prefix';
    case Infix = 'infix';
    case Postfix = 'postfix';
}
