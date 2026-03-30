<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl\Ast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dsl\Ast\Expressions\CallExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\DictLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNodeFactory;
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
use Superscript\Axiom\Dsl\Ast\Location;
use Superscript\Axiom\Dsl\Ast\Node;
use Superscript\Axiom\Dsl\Ast\Patterns\ExpressionPatternNode;
use Superscript\Axiom\Dsl\Ast\Patterns\LiteralPatternNode;
use Superscript\Axiom\Dsl\Ast\Patterns\PatternNodeFactory;
use Superscript\Axiom\Dsl\Ast\Patterns\WildcardPatternNode;
use Superscript\Axiom\Dsl\Ast\ProgramNode;
use Superscript\Axiom\Dsl\Ast\Statements\AssertStatementNode;
use Superscript\Axiom\Dsl\Ast\Statements\InputDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\NamespaceDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\NodeFactory;
use Superscript\Axiom\Dsl\Ast\Statements\SchemaDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\SymbolDeclarationNode;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;

#[CoversClass(LiteralNode::class)]
#[CoversClass(IdentifierNode::class)]
#[CoversClass(MemberExpressionNode::class)]
#[CoversClass(IndexExpressionNode::class)]
#[CoversClass(InfixExpressionNode::class)]
#[CoversClass(UnaryExpressionNode::class)]
#[CoversClass(CoercionExpressionNode::class)]
#[CoversClass(MatchExpressionNode::class)]
#[CoversClass(MatchArmNode::class)]
#[CoversClass(CallExpressionNode::class)]
#[CoversClass(PipeExpressionNode::class)]
#[CoversClass(LambdaNode::class)]
#[CoversClass(ListLiteralNode::class)]
#[CoversClass(DictLiteralNode::class)]
#[CoversClass(WildcardPatternNode::class)]
#[CoversClass(LiteralPatternNode::class)]
#[CoversClass(ExpressionPatternNode::class)]
#[CoversClass(TypeAnnotationNode::class)]
#[CoversClass(ProgramNode::class)]
#[CoversClass(SymbolDeclarationNode::class)]
#[CoversClass(NamespaceDeclarationNode::class)]
#[CoversClass(SchemaDeclarationNode::class)]
#[CoversClass(InputDeclarationNode::class)]
#[CoversClass(AssertStatementNode::class)]
#[CoversClass(Location::class)]
#[CoversClass(ExprNodeFactory::class)]
#[CoversClass(PatternNodeFactory::class)]
#[CoversClass(NodeFactory::class)]
class AstNodeTest extends TestCase
{
    #[Test]
    public function location_round_trips(): void
    {
        $loc = new Location(1, 0, 1, 10);
        $array = $loc->toArray();
        $restored = Location::fromArray($array);

        $this->assertSame(1, $restored->startLine);
        $this->assertSame(0, $restored->startCol);
        $this->assertSame(1, $restored->endLine);
        $this->assertSame(10, $restored->endCol);
    }

    #[Test]
    public function literal_node_round_trips(): void
    {
        $node = new LiteralNode(42, '42', new Location(1, 0, 1, 2));
        $this->assertRoundTrip($node, 'Literal');
    }

    #[Test]
    public function literal_node_without_location(): void
    {
        $node = new LiteralNode('hello', '"hello"');
        $array = $node->toArray();

        $this->assertArrayNotHasKey('loc', $array);
        $restored = LiteralNode::fromArray($array);
        $this->assertNull($restored->loc);
        $this->assertSame('hello', $restored->value);
        $this->assertSame('"hello"', $restored->raw);
    }

    #[Test]
    public function identifier_node_round_trips(): void
    {
        $node = new IdentifierNode('foo');
        $this->assertRoundTrip($node, 'Identifier');
    }

    #[Test]
    public function member_expression_node_round_trips(): void
    {
        $node = new MemberExpressionNode(
            new IdentifierNode('obj'),
            'prop',
        );
        $this->assertRoundTrip($node, 'MemberExpression');
    }

