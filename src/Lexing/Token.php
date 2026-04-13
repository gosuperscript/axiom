<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lexing;

use Superscript\Axiom\Diagnostics\SourceLocation;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public SourceLocation $location,
    ) {}
}
