<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lexing;

enum TokenType: string
{
    case Identifier = 'identifier';
    case Keyword = 'keyword';
    case Number = 'number';
    case String = 'string';
    case Symbol = 'symbol';
    case EndOfFile = 'eof';
}