    #[Test]
    public function index_expression_node_round_trips(): void
    {
        $node = new IndexExpressionNode(
            new IdentifierNode('arr'),
            new LiteralNode(0, '0'),
        );
        $this->assertRoundTrip($node, 'IndexExpression');
    }

    #[Test]
    public function infix_expression_node_round_trips(): void
    {
        $node = new InfixExpressionNode(
            new LiteralNode(1, '1'),
            '+',
            new LiteralNode(2, '2'),
        );
        $this->assertRoundTrip($node, 'InfixExpression');
    }

    #[Test]
    public function unary_expression_node_round_trips(): void
    {
        $node = new UnaryExpressionNode('!', new LiteralNode(true, 'true'));
        $this->assertRoundTrip($node, 'UnaryExpression');
    }

    #[Test]
    public function coercion_expression_node_round_trips(): void
    {
        $node = new CoercionExpressionNode(
            new IdentifierNode('x'),
            new TypeAnnotationNode('number'),
        );
        $this->assertRoundTrip($node, 'CoercionExpression');
    }

    #[Test]
    public function match_expression_node_round_trips(): void
    {
        $node = new MatchExpressionNode(
            new IdentifierNode('x'),
            [
                new MatchArmNode(
                    new LiteralPatternNode(1, '1'),
                    new LiteralNode('one', '"one"'),
                ),
                new MatchArmNode(
                    new WildcardPatternNode(),
                    new LiteralNode('other', '"other"'),
                ),
            ],
        );
        $this->assertRoundTrip($node, 'MatchExpression');
    }

    #[Test]
    public function match_expression_with_null_subject_round_trips(): void
    {
        $node = new MatchExpressionNode(
            null,
            [new MatchArmNode(new WildcardPatternNode(), new LiteralNode(1, '1'))],
        );
        $array = $node->toArray();
        $this->assertNull($array['subject']);

        $restored = MatchExpressionNode::fromArray($array);
        $this->assertNull($restored->subject);
    }

    #[Test]
    public function call_expression_node_round_trips(): void
    {
        $node = new CallExpressionNode(
            'abs',
            [new LiteralNode(-1, '-1')],
            ['precision' => new LiteralNode(2, '2')],
        );
        $this->assertRoundTrip($node, 'CallExpression');
    }

    #[Test]
    public function pipe_expression_node_round_trips(): void
    {
        $node = new PipeExpressionNode(
            new LiteralNode(42, '42'),
            new IdentifierNode('double'),
        );
        $this->assertRoundTrip($node, 'PipeExpression');
    }

    #[Test]
    public function lambda_node_round_trips(): void
    {
        $node = new LambdaNode(
            ['x', 'y'],
            new InfixExpressionNode(
                new IdentifierNode('x'),
                '+',
                new IdentifierNode('y'),
            ),
        );
        $this->assertRoundTrip($node, 'Lambda');
    }

    #[Test]
    public function list_literal_node_round_trips(): void
    {
        $node = new ListLiteralNode([
            new LiteralNode(1, '1'),
            new LiteralNode(2, '2'),
        ]);
        $this->assertRoundTrip($node, 'ListLiteral');
    }

    #[Test]
    public function dict_literal_node_round_trips(): void
    {
        $node = new DictLiteralNode([
            ['key' => new LiteralNode('a', '"a"'), 'value' => new LiteralNode(1, '1')],
        ]);
        $this->assertRoundTrip($node, 'DictLiteral');
    }

    #[Test]
    public function wildcard_pattern_node_round_trips(): void
    {
        $node = new WildcardPatternNode();
        $array = $node->toArray();
        $this->assertSame('WildcardPattern', $array['type']);

        $restored = WildcardPatternNode::fromArray($array);
        $this->assertNull($restored->loc);
    }

    #[Test]
    public function literal_pattern_node_round_trips(): void
    {
        $node = new LiteralPatternNode(42, '42');
        $array = $node->toArray();
        $this->assertSame('LiteralPattern', $array['type']);

        $restored = LiteralPatternNode::fromArray($array);
        $this->assertSame(42, $restored->value);
        $this->assertSame('42', $restored->raw);
    }

