<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl\PrettyPrinter;

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
use Superscript\Axiom\Dsl\PrettyPrinter\PrettyPrinter;
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
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(PrettyPrinter::class)]
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
class PrettyPrinterTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;
    private PrettyPrinter $printer;
    private OperatorRegistry $operatorRegistry;

    protected function setUp(): void
    {
        $this->operatorRegistry = new OperatorRegistry();
        $plugin = new CoreDslPlugin();
        $plugin->operators($this->operatorRegistry);
        $this->operatorRegistry->register('**', 80, Associativity::Right);
        $this->operatorRegistry->register('<', 40, Associativity::Left);
        $this->operatorRegistry->register('>', 40, Associativity::Left);

        $this->lexer = new Lexer($this->operatorRegistry);
        $this->parser = new Parser($this->operatorRegistry);
        $this->printer = new PrettyPrinter($this->operatorRegistry);
    }

    private function roundTrip(string $source): string
    {
        $tokens = $this->lexer->tokenize($source);
        $program = $this->parser->parse($tokens);

        return $this->printer->print($program);
    }

    private function assertRoundTrip(string $source): void
    {
        $output = $this->roundTrip($source);
        // Parse the output again and compare ASTs
        $originalTokens = $this->lexer->tokenize($source);
        $originalAst = $this->parser->parse($originalTokens);

        $reparsedTokens = $this->lexer->tokenize($output);
        $reparsedAst = $this->parser->parse($reparsedTokens);

        $this->assertEquals($originalAst->toArray(), $reparsedAst->toArray(), "Round-trip failed for: {$source}");
    }

    #[Test]
    public function it_prints_simple_declaration(): void
    {
        $output = $this->roundTrip('x: number = 42');

        $this->assertSame('x: number = 42', $output);
    }

    #[Test]
    public function it_prints_string_literal(): void
    {
        $output = $this->roundTrip('x: string = "hello"');

        $this->assertSame('x: string = "hello"', $output);
    }

    #[Test]
    public function it_prints_boolean(): void
    {
        $output = $this->roundTrip('x: bool = true');

        $this->assertSame('x: bool = true', $output);
    }

    #[Test]
    public function it_prints_null(): void
    {
        $output = $this->roundTrip('x: number = null');

        $this->assertSame('x: number = null', $output);
    }

    #[Test]
    public function it_prints_member_access(): void
    {
        $output = $this->roundTrip('x: number = a.b.c');

        $this->assertSame('x: number = a.b.c', $output);
    }

    #[Test]
    public function it_prints_infix_with_correct_parenthesization(): void
    {
        // 1 + 2 * 3 does not need parens
        $this->assertRoundTrip('x: number = 1 + 2 * 3');

        // (1 + 2) * 3 needs parens
        $output = $this->roundTrip('x: number = (1 + 2) * 3');
        $this->assertSame('x: number = (1 + 2) * 3', $output);
    }

    #[Test]
    public function it_round_trips_percentage(): void
    {
        $output = $this->roundTrip('x: number = 45%');

        $this->assertSame('x: number = 45%', $output);
        $this->assertRoundTrip('x: number = 45%');
    }

    #[Test]
    public function it_prints_not_in_as_not_in(): void
    {
        $output = $this->roundTrip('x: bool = a not in items');

        $this->assertSame('x: bool = a not in items', $output);
        $this->assertRoundTrip('x: bool = a not in items');
    }

    #[Test]
    public function it_prints_unary_not(): void
    {
        $output = $this->roundTrip('x: bool = not a');

        $this->assertSame('x: bool = not a', $output);
    }

    #[Test]
    public function it_prints_unary_bang(): void
    {
        $output = $this->roundTrip('x: bool = !a');

        $this->assertSame('x: bool = !a', $output);
    }

    #[Test]
    public function it_prints_coercion(): void
    {
        $output = $this->roundTrip('x: number = "42" as number');

        $this->assertSame('x: number = "42" as number', $output);
    }

    #[Test]
    public function it_prints_list_literal(): void
    {
        $output = $this->roundTrip('x: list = ["a", "b", "c"]');

        $this->assertSame('x: list = ["a", "b", "c"]', $output);
    }

    #[Test]
    public function it_prints_empty_list(): void
    {
        $output = $this->roundTrip('x: list = []');

        $this->assertSame('x: list = []', $output);
    }

    #[Test]
    public function it_prints_dict_literal(): void
    {
        $output = $this->roundTrip('x: dict = {"a": 1}');

        $this->assertSame('x: dict = {"a": 1}', $output);
    }

    #[Test]
    public function it_prints_namespace(): void
    {
        $source = "namespace math {\n    pi: number = 3.14\n}";
        $tokens = $this->lexer->tokenize($source);
        $program = $this->parser->parse($tokens);
        $output = $this->printer->print($program);

        $this->assertStringContainsString('namespace math {', $output);
        $this->assertStringContainsString('    pi: number = 3.14', $output);
        $this->assertStringContainsString('}', $output);
    }

    #[Test]
    public function it_prints_index_expression(): void
    {
        $output = $this->roundTrip('x: number = items[0]');

        $this->assertSame('x: number = items[0]', $output);
    }

    #[Test]
    public function it_prints_parameterized_type(): void
    {
        $output = $this->roundTrip('x: list<number> = []');

        $this->assertSame('x: list<number> = []', $output);
    }

    #[Test]
    public function it_round_trips_complex_expression(): void
    {
        $this->assertRoundTrip('x: number = a + b * c');
        $this->assertRoundTrip('x: number = (a + b) * c');
        $this->assertRoundTrip('x: bool = a && b || c');
    }

    #[Test]
    public function it_round_trips_unary_minus(): void
    {
        $output = $this->roundTrip('x: number = -a');

        $this->assertSame('x: number = -a', $output);
    }

    #[Test]
    public function it_prints_in_operator(): void
    {
        $output = $this->roundTrip('x: bool = a in items');

        $this->assertSame('x: bool = a in items', $output);
    }

    #[Test]
    public function it_round_trips_multiple_declarations(): void
    {
        $source = "a: number = 1\nb: number = 2";
        $output = $this->roundTrip($source);

        $this->assertSame($source, $output);
    }

    #[Test]
    public function it_prints_identifier(): void
    {
        $output = $this->roundTrip('x: number = myVar');

        $this->assertSame('x: number = myVar', $output);
    }

    #[Test]
    public function it_prints_empty_dict(): void
    {
        $output = $this->roundTrip('x: dict = {}');

        $this->assertSame('x: dict = {}', $output);
    }

    #[Test]
    public function it_throws_on_unknown_node_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot print node');

        $node = new class implements \Superscript\Axiom\Dsl\Ast\Statements\StatementNode {
            public function toArray(): array
            {
                return ['type' => 'Unknown'];
            }

            public static function fromArray(array $data): static
            {
                return new self();
            }
        };

        $program = new ProgramNode([$node]);
        $this->printer->print($program);
    }

    #[Test]
    public function it_throws_on_unknown_expression_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot print expression');

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

        $this->printer->printExpression($node);
    }

    #[Test]
    public function it_parenthesizes_not_in_when_in_higher_precedence_context(): void
    {
        // Create AST: x * (a not in b) — the not in needs parens
        // not(a in b) used in multiply context → needs parens
        $notInNode = new UnaryExpressionNode(
            'not',
            new InfixExpressionNode(
                new IdentifierNode('a'),
                'in',
                new IdentifierNode('b'),
            ),
        );

        // Print with high parent precedence to trigger parens
        $output = $this->printer->printExpression($notInNode, 60);

        $this->assertSame('(a not in b)', $output);
    }

    #[Test]
    public function it_parenthesizes_unary_not_in_higher_precedence_context(): void
    {
        // Create AST: not(x) used in a high-precedence context
        $notNode = new UnaryExpressionNode(
            'not',
            new IdentifierNode('x'),
        );

        // Print with precedence higher than 'not' (70) to force parens
        $output = $this->printer->printExpression($notNode, PHP_INT_MAX);

        $this->assertSame('(not x)', $output);
    }
}
