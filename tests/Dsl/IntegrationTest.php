<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl;

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
use Superscript\Axiom\Dsl\AxiomDsl;
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

#[CoversClass(AxiomDsl::class)]
#[UsesClass(Lexer::class)]
#[UsesClass(Token::class)]
#[UsesClass(TokenType::class)]
#[UsesClass(Parser::class)]
#[UsesClass(TokenStream::class)]
#[UsesClass(Compiler::class)]
#[UsesClass(CompilationResult::class)]
#[UsesClass(PrettyPrinter::class)]
#[UsesClass(OperatorRegistry::class)]
#[UsesClass(OperatorEntry::class)]
#[UsesClass(Associativity::class)]
#[UsesClass(OperatorPosition::class)]
#[UsesClass(TypeRegistry::class)]
#[UsesClass(FunctionRegistry::class)]
#[UsesClass(FunctionEntry::class)]
#[UsesClass(FunctionParam::class)]
#[UsesClass(CoreDslPlugin::class)]
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
class IntegrationTest extends TestCase
{
    private AxiomDsl $dsl;

    protected function setUp(): void
    {
        $this->dsl = AxiomDsl::fromPlugins(new CoreDslPlugin());
    }

    #[Test]
    public function it_evaluates_simple_literal(): void
    {
        $result = $this->dsl->evaluate('base: number = 42');

        $this->assertSame(['base'], $result->outputs);
        $source = $result->symbols->get('base')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $source);
        $this->assertInstanceOf(StaticSource::class, $source->source);
        $this->assertSame(42, $source->source->value);
    }

    #[Test]
    public function it_evaluates_percentage(): void
    {
        $result = $this->dsl->evaluate('rate: number = 45%');

        $source = $result->symbols->get('rate')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $source);
        $this->assertInstanceOf(StaticSource::class, $source->source);
        $this->assertSame(0.45, $source->source->value);
    }

    #[Test]
    public function it_evaluates_expression_with_member_access(): void
    {
        $result = $this->dsl->evaluate('base: number = rates.base_rate * 1000');

        $source = $result->symbols->get('base')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $source);
        $this->assertInstanceOf(InfixExpression::class, $source->source);
        $this->assertSame('*', $source->source->operator);
    }

    #[Test]
    public function it_evaluates_coercion(): void
    {
        $result = $this->dsl->evaluate('rate: number = "45%" as number');

        $source = $result->symbols->get('rate')->unwrap();
        // The outer type comes from the declaration
        $this->assertInstanceOf(TypeDefinition::class, $source);
        // The inner source is also a TypeDefinition from "as number"
        $this->assertInstanceOf(TypeDefinition::class, $source->source);
    }

    #[Test]
    public function it_evaluates_boolean_expression(): void
    {
        $result = $this->dsl->evaluate('is_eligible: bool = not quote.is_cancelled && quote.turnover >= 50000');

        $source = $result->symbols->get('is_eligible')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $source);
    }

    #[Test]
    public function it_evaluates_list_declaration(): void
    {
        $result = $this->dsl->evaluate('items: list = ["roofing", "demolition", "asbestos"]');

        $source = $result->symbols->get('items')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $source);
        $this->assertInstanceOf(StaticSource::class, $source->source);
        $this->assertSame(['roofing', 'demolition', 'asbestos'], $source->source->value);
    }

    #[Test]
    public function it_evaluates_in_operator(): void
    {
        $result = $this->dsl->evaluate('has_risk: bool = quote.trade in items');

        $source = $result->symbols->get('has_risk')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $source);
    }

    #[Test]
    public function it_evaluates_namespace(): void
    {
        $source = <<<'AXIOM'
namespace math {
    pi: number = 3.14159
    tau: number = 6.28318
}
AXIOM;
        $result = $this->dsl->evaluate($source);

        $this->assertSame(['math.pi', 'math.tau'], $result->outputs);
        $this->assertTrue($result->symbols->get('pi', 'math')->isSome());
        $this->assertTrue($result->symbols->get('tau', 'math')->isSome());
    }

    #[Test]
    public function it_round_trips_parse_pretty_print_parse(): void
    {
        $source = 'base: number = rates.base_rate * 1000';

        $ast1 = $this->dsl->parse($source);
        $printed = $this->dsl->prettyPrint($ast1);
        $ast2 = $this->dsl->parse($printed);

        $this->assertEquals($ast1->toArray(), $ast2->toArray());
    }

    #[Test]
    public function it_evaluates_full_dsl_program(): void
    {
        $source = <<<'AXIOM'
items: list = ["roofing", "demolition", "asbestos"]
has_risk: bool = quote.trade in items
namespace math {
    pi: number = 3.14159
}
AXIOM;
        $result = $this->dsl->evaluate($source);

        $this->assertSame(['items', 'has_risk', 'math.pi'], $result->outputs);
    }

    #[Test]
    public function it_parses_and_compiles_comments(): void
    {
        $source = <<<'AXIOM'
// This is a comment
base: number = 42 // inline comment
AXIOM;
        $result = $this->dsl->evaluate($source);

        $this->assertSame(['base'], $result->outputs);
    }

    #[Test]
    public function it_evaluates_not_in_expression(): void
    {
        $result = $this->dsl->evaluate('excluded: bool = item not in allowed');

        $source = $result->symbols->get('excluded')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $source);
        $this->assertInstanceOf(UnaryExpression::class, $source->source);
        $this->assertSame('not', $source->source->operator);
    }

    #[Test]
    public function it_creates_dsl_from_plugins(): void
    {
        $dsl = AxiomDsl::fromPlugins(new CoreDslPlugin());

        $this->assertInstanceOf(AxiomDsl::class, $dsl);
    }

    #[Test]
    public function kitchen_sink(): void
    {
        $source = <<<'AXIOM'
// Arithmetic with member access and grouping
base: number = rates.base_rate * (quote.sum_insured / 1000)

// Coercion from string percentage
percentage: number = "45%" as number

// Boolean logic: unary not, logical &&, comparison >=, member access
is_eligible: bool = not quote.is_cancelled && quote.turnover >= 50000

// List literal
items: list = ["roofing", "demolition", "asbestos"]

// Keyword operator 'in' with member access
has_risk: bool = quote.trade in items

// 'not in' desugaring
is_safe: bool = quote.trade not in items

// Namespace with multiple symbols
namespace math {
    pi: number = 3.14159
    tau: number = 6.28318
}

// Reference to namespaced symbol in arithmetic
area: number = math.pi * radius * radius

// Dict literal
thresholds: dict = {"low": 1000, "high": 5000}

// Index expression
low_threshold: number = thresholds["low"]

// Negation and nested boolean
is_excluded: bool = !has_risk && is_eligible

// Logical or with xor
flag: bool = a || b xor c
AXIOM;

        // --- parse ---
        $ast = $this->dsl->parse($source);
        $this->assertInstanceOf(ProgramNode::class, $ast);
        $this->assertCount(12, $ast->body);

        // --- compile ---
        $result = $this->dsl->compile($ast);

        $expectedOutputs = [
            'base',
            'percentage',
            'is_eligible',
            'items',
            'has_risk',
            'is_safe',
            'math.pi',
            'math.tau',
            'area',
            'thresholds',
            'low_threshold',
            'is_excluded',
            'flag',
        ];
        $this->assertSame($expectedOutputs, $result->outputs);

        // Spot-check individual compiled symbols

        // base: number = rates.base_rate * (quote.sum_insured / 1000)
        $base = $result->symbols->get('base')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $base);
        $this->assertInstanceOf(NumberType::class, $base->type);
        $this->assertInstanceOf(InfixExpression::class, $base->source);
        $this->assertSame('*', $base->source->operator);
        // left = rates.base_rate → SymbolSource('base_rate', 'rates')
        $this->assertInstanceOf(SymbolSource::class, $base->source->left);
        $this->assertSame('base_rate', $base->source->left->name);
        $this->assertSame('rates', $base->source->left->namespace);
        // right = (quote.sum_insured / 1000)
        $this->assertInstanceOf(InfixExpression::class, $base->source->right);
        $this->assertSame('/', $base->source->right->operator);

        // percentage: number = "45%" as number → TypeDefinition(number, TypeDefinition(number, StaticSource("45%")))
        $percentage = $result->symbols->get('percentage')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $percentage);
        $this->assertInstanceOf(TypeDefinition::class, $percentage->source);
        $this->assertInstanceOf(StaticSource::class, $percentage->source->source);
        $this->assertSame('45%', $percentage->source->source->value);

        // items: list = ["roofing", "demolition", "asbestos"]
        $items = $result->symbols->get('items')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $items);
        $this->assertInstanceOf(ListType::class, $items->type);
        $this->assertInstanceOf(StaticSource::class, $items->source);
        $this->assertSame(['roofing', 'demolition', 'asbestos'], $items->source->value);

        // has_risk: bool = quote.trade in items
        $hasRisk = $result->symbols->get('has_risk')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $hasRisk);
        $this->assertInstanceOf(InfixExpression::class, $hasRisk->source);
        $this->assertSame('in', $hasRisk->source->operator);
        $this->assertInstanceOf(SymbolSource::class, $hasRisk->source->left);
        $this->assertSame('trade', $hasRisk->source->left->name);
        $this->assertSame('quote', $hasRisk->source->left->namespace);

        // is_safe: bool = quote.trade not in items → UnaryExpression('not', InfixExpression('in', ...))
        $isSafe = $result->symbols->get('is_safe')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $isSafe);
        $this->assertInstanceOf(UnaryExpression::class, $isSafe->source);
        $this->assertSame('not', $isSafe->source->operator);
        $this->assertInstanceOf(InfixExpression::class, $isSafe->source->operand);
        $this->assertSame('in', $isSafe->source->operand->operator);

        // namespace math.pi / math.tau
        $pi = $result->symbols->get('pi', 'math')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $pi);
        $this->assertInstanceOf(StaticSource::class, $pi->source);
        $this->assertSame(3.14159, $pi->source->value);

        $tau = $result->symbols->get('tau', 'math')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $tau);
        $this->assertInstanceOf(StaticSource::class, $tau->source);
        $this->assertSame(6.28318, $tau->source->value);

        // area: number = math.pi * radius * radius
        $area = $result->symbols->get('area')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $area);
        $this->assertInstanceOf(InfixExpression::class, $area->source);
        $this->assertSame('*', $area->source->operator);
        // left = math.pi * radius (left-assoc, so left of outer * is the first *)
        $this->assertInstanceOf(InfixExpression::class, $area->source->left);
        $this->assertSame('*', $area->source->left->operator);
        // math.pi → SymbolSource('pi', 'math')
        $this->assertInstanceOf(SymbolSource::class, $area->source->left->left);
        $this->assertSame('pi', $area->source->left->left->name);
        $this->assertSame('math', $area->source->left->left->namespace);

        // thresholds: dict = {"low": 1000, "high": 5000}
        $thresholds = $result->symbols->get('thresholds')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $thresholds);
        $this->assertInstanceOf(StaticSource::class, $thresholds->source);
        $this->assertSame(['low' => 1000, 'high' => 5000], $thresholds->source->value);

        // low_threshold: number = thresholds["low"]
        $low = $result->symbols->get('low_threshold')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $low);
        $this->assertInstanceOf(MemberAccessSource::class, $low->source);
        $this->assertSame('low', $low->source->property);

        // is_excluded: bool = !has_risk && is_eligible
        $excluded = $result->symbols->get('is_excluded')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $excluded);
        $this->assertInstanceOf(InfixExpression::class, $excluded->source);
        $this->assertSame('&&', $excluded->source->operator);
        $this->assertInstanceOf(UnaryExpression::class, $excluded->source->left);
        $this->assertSame('!', $excluded->source->left->operator);

        // flag: bool = a || b xor c  → a || (b xor c)  (|| is prec 10, xor is 15 — higher binds tighter)
        $flag = $result->symbols->get('flag')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $flag);
        $this->assertInstanceOf(InfixExpression::class, $flag->source);
        $this->assertSame('||', $flag->source->operator);
        $this->assertInstanceOf(InfixExpression::class, $flag->source->right);
        $this->assertSame('xor', $flag->source->right->operator);

        // --- pretty-print round-trip ---
        $printed = $this->dsl->prettyPrint($ast);
        $reparsedAst = $this->dsl->parse($printed);
        $this->assertEquals($ast->toArray(), $reparsedAst->toArray());

        // Re-compile from the round-tripped AST and verify outputs match
        $result2 = $this->dsl->compile($reparsedAst);
        $this->assertSame($expectedOutputs, $result2->outputs);
    }
}