    #[Test]
    public function expression_pattern_node_round_trips(): void
    {
        $node = new ExpressionPatternNode(new IdentifierNode('x'));
        $array = $node->toArray();
        $this->assertSame('ExpressionPattern', $array['type']);

        $restored = ExpressionPatternNode::fromArray($array);
        $this->assertInstanceOf(IdentifierNode::class, $restored->expression);
    }

    #[Test]
    public function type_annotation_node_round_trips(): void
    {
        $node = new TypeAnnotationNode('list', [new TypeAnnotationNode('number')]);
        $array = $node->toArray();
        $this->assertSame('TypeAnnotation', $array['type']);

        $restored = TypeAnnotationNode::fromArray($array);
        $this->assertSame('list', $restored->keyword);
        $this->assertCount(1, $restored->args);
        $this->assertSame('number', $restored->args[0]->keyword);
    }

    #[Test]
    public function symbol_declaration_node_round_trips(): void
    {
        $node = new SymbolDeclarationNode(
            'price',
            new TypeAnnotationNode('number'),
            new LiteralNode(100, '100'),
            'public',
        );
        $array = $node->toArray();
        $this->assertSame('SymbolDeclaration', $array['type']);

        $restored = SymbolDeclarationNode::fromArray($array);
        $this->assertSame('price', $restored->name);
        $this->assertSame('number', $restored->type->keyword);
        $this->assertSame('public', $restored->visibility);
    }

    #[Test]
    public function namespace_declaration_node_round_trips(): void
    {
        $inner = new SymbolDeclarationNode(
            'x',
            new TypeAnnotationNode('number'),
            new LiteralNode(1, '1'),
        );
        $node = new NamespaceDeclarationNode('math', [$inner]);
        $array = $node->toArray();
        $this->assertSame('NamespaceDeclaration', $array['type']);

        $restored = NamespaceDeclarationNode::fromArray($array);
        $this->assertSame('math', $restored->name);
        $this->assertCount(1, $restored->body);
    }

    #[Test]
    public function schema_declaration_node_round_trips(): void
    {
        $node = new SchemaDeclarationNode('User', [
            'name' => new TypeAnnotationNode('string'),
            'age' => new TypeAnnotationNode('number'),
        ]);
        $array = $node->toArray();
        $this->assertSame('SchemaDeclaration', $array['type']);

        $restored = SchemaDeclarationNode::fromArray($array);
        $this->assertSame('User', $restored->name);
        $this->assertCount(2, $restored->fields);
        $this->assertSame('string', $restored->fields['name']->keyword);
    }

    #[Test]
    public function input_declaration_node_round_trips(): void
    {
        $node = new InputDeclarationNode('salary', new TypeAnnotationNode('number'));
        $array = $node->toArray();
        $this->assertSame('InputDeclaration', $array['type']);

        $restored = InputDeclarationNode::fromArray($array);
        $this->assertSame('salary', $restored->name);
        $this->assertSame('number', $restored->type->keyword);
    }

    #[Test]
    public function assert_statement_node_round_trips(): void
    {
        $node = new AssertStatementNode(
            new InfixExpressionNode(new IdentifierNode('x'), '>', new LiteralNode(0, '0')),
            'x must be positive',
        );
        $array = $node->toArray();
        $this->assertSame('AssertStatement', $array['type']);

        $restored = AssertStatementNode::fromArray($array);
        $this->assertSame('x must be positive', $restored->message);
    }

    #[Test]
    public function assert_statement_without_message(): void
    {
        $node = new AssertStatementNode(new LiteralNode(true, 'true'));
        $array = $node->toArray();
        $this->assertArrayNotHasKey('message', $array);

        $restored = AssertStatementNode::fromArray($array);
        $this->assertNull($restored->message);
    }

