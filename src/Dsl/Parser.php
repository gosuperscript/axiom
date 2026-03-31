<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

use RuntimeException;
use Superscript\Axiom\Dsl\Ast\Expressions\CallExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IdentifierNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IndexExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\InfixExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ListLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MatchArmNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MatchExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MemberExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\PipeExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\UnaryExpressionNode;
use Superscript\Axiom\Dsl\Ast\Location;
use Superscript\Axiom\Dsl\Ast\Node;
use Superscript\Axiom\Dsl\Ast\Patterns\ExpressionPatternNode;
use Superscript\Axiom\Dsl\Ast\Patterns\LiteralPatternNode;
use Superscript\Axiom\Dsl\Ast\Patterns\PatternNode;
use Superscript\Axiom\Dsl\Ast\Patterns\WildcardPatternNode;
use Superscript\Axiom\Dsl\Ast\ProgramNode;
use Superscript\Axiom\Dsl\Ast\Statements\AssertStatementNode;
use Superscript\Axiom\Dsl\Ast\Statements\InputDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\NamespaceDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\SchemaDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\StatementNode;
use Superscript\Axiom\Dsl\Ast\Statements\SymbolDeclarationNode;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;

final class Parser
{
    /** @var list<Token> */
    private array $tokens;

    private int $pos = 0;

    public function __construct(
        private readonly OperatorRegistry $operators,
        private readonly FunctionRegistry $functions,
    ) {}

    /**
     * @param list<Token> $tokens
     */
    public function parse(array $tokens): ProgramNode
    {
        $this->tokens = $tokens;
        $this->pos = 0;

        /** @var list<Node> $body */
        $body = [];

        $this->skipNewlines();

        while (!$this->check(TokenType::Eof)) {
            $body[] = $this->parseStatement();
            $this->skipNewlines();
        }

        return new ProgramNode($body);
    }

    private function parseStatement(): StatementNode
    {
        if ($this->currentIs('schema')) {
            return $this->parseSchemaDeclaration();
        }

        if ($this->currentIs('assert')) {
            return $this->parseAssertStatement();
        }

        if ($this->currentIs('namespace')) {
            return $this->parseNamespaceDeclaration();
        }

        return $this->parseSymbolDeclaration();
    }

    private function parseSchemaDeclaration(): SchemaDeclarationNode
    {
        $startLine = $this->current()->line;
        $startCol = $this->current()->col;

        $this->expectKeyword('schema');
        $name = $this->expect(TokenType::Ident)->value;
        $this->expect(TokenType::LeftBrace);
        $this->skipNewlines();

        /** @var list<StatementNode> $members */
        $members = [];

        while (!$this->check(TokenType::RightBrace)) {
            if ($this->currentIs('input')) {
                $memberStart = $this->current();
                $this->advance();
                $inputName = $this->expect(TokenType::Ident)->value;
                $this->expect(TokenType::Colon);
                $type = $this->parseTypeAnnotation();
                $members[] = new InputDeclarationNode(
                    $inputName,
                    $type,
                    new Location($memberStart->line, $memberStart->col, $this->previous()->line, $this->previous()->col),
                );
            } elseif ($this->currentIs('private')) {
                $this->advance();
                $members[] = $this->parseSymbolDeclaration(visibility: 'private');
            } elseif ($this->currentIs('assert')) {
                $members[] = $this->parseAssertStatement();
            } else {
                $members[] = $this->parseSymbolDeclaration(visibility: 'public');
            }

            $this->skipNewlines();
        }

        $endToken = $this->expect(TokenType::RightBrace);

        return new SchemaDeclarationNode(
            $name,
            $members,
            new Location($startLine, $startCol, $endToken->line, $endToken->col),
        );
    }

    private function parseAssertStatement(): AssertStatementNode
    {
        $startLine = $this->current()->line;
        $startCol = $this->current()->col;

        $this->expectKeyword('assert');
        $expression = $this->parseExpression();

        return new AssertStatementNode(
            $expression,
            loc: new Location($startLine, $startCol, $this->previous()->line, $this->previous()->col),
        );
    }

