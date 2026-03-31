<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Dsl\Ast\Expressions\IdentifierNode;
use Superscript\Axiom\Dsl\Ast\Expressions\InfixExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MatchArmNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MatchExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MemberExpressionNode;
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
use Superscript\Axiom\Dsl\CompilationResult;
use Superscript\Axiom\Dsl\Compiler;
use Superscript\Axiom\Dsl\CoreDslPlugin;
use Superscript\Axiom\Dsl\TypeRegistry;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(Compiler::class)]
#[CoversClass(CompilationResult::class)]
#[UsesClass(ProgramNode::class)]
#[UsesClass(LiteralNode::class)]
#[UsesClass(IdentifierNode::class)]
#[UsesClass(InfixExpressionNode::class)]
#[UsesClass(UnaryExpressionNode::class)]
#[UsesClass(MemberExpressionNode::class)]
#[UsesClass(MatchExpressionNode::class)]
#[UsesClass(MatchArmNode::class)]
#[UsesClass(WildcardPatternNode::class)]
#[UsesClass(LiteralPatternNode::class)]
#[UsesClass(ExpressionPatternNode::class)]
#[UsesClass(SymbolDeclarationNode::class)]
#[UsesClass(SchemaDeclarationNode::class)]
#[UsesClass(NamespaceDeclarationNode::class)]
#[UsesClass(InputDeclarationNode::class)]
#[UsesClass(AssertStatementNode::class)]
#[UsesClass(TypeAnnotationNode::class)]
#[UsesClass(CoreDslPlugin::class)]
#[UsesClass(TypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode::class)]
#[UsesClass(\Superscript\Axiom\Dsl\Ast\Expressions\DictLiteralNode::class)]
#[UsesClass(\Superscript\Axiom\Types\ListType::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(UnaryExpression::class)]
#[UsesClass(MemberAccessSource::class)]
#[UsesClass(MatchExpression::class)]
#[UsesClass(MatchArm::class)]
#[UsesClass(LiteralPattern::class)]
#[UsesClass(WildcardPattern::class)]
#[UsesClass(ExpressionPattern::class)]
#[UsesClass(TypeDefinition::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(DictType::class)]
class CompilerTest extends TestCase
{
    private function makeCompiler(): Compiler
    {
        $types = new TypeRegistry();
        (new CoreDslPlugin())->types($types);

        return new Compiler($types);
    }

    #[Test]
    public function it_compiles_symbol_declaration(): void
    {
        $program = new ProgramNode([
            new SymbolDeclarationNode('x', new TypeAnnotationNode('number'), new LiteralNode(42, '42')),
        ]);

        $result = $this->makeCompiler()->compile($program);

        $this->assertArrayHasKey('x', $result->symbols);
        $this->assertInstanceOf(TypeDefinition::class, $result->symbols['x']);
        $this->assertInstanceOf(StaticSource::class, $result->symbols['x']->source);
        $this->assertSame(42, $result->symbols['x']->source->value);
        $this->assertContains('x', $result->outputs);
    }

    #[Test]
    public function it_compiles_private_symbol_as_non_output(): void
    {
        $program = new ProgramNode([
            new SymbolDeclarationNode('base', new TypeAnnotationNode('number'), new LiteralNode(100, '100'), 'private'),
        ]);

        $result = $this->makeCompiler()->compile($program);

        $this->assertArrayHasKey('base', $result->symbols);
        $this->assertNotContains('base', $result->outputs);
    }

    #[Test]
    public function it_compiles_infix_expression(): void
    {
        $result = $this->makeCompiler()->compileExpression(
            new InfixExpressionNode(new LiteralNode(1, '1'), '+', new LiteralNode(2, '2')),
        );

        $this->assertInstanceOf(InfixExpression::class, $result);
        $this->assertSame('+', $result->operator);
    }

    #[Test]
    public function it_compiles_unary_expression(): void
    {
        $result = $this->makeCompiler()->compileExpression(
            new UnaryExpressionNode('!', new IdentifierNode('x')),
        );

        $this->assertInstanceOf(UnaryExpression::class, $result);
        $this->assertSame('!', $result->operator);
    }

