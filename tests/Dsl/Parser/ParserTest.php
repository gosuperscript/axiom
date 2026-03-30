<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl\Parser;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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

#[CoversClass(Parser::class)]
#[CoversClass(TokenStream::class)]
#[UsesClass(Lexer::class)]
#[UsesClass(Token::class)]
#[UsesClass(TokenType::class)]
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
class ParserTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;

    protected function setUp(): void
    {
        $registry = new OperatorRegistry();
        $plugin = new CoreDslPlugin();
        $plugin->operators($registry);
        $registry->register('**', 80, Associativity::Right);
        $registry->register('<', 40, Associativity::Left);
        $registry->register('>', 40, Associativity::Left);

        $this->lexer = new Lexer($registry);
        $this->parser = new Parser($registry);
    }

    private function parseExpression(string $source): ProgramNode
    {
        $tokens = $this->lexer->tokenize("x: number = {$source}");

        return $this->parser->parse($tokens);
    }

    private function getExpression(ProgramNode $program): mixed
    {
        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);

        return $stmt->expression;
    }

    #[Test]
    public function it_parses_integer_literal(): void
    {
        $expr = $this->getExpression($this->parseExpression('42'));

        $this->assertInstanceOf(LiteralNode::class, $expr);
        $this->assertSame(42, $expr->value);
        $this->assertSame('42', $expr->raw);
    }

    #[Test]
    public function it_parses_float_literal(): void
    {
        $expr = $this->getExpression($this->parseExpression('3.14'));

        $this->assertInstanceOf(LiteralNode::class, $expr);
        $this->assertSame(3.14, $expr->value);
        $this->assertSame('3.14', $expr->raw);
    }

    #[Test]
    public function it_parses_percentage_literal(): void
    {
        $expr = $this->getExpression($this->parseExpression('45%'));

        $this->assertInstanceOf(LiteralNode::class, $expr);
        $this->assertSame(0.45, $expr->value);
        $this->assertSame('45%', $expr->raw);
    }

    #[Test]
    public function it_parses_string_literal(): void
    {
        $expr = $this->getExpression($this->parseExpression('"hello"'));

        $this->assertInstanceOf(LiteralNode::class, $expr);
        $this->assertSame('hello', $expr->value);
    }

    #[Test]
    public function it_parses_boolean_true(): void
    {
        $expr = $this->getExpression($this->parseExpression('true'));

        $this->assertInstanceOf(LiteralNode::class, $expr);
        $this->assertTrue($expr->value);
    }

    #[Test]
    public function it_parses_boolean_false(): void
    {
        $expr = $this->getExpression($this->parseExpression('false'));

        $this->assertInstanceOf(LiteralNode::class, $expr);
        $this->assertFalse($expr->value);
    }

    #[Test]
    public function it_parses_null_literal(): void
    {
        $expr = $this->getExpression($this->parseExpression('null'));

        $this->assertInstanceOf(LiteralNode::class, $expr);
        $this->assertNull($expr->value);
    }

    #[Test]
    public function it_parses_identifier(): void
    {
        $expr = $this->getExpression($this->parseExpression('myVar'));

        $this->assertInstanceOf(IdentifierNode::class, $expr);
        $this->assertSame('myVar', $expr->name);
    }

    #[Test]
    public function it_parses_two_level_member_access(): void
    {
        $expr = $this->getExpression($this->parseExpression('a.b'));

        $this->assertInstanceOf(MemberExpressionNode::class, $expr);
        $this->assertSame('b', $expr->property);
        $this->assertInstanceOf(IdentifierNode::class, $expr->object);
        $this->assertSame('a', $expr->object->name);
    }

    #[Test]
    public function it_parses_three_level_member_access(): void
    {
        $expr = $this->getExpression($this->parseExpression('a.b.c'));

        $this->assertInstanceOf(MemberExpressionNode::class, $expr);
        $this->assertSame('c', $expr->property);
        $this->assertInstanceOf(MemberExpressionNode::class, $expr->object);
        $this->assertSame('b', $expr->object->property);
        $this->assertInstanceOf(IdentifierNode::class, $expr->object->object);
        $this->assertSame('a', $expr->object->object->name);
    }

    #[Test]
    public function it_respects_multiplication_over_addition_precedence(): void
    {
        // 1 + 2 * 3 → 1 + (2 * 3)
        $expr = $this->getExpression($this->parseExpression('1 + 2 * 3'));

        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('+', $expr->operator);

        $this->assertInstanceOf(LiteralNode::class, $expr->left);
        $this->assertSame(1, $expr->left->value);

        $this->assertInstanceOf(InfixExpressionNode::class, $expr->right);
        $this->assertSame('*', $expr->right->operator);
        $this->assertInstanceOf(LiteralNode::class, $expr->right->left);
        $this->assertSame(2, $expr->right->left->value);
        $this->assertInstanceOf(LiteralNode::class, $expr->right->right);
        $this->assertSame(3, $expr->right->right->value);
    }

    #[Test]
    public function it_handles_right_associativity(): void
    {
        // 2 ** 3 ** 4 → 2 ** (3 ** 4)
        $expr = $this->getExpression($this->parseExpression('2 ** 3 ** 4'));

        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('**', $expr->operator);

        $this->assertInstanceOf(LiteralNode::class, $expr->left);
        $this->assertSame(2, $expr->left->value);

        $this->assertInstanceOf(InfixExpressionNode::class, $expr->right);
        $this->assertSame('**', $expr->right->operator);
    }

    #[Test]
    public function it_parses_unary_bang(): void
    {
        $expr = $this->getExpression($this->parseExpression('!x'));

        $this->assertInstanceOf(UnaryExpressionNode::class, $expr);
        $this->assertSame('!', $expr->operator);
        $this->assertInstanceOf(IdentifierNode::class, $expr->operand);
    }

    #[Test]
    public function it_parses_unary_not(): void
    {
        $expr = $this->getExpression($this->parseExpression('not x'));

        $this->assertInstanceOf(UnaryExpressionNode::class, $expr);
        $this->assertSame('not', $expr->operator);
        $this->assertInstanceOf(IdentifierNode::class, $expr->operand);
    }

    #[Test]
    public function it_parses_unary_minus(): void
    {
        $expr = $this->getExpression($this->parseExpression('-x'));

        $this->assertInstanceOf(UnaryExpressionNode::class, $expr);
        $this->assertSame('-', $expr->operator);
        $this->assertInstanceOf(IdentifierNode::class, $expr->operand);
    }

    #[Test]
    public function it_desugars_not_in(): void
    {
        // x not in items → not(x in items)
        $expr = $this->getExpression($this->parseExpression('x not in items'));

        $this->assertInstanceOf(UnaryExpressionNode::class, $expr);
        $this->assertSame('not', $expr->operator);

        $inner = $expr->operand;
        $this->assertInstanceOf(InfixExpressionNode::class, $inner);
        $this->assertSame('in', $inner->operator);
        $this->assertInstanceOf(IdentifierNode::class, $inner->left);
        $this->assertSame('x', $inner->left->name);
        $this->assertInstanceOf(IdentifierNode::class, $inner->right);
        $this->assertSame('items', $inner->right->name);
    }

    #[Test]
    public function it_parses_coercion(): void
    {
        $expr = $this->getExpression($this->parseExpression('"42" as number'));

        $this->assertInstanceOf(CoercionExpressionNode::class, $expr);
        $this->assertInstanceOf(LiteralNode::class, $expr->expression);
        $this->assertSame('42', $expr->expression->value);
        $this->assertSame('number', $expr->targetType->keyword);
    }

    #[Test]
    public function it_parses_grouped_expression(): void
    {
        $expr = $this->getExpression($this->parseExpression('(1 + 2) * 3'));

        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('*', $expr->operator);

        $this->assertInstanceOf(InfixExpressionNode::class, $expr->left);
        $this->assertSame('+', $expr->left->operator);
    }

    #[Test]
    public function it_parses_list_literal(): void
    {
        $expr = $this->getExpression($this->parseExpression('["a", "b", "c"]'));

        $this->assertInstanceOf(ListLiteralNode::class, $expr);
        $this->assertCount(3, $expr->elements);
        $this->assertInstanceOf(LiteralNode::class, $expr->elements[0]);
        $this->assertSame('a', $expr->elements[0]->value);
    }

    #[Test]
    public function it_parses_empty_list(): void
    {
        $expr = $this->getExpression($this->parseExpression('[]'));

        $this->assertInstanceOf(ListLiteralNode::class, $expr);
        $this->assertCount(0, $expr->elements);
    }

    #[Test]
    public function it_parses_dict_literal(): void
    {
        $expr = $this->getExpression($this->parseExpression('{"a": 1, "b": 2}'));

        $this->assertInstanceOf(DictLiteralNode::class, $expr);
        $this->assertCount(2, $expr->entries);
    }

    #[Test]
    public function it_parses_empty_dict(): void
    {
        $expr = $this->getExpression($this->parseExpression('{}'));

        $this->assertInstanceOf(DictLiteralNode::class, $expr);
        $this->assertCount(0, $expr->entries);
    }

    #[Test]
    public function it_parses_symbol_declaration(): void
    {
        $tokens = $this->lexer->tokenize('base: number = 42');
        $program = $this->parser->parse($tokens);

        $this->assertCount(1, $program->body);
        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertSame('base', $stmt->name);
        $this->assertSame('number', $stmt->type->keyword);
        $this->assertInstanceOf(LiteralNode::class, $stmt->expression);
    }

    #[Test]
    public function it_parses_namespace_declaration(): void
    {
        $source = <<<'AXIOM'
namespace math {
    pi: number = 3.14159
    tau: number = 6.28318
}
AXIOM;
        $tokens = $this->lexer->tokenize($source);
        $program = $this->parser->parse($tokens);

        $this->assertCount(1, $program->body);
        $ns = $program->body[0];
        $this->assertInstanceOf(NamespaceDeclarationNode::class, $ns);
        $this->assertSame('math', $ns->name);
        $this->assertCount(2, $ns->body);
        $this->assertInstanceOf(SymbolDeclarationNode::class, $ns->body[0]);
        $this->assertInstanceOf(SymbolDeclarationNode::class, $ns->body[1]);
    }

    #[Test]
    public function it_parses_type_with_args(): void
    {
        $tokens = $this->lexer->tokenize('items: list<number> = []');
        $program = $this->parser->parse($tokens);

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertSame('list', $stmt->type->keyword);
        $this->assertCount(1, $stmt->type->args);
        $this->assertSame('number', $stmt->type->args[0]->keyword);
    }

    #[Test]
    public function it_parses_index_expression(): void
    {
        $expr = $this->getExpression($this->parseExpression('items[0]'));

        $this->assertInstanceOf(IndexExpressionNode::class, $expr);
        $this->assertInstanceOf(IdentifierNode::class, $expr->object);
        $this->assertSame('items', $expr->object->name);
        $this->assertInstanceOf(LiteralNode::class, $expr->index);
        $this->assertSame(0, $expr->index->value);
    }

    #[Test]
    public function it_parses_in_operator(): void
    {
        $expr = $this->getExpression($this->parseExpression('x in items'));

        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('in', $expr->operator);
    }

    #[Test]
    public function it_throws_on_unexpected_token(): void
    {
        $this->expectException(RuntimeException::class);

        $tokens = $this->lexer->tokenize('x: number = )');
        $this->parser->parse($tokens);
    }

    #[Test]
    public function it_parses_logical_operators(): void
    {
        $expr = $this->getExpression($this->parseExpression('a && b || c'));

        // || has lower precedence, so it's the root
        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('||', $expr->operator);
        $this->assertInstanceOf(InfixExpressionNode::class, $expr->left);
        $this->assertSame('&&', $expr->left->operator);
    }

    #[Test]
    public function it_parses_list_with_trailing_comma(): void
    {
        $expr = $this->getExpression($this->parseExpression('["a", "b",]'));

        $this->assertInstanceOf(ListLiteralNode::class, $expr);
        $this->assertCount(2, $expr->elements);
    }

    #[Test]
    public function it_parses_dict_with_trailing_comma(): void
    {
        $expr = $this->getExpression($this->parseExpression('{"a": 1,}'));

        $this->assertInstanceOf(DictLiteralNode::class, $expr);
        $this->assertCount(1, $expr->entries);
    }

    #[Test]
    public function it_throws_error_with_line_and_col(): void
    {
        try {
            $tokens = $this->lexer->tokenize("x: number\n= )");
            $this->parser->parse($tokens);
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('line', $e->getMessage());
            $this->assertStringContainsString('col', $e->getMessage());
        }
    }

    #[Test]
    public function it_exposes_token_stream(): void
    {
        $tokens = $this->lexer->tokenize('x: number = 42');
        $this->parser->parse($tokens);

        $stream = $this->parser->getStream();
        $this->assertInstanceOf(TokenStream::class, $stream);
        $this->assertTrue($stream->isAtEnd());
    }

    #[Test]
    public function it_parses_multiple_declarations(): void
    {
        $source = <<<'AXIOM'
a: number = 1
b: number = 2
c: number = a + b
AXIOM;
        $tokens = $this->lexer->tokenize($source);
        $program = $this->parser->parse($tokens);

        $this->assertCount(3, $program->body);
    }

    #[Test]
    public function it_parses_comparison_chain(): void
    {
        $expr = $this->getExpression($this->parseExpression('a >= 50000'));

        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('>=', $expr->operator);
    }

    #[Test]
    public function token_stream_expect_throws_on_wrong_type(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected Colon');

        $stream = new TokenStream([
            new Token(TokenType::Ident, 'x', 1, 1),
            new Token(TokenType::Eof, '', 1, 2),
        ]);

        $stream->expect(TokenType::Colon);
    }

    #[Test]
    public function token_stream_check_value(): void
    {
        $stream = new TokenStream([
            new Token(TokenType::Ident, 'namespace', 1, 1),
            new Token(TokenType::Eof, '', 1, 10),
        ]);

        $this->assertTrue($stream->checkValue('namespace'));
        $this->assertFalse($stream->checkValue('other'));
    }

    #[Test]
    public function token_stream_peek_returns_null_past_end(): void
    {
        $stream = new TokenStream([
            new Token(TokenType::Eof, '', 1, 1),
        ]);

        $this->assertNull($stream->peek());
    }

    #[Test]
    public function it_parses_with_assign_token(): void
    {
        // Use a registry where = is not an operator, so Assign token is used
        $registry = new OperatorRegistry();
        $registry->register('+', 50, Associativity::Left);
        $registry->register('*', 60, Associativity::Left);
        $lexer = new Lexer($registry);
        $parser = new Parser($registry);

        $tokens = $lexer->tokenize('x: number = 42');
        $program = $parser->parse($tokens);

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertSame('x', $stmt->name);
    }

    #[Test]
    public function it_throws_when_missing_equals_in_declaration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Expected '='");

        $tokens = [
            new Token(TokenType::Ident, 'x', 1, 1),
            new Token(TokenType::Colon, ':', 1, 2),
            new Token(TokenType::Ident, 'number', 1, 4),
            new Token(TokenType::Ident, 'oops', 1, 11),
            new Token(TokenType::Eof, '', 1, 15),
        ];

        $this->parser->parse($tokens);
    }

    #[Test]
    public function it_parses_multi_arg_type_annotation(): void
    {
        $tokens = $this->lexer->tokenize('items: dict<string, number> = {}');
        $program = $this->parser->parse($tokens);

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertSame('dict', $stmt->type->keyword);
        $this->assertCount(2, $stmt->type->args);
        $this->assertSame('string', $stmt->type->args[0]->keyword);
        $this->assertSame('number', $stmt->type->args[1]->keyword);
    }

    #[Test]
    public function it_throws_on_missing_closing_angle_bracket(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Expected '>'");

        // Manually create tokens with < but no >
        $tokens = [
            new Token(TokenType::Ident, 'items', 1, 1),
            new Token(TokenType::Colon, ':', 1, 6),
            new Token(TokenType::Ident, 'list', 1, 8),
            new Token(TokenType::Operator, '<', 1, 12),
            new Token(TokenType::Ident, 'number', 1, 13),
            new Token(TokenType::Operator, '=', 1, 20),
            new Token(TokenType::Eof, '', 1, 21),
        ];

        $this->parser->parse($tokens);
    }

    #[Test]
    public function it_handles_not_in_at_start_of_infix_with_higher_min_precedence(): void
    {
        // Test: (a not in b) && c — the not in is handled at the right level
        $expr = $this->getExpression($this->parseExpression('a not in b && c'));

        // && has precedence 20, in has precedence 40
        // So structure should be: (a not in b) && c
        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('&&', $expr->operator);
    }

    #[Test]
    public function it_handles_not_as_prefix_not_followed_by_in(): void
    {
        // 'not' followed by something other than 'in' is a unary prefix
        $expr = $this->getExpression($this->parseExpression('not true'));

        $this->assertInstanceOf(UnaryExpressionNode::class, $expr);
        $this->assertSame('not', $expr->operator);
        $this->assertInstanceOf(LiteralNode::class, $expr->operand);
        $this->assertTrue($expr->operand->value);
    }

    #[Test]
    public function it_breaks_not_in_when_min_precedence_is_too_high(): void
    {
        // a * b not in c — the * has precedence 60, the 'in' has 40
        // When parsing right side of *, minPrecedence=61 > 40, so 'not in' breaks
        // Result: (a * b) not in c
        $expr = $this->getExpression($this->parseExpression('a * b not in c'));

        // The 'not in' has lower precedence than *, so root is not(... in ...)
        $this->assertInstanceOf(UnaryExpressionNode::class, $expr);
        $this->assertSame('not', $expr->operator);
        $inner = $expr->operand;
        $this->assertInstanceOf(InfixExpressionNode::class, $inner);
        $this->assertSame('in', $inner->operator);
        // left of 'in' is a * b
        $this->assertInstanceOf(InfixExpressionNode::class, $inner->left);
        $this->assertSame('*', $inner->left->operator);
    }

    #[Test]
    public function it_parses_not_followed_by_in_as_not_in(): void
    {
        // When 'not' appears as first token and next is 'in', it should be treated
        // as 'identifier: not' which then becomes the left side of 'in' operator
        // This tests the guard in parseUnary that skips 'not' as unary when followed by 'in'
        $tokens = [
            new Token(TokenType::Ident, 'x', 1, 1),
            new Token(TokenType::Colon, ':', 1, 2),
            new Token(TokenType::Ident, 'bool', 1, 4),
            new Token(TokenType::Operator, '=', 1, 9),
            // expression: not in items — 'not' as ident, 'in' as operator
            new Token(TokenType::Ident, 'not', 1, 11),
            new Token(TokenType::Ident, 'in', 1, 15),
            new Token(TokenType::Ident, 'items', 1, 18),
            new Token(TokenType::Eof, '', 1, 23),
        ];

        $program = $this->parser->parse($tokens);
        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);

        // 'not' is treated as an identifier, 'in' as infix operator
        $this->assertInstanceOf(InfixExpressionNode::class, $stmt->expression);
        $this->assertSame('in', $stmt->expression->operator);
        $this->assertInstanceOf(IdentifierNode::class, $stmt->expression->left);
        $this->assertSame('not', $stmt->expression->left->name);
    }
}
