<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Lexer;

use RuntimeException;
use Superscript\Axiom\Dsl\OperatorRegistry;

final class Lexer
{
    public function __construct(
        private OperatorRegistry $operatorRegistry,
    ) {}

    /**
     * @return list<Token>
     */
    public function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $pos = 0;
        $line = 1;
        $col = 1;

        while ($pos < $length) {
            $char = $source[$pos];

            if ($char === ' ' || $char === "\t" || $char === "\r") {
                $pos++;
                $col++;

                continue;
            }

            if ($char === "\n") {
                $pos++;
                $line++;
                $col = 1;

                continue;
            }

            if ($char === '/' && $pos + 1 < $length && $source[$pos + 1] === '/') {
                while ($pos < $length && $source[$pos] !== "\n") {
                    $pos++;
                }
                $col = 1;

                continue;
            }

            if ($char >= '0' && $char <= '9') {
                $startCol = $col;
                $startPos = $pos;
                $hasDecimal = false;

                while ($pos < $length && (($source[$pos] >= '0' && $source[$pos] <= '9') || ($source[$pos] === '.' && !$hasDecimal && $pos + 1 < $length && $source[$pos + 1] >= '0' && $source[$pos + 1] <= '9'))) {
                    if ($source[$pos] === '.') {
                        $hasDecimal = true;
                    }
                    $pos++;
                    $col++;
                }

                if ($pos < $length && $source[$pos] === '%') {
                    $pos++;
                    $col++;
                }

                $tokens[] = new Token(TokenType::Number, substr($source, $startPos, $pos - $startPos), $line, $startCol);

                continue;
            }

            // Strings
            if ($char === '"') {
                $startCol = $col;
                $str = '';
                $pos++;
                $col++;

                while ($pos < $length) {
                    if ($source[$pos] === '\\' && $pos + 1 < $length) {
                        $str .= $source[$pos] . $source[$pos + 1];
                        $pos += 2;
                        $col += 2;
                    } elseif ($source[$pos] === '"') {
                        $pos++;
                        $col++;

                        break;
                    } elseif ($source[$pos] === "\n") {
                        throw new RuntimeException("Unterminated string at line {$line}, col {$startCol}");
                    } else {
                        $str .= $source[$pos];
                        $pos++;
                        $col++;
                    }
                }

                if ($pos === $length && $source[$pos - 1] !== '"') {
                    throw new RuntimeException("Unterminated string at line {$line}, col {$startCol}");
                }

                $tokens[] = new Token(TokenType::String, $str, $line, $startCol);

                continue;
            }

            if ($this->isIdentStart($char)) {
                $startCol = $col;
                $startPos = $pos;

                while ($pos < $length && $this->isIdentPart($source[$pos])) {
                    $pos++;
                    $col++;
                }

                $ident = substr($source, $startPos, $pos - $startPos);

                $type = match ($ident) {
                    'true' => TokenType::True,
                    'false' => TokenType::False,
                    'null' => TokenType::Null,
                    default => TokenType::Ident,
                };

                $tokens[] = new Token($type, $ident, $line, $startCol);

                continue;
            }

            $startCol = $col;

            $structuralType = match ($char) {
                '(' => TokenType::LeftParen,
                ')' => TokenType::RightParen,
                '[' => TokenType::LeftBracket,
                ']' => TokenType::RightBracket,
                '{' => TokenType::LeftBrace,
                '}' => TokenType::RightBrace,
                ',' => TokenType::Comma,
                ':' => TokenType::Colon,
                default => null,
            };

            if ($structuralType !== null) {
                $tokens[] = new Token($structuralType, $char, $line, $startCol);
                $pos++;
                $col++;

                continue;
            }

            if ($char === '.') {
                if ($pos + 1 < $length && $source[$pos + 1] === '.') {
                    $tokens[] = new Token(TokenType::DotDot, '..', $line, $startCol);
                    $pos += 2;
                    $col += 2;
                } else {
                    $tokens[] = new Token(TokenType::Dot, '.', $line, $startCol);
                    $pos++;
                    $col++;
                }

                continue;
            }

            if ($char === '=') {
                if ($pos + 1 < $length && $source[$pos + 1] === '>') {
                    $tokens[] = new Token(TokenType::Arrow, '=>', $line, $startCol);
                    $pos += 2;
                    $col += 2;

                    continue;
                }

                if ($pos + 1 < $length && $source[$pos + 1] === '=') {
                    if ($pos + 2 < $length && $source[$pos + 2] === '=') {
                        $tokens[] = new Token(TokenType::Operator, '===', $line, $startCol);
                        $pos += 3;
                        $col += 3;

                        continue;
                    }

                    $tokens[] = new Token(TokenType::Operator, '==', $line, $startCol);
                    $pos += 2;
                    $col += 2;

                    continue;
                }

                if ($this->operatorRegistry->isOperator('=')) {
                    $tokens[] = new Token(TokenType::Operator, '=', $line, $startCol);
                    $pos++;
                    $col++;

                    continue;
                }

                $tokens[] = new Token(TokenType::Assign, '=', $line, $startCol);
                $pos++;
                $col++;

                continue;
            }

            if ($char === '|') {
                if ($pos + 1 < $length && $source[$pos + 1] === '>') {
                    $tokens[] = new Token(TokenType::Operator, '|>', $line, $startCol);
                    $pos += 2;
                    $col += 2;
                } elseif ($pos + 1 < $length && $source[$pos + 1] === '|') {
                    $tokens[] = new Token(TokenType::Operator, '||', $line, $startCol);
                    $pos += 2;
                    $col += 2;
                } else {
                    $tokens[] = new Token(TokenType::Pipe, '|', $line, $startCol);
                    $pos++;
                    $col++;
                }

                continue;
            }

            $token = $this->matchOperator($source, $pos, $length, $line, $startCol);
            if ($token !== null) {
                $tokens[] = $token;
                $tokenLen = strlen($token->value);
                $pos += $tokenLen;
                $col += $tokenLen;

                continue;
            }

            throw new RuntimeException("Unexpected character '{$char}' at line {$line}, col {$col}");
        }

        $tokens[] = new Token(TokenType::Eof, '', $line, $col);

        return $tokens;
    }

    private function matchOperator(string $source, int $pos, int $length, int $line, int $col): ?Token
    {
        for ($len = 3; $len >= 1; $len--) {
            if ($pos + $len > $length) {
                continue;
            }

            $candidate = substr($source, $pos, $len);
            $entry = $this->operatorRegistry->get($candidate);
            if ($entry !== null && !$entry->isKeyword) {
                return new Token(TokenType::Operator, $candidate, $line, $col);
            }
        }

        return null;
    }

    private function isIdentStart(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || $char === '_';
    }

    private function isIdentPart(string $char): bool
    {
        return $this->isIdentStart($char) || ($char >= '0' && $char <= '9');
    }
}
