<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Lexer;

enum TokenType
{
    case Number;
    case String;
    case True;
    case False;
    case Null;
    case Ident;
    case LeftParen;
    case RightParen;
    case LeftBracket;
    case RightBracket;
    case LeftBrace;
    case RightBrace;
    case Comma;
    case Colon;
    case Dot;
    case DotDot;
    case Arrow;
    case Assign;
    case Pipe;
    case Operator;
    case Eof;
}
