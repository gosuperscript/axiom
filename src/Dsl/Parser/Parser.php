<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Parser;

use RuntimeException;
use Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\DictLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IdentifierNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IndexExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\InfixExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ListLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MemberExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\UnaryExpressionNode;
use Superscript\Axiom\Dsl\Ast\Node;
use Superscript\Axiom\Dsl\Ast\ProgramNode;
use Superscript\Axiom\Dsl\Ast\Statements\NamespaceDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\StatementNode;
use Superscript\Axiom\Dsl\Ast\Statements\SymbolDeclarationNode;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;
use Superscript\Axiom\Dsl\DslLiteralExtension;
use Superscript\Axiom\Dsl\Lexer\Token;
use Superscript\Axiom\Dsl\Lexer\TokenType;
use Superscript\Axiom\Dsl\OperatorPosition;
use Superscript\Axiom\Dsl\OperatorRegistry;
use Superscript\Axiom\Dsl\Associativity;

final class Parser
{
    private TokenStream $stream;

    /**
     * @param list<DslLiteralExtension> $literalExtensions
     */
    public function __construct(
        private OperatorRegistry $operatorRegistry,
        private array $literalExtensions = [],
    ) {}

    /**
     * @param list<Token> $tokens
     */
    public function parse(array $tokens): ProgramNode
    {
        $this->stream = new TokenStream($tokens);

        /** @var list<Node> $body */
        $body = [];

        while (!$this->stream->isAtEnd()) {
            $body[] = $this->parseStatement();
        }

        return new ProgramNode($body);
    }

    public function getStream(): TokenStream
    {
        return $this->stream;
    }

    public function parseExpression(): ExprNode
    {
        return $this->parseInfixExpression();
    }

    private function parseStatement(): StatementNode
    {
        $current = $this->stream->current();

        // namespace keyword
        if ($current->type === TokenType::Ident && $current->value === 'namespace') {
            return $this->parseNamespaceDeclaration();
        }

        return $this->parseSymbolDeclaration();
    }

    private function parseSymbolDeclaration(): SymbolDeclarationNode
    {
        $nameToken = $this->stream->expect(TokenType::Ident);
        $this->stream->expect(TokenType::Colon);
        $type = $this->parseTypeAnnotation();

        // Expect = (as Assign token or as Operator '=')
        $current = $this->stream->current();
        if ($current->type === TokenType::Assign) {
            $this->stream->advance();
        } elseif ($current->type === TokenType::Operator && $current->value === '=') {
            $this->stream->advance();
        } else {
            throw new RuntimeException(
                "Expected '=', got {$current->type->name} ('{$current->value}') at line {$current->line}, col {$current->col}",
            );
        }

        $expression = $this->parseExpression();

        return new SymbolDeclarationNode(
            name: $nameToken->value,
            type: $type,
            expression: $expression,
        );
    }

    private function parseNamespaceDeclaration(): NamespaceDeclarationNode
    {
        $this->stream->advance(); // consume 'namespace'
        $nameToken = $this->stream->expect(TokenType::Ident);
        $this->stream->expect(TokenType::LeftBrace);

        /** @var list<Node> $body */
        $body = [];

        while (!$this->stream->check(TokenType::RightBrace) && !$this->stream->isAtEnd()) {
            $body[] = $this->parseStatement();
        }

        $this->stream->expect(TokenType::RightBrace);

        return new NamespaceDeclarationNode(
            name: $nameToken->value,
            body: $body,
        );
    }