    private function parseNamespaceDeclaration(): NamespaceDeclarationNode
    {
        $startLine = $this->current()->line;
        $startCol = $this->current()->col;

        $this->expectKeyword('namespace');
        $name = $this->expect(TokenType::Ident)->value;
        $this->expect(TokenType::LeftBrace);
        $this->skipNewlines();

        /** @var list<Node> $body */
        $body = [];

        while (!$this->check(TokenType::RightBrace)) {
            $body[] = $this->parseStatement();
            $this->skipNewlines();
        }

        $endToken = $this->expect(TokenType::RightBrace);

        return new NamespaceDeclarationNode(
            $name,
            $body,
            new Location($startLine, $startCol, $endToken->line, $endToken->col),
        );
    }

    private function parseSymbolDeclaration(string $visibility = 'public'): SymbolDeclarationNode
    {
        $startLine = $this->current()->line;
        $startCol = $this->current()->col;

        $name = $this->expect(TokenType::Ident)->value;
        $this->expect(TokenType::Colon);
        $type = $this->parseTypeAnnotation();
        $this->expect(TokenType::Equals);
        $expression = $this->parseExpression();

        return new SymbolDeclarationNode(
            $name,
            $type,
            $expression,
            $visibility,
            new Location($startLine, $startCol, $this->previous()->line, $this->previous()->col),
        );
    }

    private function parseTypeAnnotation(): TypeAnnotationNode
    {
        $token = $this->expect(TokenType::Ident);
        $keyword = $token->value;

        /** @var list<TypeAnnotationNode> $args */
        $args = [];

        if ($this->check(TokenType::LeftParen)) {
            $this->advance();
            while (!$this->check(TokenType::RightParen)) {
                $args[] = $this->parseTypeAnnotation();
                if (!$this->check(TokenType::RightParen)) {
                    $this->expect(TokenType::Comma);
                }
            }
            $this->expect(TokenType::RightParen);
        }

        return new TypeAnnotationNode($keyword, $args);
    }

    private function parseExpression(): ExprNode
    {
        if ($this->currentIs('if')) {
            return $this->parseIfExpression();
        }

        if ($this->currentIs('match')) {
            return $this->parseMatchExpression();
        }

        return $this->parseInfixExpression(0);
    }

    private function parseIfExpression(): MatchExpressionNode
    {
        $this->expectKeyword('if');
        $condition = $this->parseExpression();
        $this->skipNewlines();
        $this->expectKeyword('then');
        $consequent = $this->parseExpression();

        /** @var list<MatchArmNode> $arms */
        $arms = [new MatchArmNode(
            new ExpressionPatternNode($condition),
            $consequent,
        )];

        $this->skipNewlines();

        while ($this->currentIs('else') && $this->peekIs('if')) {
            $this->advance(); // else
            $this->advance(); // if
            $cond = $this->parseExpression();
            $this->skipNewlines();
            $this->expectKeyword('then');
            $expr = $this->parseExpression();
            $arms[] = new MatchArmNode(
                new ExpressionPatternNode($cond),
                $expr,
            );
            $this->skipNewlines();
        }

        $this->expectKeyword('else');
        $arms[] = new MatchArmNode(
            new WildcardPatternNode(),
            $this->parseExpression(),
        );

        return new MatchExpressionNode(subject: null, arms: $arms);
    }

    private function parseMatchExpression(): MatchExpressionNode
    {
        $this->expectKeyword('match');

        $subject = null;
        if (!$this->check(TokenType::LeftBrace)) {
            $subject = $this->parseExpression();
        }

        $this->expect(TokenType::LeftBrace);
        $this->skipNewlines();

        /** @var list<MatchArmNode> $arms */
        $arms = [];

        while (!$this->check(TokenType::RightBrace)) {
            $pattern = $this->parsePattern($subject === null);
            $this->expect(TokenType::Arrow);
            $expression = $this->parseExpression();
            $arms[] = new MatchArmNode($pattern, $expression);
            $this->match(TokenType::Comma);
            $this->skipNewlines();
        }

        $this->expect(TokenType::RightBrace);

        return new MatchExpressionNode($subject, $arms);
    }

