<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Dsl\Ast\Expressions\CallExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\DictLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IdentifierNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IndexExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\InfixExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LambdaNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ListLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MatchArmNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MatchExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MemberExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\PipeExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\UnaryExpressionNode;
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
use Superscript\Axiom\Dsl\PrettyPrinter;

#[CoversClass(PrettyPrinter::class)]
#[UsesClass(ProgramNode::class)]
#[UsesClass(LiteralNode::class)]
#[UsesClass(IdentifierNode::class)]
#[UsesClass(InfixExpressionNode::class)]
#[UsesClass(UnaryExpressionNode::class)]
#[UsesClass(MemberExpressionNode::class)]
#[UsesClass(IndexExpressionNode::class)]
#[UsesClass(MatchExpressionNode::class)]
#[UsesClass(MatchArmNode::class)]
#[UsesClass(CoercionExpressionNode::class)]
#[UsesClass(CallExpressionNode::class)]
#[UsesClass(PipeExpressionNode::class)]
#[UsesClass(LambdaNode::class)]
#[UsesClass(ListLiteralNode::class)]
#[UsesClass(DictLiteralNode::class)]
#[UsesClass(WildcardPatternNode::class)]
#[UsesClass(LiteralPatternNode::class)]
#[UsesClass(ExpressionPatternNode::class)]
#[UsesClass(SymbolDeclarationNode::class)]
#[UsesClass(SchemaDeclarationNode::class)]
#[UsesClass(NamespaceDeclarationNode::class)]
#[UsesClass(InputDeclarationNode::class)]
#[UsesClass(AssertStatementNode::class)]
#[UsesClass(TypeAnnotationNode::class)]
class PrettyPrinterTest extends TestCase
{
    private function printer(): PrettyPrinter
    {
        return new PrettyPrinter();
    }

    // --- Symbol declarations ---

    #[Test]
    public function it_prints_symbol_declaration(): void
    {
        $node = new SymbolDeclarationNode('x', new TypeAnnotationNode('number'), new LiteralNode(42, '42'));

        $this->assertSame('x: number = 42', $this->printer()->printNode($node));
    }

    #[Test]
    public function it_prints_private_symbol(): void
    {
        $node = new SymbolDeclarationNode('base', new TypeAnnotationNode('number'), new LiteralNode(100, '100'), 'private');

        $this->assertSame('private base: number = 100', $this->printer()->printNode($node));
    }

    // --- Expressions ---

    #[Test]
    public function it_prints_infix_expression(): void
    {
        $node = new InfixExpressionNode(new IdentifierNode('a'), '+', new IdentifierNode('b'));

        $this->assertSame('a + b', $this->printer()->printExpression($node));
    }

    #[Test]
    public function it_prints_unary_expression(): void
    {
        $node = new UnaryExpressionNode('!', new IdentifierNode('x'));

        $this->assertSame('!x', $this->printer()->printExpression($node));
    }

    #[Test]
    public function it_prints_unary_keyword_with_space(): void
    {
        $node = new UnaryExpressionNode('not', new IdentifierNode('x'));

        $this->assertSame('not x', $this->printer()->printExpression($node));
    }

    #[Test]
    public function it_prints_member_expression(): void
    {
        $node = new MemberExpressionNode(new IdentifierNode('quote'), 'claims');

        $this->assertSame('quote.claims', $this->printer()->printExpression($node));
    }

    #[Test]
    public function it_prints_index_expression(): void
    {
        $node = new IndexExpressionNode(new IdentifierNode('items'), new LiteralNode(0, '0'));

        $this->assertSame('items[0]', $this->printer()->printExpression($node));
    }

    #[Test]
    public function it_prints_coercion(): void
    {
        $node = new CoercionExpressionNode(new IdentifierNode('x'), new TypeAnnotationNode('number'));

        $this->assertSame('x as number', $this->printer()->printExpression($node));
    }

