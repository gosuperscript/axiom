<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Compiler;

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
use Superscript\Axiom\Dsl\Ast\Statements\SymbolDeclarationNode;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;
use Superscript\Axiom\Dsl\DslLiteralExtension;
use Superscript\Axiom\Dsl\TypeRegistry;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\SymbolRegistry;

final class Compiler
{
    /**
     * @param list<DslLiteralExtension> $literalExtensions
     */
    public function __construct(
        private TypeRegistry $typeRegistry,
        private array $literalExtensions = [],
    ) {}

    public function compile(ExprNode $node): Source
    {
        foreach ($this->literalExtensions as $ext) {
            if ($ext->handles($node)) {
                return $ext->compile($node, $this);
            }
        }

        return match (true) {
            $node instanceof LiteralNode => $this->compileLiteral($node),
            $node instanceof IdentifierNode => $this->compileIdentifier($node),
            $node instanceof MemberExpressionNode => $this->compileMemberAccess($node),
            $node instanceof IndexExpressionNode => $this->compileIndexAccess($node),
            $node instanceof InfixExpressionNode => $this->compileInfix($node),
            $node instanceof UnaryExpressionNode => $this->compileUnary($node),
            $node instanceof CoercionExpressionNode => $this->compileCoercion($node),
            $node instanceof ListLiteralNode => $this->compileList($node),
            $node instanceof DictLiteralNode => $this->compileDict($node),
            default => throw new RuntimeException('Cannot compile node of type ' . $node::class),
        };
    }

    public function compileProgram(ProgramNode $program): CompilationResult
    {
        /** @var array<string, Source|array<string, Source>> $symbols */
        $symbols = [];

        /** @var list<string> $outputs */
        $outputs = [];

        foreach ($program->body as $node) {
            $this->compileNode($node, $symbols, $outputs);
        }

        return new CompilationResult(
            new SymbolRegistry($symbols),
            $outputs,
        );
    }

    /**
     * @param array<string, Source|array<string, Source>> $symbols
     * @param list<string> $outputs
     */
    private function compileNode(Node $node, array &$symbols, array &$outputs, ?string $namespace = null): void
    {
        if ($node instanceof SymbolDeclarationNode) {
            $source = $this->compile($node->expression);
            $source = $this->applyTypeCoercion($node->type, $source);

            if ($namespace !== null) {
                if (!isset($symbols[$namespace]) || !is_array($symbols[$namespace])) {
                    $symbols[$namespace] = [];
                }
                $symbols[$namespace][$node->name] = $source;
            } else {
                $symbols[$node->name] = $source;
            }

            if ($node->visibility === 'public') {
                $outputName = $namespace !== null ? $namespace . '.' . $node->name : $node->name;
                $outputs[] = $outputName;
            }
        } elseif ($node instanceof NamespaceDeclarationNode) {
            foreach ($node->body as $child) {
                $this->compileNode($child, $symbols, $outputs, $node->name);
            }
        }
    }

    private function applyTypeCoercion(TypeAnnotationNode $typeNode, Source $source): Source
    {
        if (!$this->typeRegistry->has($typeNode->keyword)) {
            return $source;
        }

        $typeArgs = array_map(
            fn(TypeAnnotationNode $arg) => $arg->keyword,
            $typeNode->args,
        );

        $type = $this->typeRegistry->resolve($typeNode->keyword, ...$typeArgs);

        return new TypeDefinition($type, $source);
    }

    private function compileLiteral(LiteralNode $node): Source
    {
        return new StaticSource($node->value);
    }

    private function compileIdentifier(IdentifierNode $node): Source
    {
        return new SymbolSource($node->name);
    }

    private function compileMemberAccess(MemberExpressionNode $node): Source
    {
        // Two-level (a.b) → SymbolSource('b', 'a') for backward compatibility
        if ($node->object instanceof IdentifierNode) {
            return new SymbolSource($node->property, $node->object->name);
        }

        return new MemberAccessSource(
            $this->compile($node->object),
            $node->property,
        );
    }

    private function compileIndexAccess(IndexExpressionNode $node): Source
    {
        return new MemberAccessSource(
            $this->compile($node->object),
            $this->compileIndexProperty($node->index),
        );
    }

    private function compileIndexProperty(ExprNode $index): string
    {
        if ($index instanceof LiteralNode) {
            if (is_string($index->value) || is_int($index->value)) {
                return (string) $index->value;
            }

            throw new RuntimeException('Index literal must be a string or integer');
        }

        if ($index instanceof IdentifierNode) {
            return $index->name;
        }

        throw new RuntimeException('Index expression must be a literal or identifier');
    }

    private function compileInfix(InfixExpressionNode $node): Source
    {
        return new InfixExpression(
            $this->compile($node->left),
            $node->operator,
            $this->compile($node->right),
        );
    }

    private function compileUnary(UnaryExpressionNode $node): Source
    {
        return new UnaryExpression(
            $node->operator,
            $this->compile($node->operand),
        );
    }

    private function compileCoercion(CoercionExpressionNode $node): Source
    {
        $source = $this->compile($node->expression);

        return $this->applyTypeCoercion($node->targetType, $source);
    }

    private function compileList(ListLiteralNode $node): Source
    {
        $values = array_map(
            fn(ExprNode $el) => $this->compileToValue($el),
            $node->elements,
        );

        return new StaticSource($values);
    }

    private function compileDict(DictLiteralNode $node): Source
    {
        /** @var array<string, mixed> $dict */
        $dict = [];

        foreach ($node->entries as $entry) {
            $key = $this->compileToValue($entry['key']);
            $value = $this->compileToValue($entry['value']);
            if (!is_string($key) && !is_int($key)) {
                throw new RuntimeException('Dict key must be a string or integer');
            }
            $dict[(string) $key] = $value;
        }

        return new StaticSource($dict);
    }

    private function compileToValue(ExprNode $node): mixed
    {
        if ($node instanceof LiteralNode) {
            return $node->value;
        }

        return $this->compile($node);
    }
}
