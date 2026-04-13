<?php

declare(strict_types=1);

namespace Superscript\Axiom\Parsing;

use Superscript\Axiom\Lexing\Token;
use Superscript\Axiom\Runtime\ParsedProgram;

interface Parser
{
    /**
     * @param list<Token> $tokens
     */
    public function parse(array $tokens): ParsedProgram;
}