    private function parseInfixExpression(int $minPrecedence = 0): ExprNode
    {
        $left = $this->parseUnary();

        while (true) {
            $current = $this->stream->current();
            $opSymbol = $current->value;

            // Handle 'not in' desugaring
            if ($opSymbol === 'not' && $this->stream->peek()?->value === 'in') {
                $inOp = $this->operatorRegistry->get('in');
                if ($inOp === null || $inOp->precedence < $minPrecedence) {
                    break;
                }
                $this->stream->advance(); // not
                $this->stream->advance(); // in
                $right = $this->parseInfixExpression($inOp->precedence + 1);
                $left = new UnaryExpressionNode(
                    'not',
                    new InfixExpressionNode($left, 'in', $right),
                );

                continue;
            }

            // Get operator from registry
            $isOp = ($current->type === TokenType::Operator)
                || ($current->type === TokenType::Ident && $this->operatorRegistry->isKeywordOperator($opSymbol));

            if (!$isOp) {
                break;
            }

            $op = $this->operatorRegistry->get($opSymbol);
            if ($op === null || $op->position !== OperatorPosition::Infix || $op->precedence < $minPrecedence) {
                break;
            }

            $this->stream->advance();

            $nextMin = $op->associativity === Associativity::Left
                ? $op->precedence + 1
                : $op->precedence;

            $right = $this->parseInfixExpression($nextMin);
            $left = new InfixExpressionNode($left, $op->symbol, $right);
        }

        return $left;
    }

    private function parseUnary(): ExprNode
    {
        $current = $this->stream->current();

        // Prefix ! operator
        if ($current->type === TokenType::Operator && $current->value === '!') {
            $this->stream->advance();

            return new UnaryExpressionNode('!', $this->parseUnary());
        }

        // Prefix 'not' keyword
        if ($current->type === TokenType::Ident && $current->value === 'not') {
            // Make sure the next token is not 'in' — that's handled in parseInfixExpression
            $next = $this->stream->peek();
            if ($next !== null && $next->value === 'in') {
                return $this->parsePostfix();
            }

            $this->stream->advance();

            return new UnaryExpressionNode('not', $this->parseUnary());
        }

        // Prefix - (unary negation)
        if ($current->type === TokenType::Operator && $current->value === '-') {
            $this->stream->advance();

            return new UnaryExpressionNode('-', $this->parseUnary());
        }

        return $this->parsePostfix();
    }

    private function parsePostfix(): ExprNode
    {
        $expr = $this->parsePrimary();

        while (true) {
            if ($this->stream->check(TokenType::Dot)) {
                $this->stream->advance();
                $property = $this->stream->expect(TokenType::Ident);
                $expr = new MemberExpressionNode($expr, $property->value);
            } elseif ($this->stream->check(TokenType::LeftBracket)) {
                $this->stream->advance();
                $index = $this->parseExpression();
                $this->stream->expect(TokenType::RightBracket);
                $expr = new IndexExpressionNode($expr, $index);
            } elseif ($this->stream->current()->type === TokenType::Ident && $this->stream->current()->value === 'as') {
                $this->stream->advance();
                $type = $this->parseTypeAnnotation();
                $expr = new CoercionExpressionNode($expr, $type);
            } else {
                break;
            }
        }

        return $expr;
    }

    private function parsePrimary(): ExprNode
    {
        // Check literal extensions first
        foreach ($this->literalExtensions as $ext) {
            if ($ext->canParse($this->stream->current(), $this->stream)) {
                return $ext->parse($this);
            }
        }

        $current = $this->stream->current();

        return match ($current->type) {
            TokenType::Number => $this->parseNumberLiteral(),
            TokenType::String => $this->parseStringLiteral(),
            TokenType::True => $this->parseBooleanLiteral(true),
            TokenType::False => $this->parseBooleanLiteral(false),
            TokenType::Null => $this->parseNullLiteral(),
            TokenType::Ident => $this->parseIdentifier(),
            TokenType::LeftParen => $this->parseGrouped(),
            TokenType::LeftBracket => $this->parseListLiteral(),
            TokenType::LeftBrace => $this->parseDictLiteral(),
            default => throw new RuntimeException(
                "Unexpected token {$current->type->name} ('{$current->value}') at line {$current->line}, col {$current->col}",
            ),
        };
    }

