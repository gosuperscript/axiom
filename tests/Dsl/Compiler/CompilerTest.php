<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dsl\Associativity;
use Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\DictLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNodeFactory;
use Superscript\Axiom\Dsl\Ast\Expressions\IdentifierNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IndexExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\InfixExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ListLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MemberExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\UnaryExpressionNode;
use Superscript\Axiom\Dsl\Ast\Location;
use Superscript\Axiom\Dsl\Ast\ProgramNode;
use Superscript\Axiom\Dsl\Ast\Statements\NamespaceDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\NodeFactory;
use Superscript\Axiom\Dsl\Ast\Statements\SymbolDeclarationNode;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;
use Superscript\Axiom\Dsl\Compiler\CompilationResult;
use Superscript\Axiom\Dsl\Compiler\Compiler;
use Superscript\Axiom\Dsl\CoreDslPlugin;
use Superscript\Axiom\Dsl\FunctionEntry;
use Superscript\Axiom\Dsl\FunctionParam;
use Superscript\Axiom\Dsl\FunctionRegistry;
use Superscript\Axiom\Dsl\Lexer\Lexer;
use Superscript\Axiom\Dsl\Lexer\Token;
use Superscript\Axiom\Dsl\Lexer\TokenType;
use Superscript\Axiom\Dsl\OperatorEntry;
use Superscript\Axiom\Dsl\OperatorPosition;
use Superscript\Axiom\Dsl\OperatorRegistry;
use Superscript\Axiom\Dsl\Parser\Parser;
use Superscript\Axiom\Dsl\Parser\TokenStream;
use Superscript\Axiom\Dsl\TypeRegistry;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\ComparisonOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\HasOverloader;
use Superscript\Axiom\Operators\InOverloader;
use Superscript\Axiom\Operators\IntersectsOverloader;
use Superscript\Axiom\Operators\LogicalOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Patterns\LiteralMatcher;
use Superscript\Axiom\Patterns\WildcardMatcher;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\SymbolRegistry;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(Compiler::class)]
#[CoversClass(CompilationResult::class)]
#[UsesClass(Lexer::class)]
#[UsesClass(Token::class)]
#[UsesClass(TokenType::class)]
#[UsesClass(Parser::class)]
#[UsesClass(TokenStream::class)]
#[UsesClass(OperatorRegistry::class)]
#[UsesClass(OperatorEntry::class)]
#[UsesClass(Associativity::class)]
#[UsesClass(OperatorPosition::class)]
#[UsesClass(TypeRegistry::class)]
#[UsesClass(CoreDslPlugin::class)]
#[UsesClass(FunctionRegistry::class)]
#[UsesClass(FunctionEntry::class)]
#[UsesClass(FunctionParam::class)]
#[UsesClass(ProgramNode::class)]
#[UsesClass(SymbolDeclarationNode::class)]
#[UsesClass(NamespaceDeclarationNode::class)]
#[UsesClass(LiteralNode::class)]
#[UsesClass(IdentifierNode::class)]
#[UsesClass(InfixExpressionNode::class)]
#[UsesClass(UnaryExpressionNode::class)]
#[UsesClass(MemberExpressionNode::class)]
#[UsesClass(IndexExpressionNode::class)]
#[UsesClass(CoercionExpressionNode::class)]
#[UsesClass(ListLiteralNode::class)]
#[UsesClass(DictLiteralNode::class)]
#[UsesClass(TypeAnnotationNode::class)]
#[UsesClass(Location::class)]
#[UsesClass(ExprNodeFactory::class)]
#[UsesClass(NodeFactory::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(UnaryExpression::class)]
#[UsesClass(MemberAccessSource::class)]
#[UsesClass(TypeDefinition::class)]
#[UsesClass(SymbolRegistry::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(NullOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(ComparisonOverloader::class)]
#[UsesClass(HasOverloader::class)]
#[UsesClass(InOverloader::class)]
#[UsesClass(LogicalOverloader::class)]
#[UsesClass(IntersectsOverloader::class)]
#[UsesClass(WildcardMatcher::class)]
#[UsesClass(LiteralMatcher::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(ListType::class)]
#[UsesClass(DictType::class)]
class CompilerTest extends TestCase
{
    private Compiler $compiler;
    private Lexer $lexer;
    private Parser $parser;

    protected function setUp(): void
    {
        $operatorRegistry = new OperatorRegistry();
        $typeRegistry = new TypeRegistry();
        $plugin = new CoreDslPlugin();
        $plugin->operators($operatorRegistry);
        $plugin->types($typeRegistry);
        $operatorRegistry->register('**', 80, Associativity::Right);
        $operatorRegistry->register('<', 40, Associativity::Left);
        $operatorRegistry->register('>', 40, Associativity::Left);

        $this->lexer = new Lexer($operatorRegistry);
        $this->parser = new Parser($operatorRegistry);
        $this->compiler = new Compiler($typeRegistry);
    }

