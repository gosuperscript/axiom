<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

use RuntimeException;
use Superscript\Axiom\Dsl\Ast\Expressions\CallExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\DictLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IdentifierNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IndexExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\InfixExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LambdaNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ListLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MatchExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MemberExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\PipeExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\UnaryExpressionNode;
use Superscript\Axiom\Dsl\Ast\Node;
use Superscript\Axiom\Dsl\Ast\Patterns\PatternNode;
use Superscript\Axiom\Dsl\Ast\Patterns\ExpressionPatternNode;
use Superscript\Axiom\Dsl\Ast\Patterns\LiteralPatternNode;
use Superscript\Axiom\Dsl\Ast\Patterns\WildcardPatternNode;
use Superscript\Axiom\Dsl\Ast\ProgramNode;
use Superscript\Axiom\Dsl\Ast\Statements\AssertStatementNode;
use Superscript\Axiom\Dsl\Ast\Statements\InputDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\NamespaceDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\SchemaDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\SymbolDeclarationNode;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;

final class PrettyPrinter
{
    private int $indent = 0;

    private const INDENT_SIZE = 4;

    public function print(ProgramNode $program): string
    {
        $lines = [];

        foreach ($program->body as $node) {
            $lines[] = $this->printNode($node);
        }

        return implode("\n", $lines);
    }

    public function printNode(Node $node): string
    {
        return match (true) {
            $node instanceof SymbolDeclarationNode => $this->printSymbolDeclaration($node),
            $node instanceof SchemaDeclarationNode => $this->printSchemaDeclaration($node),
            $node instanceof NamespaceDeclarationNode => $this->printNamespaceDeclaration($node),
            $node instanceof AssertStatementNode => $this->printAssertStatement($node),
            $node instanceof InputDeclarationNode => $this->printInputDeclaration($node),
            default => throw new RuntimeException('Cannot print node: ' . get_class($node)),
        };
    }

    public function printExpression(ExprNode $node): string
    {
        return match (true) {
            $node instanceof LiteralNode => $node->raw,
            $node instanceof IdentifierNode => $node->name,
            $node instanceof InfixExpressionNode => $this->printInfix($node),
            $node instanceof UnaryExpressionNode => $this->printUnary($node),
            $node instanceof MemberExpressionNode => $this->printMember($node),
            $node instanceof IndexExpressionNode => $this->printIndex($node),
            $node instanceof MatchExpressionNode => $this->printMatch($node),
            $node instanceof CoercionExpressionNode => $this->printCoercion($node),
            $node instanceof CallExpressionNode => $this->printCall($node),
            $node instanceof PipeExpressionNode => $this->printPipe($node),
            $node instanceof LambdaNode => $this->printLambda($node),
            $node instanceof ListLiteralNode => $this->printList($node),
            $node instanceof DictLiteralNode => $this->printDict($node),
            default => throw new RuntimeException('Cannot print expression: ' . get_class($node)),
        };
    }

    private function printSymbolDeclaration(SymbolDeclarationNode $node): string
    {
        $prefix = $node->visibility === 'private' ? 'private ' : '';

        return $this->indentation() . $prefix . $node->name . ': ' . $this->printType($node->type) . ' = ' . $this->printExpression($node->expression);
    }

    private function printSchemaDeclaration(SchemaDeclarationNode $node): string
    {
        $result = $this->indentation() . 'schema ' . $node->name . " {\n";
        $this->indent++;

        foreach ($node->members as $member) {
            $result .= $this->printNode($member) . "\n";
        }

        $this->indent--;
        $result .= $this->indentation() . '}';

        return $result;
    }

    private function printNamespaceDeclaration(NamespaceDeclarationNode $node): string
    {
        $result = $this->indentation() . 'namespace ' . $node->name . " {\n";
        $this->indent++;

        foreach ($node->body as $child) {
            $result .= $this->printNode($child) . "\n";
        }

        $this->indent--;
        $result .= $this->indentation() . '}';

        return $result;
    }

    private function printAssertStatement(AssertStatementNode $node): string
    {
        return $this->indentation() . 'assert ' . $this->printExpression($node->expression);
    }

    private function printInputDeclaration(InputDeclarationNode $node): string
    {
        return $this->indentation() . 'input ' . $node->name . ': ' . $this->printType($node->type);
    }

    private function printType(TypeAnnotationNode $type): string
    {
        if ($type->args === []) {
            return $type->keyword;
        }

        $args = implode(', ', array_map(fn(TypeAnnotationNode $a) => $this->printType($a), $type->args));

        return $type->keyword . '(' . $args . ')';
    }

    private function printInfix(InfixExpressionNode $node): string
    {
        return $this->printExpression($node->left) . ' ' . $node->operator . ' ' . $this->printExpression($node->right);
    }

    private function printUnary(UnaryExpressionNode $node): string
    {
        // Keyword operators like 'not' need a space
        $separator = ctype_alpha($node->operator[0]) ? ' ' : '';

        return $node->operator . $separator . $this->printExpression($node->operand);
    }