    #[Test]
    public function it_prints_pipe(): void
    {
        $node = new PipeExpressionNode(new IdentifierNode('x'), new IdentifierNode('func'));

        $this->assertSame('x |> func', $this->printer()->printExpression($node));
    }

    #[Test]
    public function it_prints_call(): void
    {
        $node = new CallExpressionNode('max', [new IdentifierNode('a'), new IdentifierNode('b')]);

        $this->assertSame('max(a, b)', $this->printer()->printExpression($node));
    }

    #[Test]
    public function it_prints_call_with_named_args(): void
    {
        $node = new CallExpressionNode('func', [], ['x' => new LiteralNode(1, '1')]);

        $this->assertSame('func(x: 1)', $this->printer()->printExpression($node));
    }

    #[Test]
    public function it_prints_lambda(): void
    {
        $node = new LambdaNode(['x'], new InfixExpressionNode(new IdentifierNode('x'), '*', new LiteralNode(2, '2')));

        $this->assertSame('(x) -> x * 2', $this->printer()->printExpression($node));
    }

    #[Test]
    public function it_prints_list_literal(): void
    {
        $node = new ListLiteralNode([
            new LiteralNode(1, '1'),
            new LiteralNode(2, '2'),
        ]);

        $this->assertSame('[1, 2]', $this->printer()->printExpression($node));
    }

    #[Test]
    public function it_prints_dict_literal(): void
    {
        $node = new DictLiteralNode([
            ['key' => new LiteralNode('a', '"a"'), 'value' => new LiteralNode(1, '1')],
        ]);

        $this->assertSame('{"a": 1}', $this->printer()->printExpression($node));
    }

    // --- If/then/else form ---