    private function parsePattern(bool $isSubjectless): PatternNode
    {
        if ($this->current()->value === '_' && $this->current()->type === TokenType::Ident) {
            $this->advance();

            return new WildcardPatternNode();
        }

        if ($isSubjectless) {
            return new ExpressionPatternNode($this->parseExpression());
        }

        return $this->parseLiteralPattern();
    }

    private function parseLiteralPattern(): PatternNode
    {
        $token = $this->current();

        if ($token->type === TokenType::String) {
            $this->advance();

            return new LiteralPatternNode($token->value, '"' . $token->value . '"');
        }

        if ($token->type === TokenType::Number) {
            $this->advance();
            $value = str_contains($token->value, '.') ? (float) $token->value : (int) $token->value;

            return new LiteralPatternNode($value, $token->value);
        }

        if ($token->type === TokenType::Ident && ($token->value === 'true' || $token->value === 'false')) {
            $this->advance();

            return new LiteralPatternNode($token->value === 'true', $token->value);
        }

        if ($token->type === TokenType::Ident && $token->value === 'null') {
            $this->advance();

            return new LiteralPatternNode(null, 'null');
        }

        throw new RuntimeException("Expected pattern at line {$token->line}, col {$token->col}, got '{$token->value}'");
    }

    private function parseInfixExpression(int $minPrecedence): ExprNode
    {
        $left = $this->parsePrefixExpression();

        while (true) {
            // Handle pipe operator
            if ($this->check(TokenType::Pipe)) {
                /** @var OperatorEntry $pipeOp */
                $pipeOp = $this->operators->get('|>');
                $this->advance();
                $right = $this->parseInfixExpression($pipeOp->precedence + 1);
                $left = new PipeExpressionNode($left, $right);
                /** @infection-ignore-all */
                continue;
            }

            // Handle 'as' coercion
            if ($this->currentIs('as')) {
                $this->advance();
                $targetType = $this->parseTypeAnnotation();
                $left = new CoercionExpressionNode($left, $targetType);
                continue;
            }

            $token = $this->current();

            if ($token->type !== TokenType::Operator) {
                break;
            }

            $op = $this->operators->get($token->value);
            if ($op === null || $op->position !== OperatorPosition::Infix || $op->precedence < $minPrecedence) {
                break;
            }

            $this->advance();
            $nextMin = $op->associativity === Associativity::Left
                ? $op->precedence + 1
                : $op->precedence;

            $right = $this->parseInfixExpression($nextMin);
            $left = new InfixExpressionNode($left, $token->value, $right);
        }

        return $left;
    }

    private function parsePrefixExpression(): ExprNode
    {
        $token = $this->current();

        if ($token->type === TokenType::Operator) {
            $op = $this->operators->get($token->value);
            if ($op !== null && $op->position === OperatorPosition::Prefix) {
                $this->advance();
                $operand = $this->parseInfixExpression($op->precedence);

                return new UnaryExpressionNode($token->value, $operand);
            }
        }

        return $this->parsePostfixExpression();
    }

    private function parsePostfixExpression(): ExprNode
    {
        $expr = $this->parsePrimary();

        while (true) {
            if ($this->check(TokenType::Dot)) {
                $this->advance();
                $property = $this->expect(TokenType::Ident)->value;
                $expr = new MemberExpressionNode($expr, $property);
                continue;
            }

            if ($this->check(TokenType::LeftBracket)) {
                $this->advance();
                $index = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $expr = new IndexExpressionNode($expr, $index);
                continue;
            }

            break;
        }

        return $expr;
    }

