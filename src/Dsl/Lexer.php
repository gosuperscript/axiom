<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

use RuntimeException;

final class Lexer
{
    private string $source;

    private int $pos = 0;

    private int $line = 1;

    private int $col = 1;

    /** @var list<string> */
    private array $keywordOperators;

    /**
     * @param list<string> $keywordOperators
     */
    public function __construct(
        private readonly OperatorRegistry $operators,
        array $keywordOperators = [],
    ) {
        $this->keywordOperators = $keywordOperators;
    }

    /**
     * @return list<Token>
     */
    public function tokenize(string $source): array
    {
        $this->source = $source;
        $this->pos = 0;
        $this->line = 1;
        $this->col = 1;

        $tokens = [];

        while ($this->pos < strlen($this->source)) {
            $this->skipWhitespaceAndComments();

            if ($this->pos >= strlen($this->source)) {
                /** @infection-ignore-all */
                break;
            }

            $char = $this->source[$this->pos];

            if ($char === "\n") {
                $tokens[] = new Token(TokenType::Newline, "\n", $this->line, $this->col);
                $this->advance();
                continue;
            }

            if ($char === '"' || $char === "'") {
                $tokens[] = $this->readString($char);
                continue;
            }

            /** @infection-ignore-all */
            if (ctype_digit($char) || ($char === '-' && $this->pos + 1 < strlen($this->source) && ctype_digit($this->source[$this->pos + 1]) && $this->shouldTreatMinusAsNegative($tokens))) {
                $tokens[] = $this->readNumber();
                continue;
            }

            if ($char === '_' && ($this->pos + 1 >= strlen($this->source) || !$this->isIdentChar($this->source[$this->pos + 1]))) {
                $tokens[] = new Token(TokenType::Ident, '_', $this->line, $this->col);
                $this->advance();
                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                $tokens[] = $this->readIdentOrKeyword();
                continue;
            }

            $token = $this->readSymbol();
            if ($token !== null) {
                $tokens[] = $token;
                continue;
            }

            throw new RuntimeException("Unexpected character '{$char}' at line {$this->line}, col {$this->col}");
        }

        $tokens[] = new Token(TokenType::Eof, '', $this->line, $this->col);

        return $tokens;
    }

    /**
     * @param list<Token> $tokens
     */
    private function shouldTreatMinusAsNegative(array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }

        $last = $tokens[count($tokens) - 1];

