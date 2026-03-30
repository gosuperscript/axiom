<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

use Superscript\Axiom\Dsl\Ast\Expressions\ExprNode;
use Superscript\Axiom\Dsl\Compiler\Compiler;
use Superscript\Axiom\Dsl\Lexer\Token;
use Superscript\Axiom\Dsl\Parser\Parser;
use Superscript\Axiom\Dsl\Parser\TokenStream;
use Superscript\Axiom\Dsl\PrettyPrinter\PrettyPrinter;
use Superscript\Axiom\Source;

interface DslLiteralExtension
{
    public function canParse(Token $current, TokenStream $stream): bool;

    public function parse(Parser $parser): ExprNode;

    public function compile(ExprNode $node, Compiler $compiler): Source;

    public function handles(ExprNode $node): bool;

    public function prettyPrint(ExprNode $node, PrettyPrinter $printer, int $parentPrecedence): string;
}
