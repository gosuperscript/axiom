<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

enum TokenType: string
{
    case Ident = 'Ident';
    case Number = 'Number';
    case String = 'String';
    case LeftParen = 'LeftParen';
    case RightParen = 'RightParen';
    case LeftBracket = 'LeftBracket';
    case RightBracket = 'RightBracket';
    case LeftBrace = 'LeftBrace';
    case RightBrace = 'RightBrace';
    case Comma = 'Comma';
    case Colon = 'Colon';
    case Dot = 'Dot';
    case Equals = 'Equals';
    case Arrow = 'Arrow';
    case Pipe = 'Pipe';
    case Operator = 'Operator';
    case Newline = 'Newline';
    case Eof = 'Eof';
}