    #[Test]
    public function it_prints_simple_if_then_else(): void
    {
        $node = new MatchExpressionNode(
            subject: null,
            arms: [
                new MatchArmNode(
                    new ExpressionPatternNode(new InfixExpressionNode(new IdentifierNode('a'), '>', new LiteralNode(2, '2'))),
                    new IdentifierNode('b'),
                ),
                new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0, '0')),
            ],
        );

        $this->assertSame(
            "if a > 2\n    then b\nelse 0",
            $this->printer()->printExpression($node),
        );
    }

    #[Test]
    public function it_prints_chained_else_if(): void
    {
        $node = new MatchExpressionNode(
            subject: null,
            arms: [
                new MatchArmNode(
                    new ExpressionPatternNode(new InfixExpressionNode(new IdentifierNode('a'), '==', new LiteralNode(0, '0'))),
                    new LiteralNode(0.9, '0.9'),
                ),
                new MatchArmNode(
                    new ExpressionPatternNode(new InfixExpressionNode(new IdentifierNode('a'), '<=', new LiteralNode(2, '2'))),
                    new LiteralNode(1.0, '1.0'),
                ),
                new MatchArmNode(new WildcardPatternNode(), new LiteralNode(1.5, '1.5')),
            ],
        );

        $this->assertSame(
            "if a == 0\n    then 0.9\nelse if a <= 2\n    then 1.0\nelse 1.5",
            $this->printer()->printExpression($node),
        );
    }

    #[Test]
    public function it_prints_4_arms_as_match_block_not_if(): void
    {
        $node = new MatchExpressionNode(
            subject: null,
            arms: [
                new MatchArmNode(new ExpressionPatternNode(new LiteralNode(true, 'true')), new LiteralNode(1, '1')),
                new MatchArmNode(new ExpressionPatternNode(new LiteralNode(true, 'true')), new LiteralNode(2, '2')),
                new MatchArmNode(new ExpressionPatternNode(new LiteralNode(true, 'true')), new LiteralNode(3, '3')),
                new MatchArmNode(new ExpressionPatternNode(new LiteralNode(true, 'true')), new LiteralNode(4, '4')),
                new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0, '0')),
            ],
        );

        $this->assertSame(
            "match {\n    true => 1,\n    true => 2,\n    true => 3,\n    true => 4,\n    _ => 0,\n}",
            $this->printer()->printExpression($node),
        );
    }

    // --- Match with subject ---

    #[Test]
    public function it_prints_match_with_subject(): void
    {
        $node = new MatchExpressionNode(
            subject: new IdentifierNode('tier'),
            arms: [
                new MatchArmNode(new LiteralPatternNode('micro', '"micro"'), new LiteralNode(1.3, '1.3')),
                new MatchArmNode(new LiteralPatternNode('small', '"small"'), new LiteralNode(1.1, '1.1')),
                new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0.85, '0.85')),
            ],
        );

        $this->assertSame(
            "match tier {\n    \"micro\" => 1.3,\n    \"small\" => 1.1,\n    _ => 0.85,\n}",
            $this->printer()->printExpression($node),
        );
    }

    #[Test]
    public function it_prints_subjectless_match_block(): void
    {
        $node = new MatchExpressionNode(
            subject: null,
            arms: [
                new MatchArmNode(new ExpressionPatternNode(new LiteralNode(true, 'true')), new LiteralNode(1, '1')),
                new MatchArmNode(new ExpressionPatternNode(new LiteralNode(true, 'true')), new LiteralNode(2, '2')),
                new MatchArmNode(new ExpressionPatternNode(new LiteralNode(true, 'true')), new LiteralNode(3, '3')),
                new MatchArmNode(new ExpressionPatternNode(new LiteralNode(true, 'true')), new LiteralNode(4, '4')),
                new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0, '0')),
            ],
        );

        $this->assertSame(
            "match {\n    true => 1,\n    true => 2,\n    true => 3,\n    true => 4,\n    _ => 0,\n}",
            $this->printer()->printExpression($node),
        );
    }

    // --- Schema ---

    #[Test]
    public function it_prints_schema(): void
    {
        $node = new SchemaDeclarationNode('PremiumCalc', [
            new InputDeclarationNode('quote', new TypeAnnotationNode('dict')),
            new SymbolDeclarationNode('base', new TypeAnnotationNode('number'), new LiteralNode(100, '100'), 'private'),
            new SymbolDeclarationNode('gross', new TypeAnnotationNode('number'), new LiteralNode(110, '110')),
            new AssertStatementNode(new InfixExpressionNode(new IdentifierNode('gross'), '>=', new LiteralNode(500, '500'))),
        ]);

        $this->assertSame(
            "schema PremiumCalc {\n    input quote: dict\n    private base: number = 100\n    gross: number = 110\n    assert gross >= 500\n}",
            $this->printer()->printNode($node),
        );
    }

    // --- Namespace ---

    #[Test]
    public function it_prints_namespace(): void
    {
        $node = new NamespaceDeclarationNode('premium', [
            new SymbolDeclarationNode('base', new TypeAnnotationNode('number'), new LiteralNode(100, '100')),
        ]);

        $this->assertSame(
            "namespace premium {\n    base: number = 100\n}",
            $this->printer()->printNode($node),
        );
    }

    // --- Assert ---

    #[Test]
    public function it_prints_assert(): void
    {
        $node = new AssertStatementNode(
            new InfixExpressionNode(new IdentifierNode('x'), '>=', new LiteralNode(0, '0')),
        );

        $this->assertSame('assert x >= 0', $this->printer()->printNode($node));
    }

    // --- Input ---

    #[Test]
    public function it_prints_input(): void
    {
        $node = new InputDeclarationNode('quote', new TypeAnnotationNode('dict'));

        $this->assertSame('input quote: dict', $this->printer()->printNode($node));
    }

    // --- Type with args ---

    #[Test]
    public function it_prints_type_with_args(): void
    {
        $node = new SymbolDeclarationNode('x', new TypeAnnotationNode('list', [new TypeAnnotationNode('number')]), new ListLiteralNode([]));

        $this->assertSame('x: list(number) = []', $this->printer()->printNode($node));
    }

    // --- Program ---

    #[Test]
    public function it_prints_program(): void
    {
        $program = new ProgramNode([
            new SymbolDeclarationNode('a', new TypeAnnotationNode('number'), new LiteralNode(1, '1')),
            new SymbolDeclarationNode('b', new TypeAnnotationNode('number'), new LiteralNode(2, '2')),
        ]);

        $result = $this->printer()->print($program);

        $this->assertSame("a: number = 1\nb: number = 2", $result);
    }

    // --- Error cases ---

    #[Test]
    public function it_throws_for_unknown_node(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot print node');

        $fakeNode = new class implements \Superscript\Axiom\Dsl\Ast\Node {
            public function toArray(): array
            {
                return [];
            }

            public static function fromArray(array $data): static
            {
                return new static();
            }
        };

        $this->printer()->printNode($fakeNode);
    }

    #[Test]
    public function it_throws_for_unknown_expression(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot print expression');

        $fakeExpr = new class implements \Superscript\Axiom\Dsl\Ast\Expressions\ExprNode {
            public function toArray(): array
            {
                return [];
            }

            public static function fromArray(array $data): static
            {
                return new static();
            }
        };

        $this->printer()->printExpression($fakeExpr);
    }

    #[Test]
    public function it_throws_for_unknown_pattern(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot print pattern');

        $fakePattern = new class implements \Superscript\Axiom\Dsl\Ast\Patterns\PatternNode {
            public function toArray(): array
            {
                return [];
            }

            public static function fromArray(array $data): static
            {
                return new static();
            }
        };

        $match = new MatchExpressionNode(
            subject: new IdentifierNode('x'),
            arms: [new MatchArmNode($fakePattern, new LiteralNode(1, '1'))],
        );

        $this->printer()->printExpression($match);
    }

    #[Test]
    public function it_prints_match_with_only_wildcard_as_match_block(): void
    {
        $node = new MatchExpressionNode(
            subject: null,
            arms: [
                new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0, '0')),
            ],
        );

        $this->assertSame(
            "match {\n    _ => 0,\n}",
            $this->printer()->printExpression($node),
        );
    }

    #[Test]
    public function it_prints_subjectless_without_wildcard_as_match_block(): void
    {
        $node = new MatchExpressionNode(
            subject: null,
            arms: [
                new MatchArmNode(new ExpressionPatternNode(new LiteralNode(true, 'true')), new LiteralNode(1, '1')),
                new MatchArmNode(new ExpressionPatternNode(new LiteralNode(false, 'false')), new LiteralNode(0, '0')),
            ],
        );

        $this->assertSame(
            "match {\n    true => 1,\n    false => 0,\n}",
            $this->printer()->printExpression($node),
        );
    }

    #[Test]
    public function it_prints_subjectless_with_mixed_patterns_as_match_block(): void
    {
        $node = new MatchExpressionNode(
            subject: null,
            arms: [
                new MatchArmNode(new LiteralPatternNode('a', '"a"'), new LiteralNode(1, '1')),
                new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0, '0')),
            ],
        );

        $this->assertSame(
            "match {\n    \"a\" => 1,\n    _ => 0,\n}",
            $this->printer()->printExpression($node),
        );
    }

    #[Test]
    public function it_prints_schema_inside_namespace_with_correct_indentation(): void
    {
        $program = new ProgramNode([
            new NamespaceDeclarationNode('outer', [
                new SchemaDeclarationNode('Inner', [
                    new SymbolDeclarationNode('x', new TypeAnnotationNode('number'), new LiteralNode(1, '1')),
                ]),
            ]),
        ]);

        $this->assertSame(
            "namespace outer {\n    schema Inner {\n        x: number = 1\n    }\n}",
            $this->printer()->print($program),
        );
    }

    #[Test]
    public function it_prints_match_inside_schema_with_correct_indentation(): void
    {
        $node = new SchemaDeclarationNode('Calc', [
            new SymbolDeclarationNode('x', new TypeAnnotationNode('number'), new MatchExpressionNode(
                subject: new IdentifierNode('tier'),
                arms: [
                    new MatchArmNode(new LiteralPatternNode('a', '"a"'), new LiteralNode(1, '1')),
                    new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0, '0')),
                ],
            )),
        ]);

        $this->assertSame(
            "schema Calc {\n    x: number = match tier {\n        \"a\" => 1,\n        _ => 0,\n    }\n}",
            $this->printer()->printNode($node),
        );
    }

    #[Test]
    public function it_prints_if_then_else_inside_schema_with_correct_indentation(): void
    {
        $node = new SchemaDeclarationNode('Calc', [
            new SymbolDeclarationNode('x', new TypeAnnotationNode('number'), new MatchExpressionNode(
                subject: null,
                arms: [
                    new MatchArmNode(
                        new ExpressionPatternNode(new InfixExpressionNode(new IdentifierNode('a'), '>', new LiteralNode(0, '0'))),
                        new IdentifierNode('a'),
                    ),
                    new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0, '0')),
                ],
            )),
        ]);

        $this->assertSame(
            "schema Calc {\n    x: number = if a > 0\n        then a\n    else 0\n}",
            $this->printer()->printNode($node),
        );
    }

    #[Test]
    public function it_prints_namespace_inside_schema_with_correct_indentation(): void
    {
        $program = new ProgramNode([
            new SchemaDeclarationNode('Outer', [
                new NamespaceDeclarationNode('inner', [
                    new SymbolDeclarationNode('x', new TypeAnnotationNode('number'), new LiteralNode(1, '1')),
                ]),
            ]),
        ]);

        $this->assertSame(
            "schema Outer {\n    namespace inner {\n        x: number = 1\n    }\n}",
            $this->printer()->print($program),
        );
    }

    #[Test]
    public function it_prints_chained_else_if_inside_schema_with_correct_indentation(): void
    {
        $node = new SchemaDeclarationNode('Calc', [
            new SymbolDeclarationNode('x', new TypeAnnotationNode('number'), new MatchExpressionNode(
                subject: null,
                arms: [
                    new MatchArmNode(
                        new ExpressionPatternNode(new InfixExpressionNode(new IdentifierNode('a'), '==', new LiteralNode(1, '1'))),
                        new LiteralNode(10, '10'),
                    ),
                    new MatchArmNode(
                        new ExpressionPatternNode(new InfixExpressionNode(new IdentifierNode('a'), '==', new LiteralNode(2, '2'))),
                        new LiteralNode(20, '20'),
                    ),
                    new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0, '0')),
                ],
            )),
        ]);

        $this->assertSame(
            "schema Calc {\n    x: number = if a == 1\n        then 10\n    else if a == 2\n        then 20\n    else 0\n}",
            $this->printer()->printNode($node),
        );
    }

    #[Test]
    public function it_prints_3_expression_arms_plus_wildcard_as_if_then_else(): void
    {
        $node = new MatchExpressionNode(
            subject: null,
            arms: [
                new MatchArmNode(
                    new ExpressionPatternNode(new InfixExpressionNode(new IdentifierNode('a'), '==', new LiteralNode(1, '1'))),
                    new LiteralNode(10, '10'),
                ),
                new MatchArmNode(
                    new ExpressionPatternNode(new InfixExpressionNode(new IdentifierNode('a'), '==', new LiteralNode(2, '2'))),
                    new LiteralNode(20, '20'),
                ),
                new MatchArmNode(
                    new ExpressionPatternNode(new InfixExpressionNode(new IdentifierNode('a'), '==', new LiteralNode(3, '3'))),
                    new LiteralNode(30, '30'),
                ),
                new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0, '0')),
            ],
        );

        $this->assertSame(
            "if a == 1\n    then 10\nelse if a == 2\n    then 20\nelse if a == 3\n    then 30\nelse 0",
            $this->printer()->printExpression($node),
        );
    }
}