    #[Test]
    public function it_compiles_member_access_on_identifier_to_symbol_source(): void
    {
        $result = $this->makeCompiler()->compileExpression(
            new MemberExpressionNode(new IdentifierNode('quote'), 'claims'),
        );

        $this->assertInstanceOf(SymbolSource::class, $result);
        $this->assertSame('claims', $result->name);
        $this->assertSame('quote', $result->namespace);
    }

    #[Test]
    public function it_compiles_chained_member_access_to_member_access_source(): void
    {
        $result = $this->makeCompiler()->compileExpression(
            new MemberExpressionNode(
                new MemberExpressionNode(new IdentifierNode('a'), 'b'),
                'c',
            ),
        );

        $this->assertInstanceOf(MemberAccessSource::class, $result);
        $this->assertSame('c', $result->property);
    }

    #[Test]
    public function it_compiles_if_then_else_to_match_expression_with_static_true_subject(): void
    {
        $program = new ProgramNode([
            new SymbolDeclarationNode('x', new TypeAnnotationNode('number'), new MatchExpressionNode(
                subject: null,
                arms: [
                    new MatchArmNode(new ExpressionPatternNode(new LiteralNode(true, 'true')), new LiteralNode(1, '1')),
                    new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0, '0')),
                ],
            )),
        ]);

        $result = $this->makeCompiler()->compile($program);
        $source = $result->symbols['x'];
        $this->assertInstanceOf(TypeDefinition::class, $source);
        $match = $source->source;
        $this->assertInstanceOf(MatchExpression::class, $match);
        $this->assertInstanceOf(StaticSource::class, $match->subject);
        $this->assertTrue($match->subject->value);
        $this->assertCount(2, $match->arms);
    }

    #[Test]
    public function it_compiles_match_with_subject(): void
    {
        $matchNode = new MatchExpressionNode(
            subject: new IdentifierNode('tier'),
            arms: [
                new MatchArmNode(new LiteralPatternNode('micro', '"micro"'), new LiteralNode(1.3, '1.3')),
                new MatchArmNode(new WildcardPatternNode(), new LiteralNode(1.0, '1.0')),
            ],
        );

        $result = $this->makeCompiler()->compileExpression($matchNode);

        $this->assertInstanceOf(MatchExpression::class, $result);
        $this->assertInstanceOf(SymbolSource::class, $result->subject);
        $this->assertCount(2, $result->arms);
        $this->assertInstanceOf(LiteralPattern::class, $result->arms[0]->pattern);
        $this->assertSame('micro', $result->arms[0]->pattern->value);
        $this->assertInstanceOf(WildcardPattern::class, $result->arms[1]->pattern);
    }

    #[Test]
    public function it_compiles_expression_pattern(): void
    {
        $matchNode = new MatchExpressionNode(
            subject: null,
            arms: [
                new MatchArmNode(
                    new ExpressionPatternNode(new InfixExpressionNode(new IdentifierNode('x'), '>', new LiteralNode(5, '5'))),
                    new LiteralNode(1, '1'),
                ),
                new MatchArmNode(new WildcardPatternNode(), new LiteralNode(0, '0')),
            ],
        );

        $result = $this->makeCompiler()->compileExpression($matchNode);

        $this->assertInstanceOf(MatchExpression::class, $result);
        $this->assertInstanceOf(ExpressionPattern::class, $result->arms[0]->pattern);
        $this->assertInstanceOf(InfixExpression::class, $result->arms[0]->pattern->source);
    }

    #[Test]
    public function it_compiles_namespace(): void
    {
        $program = new ProgramNode([
            new NamespaceDeclarationNode('premium', [
                new SymbolDeclarationNode('base', new TypeAnnotationNode('number'), new LiteralNode(100, '100')),
            ]),
        ]);

        $result = $this->makeCompiler()->compile($program);

        $this->assertArrayHasKey('premium.base', $result->symbols);
    }

    #[Test]
    public function it_compiles_schema_with_members(): void
    {
        $program = new ProgramNode([
            new SchemaDeclarationNode('Calc', [
                new InputDeclarationNode('quote', new TypeAnnotationNode('dict')),
                new SymbolDeclarationNode('base', new TypeAnnotationNode('number'), new LiteralNode(100, '100'), 'private'),
                new SymbolDeclarationNode('gross', new TypeAnnotationNode('number'), new LiteralNode(110, '110'), 'public'),
                new AssertStatementNode(new InfixExpressionNode(new IdentifierNode('gross'), '>=', new LiteralNode(500, '500'))),
            ]),
        ]);

        $result = $this->makeCompiler()->compile($program);

        $this->assertArrayHasKey('quote', $result->inputs);
        $this->assertSame('dict', $result->inputs['quote']);
        $this->assertArrayHasKey('base', $result->symbols);
        $this->assertArrayHasKey('gross', $result->symbols);
        $this->assertNotContains('base', $result->outputs);
        $this->assertContains('gross', $result->outputs);
        $this->assertCount(1, $result->assertions);
        $this->assertInstanceOf(InfixExpression::class, $result->assertions[0]);
    }

    #[Test]
    public function it_compiles_assert_statement(): void
    {
        $program = new ProgramNode([
            new AssertStatementNode(new InfixExpressionNode(new IdentifierNode('x'), '>=', new LiteralNode(0, '0'))),
        ]);

        $result = $this->makeCompiler()->compile($program);

        $this->assertCount(1, $result->assertions);
    }

    #[Test]
    public function it_compiles_type_with_args(): void
    {
        $program = new ProgramNode([
            new SymbolDeclarationNode('x', new TypeAnnotationNode('list', [new TypeAnnotationNode('number')]), new LiteralNode([], '[]')),
        ]);

        $result = $this->makeCompiler()->compile($program);

        $source = $result->symbols['x'];
        $this->assertInstanceOf(TypeDefinition::class, $source);
        $this->assertInstanceOf(\Superscript\Axiom\Types\ListType::class, $source->type);
        // Verify args were resolved correctly (inner type is NumberType, not StringType fallback)
        $this->assertInstanceOf(NumberType::class, $source->type->type);
    }

    #[Test]
    public function it_compiles_coercion_expression(): void
    {
        $result = $this->makeCompiler()->compileExpression(
            new \Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode(
                new IdentifierNode('x'),
                new TypeAnnotationNode('number'),
            ),
        );

        $this->assertInstanceOf(TypeDefinition::class, $result);
        $this->assertInstanceOf(NumberType::class, $result->type);
    }

    #[Test]
    public function it_compiles_coercion_with_type_args(): void
    {
        $result = $this->makeCompiler()->compileExpression(
            new \Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode(
                new IdentifierNode('x'),
                new TypeAnnotationNode('list', [new TypeAnnotationNode('number')]),
            ),
        );

        $this->assertInstanceOf(TypeDefinition::class, $result);
        $this->assertInstanceOf(\Superscript\Axiom\Types\ListType::class, $result->type);
        // Verify args were resolved correctly
        $this->assertInstanceOf(NumberType::class, $result->type->type);
    }

    #[Test]
    public function it_throws_for_unknown_expression_type(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot compile expression');

        $this->makeCompiler()->compileExpression(
            new \Superscript\Axiom\Dsl\Ast\Expressions\DictLiteralNode([]),
        );
    }

    #[Test]
    public function it_throws_for_unknown_node_type(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown node type');

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

        $program = new ProgramNode([$fakeNode]);
        $this->makeCompiler()->compile($program);
    }

    #[Test]
    public function it_throws_for_unknown_pattern_type(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown pattern type');

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

        $matchNode = new MatchExpressionNode(
            subject: new IdentifierNode('x'),
            arms: [new MatchArmNode($fakePattern, new LiteralNode(1, '1'))],
        );

        $this->makeCompiler()->compileExpression($matchNode);
    }
}