    private function parseNumberLiteral(): LiteralNode
    {
        $token = $this->stream->advance();
        $raw = $token->value;

        if (str_ends_with($raw, '%')) {
            $numeric = substr($raw, 0, -1);
            $value = (float) $numeric / 100;
        } elseif (str_contains($raw, '.')) {
            $value = (float) $raw;
        } else {
            $value = (int) $raw;
        }

        return new LiteralNode($value, $raw);
    }

    private function parseStringLiteral(): LiteralNode
    {
        $token = $this->stream->advance();
        // Unescape the string value
        $value = str_replace(
            ['\\\\', '\\"', '\\n', '\\t', '\\r'],
            ['\\', '"', "\n", "\t", "\r"],
            $token->value,
        );

        return new LiteralNode($value, '"' . $token->value . '"');
    }

    private function parseBooleanLiteral(bool $value): LiteralNode
    {
        $token = $this->stream->advance();

        return new LiteralNode($value, $token->value);
    }

    private function parseNullLiteral(): LiteralNode
    {
        $token = $this->stream->advance();

        return new LiteralNode(null, $token->value);
    }

    private function parseIdentifier(): IdentifierNode
    {
        $token = $this->stream->advance();

        return new IdentifierNode($token->value);
    }

    private function parseGrouped(): ExprNode
    {
        $this->stream->advance(); // (
        $expr = $this->parseExpression();
        $this->stream->expect(TokenType::RightParen);

        return $expr;
    }

    private function parseListLiteral(): ListLiteralNode
    {
        $this->stream->advance(); // [

        /** @var list<ExprNode> $elements */
        $elements = [];

        if (!$this->stream->check(TokenType::RightBracket)) {
            $elements[] = $this->parseExpression();

            while ($this->stream->check(TokenType::Comma)) {
                $this->stream->advance();
                if ($this->stream->check(TokenType::RightBracket)) {
                    break;
                }
                $elements[] = $this->parseExpression();
            }
        }

        $this->stream->expect(TokenType::RightBracket);

        return new ListLiteralNode($elements);
    }

    private function parseDictLiteral(): DictLiteralNode
    {
        $this->stream->advance(); // {

        /** @var list<array{key: ExprNode, value: ExprNode}> $entries */
        $entries = [];

        if (!$this->stream->check(TokenType::RightBrace)) {
            $entries[] = $this->parseDictEntry();

            while ($this->stream->check(TokenType::Comma)) {
                $this->stream->advance();
                if ($this->stream->check(TokenType::RightBrace)) {
                    break;
                }
                $entries[] = $this->parseDictEntry();
            }
        }

        $this->stream->expect(TokenType::RightBrace);

        return new DictLiteralNode($entries);
    }

    /**
     * @return array{key: ExprNode, value: ExprNode}
     */
    private function parseDictEntry(): array
    {
        $key = $this->parseExpression();
        $this->stream->expect(TokenType::Colon);
        $value = $this->parseExpression();

        return ['key' => $key, 'value' => $value];
    }

    public function parseTypeAnnotation(): TypeAnnotationNode
    {
        $keywordToken = $this->stream->expect(TokenType::Ident);
        $keyword = $keywordToken->value;

        /** @var list<TypeAnnotationNode> $args */
        $args = [];

        // Check for parameterized types: type<arg, arg>
        if ($this->stream->current()->type === TokenType::Operator && $this->stream->current()->value === '<') {
            $this->stream->advance(); // <
            $args[] = $this->parseTypeAnnotation();

            while ($this->stream->check(TokenType::Comma)) {
                $this->stream->advance();
                $args[] = $this->parseTypeAnnotation();
            }

            // Expect >
            $current = $this->stream->current();
            if ($current->type === TokenType::Operator && $current->value === '>') {
                $this->stream->advance();
            } else {
                throw new RuntimeException(
                    "Expected '>', got {$current->type->name} ('{$current->value}') at line {$current->line}, col {$current->col}",
                );
            }
        }

        return new TypeAnnotationNode($keyword, $args);
    }
}