        return match ($last->type) {
            TokenType::Operator, TokenType::LeftParen, TokenType::LeftBracket,
            TokenType::Comma, TokenType::Colon, TokenType::Equals, TokenType::Arrow => true,
            default => false,
        };
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->pos < strlen($this->source)) {
            $char = $this->source[$this->pos];

            if ($char === ' ' || $char === "\t" || $char === "\r") {
                $this->advance();
                continue;
            }

            if ($char === '/' && $this->pos + 1 < strlen($this->source) && $this->source[$this->pos + 1] === '/') {
                while ($this->pos < strlen($this->source) && $this->source[$this->pos] !== "\n") {
                    $this->advance();
                }
                /** @infection-ignore-all */
                continue;
            }

            break;
        }
    }

    private function readString(string $quote): Token
    {
        $startLine = $this->line;
        $startCol = $this->col;
        $this->advance(); // skip opening quote

        $value = '';
        while ($this->pos < strlen($this->source) && $this->source[$this->pos] !== $quote) {
            if ($this->source[$this->pos] === '\\' && $this->pos + 1 < strlen($this->source)) {
                $this->advance();
                $value .= match ($this->source[$this->pos]) {
                    'n' => "\n",
                    't' => "\t",
                    '\\' => '\\',
                    $quote => $quote,
                    default => '\\' . $this->source[$this->pos],
                };
                $this->advance();
                continue;
            }
            $value .= $this->source[$this->pos];
            $this->advance();
        }

        if ($this->pos >= strlen($this->source)) {
            throw new RuntimeException("Unterminated string at line {$startLine}, col {$startCol}");
        }

        $this->advance(); // skip closing quote

        return new Token(TokenType::String, $value, $startLine, $startCol);
    }

    private function readNumber(): Token
    {
        $startLine = $this->line;
        $startCol = $this->col;
        $value = '';

        if ($this->source[$this->pos] === '-') {
            $value .= '-';
            $this->advance();
        }

        while ($this->pos < strlen($this->source) && ctype_digit($this->source[$this->pos])) {
            $value .= $this->source[$this->pos];
            $this->advance();
        }

        if ($this->pos < strlen($this->source) && $this->source[$this->pos] === '.' && $this->pos + 1 < strlen($this->source) && ctype_digit($this->source[$this->pos + 1])) {
            $value .= '.';
            $this->advance();
            while ($this->pos < strlen($this->source) && ctype_digit($this->source[$this->pos])) {
                $value .= $this->source[$this->pos];
                $this->advance();
            }
        }

        return new Token(TokenType::Number, $value, $startLine, $startCol);
    }

    private function readIdentOrKeyword(): Token
    {
        $startLine = $this->line;
        $startCol = $this->col;
        $value = '';

        while ($this->pos < strlen($this->source) && $this->isIdentChar($this->source[$this->pos])) {
            $value .= $this->source[$this->pos];
            $this->advance();
        }

        if ($value === 'true' || $value === 'false' || $value === 'null') {
            return new Token(TokenType::Ident, $value, $startLine, $startCol);
        }

        if (in_array($value, $this->keywordOperators, true) || $this->operators->isKeywordOperator($value)) {
            return new Token(TokenType::Operator, $value, $startLine, $startCol);
        }

        return new Token(TokenType::Ident, $value, $startLine, $startCol);
    }

    private function readSymbol(): ?Token
    {
        $startLine = $this->line;
        $startCol = $this->col;
        $char = $this->source[$this->pos];
        $next = $this->pos + 1 < strlen($this->source) ? $this->source[$this->pos + 1] : '';

        // Two-character tokens
        if ($char === '=' && $next === '>') {
            $this->advance();
            $this->advance();

            return new Token(TokenType::Arrow, '=>', $startLine, $startCol);
        }

        if ($char === '|' && $next === '>') {
            $this->advance();
            $this->advance();

            return new Token(TokenType::Pipe, '|>', $startLine, $startCol);
        }

        // Try multi-char operators first (===, !==, ==, !=, <=, >=, &&, ||)
        /** @infection-ignore-all */
        $threeChar = $char . $next . ($this->pos + 2 < strlen($this->source) ? $this->source[$this->pos + 2] : '');
        if ($this->operators->isOperator($threeChar)) {
            $this->advance();
            $this->advance();
            $this->advance();

            return new Token(TokenType::Operator, $threeChar, $startLine, $startCol);
        }

        $twoChar = $char . $next;
        if ($this->operators->isOperator($twoChar)) {
            $this->advance();
            $this->advance();

            return new Token(TokenType::Operator, $twoChar, $startLine, $startCol);
        }

        // Single-character tokens
        $singleTokenMap = [
            '(' => TokenType::LeftParen,
            ')' => TokenType::RightParen,
            '[' => TokenType::LeftBracket,
            ']' => TokenType::RightBracket,
            '{' => TokenType::LeftBrace,
            '}' => TokenType::RightBrace,
            ',' => TokenType::Comma,
            ':' => TokenType::Colon,
            '.' => TokenType::Dot,
            '=' => TokenType::Equals,
        ];

        if (isset($singleTokenMap[$char])) {
            $this->advance();

            return new Token($singleTokenMap[$char], $char, $startLine, $startCol);
        }

        if ($this->operators->isOperator($char)) {
            $this->advance();

            return new Token(TokenType::Operator, $char, $startLine, $startCol);
        }

        return null;
    }

    private function isIdentChar(string $char): bool
    {
        return ctype_alnum($char) || $char === '_';
    }

    private function advance(): void
    {
        if ($this->pos < strlen($this->source) && $this->source[$this->pos] === "\n") {
            $this->line++;
            $this->col = 1;
        } else {
            $this->col++;
        }
        $this->pos++;
    }
}
