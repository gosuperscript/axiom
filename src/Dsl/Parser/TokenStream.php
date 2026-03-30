<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Parser;

use Superscript\Axiom\Dsl\Lexer\Token;
use Superscript\Axiom\Dsl\Lexer\TokenType;

final class TokenStream
{
    private int $pos = 0;

    /**
     * @param list<Token> $tokens
     */
    public function __construct(
        private array $tokens,
    ) {}

    public function current(): Token
    {
        return $this->tokens[$this->pos];
    }

    public function peek(int $offset = 1): ?Token
    {
        $index = $this->pos + $offset;

        return $this->tokens[$index] ?? null;
    }

    public function advance(): Token
    {
        $token = $this->tokens[$this->pos];
        $this->pos++;

        return $token;
    }

    /** @phpstan-impure */
    public function check(TokenType $type): bool
    {
        return $this->current()->type === $type;
    }

    public function checkValue(string $value): bool
    {
        return $this->current()->value === $value;
    }

    public function expect(TokenType $type): Token
    {
        $token = $this->current();
        if ($token->type !== $type) {
            throw new \RuntimeException(
                "Expected {$type->name}, got {$token->type->name} ('{$token->value}') at line {$token->line}, col {$token->col}",
            );
        }
        $this->advance();

        return $token;
    }

    public function isAtEnd(): bool
    {
        return $this->current()->type === TokenType::Eof;
    }
}
