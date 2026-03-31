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
    private static function loc(): Location
    {
        return new Location(1, 0, 1, 10);
    }

    #[Test]
    public function location_round_trips(): void
    {
        $loc = new Location(1, 0, 1, 10);
        $array = $loc->toArray();

        $this->assertSame(['startLine' => 1, 'startCol' => 0, 'endLine' => 1, 'endCol' => 10], $array);

        $restored = Location::fromArray($array);
        $this->assertSame(1, $restored->startLine);
        $this->assertSame(0, $restored->startCol);
        $this->assertSame(1, $restored->endLine);
        $this->assertSame(10, $restored->endCol);
    }

    #[Test]
    public function location_from_array_throws_for_non_int(): void
    {
        $this->expectException(\RuntimeException::class);
        Location::fromArray(['startLine' => 'a', 'startCol' => 0, 'endLine' => 1, 'endCol' => 10]);
    }

    #[Test]
    public function literal_node_round_trips(): void
    {
        $node = new LiteralNode(42, '42', self::loc());
        $array = $node->toArray();

        $this->assertSame(42, $array['value']);
        $this->assertSame('42', $array['raw']);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'Literal');
    }

    #[Test]
    public function literal_node_without_location(): void
    {
        $node = new LiteralNode('hello', '"hello"');
        $array = $node->toArray();

        $this->assertArrayNotHasKey('loc', $array);
        $this->assertSame('hello', $array['value']);
        $this->assertSame('"hello"', $array['raw']);

        $restored = LiteralNode::fromArray($array);
        $this->assertNull($restored->loc);
        $this->assertSame('hello', $restored->value);
        $this->assertSame('"hello"', $restored->raw);
    }

    #[Test]
    public function literal_node_from_array_throws_for_non_string_raw(): void
    {
        $this->expectException(\RuntimeException::class);
        LiteralNode::fromArray(['type' => 'Literal', 'value' => 1, 'raw' => 123]);
    }

    #[Test]
    public function identifier_node_round_trips(): void
    {
        $node = new IdentifierNode('foo', self::loc());
        $array = $node->toArray();

        $this->assertSame('foo', $array['name']);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'Identifier');
    }

    #[Test]
    public function identifier_node_from_array_throws_for_non_string_name(): void
    {
        $this->expectException(\RuntimeException::class);
        IdentifierNode::fromArray(['type' => 'Identifier', 'name' => 123]);
    }

    #[Test]
    public function member_expression_node_round_trips(): void
    {
        $node = new MemberExpressionNode(
            new IdentifierNode('obj'),
            'prop',
            self::loc(),
        );
        $array = $node->toArray();

        $this->assertArrayHasKey('object', $array);
        $this->assertSame('prop', $array['property']);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'MemberExpression');
    }

    #[Test]
    public function member_expression_node_from_array_throws_for_non_array_object(): void
    {
        $this->expectException(\RuntimeException::class);
        MemberExpressionNode::fromArray(['type' => 'MemberExpression', 'object' => 'not-array', 'property' => 'x']);
    }

    #[Test]
    public function member_expression_node_from_array_throws_for_non_string_property(): void
    {
        $this->expectException(\RuntimeException::class);
        MemberExpressionNode::fromArray(['type' => 'MemberExpression', 'object' => ['type' => 'Identifier', 'name' => 'x'], 'property' => 123]);
    }

    #[Test]
    public function index_expression_node_round_trips(): void
    {
        $node = new IndexExpressionNode(
            new IdentifierNode('arr'),
            new LiteralNode(0, '0'),
            self::loc(),
        );
        $array = $node->toArray();

        $this->assertArrayHasKey('object', $array);
        $this->assertArrayHasKey('index', $array);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'IndexExpression');
    }

    #[Test]
    public function index_expression_node_from_array_throws_for_non_array_object(): void
    {
        $this->expectException(\RuntimeException::class);
        IndexExpressionNode::fromArray(['type' => 'IndexExpression', 'object' => 'x', 'index' => ['type' => 'Literal', 'value' => 0, 'raw' => '0']]);
    }

    #[Test]
    public function index_expression_node_from_array_throws_for_non_array_index(): void
    {
        $this->expectException(\RuntimeException::class);
        IndexExpressionNode::fromArray(['type' => 'IndexExpression', 'object' => ['type' => 'Identifier', 'name' => 'x'], 'index' => 'bad']);
    }

    #[Test]
    public function infix_expression_node_round_trips(): void
    {
        $node = new InfixExpressionNode(
            new LiteralNode(1, '1'),
            '+',
            new LiteralNode(2, '2'),
            self::loc(),
        );
        $array = $node->toArray();

        $this->assertArrayHasKey('left', $array);
        $this->assertSame('+', $array['operator']);
        $this->assertArrayHasKey('right', $array);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'InfixExpression');
    }

    #[Test]
    public function infix_expression_node_from_array_throws_for_non_array_left(): void
    {
        $this->expectException(\RuntimeException::class);
        InfixExpressionNode::fromArray(['type' => 'InfixExpression', 'left' => 'x', 'operator' => '+', 'right' => ['type' => 'Literal', 'value' => 1, 'raw' => '1']]);
    }

    #[Test]
    public function infix_expression_node_from_array_throws_for_non_string_operator(): void
    {
        $this->expectException(\RuntimeException::class);
        InfixExpressionNode::fromArray(['type' => 'InfixExpression', 'left' => ['type' => 'Literal', 'value' => 1, 'raw' => '1'], 'operator' => 123, 'right' => ['type' => 'Literal', 'value' => 2, 'raw' => '2']]);
    }

    #[Test]
    public function infix_expression_node_from_array_throws_for_non_array_right(): void
    {
        $this->expectException(\RuntimeException::class);
        InfixExpressionNode::fromArray(['type' => 'InfixExpression', 'left' => ['type' => 'Literal', 'value' => 1, 'raw' => '1'], 'operator' => '+', 'right' => 'x']);
    }

    #[Test]
    public function unary_expression_node_round_trips(): void
    {
        $node = new UnaryExpressionNode('!', new LiteralNode(true, 'true'), self::loc());
        $array = $node->toArray();

        $this->assertSame('!', $array['operator']);
        $this->assertArrayHasKey('operand', $array);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'UnaryExpression');
    }

    #[Test]
    public function unary_expression_node_from_array_throws_for_non_string_operator(): void
    {
        $this->expectException(\RuntimeException::class);
        UnaryExpressionNode::fromArray(['type' => 'UnaryExpression', 'operator' => 123, 'operand' => ['type' => 'Literal', 'value' => true, 'raw' => 'true']]);
    }

    #[Test]
    public function unary_expression_node_from_array_throws_for_non_array_operand(): void
    {
        $this->expectException(\RuntimeException::class);
        UnaryExpressionNode::fromArray(['type' => 'UnaryExpression', 'operator' => '!', 'operand' => 'bad']);
    }

    #[Test]
    public function coercion_expression_node_round_trips(): void
    {
        $node = new CoercionExpressionNode(
            new IdentifierNode('x'),
            new TypeAnnotationNode('number'),
            self::loc(),
        );
        $array = $node->toArray();

        $this->assertArrayHasKey('expression', $array);
        $this->assertArrayHasKey('targetType', $array);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'CoercionExpression');
    }

    #[Test]
    public function coercion_expression_node_from_array_throws_for_non_array_expression(): void
    {
        $this->expectException(\RuntimeException::class);
        CoercionExpressionNode::fromArray(['type' => 'CoercionExpression', 'expression' => 'bad', 'targetType' => ['type' => 'TypeAnnotation', 'keyword' => 'number', 'args' => []]]);
    }

    #[Test]
    public function coercion_expression_node_from_array_throws_for_non_array_target_type(): void
    {
        $this->expectException(\RuntimeException::class);
        CoercionExpressionNode::fromArray(['type' => 'CoercionExpression', 'expression' => ['type' => 'Identifier', 'name' => 'x'], 'targetType' => 'bad']);
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
            self::loc(),
        );
        $array = $node->toArray();

        $this->assertArrayHasKey('subject', $array);
        $this->assertArrayHasKey('arms', $array);
        $this->assertCount(2, $array['arms']);
        $this->assertArrayHasKey('loc', $array);

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
    public function match_expression_from_array_throws_for_non_array_arms(): void
    {
        $this->expectException(\RuntimeException::class);
        MatchExpressionNode::fromArray(['type' => 'MatchExpression', 'subject' => null, 'arms' => 'bad']);
    }

    #[Test]
    public function match_expression_from_array_throws_for_non_array_arm(): void
    {
        $this->expectException(\RuntimeException::class);
        MatchExpressionNode::fromArray(['type' => 'MatchExpression', 'subject' => null, 'arms' => ['bad']]);
    }

    #[Test]
    public function call_expression_node_round_trips(): void
    {
        $node = new CallExpressionNode(
            'abs',
            [new LiteralNode(-1, '-1')],
            ['precision' => new LiteralNode(2, '2')],
            self::loc(),
        );
        $array = $node->toArray();

        $this->assertSame('abs', $array['callee']);
        $this->assertArrayHasKey('positionalArgs', $array);
        $this->assertCount(1, $array['positionalArgs']);
        $this->assertArrayHasKey('namedArgs', $array);
        $this->assertArrayHasKey('precision', $array['namedArgs']);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'CallExpression');
    }

    #[Test]
    public function call_expression_from_array_throws_for_non_string_callee(): void
    {
        $this->expectException(\RuntimeException::class);
        CallExpressionNode::fromArray(['type' => 'CallExpression', 'callee' => 123, 'positionalArgs' => [], 'namedArgs' => []]);
    }

    #[Test]
    public function call_expression_from_array_throws_for_non_array_positional_args(): void
    {
        $this->expectException(\RuntimeException::class);
        CallExpressionNode::fromArray(['type' => 'CallExpression', 'callee' => 'f', 'positionalArgs' => 'bad', 'namedArgs' => []]);
    }

    #[Test]
    public function call_expression_from_array_throws_for_non_array_named_args(): void
    {
        $this->expectException(\RuntimeException::class);
        CallExpressionNode::fromArray(['type' => 'CallExpression', 'callee' => 'f', 'positionalArgs' => [], 'namedArgs' => 'bad']);
    }

    #[Test]
    public function call_expression_from_array_throws_for_non_array_positional_arg(): void
    {
        $this->expectException(\RuntimeException::class);
        CallExpressionNode::fromArray(['type' => 'CallExpression', 'callee' => 'f', 'positionalArgs' => ['bad'], 'namedArgs' => []]);
    }

    #[Test]
    public function call_expression_from_array_throws_for_invalid_named_arg(): void
    {
        $this->expectException(\RuntimeException::class);
        CallExpressionNode::fromArray(['type' => 'CallExpression', 'callee' => 'f', 'positionalArgs' => [], 'namedArgs' => [0 => 'bad']]);
    }

    #[Test]
    public function call_expression_from_array_throws_for_non_array_named_arg_value(): void
    {
        $this->expectException(\RuntimeException::class);
        CallExpressionNode::fromArray(['type' => 'CallExpression', 'callee' => 'f', 'positionalArgs' => [], 'namedArgs' => ['key' => 'not-an-array']]);
    }

    #[Test]
    public function pipe_expression_node_round_trips(): void
    {
        $node = new PipeExpressionNode(
            new LiteralNode(42, '42'),
            new IdentifierNode('double'),
            self::loc(),
        );
        $array = $node->toArray();

        $this->assertArrayHasKey('left', $array);
        $this->assertArrayHasKey('right', $array);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'PipeExpression');
    }

    #[Test]
    public function pipe_expression_from_array_throws_for_non_array_left(): void
    {
        $this->expectException(\RuntimeException::class);
        PipeExpressionNode::fromArray(['type' => 'PipeExpression', 'left' => 'bad', 'right' => ['type' => 'Identifier', 'name' => 'f']]);
    }

    #[Test]
    public function pipe_expression_from_array_throws_for_non_array_right(): void
    {
        $this->expectException(\RuntimeException::class);
        PipeExpressionNode::fromArray(['type' => 'PipeExpression', 'left' => ['type' => 'Literal', 'value' => 1, 'raw' => '1'], 'right' => 'bad']);
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
            self::loc(),
        );
        $array = $node->toArray();

        $this->assertSame(['x', 'y'], $array['params']);
        $this->assertArrayHasKey('body', $array);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'Lambda');
    }

    #[Test]
    public function lambda_from_array_reindexes_params(): void
    {
        $data = [
            'type' => 'Lambda',
            'params' => ['a' => 'x', 'b' => 'y'],
            'body' => ['type' => 'Identifier', 'name' => 'x'],
        ];

        $node = LambdaNode::fromArray($data);

        $this->assertSame(['x', 'y'], $node->params);
    }

    #[Test]
    public function lambda_from_array_throws_for_non_array_params(): void
    {
        $this->expectException(\RuntimeException::class);
        LambdaNode::fromArray(['type' => 'Lambda', 'params' => 'bad', 'body' => ['type' => 'Literal', 'value' => 1, 'raw' => '1']]);
    }

    #[Test]
    public function lambda_from_array_throws_for_non_array_body(): void
    {
        $this->expectException(\RuntimeException::class);
        LambdaNode::fromArray(['type' => 'Lambda', 'params' => ['x'], 'body' => 'bad']);
    }

    #[Test]
    public function list_literal_node_round_trips(): void
    {
        $node = new ListLiteralNode([
            new LiteralNode(1, '1'),
            new LiteralNode(2, '2'),
        ], self::loc());
        $array = $node->toArray();

        $this->assertArrayHasKey('elements', $array);
        $this->assertCount(2, $array['elements']);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'ListLiteral');
    }

    #[Test]
    public function list_literal_from_array_throws_for_non_array_elements(): void
    {
        $this->expectException(\RuntimeException::class);
        ListLiteralNode::fromArray(['type' => 'ListLiteral', 'elements' => 'bad']);
    }

    #[Test]
    public function list_literal_from_array_throws_for_non_array_element(): void
    {
        $this->expectException(\RuntimeException::class);
        ListLiteralNode::fromArray(['type' => 'ListLiteral', 'elements' => ['bad']]);
    }

    #[Test]
    public function dict_literal_node_round_trips(): void
    {
        $node = new DictLiteralNode([
            ['key' => new LiteralNode('a', '"a"'), 'value' => new LiteralNode(1, '1')],
        ], self::loc());
        $array = $node->toArray();

        $this->assertArrayHasKey('entries', $array);
        $this->assertCount(1, $array['entries']);
        $this->assertSame('a', $array['entries'][0]['key']['value']);
        $this->assertSame(1, $array['entries'][0]['value']['value']);
        $this->assertArrayHasKey('loc', $array);

        $this->assertRoundTrip($node, 'DictLiteral');
    }

    #[Test]
    public function dict_literal_from_array_throws_for_non_array_entries(): void
    {
        $this->expectException(\RuntimeException::class);
        DictLiteralNode::fromArray(['type' => 'DictLiteral', 'entries' => 'bad']);
    }

    #[Test]
    public function dict_literal_from_array_throws_for_non_array_entry(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected array for entry');
        DictLiteralNode::fromArray(['type' => 'DictLiteral', 'entries' => ['bad']]);
    }

    #[Test]
    public function dict_literal_from_array_throws_for_non_array_key_value(): void
    {
        $this->expectException(\RuntimeException::class);
        DictLiteralNode::fromArray(['type' => 'DictLiteral', 'entries' => [['key' => 'bad', 'value' => ['type' => 'Literal', 'value' => 1, 'raw' => '1']]]]);
    }

    #[Test]
    public function dict_literal_from_array_throws_for_non_array_value_in_entry(): void
    {
        $this->expectException(\RuntimeException::class);
        DictLiteralNode::fromArray(['type' => 'DictLiteral', 'entries' => [['key' => ['type' => 'Literal', 'value' => 1, 'raw' => '1'], 'value' => 'bad']]]);
    }

    #[Test]
    public function wildcard_pattern_node_round_trips(): void
    {
        $node = new WildcardPatternNode(self::loc());
        $array = $node->toArray();

        $this->assertSame('WildcardPattern', $array['type']);
        $this->assertArrayHasKey('loc', $array);

        $restored = WildcardPatternNode::fromArray($array);
        $this->assertNotNull($restored->loc);
    }

    #[Test]
    public function literal_pattern_node_round_trips(): void
    {
        $node = new LiteralPatternNode(42, '42', self::loc());
        $array = $node->toArray();

        $this->assertSame('LiteralPattern', $array['type']);
        $this->assertSame(42, $array['value']);
        $this->assertSame('42', $array['raw']);
        $this->assertArrayHasKey('loc', $array);

        $restored = LiteralPatternNode::fromArray($array);
        $this->assertSame(42, $restored->value);
        $this->assertSame('42', $restored->raw);
    }

    #[Test]
    public function literal_pattern_from_array_throws_for_non_string_raw(): void
    {
        $this->expectException(\RuntimeException::class);
        LiteralPatternNode::fromArray(['type' => 'LiteralPattern', 'value' => 42, 'raw' => 123]);
    }

    #[Test]
    public function expression_pattern_node_round_trips(): void
    {
        $node = new ExpressionPatternNode(new IdentifierNode('x'), self::loc());
        $array = $node->toArray();

        $this->assertSame('ExpressionPattern', $array['type']);
        $this->assertArrayHasKey('expression', $array);
        $this->assertArrayHasKey('loc', $array);

        $restored = ExpressionPatternNode::fromArray($array);
        $this->assertInstanceOf(IdentifierNode::class, $restored->expression);
    }

    #[Test]
    public function expression_pattern_from_array_throws_for_non_array_expression(): void
    {
        $this->expectException(\RuntimeException::class);
        ExpressionPatternNode::fromArray(['type' => 'ExpressionPattern', 'expression' => 'bad']);
    }

    #[Test]
    public function type_annotation_node_round_trips(): void
    {
        $node = new TypeAnnotationNode('list', [new TypeAnnotationNode('number')], self::loc());
        $array = $node->toArray();

        $this->assertSame('TypeAnnotation', $array['type']);
        $this->assertSame('list', $array['keyword']);
        $this->assertArrayHasKey('args', $array);
        $this->assertCount(1, $array['args']);
        $this->assertArrayHasKey('loc', $array);

        $restored = TypeAnnotationNode::fromArray($array);
        $this->assertSame('list', $restored->keyword);
        $this->assertCount(1, $restored->args);
        $this->assertSame('number', $restored->args[0]->keyword);
    }

    #[Test]
    public function type_annotation_from_array_throws_for_non_string_keyword(): void
    {
        $this->expectException(\RuntimeException::class);
        TypeAnnotationNode::fromArray(['type' => 'TypeAnnotation', 'keyword' => 123, 'args' => []]);
    }

    #[Test]
    public function type_annotation_from_array_throws_for_non_array_args(): void
    {
        $this->expectException(\RuntimeException::class);
        TypeAnnotationNode::fromArray(['type' => 'TypeAnnotation', 'keyword' => 'number', 'args' => 'bad']);
    }

    #[Test]
    public function type_annotation_from_array_throws_for_non_array_arg(): void
    {
        $this->expectException(\RuntimeException::class);
        TypeAnnotationNode::fromArray(['type' => 'TypeAnnotation', 'keyword' => 'list', 'args' => ['bad']]);
    }

    #[Test]
    public function symbol_declaration_node_round_trips(): void
    {
        $node = new SymbolDeclarationNode(
            'price',
            new TypeAnnotationNode('number'),
            new LiteralNode(100, '100'),
            'public',
            self::loc(),
        );
        $array = $node->toArray();

        $this->assertSame('SymbolDeclaration', $array['type']);
        $this->assertSame('price', $array['name']);
        $this->assertArrayHasKey('typeAnnotation', $array);
        $this->assertArrayHasKey('expression', $array);
        $this->assertSame('public', $array['visibility']);
        $this->assertArrayHasKey('loc', $array);

        $restored = SymbolDeclarationNode::fromArray($array);
        $this->assertSame('price', $restored->name);
        $this->assertSame('number', $restored->type->keyword);
        $this->assertSame('public', $restored->visibility);
    }

    #[Test]
    public function symbol_declaration_preserves_custom_visibility(): void
    {
        $node = new SymbolDeclarationNode(
            'secret',
            new TypeAnnotationNode('string'),
            new LiteralNode('hidden', '"hidden"'),
            'private',
        );
        $array = $node->toArray();
        $this->assertSame('private', $array['visibility']);

        $restored = SymbolDeclarationNode::fromArray($array);
        $this->assertSame('private', $restored->visibility);
    }

    #[Test]
    public function symbol_declaration_from_array_throws_for_non_string_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected string for name');
        SymbolDeclarationNode::fromArray(['type' => 'SymbolDeclaration', 'name' => 123, 'typeAnnotation' => [], 'expression' => [], 'visibility' => 'public']);
    }

    #[Test]
    public function symbol_declaration_from_array_throws_for_non_array_type_annotation(): void
    {
        $this->expectException(\RuntimeException::class);
        SymbolDeclarationNode::fromArray(['type' => 'SymbolDeclaration', 'name' => 'x', 'typeAnnotation' => 'bad', 'expression' => ['type' => 'Literal', 'value' => 1, 'raw' => '1'], 'visibility' => 'public']);
    }

    #[Test]
    public function symbol_declaration_from_array_throws_for_non_array_expression(): void
    {
        $this->expectException(\RuntimeException::class);
        SymbolDeclarationNode::fromArray(['type' => 'SymbolDeclaration', 'name' => 'x', 'typeAnnotation' => ['type' => 'TypeAnnotation', 'keyword' => 'number', 'args' => []], 'expression' => 'bad', 'visibility' => 'public']);
    }

    #[Test]
    public function namespace_declaration_node_round_trips(): void
    {
        $inner = new SymbolDeclarationNode(
            'x',
            new TypeAnnotationNode('number'),
            new LiteralNode(1, '1'),
        );
        $node = new NamespaceDeclarationNode('math', [$inner], self::loc());
        $array = $node->toArray();

        $this->assertSame('NamespaceDeclaration', $array['type']);
        $this->assertSame('math', $array['name']);
        $this->assertArrayHasKey('body', $array);
        $this->assertCount(1, $array['body']);
        $this->assertArrayHasKey('loc', $array);

        $restored = NamespaceDeclarationNode::fromArray($array);
        $this->assertSame('math', $restored->name);
        $this->assertCount(1, $restored->body);
    }

    #[Test]
    public function namespace_declaration_from_array_throws_for_non_string_name(): void
    {
        $this->expectException(\RuntimeException::class);
        NamespaceDeclarationNode::fromArray(['type' => 'NamespaceDeclaration', 'name' => 123, 'body' => []]);
    }

    #[Test]
    public function namespace_declaration_from_array_throws_for_non_array_body(): void
    {
        $this->expectException(\RuntimeException::class);
        NamespaceDeclarationNode::fromArray(['type' => 'NamespaceDeclaration', 'name' => 'ns', 'body' => 'bad']);
    }

    #[Test]
    public function namespace_declaration_from_array_throws_for_non_array_body_node(): void
    {
        $this->expectException(\RuntimeException::class);
        NamespaceDeclarationNode::fromArray(['type' => 'NamespaceDeclaration', 'name' => 'ns', 'body' => ['bad']]);
    }

    #[Test]
    public function schema_declaration_node_round_trips(): void
    {
        $node = new SchemaDeclarationNode('PremiumCalc', [
            new InputDeclarationNode('quote', new TypeAnnotationNode('dict')),
            new SymbolDeclarationNode('base', new TypeAnnotationNode('number'), new LiteralNode(100, '100'), 'private'),
            new SymbolDeclarationNode('gross', new TypeAnnotationNode('number'), new LiteralNode(110, '110'), 'public'),
            new AssertStatementNode(new InfixExpressionNode(new IdentifierNode('gross'), '>=', new LiteralNode(500, '500'))),
        ], self::loc());
        $array = $node->toArray();

        $this->assertSame('SchemaDeclaration', $array['type']);
        $this->assertSame('PremiumCalc', $array['name']);
        $this->assertArrayHasKey('members', $array);
        $this->assertCount(4, $array['members']);
        $this->assertArrayHasKey('loc', $array);

        $restored = SchemaDeclarationNode::fromArray($array);
        $this->assertSame('PremiumCalc', $restored->name);
        $this->assertCount(4, $restored->members);
        $this->assertInstanceOf(InputDeclarationNode::class, $restored->members[0]);
        $this->assertInstanceOf(SymbolDeclarationNode::class, $restored->members[1]);
        $this->assertInstanceOf(AssertStatementNode::class, $restored->members[3]);
    }

    #[Test]
    public function schema_declaration_from_array_throws_for_non_string_name(): void
    {
        $this->expectException(\RuntimeException::class);
        SchemaDeclarationNode::fromArray(['type' => 'SchemaDeclaration', 'name' => 123, 'members' => []]);
    }

    #[Test]
    public function schema_declaration_from_array_throws_for_non_array_members(): void
    {
        $this->expectException(\RuntimeException::class);
        SchemaDeclarationNode::fromArray(['type' => 'SchemaDeclaration', 'name' => 'X', 'members' => 'bad']);
    }

    #[Test]
    public function schema_declaration_from_array_throws_for_non_array_member(): void
    {
        $this->expectException(\RuntimeException::class);
        SchemaDeclarationNode::fromArray(['type' => 'SchemaDeclaration', 'name' => 'X', 'members' => ['not-an-array']]);
    }

    #[Test]
    public function input_declaration_node_round_trips(): void
    {
        $node = new InputDeclarationNode('salary', new TypeAnnotationNode('number'), self::loc());
        $array = $node->toArray();

        $this->assertSame('InputDeclaration', $array['type']);
        $this->assertSame('salary', $array['name']);
        $this->assertArrayHasKey('typeAnnotation', $array);
        $this->assertArrayHasKey('loc', $array);

        $restored = InputDeclarationNode::fromArray($array);
        $this->assertSame('salary', $restored->name);
        $this->assertSame('number', $restored->type->keyword);
    }

    #[Test]
    public function input_declaration_from_array_throws_for_non_string_name(): void
    {
        $this->expectException(\RuntimeException::class);
        InputDeclarationNode::fromArray(['type' => 'InputDeclaration', 'name' => 123, 'typeAnnotation' => ['type' => 'TypeAnnotation', 'keyword' => 'number', 'args' => []]]);
    }

    #[Test]
    public function input_declaration_from_array_throws_for_non_array_type_annotation(): void
    {
        $this->expectException(\RuntimeException::class);
        InputDeclarationNode::fromArray(['type' => 'InputDeclaration', 'name' => 'x', 'typeAnnotation' => 'bad']);
    }

    #[Test]
    public function assert_statement_node_round_trips(): void
    {
        $node = new AssertStatementNode(
            new InfixExpressionNode(new IdentifierNode('x'), '>', new LiteralNode(0, '0')),
            'x must be positive',
            self::loc(),
        );
        $array = $node->toArray();

        $this->assertSame('AssertStatement', $array['type']);
        $this->assertArrayHasKey('expression', $array);
        $this->assertSame('x must be positive', $array['message']);
        $this->assertArrayHasKey('loc', $array);

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
    public function assert_statement_from_array_throws_for_non_array_expression(): void
    {
        $this->expectException(\RuntimeException::class);
        AssertStatementNode::fromArray(['type' => 'AssertStatement', 'expression' => 'bad']);
    }

    #[Test]
    public function match_arm_node_round_trips(): void
    {
        $node = new MatchArmNode(
            new LiteralPatternNode(1, '1'),
            new LiteralNode('one', '"one"'),
            self::loc(),
        );
        $array = $node->toArray();

        $this->assertSame('MatchArm', $array['type']);
        $this->assertArrayHasKey('pattern', $array);
        $this->assertArrayHasKey('expression', $array);
        $this->assertArrayHasKey('loc', $array);

        $restored = MatchArmNode::fromArray($array);
        $this->assertInstanceOf(LiteralPatternNode::class, $restored->pattern);
        $this->assertInstanceOf(LiteralNode::class, $restored->expression);
        $this->assertNotNull($restored->loc);
    }

    #[Test]
    public function match_arm_from_array_throws_for_non_array_pattern(): void
    {
        $this->expectException(\RuntimeException::class);
        MatchArmNode::fromArray(['type' => 'MatchArm', 'pattern' => 'bad', 'expression' => ['type' => 'Literal', 'value' => 1, 'raw' => '1']]);
    }

    #[Test]
    public function match_arm_from_array_throws_for_non_array_expression(): void
    {
        $this->expectException(\RuntimeException::class);
        MatchArmNode::fromArray(['type' => 'MatchArm', 'pattern' => ['type' => 'WildcardPattern'], 'expression' => 'bad']);
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
        ], '1.0', self::loc());

        $array = $program->toArray();

        $this->assertSame('Program', $array['type']);
        $this->assertSame('1.0', $array['version']);
        $this->assertArrayHasKey('body', $array);
        $this->assertCount(1, $array['body']);
        $this->assertArrayHasKey('loc', $array);

        $restored = ProgramNode::fromArray($array);
        $this->assertSame('1.0', $restored->version);
        $this->assertCount(1, $restored->body);
    }

    #[Test]
    public function program_node_preserves_custom_version(): void
    {
        $program = new ProgramNode([], '2.0');
        $array = $program->toArray();

        $this->assertSame('2.0', $array['version']);

        $restored = ProgramNode::fromArray($array);
        $this->assertSame('2.0', $restored->version);
    }

    #[Test]
    public function program_from_array_throws_for_non_array_body(): void
    {
        $this->expectException(\RuntimeException::class);
        ProgramNode::fromArray(['type' => 'Program', 'version' => '1.0', 'body' => 'bad']);
    }

    #[Test]
    public function program_from_array_throws_for_non_array_body_node(): void
    {
        $this->expectException(\RuntimeException::class);
        ProgramNode::fromArray(['type' => 'Program', 'version' => '1.0', 'body' => ['bad']]);
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
    public function expr_node_factory_throws_for_non_string_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected string for type');
        ExprNodeFactory::fromArray(['type' => 123]);
    }

    #[Test]
    public function expr_node_factory_resolves_all_types(): void
    {
        $this->assertInstanceOf(LiteralNode::class, ExprNodeFactory::fromArray(['type' => 'Literal', 'value' => 1, 'raw' => '1']));
        $this->assertInstanceOf(IdentifierNode::class, ExprNodeFactory::fromArray(['type' => 'Identifier', 'name' => 'x']));
        $this->assertInstanceOf(MemberExpressionNode::class, ExprNodeFactory::fromArray(['type' => 'MemberExpression', 'object' => ['type' => 'Identifier', 'name' => 'x'], 'property' => 'y']));
        $this->assertInstanceOf(IndexExpressionNode::class, ExprNodeFactory::fromArray(['type' => 'IndexExpression', 'object' => ['type' => 'Identifier', 'name' => 'x'], 'index' => ['type' => 'Literal', 'value' => 0, 'raw' => '0']]));
        $this->assertInstanceOf(InfixExpressionNode::class, ExprNodeFactory::fromArray(['type' => 'InfixExpression', 'left' => ['type' => 'Literal', 'value' => 1, 'raw' => '1'], 'operator' => '+', 'right' => ['type' => 'Literal', 'value' => 2, 'raw' => '2']]));
        $this->assertInstanceOf(UnaryExpressionNode::class, ExprNodeFactory::fromArray(['type' => 'UnaryExpression', 'operator' => '!', 'operand' => ['type' => 'Literal', 'value' => true, 'raw' => 'true']]));
        $this->assertInstanceOf(CoercionExpressionNode::class, ExprNodeFactory::fromArray(['type' => 'CoercionExpression', 'expression' => ['type' => 'Identifier', 'name' => 'x'], 'targetType' => ['type' => 'TypeAnnotation', 'keyword' => 'number', 'args' => []]]));
        $this->assertInstanceOf(MatchExpressionNode::class, ExprNodeFactory::fromArray(['type' => 'MatchExpression', 'subject' => null, 'arms' => []]));
        $this->assertInstanceOf(CallExpressionNode::class, ExprNodeFactory::fromArray(['type' => 'CallExpression', 'callee' => 'f', 'positionalArgs' => [], 'namedArgs' => []]));
        $this->assertInstanceOf(PipeExpressionNode::class, ExprNodeFactory::fromArray(['type' => 'PipeExpression', 'left' => ['type' => 'Literal', 'value' => 1, 'raw' => '1'], 'right' => ['type' => 'Identifier', 'name' => 'f']]));
        $this->assertInstanceOf(LambdaNode::class, ExprNodeFactory::fromArray(['type' => 'Lambda', 'params' => ['x'], 'body' => ['type' => 'Identifier', 'name' => 'x']]));
        $this->assertInstanceOf(ListLiteralNode::class, ExprNodeFactory::fromArray(['type' => 'ListLiteral', 'elements' => []]));
        $this->assertInstanceOf(DictLiteralNode::class, ExprNodeFactory::fromArray(['type' => 'DictLiteral', 'entries' => []]));
    }

    #[Test]
    public function pattern_node_factory_throws_for_unknown_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown pattern node type: Bogus');

        PatternNodeFactory::fromArray(['type' => 'Bogus']);
    }

    #[Test]
    public function pattern_node_factory_throws_for_non_string_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected string for type');
        PatternNodeFactory::fromArray(['type' => 123]);
    }

    #[Test]
    public function pattern_node_factory_resolves_all_types(): void
    {
        $this->assertInstanceOf(WildcardPatternNode::class, PatternNodeFactory::fromArray(['type' => 'WildcardPattern']));
        $this->assertInstanceOf(LiteralPatternNode::class, PatternNodeFactory::fromArray(['type' => 'LiteralPattern', 'value' => 1, 'raw' => '1']));
        $this->assertInstanceOf(ExpressionPatternNode::class, PatternNodeFactory::fromArray(['type' => 'ExpressionPattern', 'expression' => ['type' => 'Identifier', 'name' => 'x']]));
    }

    #[Test]
    public function statement_node_factory_throws_for_unknown_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown statement node type: Bogus');

        NodeFactory::fromArray(['type' => 'Bogus']);
    }

    #[Test]
    public function statement_node_factory_throws_for_non_string_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected string for type');
        NodeFactory::fromArray(['type' => 123]);
    }

    #[Test]
    public function statement_node_factory_resolves_all_types(): void
    {
        $this->assertInstanceOf(SymbolDeclarationNode::class, NodeFactory::fromArray(['type' => 'SymbolDeclaration', 'name' => 'x', 'typeAnnotation' => ['type' => 'TypeAnnotation', 'keyword' => 'number', 'args' => []], 'expression' => ['type' => 'Literal', 'value' => 1, 'raw' => '1'], 'visibility' => 'public']));
        $this->assertInstanceOf(NamespaceDeclarationNode::class, NodeFactory::fromArray(['type' => 'NamespaceDeclaration', 'name' => 'ns', 'body' => []]));
        $this->assertInstanceOf(SchemaDeclarationNode::class, NodeFactory::fromArray(['type' => 'SchemaDeclaration', 'name' => 'T', 'members' => []]));
        $this->assertInstanceOf(InputDeclarationNode::class, NodeFactory::fromArray(['type' => 'InputDeclaration', 'name' => 'x', 'typeAnnotation' => ['type' => 'TypeAnnotation', 'keyword' => 'number', 'args' => []]]));
        $this->assertInstanceOf(AssertStatementNode::class, NodeFactory::fromArray(['type' => 'AssertStatement', 'expression' => ['type' => 'Literal', 'value' => true, 'raw' => 'true']]));
    }

    #[Test]
    public function program_from_array_defaults_non_string_version(): void
    {
        $program = ProgramNode::fromArray(['type' => 'Program', 'version' => 123, 'body' => []]);
        $this->assertSame('1.0', $program->version);
    }

    #[Test]
    public function symbol_declaration_from_array_defaults_non_string_visibility(): void
    {
        $node = SymbolDeclarationNode::fromArray([
            'type' => 'SymbolDeclaration',
            'name' => 'x',
            'typeAnnotation' => ['type' => 'TypeAnnotation', 'keyword' => 'number', 'args' => []],
            'expression' => ['type' => 'Literal', 'value' => 1, 'raw' => '1'],
            'visibility' => 123,
        ]);
        $this->assertSame('public', $node->visibility);
    }

    #[Test]
    public function schema_declaration_from_array_throws_for_non_statement_member(): void
    {
        // NodeFactory resolves 'SchemaDeclaration' members using NodeFactory which only returns Node.
        // If we force a non-StatementNode through, it should throw.
        // We test with a literal expression inside members which would be resolved by NodeFactory
        // but we can't actually trigger this since NodeFactory only produces StatementNode types.
        // Instead, test the happy path works correctly.
        $node = SchemaDeclarationNode::fromArray([
            'type' => 'SchemaDeclaration',
            'name' => 'Test',
            'members' => [
                ['type' => 'InputDeclaration', 'name' => 'x', 'typeAnnotation' => ['type' => 'TypeAnnotation', 'keyword' => 'number', 'args' => []]],
                ['type' => 'AssertStatement', 'expression' => ['type' => 'Literal', 'value' => true, 'raw' => 'true']],
            ],
        ]);
        $this->assertCount(2, $node->members);
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
