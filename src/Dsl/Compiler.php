<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

use RuntimeException;
use Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IdentifierNode;
use Superscript\Axiom\Dsl\Ast\Expressions\InfixExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MatchArmNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MatchExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MemberExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\UnaryExpressionNode;
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
use Superscript\Axiom\Dsl\Ast\Statements\SymbolDeclarationNode;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;

final readonly class Compiler
{
    public function __construct(
        private TypeRegistry $types,
    ) {}

    public function compile(ProgramNode $program): CompilationResult
    {
        /** @var array<string, Source> $symbols */
        $symbols = [];
        /** @var array<string, string> $inputs */
        $inputs = [];
        /** @var list<string> $outputs */
        $outputs = [];
        /** @var list<Source> $assertions */
        $assertions = [];

        foreach ($program->body as $node) {
            $this->compileNode($node, $symbols, $inputs, $outputs, $assertions);
        }

        return new CompilationResult($symbols, $inputs, $outputs, $assertions);
    }

    /**
     * @param array<string, Source> $symbols
     * @param array<string, string> $inputs
     * @param list<string> $outputs
     * @param list<Source> $assertions
     */
    private function compileNode(
        Node $node,
        array &$symbols,
        array &$inputs,
        array &$outputs,
        array &$assertions,
        ?string $namespace = null,
    ): void {
        if ($node instanceof SymbolDeclarationNode) {
            $source = $this->compileExpression($node->expression);
            $source = new TypeDefinition(
                $this->types->resolve($node->type->keyword, ...array_map(fn($a) => $a->keyword, $node->type->args)),
                $source,
            );
            $key = $namespace !== null ? $namespace . '.' . $node->name : $node->name;
            $symbols[$key] = $source;

            if ($node->visibility === 'public') {
                $outputs[] = $node->name;
            }

            return;
        }

        if ($node instanceof NamespaceDeclarationNode) {
            foreach ($node->body as $child) {
                $this->compileNode($child, $symbols, $inputs, $outputs, $assertions, $node->name);
            }

            return;
        }

        if ($node instanceof SchemaDeclarationNode) {
            $this->compileSchema($node, $symbols, $inputs, $outputs, $assertions);

            return;
        }

        if ($node instanceof AssertStatementNode) {
            $assertions[] = $this->compileExpression($node->expression);

            return;
        }

        if ($node instanceof InputDeclarationNode) {
            $inputs[$node->name] = $node->type->keyword;

            return;
        }

        throw new RuntimeException('Unknown node type: ' . get_class($node));
    }

    /**
     * @param array<string, Source> $symbols
     * @param array<string, string> $inputs
     * @param list<string> $outputs
     * @param list<Source> $assertions
     */
    private function compileSchema(
        SchemaDeclarationNode $schema,
        array &$symbols,
        array &$inputs,
        array &$outputs,
        array &$assertions,
    ): void {
        foreach ($schema->members as $member) {
            $this->compileNode($member, $symbols, $inputs, $outputs, $assertions);
        }
    }

    public function compileExpression(ExprNode $node): Source
    {
        return match (true) {
            $node instanceof LiteralNode => new StaticSource($node->value),
            $node instanceof IdentifierNode => new SymbolSource($node->name),
            $node instanceof InfixExpressionNode => new InfixExpression(
                $this->compileExpression($node->left),
                $node->operator,
                $this->compileExpression($node->right),
            ),
            $node instanceof UnaryExpressionNode => new UnaryExpression(
                $node->operator,
                $this->compileExpression($node->operand),
            ),
            $node instanceof MemberExpressionNode => $this->compileMemberExpression($node),
            $node instanceof MatchExpressionNode => $this->compileMatch($node),
            $node instanceof CoercionExpressionNode => new TypeDefinition(
                $this->types->resolve($node->targetType->keyword, ...array_map(fn($a) => $a->keyword, $node->targetType->args)),
                $this->compileExpression($node->expression),
            ),
            default => throw new RuntimeException('Cannot compile expression: ' . get_class($node)),
        };
    }

    private function compileMemberExpression(MemberExpressionNode $node): Source
    {
        if ($node->object instanceof IdentifierNode) {
            return new SymbolSource($node->property, $node->object->name);
        }

        return new MemberAccessSource(
            $this->compileExpression($node->object),
            $node->property,
        );
    }

    private function compileMatch(MatchExpressionNode $node): MatchExpression
    {
        $subject = $node->subject !== null
            ? $this->compileExpression($node->subject)
            : new StaticSource(true);

        $arms = array_map(fn(MatchArmNode $arm) => new MatchArm(
            $this->compilePattern($arm->pattern),
            $this->compileExpression($arm->expression),
        ), $node->arms);

        return new MatchExpression($subject, $arms);
    }

    private function compilePattern(PatternNode $pattern): MatchPattern
    {
        return match (true) {
            $pattern instanceof WildcardPatternNode => new WildcardPattern(),
            $pattern instanceof LiteralPatternNode => new LiteralPattern($pattern->value),
            $pattern instanceof ExpressionPatternNode => new ExpressionPattern(
                $this->compileExpression($pattern->expression),
            ),
            default => throw new RuntimeException('Unknown pattern type: ' . get_class($pattern)),
        };
    }
}
