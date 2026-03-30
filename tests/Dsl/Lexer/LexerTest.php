<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl\Lexer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Dsl\Associativity;
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

#[CoversClass(Lexer::class)]
#[CoversClass(Token::class)]
#[CoversClass(TokenType::class)]
#[UsesClass(OperatorRegistry::class)]
#[UsesClass(OperatorEntry::class)]
#[UsesClass(Associativity::class)]
#[UsesClass(OperatorPosition::class)]
#[UsesClass(CoreDslPlugin::class)]
#[UsesClass(TypeRegistry::class)]
#[UsesClass(FunctionRegistry::class)]
#[UsesClass(FunctionEntry::class)]
#[UsesClass(FunctionParam::class)]
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
class LexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $registry = new OperatorRegistry();
        $plugin = new CoreDslPlugin();
        $plugin->operators($registry);
        // Also register ** for tests
        $registry->register('**', 80, Associativity::Right);
        // Register < and > as operators for type annotations
        $registry->register('<', 40, Associativity::Left);
        $registry->register('>', 40, Associativity::Left);

        $this->lexer = new Lexer($registry);
    }

    #[Test]
    public function it_tokenizes_integers(): void
    {
        $tokens = $this->lexer->tokenize('42');

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('42', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_floats(): void
    {
        $tokens = $this->lexer->tokenize('3.14');

        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('3.14', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_percentages(): void
    {
        $tokens = $this->lexer->tokenize('45%');

        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('45%', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_strings(): void
    {
        $tokens = $this->lexer->tokenize('"hello"');

        $this->assertSame(TokenType::String, $tokens[0]->type);
        $this->assertSame('hello', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_strings_with_escaped_quotes(): void
    {
        $tokens = $this->lexer->tokenize('"escaped \\"quote\\""');

        $this->assertSame(TokenType::String, $tokens[0]->type);
        $this->assertSame('escaped \\"quote\\"', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_true(): void
    {
        $tokens = $this->lexer->tokenize('true');

        $this->assertSame(TokenType::True, $tokens[0]->type);
        $this->assertSame('true', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_false(): void
    {
        $tokens = $this->lexer->tokenize('false');

        $this->assertSame(TokenType::False, $tokens[0]->type);
        $this->assertSame('false', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_null(): void
    {
        $tokens = $this->lexer->tokenize('null');

        $this->assertSame(TokenType::Null, $tokens[0]->type);
        $this->assertSame('null', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_identifiers(): void
    {
        $tokens = $this->lexer->tokenize('myVar');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('myVar', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_identifiers_with_underscores(): void
    {
        $tokens = $this->lexer->tokenize('_my_var2');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('_my_var2', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_multi_char_operators(): void
    {
        $tokens = $this->lexer->tokenize('**');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('**', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_not_equal(): void
    {
        $tokens = $this->lexer->tokenize('!=');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('!=', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_less_than_or_equal(): void
    {
        $tokens = $this->lexer->tokenize('<=');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('<=', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_greater_than_or_equal(): void
    {
        $tokens = $this->lexer->tokenize('>=');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('>=', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_double_equal(): void
    {
        $tokens = $this->lexer->tokenize('==');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('==', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_triple_equal(): void
    {
        $tokens = $this->lexer->tokenize('===');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('===', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_keyword_operators_as_ident(): void
    {
        $tokens = $this->lexer->tokenize('x in items');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('x', $tokens[0]->value);

        $this->assertSame(TokenType::Ident, $tokens[1]->type);
        $this->assertSame('in', $tokens[1]->value);

        $this->assertSame(TokenType::Ident, $tokens[2]->type);
        $this->assertSame('items', $tokens[2]->value);
    }

    #[Test]
    public function it_tokenizes_has_keyword_as_ident(): void
    {
        $tokens = $this->lexer->tokenize('has');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('has', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_not_keyword_as_ident(): void
    {
        $tokens = $this->lexer->tokenize('not');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('not', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_structural_tokens(): void
    {
        $tokens = $this->lexer->tokenize('( ) [ ] { } , :');

        $this->assertSame(TokenType::LeftParen, $tokens[0]->type);
        $this->assertSame(TokenType::RightParen, $tokens[1]->type);
        $this->assertSame(TokenType::LeftBracket, $tokens[2]->type);
        $this->assertSame(TokenType::RightBracket, $tokens[3]->type);
        $this->assertSame(TokenType::LeftBrace, $tokens[4]->type);
        $this->assertSame(TokenType::RightBrace, $tokens[5]->type);
        $this->assertSame(TokenType::Comma, $tokens[6]->type);
        $this->assertSame(TokenType::Colon, $tokens[7]->type);
    }

    #[Test]
    public function it_tokenizes_dot_vs_dotdot(): void
    {
        $tokens = $this->lexer->tokenize('a.b .. c');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Dot, $tokens[1]->type);
        $this->assertSame('.', $tokens[1]->value);
        $this->assertSame(TokenType::Ident, $tokens[2]->type);
        $this->assertSame(TokenType::DotDot, $tokens[3]->type);
        $this->assertSame('..', $tokens[3]->value);
        $this->assertSame(TokenType::Ident, $tokens[4]->type);
    }

    #[Test]
    public function it_tokenizes_arrow_vs_assign(): void
    {
        $tokens = $this->lexer->tokenize('=> =');

        $this->assertSame(TokenType::Arrow, $tokens[0]->type);
        $this->assertSame('=>', $tokens[0]->value);

        // = is registered as an operator in CoreDslPlugin
        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('=', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_assign_when_not_operator(): void
    {
        // Use a fresh registry without = as operator
        $registry = new OperatorRegistry();
        $registry->register('+', 50, Associativity::Left);
        $lexer = new Lexer($registry);

        $tokens = $lexer->tokenize('x = 5');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Assign, $tokens[1]->type);
        $this->assertSame('=', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_pipe(): void
    {
        $tokens = $this->lexer->tokenize('|');

        $this->assertSame(TokenType::Pipe, $tokens[0]->type);
        $this->assertSame('|', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_pipe_operator(): void
    {
        $tokens = $this->lexer->tokenize('|>');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('|>', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_logical_or(): void
    {
        $tokens = $this->lexer->tokenize('||');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('||', $tokens[0]->value);
    }

    #[Test]
    public function it_skips_whitespace(): void
    {
        $tokens = $this->lexer->tokenize("  42  \t  true  ");

        $this->assertCount(3, $tokens);
        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame(TokenType::True, $tokens[1]->type);
        $this->assertSame(TokenType::Eof, $tokens[2]->type);
    }

    #[Test]
    public function it_skips_comments(): void
    {
        $tokens = $this->lexer->tokenize("42 // this is a comment\ntrue");

        $this->assertCount(3, $tokens);
        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('42', $tokens[0]->value);
        $this->assertSame(TokenType::True, $tokens[1]->type);
    }

    #[Test]
    public function it_tracks_line_and_col_positions(): void
    {
        $tokens = $this->lexer->tokenize("x + y\n  z");

        $this->assertSame(1, $tokens[0]->line);
        $this->assertSame(1, $tokens[0]->col);

        $this->assertSame(1, $tokens[1]->line);
        $this->assertSame(3, $tokens[1]->col);

        $this->assertSame(1, $tokens[2]->line);
        $this->assertSame(5, $tokens[2]->col);

        $this->assertSame(2, $tokens[3]->line);
        $this->assertSame(3, $tokens[3]->col);
    }

    #[Test]
    public function it_throws_on_unterminated_string(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unterminated string');

        $this->lexer->tokenize('"hello');
    }

    #[Test]
    public function it_throws_on_unterminated_string_with_newline(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unterminated string');

        $this->lexer->tokenize("\"hello\nworld\"");
    }

    #[Test]
    public function it_throws_on_unexpected_character(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected character');

        $this->lexer->tokenize('$invalid');
    }

    #[Test]
    public function it_always_ends_with_eof(): void
    {
        $tokens = $this->lexer->tokenize('');

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::Eof, $tokens[0]->type);
    }

    #[Test]
    public function it_tokenizes_arithmetic_operators(): void
    {
        $tokens = $this->lexer->tokenize('+ - * /');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('+', $tokens[0]->value);
        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('-', $tokens[1]->value);
        $this->assertSame(TokenType::Operator, $tokens[2]->type);
        $this->assertSame('*', $tokens[2]->value);
        $this->assertSame(TokenType::Operator, $tokens[3]->type);
        $this->assertSame('/', $tokens[3]->value);
    }

    #[Test]
    public function it_tokenizes_logical_and(): void
    {
        $tokens = $this->lexer->tokenize('&&');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('&&', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_bang(): void
    {
        $tokens = $this->lexer->tokenize('!x');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('!', $tokens[0]->value);
        $this->assertSame(TokenType::Ident, $tokens[1]->type);
    }

    #[Test]
    public function it_tokenizes_not_equal_before_single_bang(): void
    {
        $tokens = $this->lexer->tokenize('!=');

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('!=', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_complex_expression(): void
    {
        $tokens = $this->lexer->tokenize('base: number = rates.base_rate * (quote.sum_insured / 1000)');

        $expected = [
            [TokenType::Ident, 'base'],
            [TokenType::Colon, ':'],
            [TokenType::Ident, 'number'],
            [TokenType::Operator, '='],
            [TokenType::Ident, 'rates'],
            [TokenType::Dot, '.'],
            [TokenType::Ident, 'base_rate'],
            [TokenType::Operator, '*'],
            [TokenType::LeftParen, '('],
            [TokenType::Ident, 'quote'],
            [TokenType::Dot, '.'],
            [TokenType::Ident, 'sum_insured'],
            [TokenType::Operator, '/'],
            [TokenType::Number, '1000'],
            [TokenType::RightParen, ')'],
            [TokenType::Eof, ''],
        ];

        $this->assertCount(count($expected), $tokens);
        foreach ($expected as $i => [$type, $value]) {
            $this->assertSame($type, $tokens[$i]->type, "Token $i type mismatch");
            $this->assertSame($value, $tokens[$i]->value, "Token $i value mismatch");
        }
    }

    #[Test]
    public function it_tokenizes_not_strict_equal(): void
    {
        $tokens = $this->lexer->tokenize('!==');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('!==', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_string_with_escape_sequences(): void
    {
        $tokens = $this->lexer->tokenize('"hello\\nworld"');

        $this->assertSame(TokenType::String, $tokens[0]->type);
        $this->assertSame('hello\\nworld', $tokens[0]->value);
    }

    #[Test]
    public function it_tracks_column_correctly_for_multichar_tokens(): void
    {
        $tokens = $this->lexer->tokenize('abc == def');

        // 'abc' at col 1, '==' at col 5, 'def' at col 8
        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame(5, $tokens[1]->col);
        $this->assertSame(8, $tokens[2]->col);
    }

    #[Test]
    public function it_tracks_column_after_newline_correctly(): void
    {
        $tokens = $this->lexer->tokenize("abc\ndef");

        $this->assertSame(1, $tokens[0]->line);
        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame(2, $tokens[1]->line);
        $this->assertSame(1, $tokens[1]->col);
    }

    #[Test]
    public function it_tracks_column_correctly_for_number_with_decimal(): void
    {
        $tokens = $this->lexer->tokenize('3.14 x');

        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame('3.14', $tokens[0]->value);
        $this->assertSame(6, $tokens[1]->col);
    }

    #[Test]
    public function it_tracks_column_correctly_for_percentage(): void
    {
        $tokens = $this->lexer->tokenize('45% x');

        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame('45%', $tokens[0]->value);
        $this->assertSame(5, $tokens[1]->col);
    }

    #[Test]
    public function it_tracks_column_correctly_for_strings(): void
    {
        $tokens = $this->lexer->tokenize('"hi" x');

        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame('hi', $tokens[0]->value);
        $this->assertSame(6, $tokens[1]->col);
    }

    #[Test]
    public function it_tracks_column_after_comment(): void
    {
        $tokens = $this->lexer->tokenize("// comment\nx");

        $this->assertSame(2, $tokens[0]->line);
        $this->assertSame(1, $tokens[0]->col);
    }

    #[Test]
    public function it_correctly_tokenizes_dot_before_number(): void
    {
        // `.5` should be Dot + Number(5), not a float
        $tokens = $this->lexer->tokenize('.5');

        $this->assertSame(TokenType::Dot, $tokens[0]->type);
        $this->assertSame(TokenType::Number, $tokens[1]->type);
        $this->assertSame('5', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_number_followed_by_dot_not_decimal(): void
    {
        // `5..` should be Number(5) DotDot
        $tokens = $this->lexer->tokenize('5..');

        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('5', $tokens[0]->value);
        $this->assertSame(TokenType::DotDot, $tokens[1]->type);
    }

    #[Test]
    public function it_tokenizes_number_followed_by_dot_ident(): void
    {
        // `5.x` should be Number(5) Dot Ident(x)
        $tokens = $this->lexer->tokenize('5.x');

        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('5', $tokens[0]->value);
        $this->assertSame(TokenType::Dot, $tokens[1]->type);
        $this->assertSame(TokenType::Ident, $tokens[2]->type);
    }

    #[Test]
    public function it_tracks_column_for_dotdot(): void
    {
        $tokens = $this->lexer->tokenize('a.. b');

        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame(2, $tokens[1]->col);
        $this->assertSame(5, $tokens[2]->col);
    }

    #[Test]
    public function it_tracks_column_for_arrow(): void
    {
        $tokens = $this->lexer->tokenize('=> x');

        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame(4, $tokens[1]->col);
    }

    #[Test]
    public function it_tracks_column_for_triple_equal(): void
    {
        $tokens = $this->lexer->tokenize('=== x');

        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame(5, $tokens[1]->col);
    }

    #[Test]
    public function it_tracks_column_for_double_pipe(): void
    {
        $tokens = $this->lexer->tokenize('|| x');

        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame(4, $tokens[1]->col);
    }

    #[Test]
    public function it_tracks_column_for_pipe_arrow(): void
    {
        $tokens = $this->lexer->tokenize('|> x');

        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame(4, $tokens[1]->col);
    }

    #[Test]
    public function it_tracks_column_for_single_pipe(): void
    {
        $tokens = $this->lexer->tokenize('| x');

        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame(3, $tokens[1]->col);
    }

    #[Test]
    public function it_handles_tab_as_whitespace(): void
    {
        $tokens = $this->lexer->tokenize("x\ty");

        $this->assertCount(3, $tokens);
        $this->assertSame('x', $tokens[0]->value);
        $this->assertSame('y', $tokens[1]->value);
    }

    #[Test]
    public function it_handles_carriage_return_as_whitespace(): void
    {
        $tokens = $this->lexer->tokenize("x\ry");

        $this->assertCount(3, $tokens);
        $this->assertSame('x', $tokens[0]->value);
        $this->assertSame('y', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_escaped_backslash_in_string(): void
    {
        $tokens = $this->lexer->tokenize('"a\\\\b"');

        $this->assertSame(TokenType::String, $tokens[0]->type);
        $this->assertSame('a\\\\b', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_comment_at_end_of_input(): void
    {
        $tokens = $this->lexer->tokenize('42 // comment');

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame(TokenType::Eof, $tokens[1]->type);
    }
}