    private function parsePrimary(): ExprNode
    {
        $token = $this->current();

        // Grouped expression
        if ($token->type === TokenType::LeftParen) {
            $this->advance();
            $expr = $this->parseExpression();
            $this->expect(TokenType::RightParen);

            return $expr;
        }

        // List literal
        if ($token->type === TokenType::LeftBracket) {
            return $this->parseListLiteral();
        }

        // Number literal
        if ($token->type === TokenType::Number) {
            $this->advance();
            $value = str_contains($token->value, '.') ? (float) $token->value : (int) $token->value;

            return new LiteralNode($value, $token->value);
        }

        // String literal
        if ($token->type === TokenType::String) {
            $this->advance();

            return new LiteralNode($token->value, '"' . $token->value . '"');
        }

        // Boolean / null / identifier / function call
        if ($token->type === TokenType::Ident) {
            if ($token->value === 'true') {
                $this->advance();

                return new LiteralNode(true, 'true');
            }
            if ($token->value === 'false') {
                $this->advance();

                return new LiteralNode(false, 'false');
            }
            if ($token->value === 'null') {
                $this->advance();

                return new LiteralNode(null, 'null');
            }

            // Check if this is a function call
            if ($this->functions->has($token->value) && $this->pos + 1 < count($this->tokens) && $this->tokens[$this->pos + 1]->type === TokenType::LeftParen) {
                return $this->parseCallExpression();
            }

            $this->advance();

            return new IdentifierNode($token->value);
        }

        throw new RuntimeException("Unexpected token '{$token->value}' ({$token->type->value}) at line {$token->line}, col {$token->col}");
    }

    private function parseCallExpression(): CallExpressionNode
    {
        $callee = $this->expect(TokenType::Ident)->value;
        $this->expect(TokenType::LeftParen);

        /** @var list<ExprNode> $positionalArgs */
        $positionalArgs = [];
        /** @var array<string, ExprNode> $namedArgs */
        $namedArgs = [];

        while (!$this->check(TokenType::RightParen)) {
            // Check for named argument: ident: expr
            if ($this->current()->type === TokenType::Ident && $this->pos + 1 < count($this->tokens) && $this->tokens[$this->pos + 1]->type === TokenType::Colon) {
                $name = $this->current()->value;
                $this->advance(); // name
                $this->advance(); // colon
                $namedArgs[$name] = $this->parseExpression();
            } else {
                $positionalArgs[] = $this->parseExpression();
            }

            if (!$this->check(TokenType::RightParen)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightParen);

        return new CallExpressionNode($callee, $positionalArgs, $namedArgs);
    }

    private function parseListLiteral(): ExprNode
    {
        $this->expect(TokenType::LeftBracket);

        /** @var list<ExprNode> $elements */
        $elements = [];

        while (!$this->check(TokenType::RightBracket)) {
            $elements[] = $this->parseExpression();
            if (!$this->check(TokenType::RightBracket)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightBracket);

        return new ListLiteralNode($elements);
    }

    private function current(): Token
    {
        return $this->tokens[$this->pos];
    }

    private function previous(): Token
    {
        return $this->tokens[$this->pos - 1];
    }

    /** @phpstan-impure */
    private function check(TokenType $type): bool
    {
        return $this->current()->type === $type;
    }

    private function currentIs(string $value): bool
    {
        return $this->current()->type === TokenType::Ident && $this->current()->value === $value;
    }

    private function peekIs(string $value): bool
    {
        $next = $this->pos + 1;

        return $next < count($this->tokens) && $this->tokens[$next]->type === TokenType::Ident && $this->tokens[$next]->value === $value;
    }

    private function advance(): void
    {
        $this->pos++;
    }

    private function match(TokenType $type): void
    {
        if ($this->check($type)) {
            $this->advance();
        }
    }

    private function expect(TokenType $type): Token
    {
        $token = $this->current();
        if ($token->type !== $type) {
            throw new RuntimeException("Expected {$type->value}, got {$token->type->value} ('{$token->value}') at line {$token->line}, col {$token->col}");
        }
        $this->advance();

        return $token;
    }

    private function expectKeyword(string $keyword): void
    {
        $token = $this->current();
        if ($token->type !== TokenType::Ident || $token->value !== $keyword) {
            throw new RuntimeException("Expected '{$keyword}', got '{$token->value}' at line {$token->line}, col {$token->col}");
        }
        $this->advance();
    }

    private function skipNewlines(): void
    {
        while ($this->check(TokenType::Newline)) {
            $this->advance();
        }
    }
}
