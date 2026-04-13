<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lexing;

interface Lexer
{
    /**
     * @return list<Token>
     */
    public function tokenize(string $file, string $source): array;
}