    #[Test]
    public function program_node_round_trips(): void
    {
        $program = new ProgramNode([
            new SymbolDeclarationNode(
                'total',
                new TypeAnnotationNode('number'),
                new InfixExpressionNode(
                    new IdentifierNode('a'),
                    '+',
                    new IdentifierNode('b'),
                ),
            ),
        ], '1.0');

        $array = $program->toArray();
        $this->assertSame('Program', $array['type']);
        $this->assertSame('1.0', $array['version']);

        $restored = ProgramNode::fromArray($array);
        $this->assertSame('1.0', $restored->version);
        $this->assertCount(1, $restored->body);
    }

    #[Test]
    public function json_serialization_round_trip(): void
    {
        $program = new ProgramNode([
            new SymbolDeclarationNode(
                'result',
                new TypeAnnotationNode('number'),
                new MatchExpressionNode(
                    new IdentifierNode('status'),
                    [
                        new MatchArmNode(
                            new LiteralPatternNode('active', '"active"'),
                            new LiteralNode(1, '1'),
                        ),
                        new MatchArmNode(
                            new WildcardPatternNode(),
                            new LiteralNode(0, '0'),
                        ),
                    ],
                ),
            ),
        ]);

        $json = json_encode($program->toArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $restored = ProgramNode::fromArray($decoded);

        $this->assertSame('1.0', $restored->version);
        $this->assertCount(1, $restored->body);
        $this->assertSame($program->toArray(), $restored->toArray());
    }

    #[Test]
    public function expr_node_factory_throws_for_unknown_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown expression node type: Bogus');

        ExprNodeFactory::fromArray(['type' => 'Bogus']);
    }

    #[Test]
    public function pattern_node_factory_throws_for_unknown_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown pattern node type: Bogus');

        PatternNodeFactory::fromArray(['type' => 'Bogus']);
    }

    #[Test]
    public function statement_node_factory_throws_for_unknown_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown statement node type: Bogus');

        NodeFactory::fromArray(['type' => 'Bogus']);
    }

    #[Test]
    public function match_arm_node_round_trips(): void
    {
        $node = new MatchArmNode(
            new LiteralPatternNode(1, '1'),
            new LiteralNode('one', '"one"'),
            new Location(1, 0, 1, 10),
        );
        $array = $node->toArray();
        $this->assertSame('MatchArm', $array['type']);

        $restored = MatchArmNode::fromArray($array);
        $this->assertInstanceOf(LiteralPatternNode::class, $restored->pattern);
        $this->assertInstanceOf(LiteralNode::class, $restored->expression);
        $this->assertNotNull($restored->loc);
    }

    /**
     * @param array<string, mixed> $array
     */
    private function assertRoundTrip(Node $node, string $expectedType): void
    {
        $array = $node->toArray();
        $this->assertSame($expectedType, $array['type']);

        $restored = match (true) {
            $node instanceof LiteralNode => LiteralNode::fromArray($array),
            $node instanceof IdentifierNode => IdentifierNode::fromArray($array),
            $node instanceof MemberExpressionNode => MemberExpressionNode::fromArray($array),
            $node instanceof IndexExpressionNode => IndexExpressionNode::fromArray($array),
            $node instanceof InfixExpressionNode => InfixExpressionNode::fromArray($array),
            $node instanceof UnaryExpressionNode => UnaryExpressionNode::fromArray($array),
            $node instanceof CoercionExpressionNode => CoercionExpressionNode::fromArray($array),
            $node instanceof MatchExpressionNode => MatchExpressionNode::fromArray($array),
            $node instanceof CallExpressionNode => CallExpressionNode::fromArray($array),
            $node instanceof PipeExpressionNode => PipeExpressionNode::fromArray($array),
            $node instanceof LambdaNode => LambdaNode::fromArray($array),
            $node instanceof ListLiteralNode => ListLiteralNode::fromArray($array),
            $node instanceof DictLiteralNode => DictLiteralNode::fromArray($array),
            default => throw new \RuntimeException('Unknown node type'),
        };

        $this->assertSame($array, $restored->toArray());
    }
}