    private function compileExpression(string $source): Source
    {
        $tokens = $this->lexer->tokenize("x: number = {$source}");
        $program = $this->parser->parse($tokens);
        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);

        return $this->compiler->compile($stmt->expression);
    }

    #[Test]
    public function it_compiles_literal_to_static_source(): void
    {
        $source = $this->compileExpression('42');

        $this->assertInstanceOf(StaticSource::class, $source);
        $this->assertSame(42, $source->value);
    }

    #[Test]
    public function it_compiles_string_literal_to_static_source(): void
    {
        $source = $this->compileExpression('"hello"');

        $this->assertInstanceOf(StaticSource::class, $source);
        $this->assertSame('hello', $source->value);
    }

    #[Test]
    public function it_compiles_boolean_literal_to_static_source(): void
    {
        $source = $this->compileExpression('true');

        $this->assertInstanceOf(StaticSource::class, $source);
        $this->assertTrue($source->value);
    }

    #[Test]
    public function it_compiles_null_to_static_source(): void
    {
        $source = $this->compileExpression('null');

        $this->assertInstanceOf(StaticSource::class, $source);
        $this->assertNull($source->value);
    }

    #[Test]
    public function it_compiles_identifier_to_symbol_source(): void
    {
        $source = $this->compileExpression('myVar');

        $this->assertInstanceOf(SymbolSource::class, $source);
        $this->assertSame('myVar', $source->name);
        $this->assertNull($source->namespace);
    }

    #[Test]
    public function it_compiles_two_level_member_access_to_symbol_source(): void
    {
        $source = $this->compileExpression('quote.trade');

        $this->assertInstanceOf(SymbolSource::class, $source);
        $this->assertSame('trade', $source->name);
        $this->assertSame('quote', $source->namespace);
    }

    #[Test]
    public function it_compiles_three_level_member_access_to_chained_member_access(): void
    {
        $source = $this->compileExpression('a.b.c');

        $this->assertInstanceOf(MemberAccessSource::class, $source);
        $this->assertSame('c', $source->property);

        $inner = $source->object;
        $this->assertInstanceOf(SymbolSource::class, $inner);
        $this->assertSame('b', $inner->name);
        $this->assertSame('a', $inner->namespace);
    }

    #[Test]
    public function it_compiles_infix_expression(): void
    {
        $source = $this->compileExpression('a + b');

        $this->assertInstanceOf(InfixExpression::class, $source);
        $this->assertSame('+', $source->operator);
        $this->assertInstanceOf(SymbolSource::class, $source->left);
        $this->assertInstanceOf(SymbolSource::class, $source->right);
    }

    #[Test]
    public function it_compiles_unary_expression(): void
    {
        $source = $this->compileExpression('!x');

        $this->assertInstanceOf(UnaryExpression::class, $source);
        $this->assertSame('!', $source->operator);
        $this->assertInstanceOf(SymbolSource::class, $source->operand);
    }

    #[Test]
    public function it_compiles_coercion_to_type_definition(): void
    {
        $source = $this->compileExpression('"42" as number');

        $this->assertInstanceOf(TypeDefinition::class, $source);
        $this->assertInstanceOf(NumberType::class, $source->type);
        $this->assertInstanceOf(StaticSource::class, $source->source);
    }

    #[Test]
    public function it_compiles_list_to_static_source(): void
    {
        $source = $this->compileExpression('["a", "b", "c"]');

        $this->assertInstanceOf(StaticSource::class, $source);
        $this->assertSame(['a', 'b', 'c'], $source->value);
    }

    #[Test]
    public function it_compiles_dict_to_static_source(): void
    {
        $source = $this->compileExpression('{"key": "value"}');

        $this->assertInstanceOf(StaticSource::class, $source);
        $this->assertSame(['key' => 'value'], $source->value);
    }

    #[Test]
    public function it_compiles_program_to_compilation_result(): void
    {
        $source = <<<'AXIOM'
base: number = 42
rate: number = 3.14
AXIOM;
        $tokens = $this->lexer->tokenize($source);
        $program = $this->parser->parse($tokens);
        $result = $this->compiler->compileProgram($program);

        $this->assertInstanceOf(CompilationResult::class, $result);
        $this->assertInstanceOf(SymbolRegistry::class, $result->symbols);
        $this->assertSame(['base', 'rate'], $result->outputs);
    }

    #[Test]
    public function it_compiles_namespace_to_compilation_result(): void
    {
        $source = <<<'AXIOM'
namespace math {
    pi: number = 3.14159
    tau: number = 6.28318
}
AXIOM;
        $tokens = $this->lexer->tokenize($source);
        $program = $this->parser->parse($tokens);
        $result = $this->compiler->compileProgram($program);

        $this->assertSame(['math.pi', 'math.tau'], $result->outputs);

        // Verify symbols are accessible
        $pi = $result->symbols->get('pi', 'math');
        $this->assertTrue($pi->isSome());
    }

    #[Test]
    public function it_compiles_symbol_declaration_with_type_coercion(): void
    {
        $tokens = $this->lexer->tokenize('base: number = 42');
        $program = $this->parser->parse($tokens);
        $result = $this->compiler->compileProgram($program);

        $source = $result->symbols->get('base');
        $this->assertTrue($source->isSome());
        $this->assertInstanceOf(TypeDefinition::class, $source->unwrap());
    }

    #[Test]
    public function it_compiles_index_expression(): void
    {
        $source = $this->compileExpression('items[0]');

        $this->assertInstanceOf(MemberAccessSource::class, $source);
        $this->assertSame('0', $source->property);
    }

    #[Test]
    public function it_compiles_index_with_string_key(): void
    {
        $source = $this->compileExpression('items["key"]');

        $this->assertInstanceOf(MemberAccessSource::class, $source);
        $this->assertSame('key', $source->property);
    }

    #[Test]
    public function it_compiles_complex_expression(): void
    {
        $source = $this->compileExpression('a * (b + c)');

        $this->assertInstanceOf(InfixExpression::class, $source);
        $this->assertSame('*', $source->operator);
        $this->assertInstanceOf(InfixExpression::class, $source->right);
        $this->assertSame('+', $source->right->operator);
    }

    #[Test]
    public function it_compiles_unary_not(): void
    {
        $source = $this->compileExpression('not x');

        $this->assertInstanceOf(UnaryExpression::class, $source);
        $this->assertSame('not', $source->operator);
    }

    #[Test]
    public function it_compiles_list_with_non_literal_elements(): void
    {
        $source = $this->compileExpression('[a, b]');

        $this->assertInstanceOf(StaticSource::class, $source);
        // Non-literal elements compile to Source objects in the array
        $this->assertIsArray($source->value);
        $this->assertCount(2, $source->value);
        $this->assertInstanceOf(SymbolSource::class, $source->value[0]);
    }

    #[Test]
    public function it_compiles_index_with_identifier_key(): void
    {
        $source = $this->compileExpression('items[key]');

        $this->assertInstanceOf(MemberAccessSource::class, $source);
        $this->assertSame('key', $source->property);
    }

    #[Test]
    public function it_throws_on_unknown_node_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot compile node');

        // Create a custom ExprNode that the compiler doesn't handle
        $node = new class implements \Superscript\Axiom\Dsl\Ast\Expressions\ExprNode {
            public function toArray(): array
            {
                return ['type' => 'Unknown'];
            }

            public static function fromArray(array $data): static
            {
                return new self();
            }
        };

        $this->compiler->compile($node);
    }

    #[Test]
    public function it_compiles_declaration_with_unknown_type(): void
    {
        // Create AST manually with unknown type
        $program = new ProgramNode([
            new SymbolDeclarationNode(
                'x',
                new TypeAnnotationNode('unknownType'),
                new LiteralNode(42, '42'),
            ),
        ]);

        $result = $this->compiler->compileProgram($program);

        // With unknown type, no TypeDefinition wrapper should be applied
        $source = $result->symbols->get('x')->unwrap();
        $this->assertInstanceOf(StaticSource::class, $source);
    }

    #[Test]
    public function it_throws_on_index_with_invalid_literal_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Index literal must be a string or integer');

        // IndexExpressionNode with a boolean literal as index
        $node = new IndexExpressionNode(
            new IdentifierNode('items'),
            new LiteralNode(true, 'true'),
        );

        $this->compiler->compile($node);
    }

    #[Test]
    public function it_throws_on_index_with_non_literal_non_identifier(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Index expression must be a literal or identifier');

        // IndexExpressionNode with an infix expression as index
        $node = new IndexExpressionNode(
            new IdentifierNode('items'),
            new InfixExpressionNode(
                new LiteralNode(1, '1'),
                '+',
                new LiteralNode(2, '2'),
            ),
        );

        $this->compiler->compile($node);
    }

    #[Test]
    public function it_throws_on_dict_with_invalid_key_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dict key must be a string or integer');

        $node = new DictLiteralNode([
            ['key' => new LiteralNode(true, 'true'), 'value' => new LiteralNode(1, '1')],
        ]);

        $this->compiler->compile($node);
    }
}