    private function printMember(MemberExpressionNode $node): string
    {
        return $this->printExpression($node->object) . '.' . $node->property;
    }

    private function printIndex(IndexExpressionNode $node): string
    {
        return $this->printExpression($node->object) . '[' . $this->printExpression($node->index) . ']';
    }

    private function printCoercion(CoercionExpressionNode $node): string
    {
        return $this->printExpression($node->expression) . ' as ' . $this->printType($node->targetType);
    }

    private function printCall(CallExpressionNode $node): string
    {
        $args = [];
        foreach ($node->positionalArgs as $arg) {
            $args[] = $this->printExpression($arg);
        }
        foreach ($node->namedArgs as $name => $arg) {
            $args[] = $name . ': ' . $this->printExpression($arg);
        }

        return $node->callee . '(' . implode(', ', $args) . ')';
    }

    private function printPipe(PipeExpressionNode $node): string
    {
        return $this->printExpression($node->left) . ' |> ' . $this->printExpression($node->right);
    }

    private function printLambda(LambdaNode $node): string
    {
        return '(' . implode(', ', $node->params) . ') -> ' . $this->printExpression($node->body);
    }

    private function printList(ListLiteralNode $node): string
    {
        $elements = array_map(fn(ExprNode $el) => $this->printExpression($el), $node->elements);

        return '[' . implode(', ', $elements) . ']';
    }

    private function printDict(DictLiteralNode $node): string
    {
        $entries = array_map(fn(array $entry) => $this->printExpression($entry['key']) . ': ' . $this->printExpression($entry['value']), $node->entries);

        return '{' . implode(', ', $entries) . '}';
    }

    private function printMatch(MatchExpressionNode $node): string
    {
        // Detect if this should be printed as if/then/else
        if ($node->subject === null && $this->isIfThenElseForm($node)) {
            return $this->printAsIfThenElse($node);
        }

        // Print as match { } form
        return $this->printAsMatchBlock($node);
    }

    private function isIfThenElseForm(MatchExpressionNode $node): bool
    {
        $armCount = count($node->arms);
        if ($armCount < 2) {
            return false;
        }

        // Last arm must be wildcard
        $lastArm = $node->arms[$armCount - 1];
        if (!$lastArm->pattern instanceof WildcardPatternNode) {
            return false;
        }

        // All arms except the last must be expression patterns
        for ($i = 0; $i < $armCount - 1; $i++) {
            if (!$node->arms[$i]->pattern instanceof ExpressionPatternNode) {
                return false;
            }
        }

        // Use if/then/else for up to 3 expression arms (+ wildcard)
        return $armCount - 1 <= 3;
    }

    private function printAsIfThenElse(MatchExpressionNode $node): string
    {
        $arms = $node->arms;
        $firstArm = $arms[0];
        /** @var ExpressionPatternNode $firstPattern */
        $firstPattern = $firstArm->pattern;

        $result = 'if ' . $this->printExpression($firstPattern->expression) . "\n";
        $this->indent++;
        $result .= $this->indentation() . 'then ' . $this->printExpression($firstArm->expression);
        $this->indent--;

        // Middle arms (else if)
        for ($i = 1; $i < count($arms) - 1; $i++) {
            /** @var ExpressionPatternNode $pattern */
            $pattern = $arms[$i]->pattern;
            $result .= "\n" . $this->indentation() . 'else if ' . $this->printExpression($pattern->expression) . "\n";
            $this->indent++;
            $result .= $this->indentation() . 'then ' . $this->printExpression($arms[$i]->expression);
            $this->indent--;
        }

        // Else arm (last arm, wildcard)
        $lastArm = $arms[count($arms) - 1];
        $result .= "\n" . $this->indentation() . 'else ' . $this->printExpression($lastArm->expression);

        return $result;
    }

    private function printAsMatchBlock(MatchExpressionNode $node): string
    {
        $result = 'match';

        if ($node->subject !== null) {
            $result .= ' ' . $this->printExpression($node->subject);
        }

        $result .= " {\n";
        $this->indent++;

        foreach ($node->arms as $arm) {
            $result .= $this->indentation() . $this->printPattern($arm->pattern) . ' => ' . $this->printExpression($arm->expression) . ",\n";
        }

        $this->indent--;
        $result .= $this->indentation() . '}';

        return $result;
    }

    private function printPattern(PatternNode $pattern): string
    {
        return match (true) {
            $pattern instanceof WildcardPatternNode => '_',
            $pattern instanceof LiteralPatternNode => $pattern->raw,
            $pattern instanceof ExpressionPatternNode => $this->printExpression($pattern->expression),
            default => throw new RuntimeException('Cannot print pattern: ' . get_class($pattern)),
        };
    }

    private function indentation(): string
    {
        return str_repeat(' ', $this->indent * self::INDENT_SIZE);
    }
}
