<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Lexer;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $line,
        public int $col,
    ) {}
}
