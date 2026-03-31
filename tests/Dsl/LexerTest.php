<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Dsl\Associativity;
use Superscript\Axiom\Dsl\CoreDslPlugin;
use Superscript\Axiom\Dsl\Lexer;
use Superscript\Axiom\Dsl\OperatorEntry;
use Superscript\Axiom\Dsl\OperatorRegistry;
use Superscript\Axiom\Dsl\Token;
use Superscript\Axiom\Dsl\TokenType;

#[CoversClass(Lexer::class)]
#[CoversClass(Token::class)]
#[CoversClass(TokenType::class)]
#[UsesClass(OperatorRegistry::class)]
#[UsesClass(OperatorEntry::class)]
#[UsesClass(Associativity::class)]
#[UsesClass(CoreDslPlugin::class)]
class LexerTest extends TestCase
{
    private function makeLexer(): Lexer
    {
        $operators = new OperatorRegistry();
        (new CoreDslPlugin())->operators($operators);

        return new Lexer($operators);
    }

    #[Test]
    public function it_tokenizes_simple_symbol_declaration(): void
    {
        $tokens = $this->makeLexer()->tokenize('x: number = 42');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('x', $tokens[0]->value);
        $this->assertSame(TokenType::Colon, $tokens[1]->type);
        $this->assertSame(TokenType::Ident, $tokens[2]->type);
        $this->assertSame('number', $tokens[2]->value);
        $this->assertSame(TokenType::Equals, $tokens[3]->type);
        $this->assertSame(TokenType::Number, $tokens[4]->type);
        $this->assertSame('42', $tokens[4]->value);
        $this->assertSame(TokenType::Eof, $tokens[5]->type);
    }

    #[Test]
    public function it_tokenizes_strings(): void
    {
        $tokens = $this->makeLexer()->tokenize('"hello world"');

        $this->assertSame(TokenType::String, $tokens[0]->type);
        $this->assertSame('hello world', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_single_quoted_strings(): void
    {
        $tokens = $this->makeLexer()->tokenize("'hello'");

        $this->assertSame(TokenType::String, $tokens[0]->type);
        $this->assertSame('hello', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_string_escape_sequences(): void
    {
        $tokens = $this->makeLexer()->tokenize('"hello\\nworld"');

        $this->assertSame("hello\nworld", $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_escaped_quote(): void
    {
        $tokens = $this->makeLexer()->tokenize('"say \\"hi\\""');

        $this->assertSame('say "hi"', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_escaped_backslash(): void
    {
        $tokens = $this->makeLexer()->tokenize('"back\\\\slash"');

        $this->assertSame('back\\slash', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_escaped_tab(): void
    {
        $tokens = $this->makeLexer()->tokenize('"tab\\there"');

        $this->assertSame("tab\there", $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_unknown_escape_literally(): void
    {
        $tokens = $this->makeLexer()->tokenize('"hello\\xworld"');

        $this->assertSame('hello\\xworld', $tokens[0]->value);
    }

    #[Test]
    public function it_throws_for_unterminated_string(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unterminated string');
        $this->makeLexer()->tokenize('"hello');
    }

    #[Test]
    public function it_tokenizes_floating_point_numbers(): void
    {
        $tokens = $this->makeLexer()->tokenize('3.14');

        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('3.14', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_negative_numbers(): void
    {
        $tokens = $this->makeLexer()->tokenize('-5');

        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('-5', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_minus_as_operator_after_ident(): void
    {
        $tokens = $this->makeLexer()->tokenize('x - 5');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('-', $tokens[1]->value);
        $this->assertSame(TokenType::Number, $tokens[2]->type);
        $this->assertSame('5', $tokens[2]->value);
    }

    #[Test]
    public function it_tokenizes_negative_number_after_operator(): void
    {
        $tokens = $this->makeLexer()->tokenize('x + -5');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('+', $tokens[1]->value);
        $this->assertSame(TokenType::Number, $tokens[2]->type);
        $this->assertSame('-5', $tokens[2]->value);
    }

    #[Test]
    public function it_tokenizes_operators(): void
    {
        $tokens = $this->makeLexer()->tokenize('a + b * c >= d && e');

        $values = array_map(fn(Token $t) => $t->value, $tokens);
        $this->assertSame(['a', '+', 'b', '*', 'c', '>=', 'd', '&&', 'e', ''], $values);
    }

    #[Test]
    public function it_tokenizes_triple_equals(): void
    {
        $tokens = $this->makeLexer()->tokenize('a === b');

        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('===', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_keyword_operators(): void
    {
        $tokens = $this->makeLexer()->tokenize('x in list');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('in', $tokens[1]->value);
        $this->assertSame(TokenType::Ident, $tokens[2]->type);
    }

    #[Test]
    public function it_tokenizes_arrow(): void
    {
        $tokens = $this->makeLexer()->tokenize('"a" => 1');

        $this->assertSame(TokenType::String, $tokens[0]->type);
        $this->assertSame(TokenType::Arrow, $tokens[1]->type);
        $this->assertSame('=>', $tokens[1]->value);
        $this->assertSame(TokenType::Number, $tokens[2]->type);
    }

    #[Test]
    public function it_tokenizes_pipe(): void
    {
        $tokens = $this->makeLexer()->tokenize('x |> func');

        $this->assertSame(TokenType::Pipe, $tokens[1]->type);
        $this->assertSame('|>', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_braces_brackets_parens(): void
    {
        $tokens = $this->makeLexer()->tokenize('{ } [ ] ( )');

        $this->assertSame(TokenType::LeftBrace, $tokens[0]->type);
        $this->assertSame(TokenType::RightBrace, $tokens[1]->type);
        $this->assertSame(TokenType::LeftBracket, $tokens[2]->type);
        $this->assertSame(TokenType::RightBracket, $tokens[3]->type);
        $this->assertSame(TokenType::LeftParen, $tokens[4]->type);
        $this->assertSame(TokenType::RightParen, $tokens[5]->type);
    }

    #[Test]
    public function it_tokenizes_dot(): void
    {
        $tokens = $this->makeLexer()->tokenize('quote.claims');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Dot, $tokens[1]->type);
        $this->assertSame(TokenType::Ident, $tokens[2]->type);
    }

    #[Test]
    public function it_tokenizes_newlines(): void
    {
        $tokens = $this->makeLexer()->tokenize("a\nb");

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Newline, $tokens[1]->type);
        $this->assertSame(TokenType::Ident, $tokens[2]->type);
    }

    #[Test]
    public function it_skips_comments(): void
    {
        $tokens = $this->makeLexer()->tokenize("x // comment\ny");

        $values = array_map(fn(Token $t) => $t->value, $tokens);
        $this->assertSame(['x', "\n", 'y', ''], $values);
    }

    #[Test]
    public function it_tokenizes_wildcard_underscore(): void
    {
        $tokens = $this->makeLexer()->tokenize('_ => 1');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('_', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_underscore_prefixed_ident(): void
    {
        $tokens = $this->makeLexer()->tokenize('_foo');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('_foo', $tokens[0]->value);
    }

    #[Test]
    public function it_tracks_line_and_column(): void
    {
        $tokens = $this->makeLexer()->tokenize("x\ny");

        $this->assertSame(1, $tokens[0]->line);
        $this->assertSame(1, $tokens[0]->col);
        $this->assertSame(2, $tokens[2]->line);
        $this->assertSame(1, $tokens[2]->col);
    }

    #[Test]
    public function it_throws_for_unexpected_character(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected character');
        $this->makeLexer()->tokenize('@');
    }

    #[Test]
    public function it_tokenizes_booleans_and_null_as_idents(): void
    {
        $tokens = $this->makeLexer()->tokenize('true false null');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('true', $tokens[0]->value);
        $this->assertSame(TokenType::Ident, $tokens[1]->type);
        $this->assertSame('false', $tokens[1]->value);
        $this->assertSame(TokenType::Ident, $tokens[2]->type);
        $this->assertSame('null', $tokens[2]->value);
    }

    #[Test]
    public function it_tokenizes_comma(): void
    {
        $tokens = $this->makeLexer()->tokenize('a, b');

        $this->assertSame(TokenType::Comma, $tokens[1]->type);
    }

    #[Test]
    public function it_tokenizes_negative_after_equals(): void
    {
        $tokens = $this->makeLexer()->tokenize('x = -5');

        $this->assertSame(TokenType::Equals, $tokens[1]->type);
        $this->assertSame(TokenType::Number, $tokens[2]->type);
        $this->assertSame('-5', $tokens[2]->value);
    }

    #[Test]
    public function it_tokenizes_negative_after_arrow(): void
    {
        $tokens = $this->makeLexer()->tokenize('"a" => -1');

        $this->assertSame(TokenType::Arrow, $tokens[1]->type);
        $this->assertSame(TokenType::Number, $tokens[2]->type);
        $this->assertSame('-1', $tokens[2]->value);
    }

    #[Test]
    public function it_tokenizes_not_keyword_operator(): void
    {
        $tokens = $this->makeLexer()->tokenize('not x');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('not', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_exclamation_operator(): void
    {
        $tokens = $this->makeLexer()->tokenize('!x');

        $this->assertSame(TokenType::Operator, $tokens[0]->type);
        $this->assertSame('!', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_empty_string(): void
    {
        $tokens = $this->makeLexer()->tokenize('');

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::Eof, $tokens[0]->type);
    }

    #[Test]
    public function it_tokenizes_negative_after_comma(): void
    {
        $tokens = $this->makeLexer()->tokenize('1, -2');

        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame(TokenType::Comma, $tokens[1]->type);
        $this->assertSame(TokenType::Number, $tokens[2]->type);
        $this->assertSame('-2', $tokens[2]->value);
    }

    #[Test]
    public function it_tokenizes_negative_after_colon(): void
    {
        $tokens = $this->makeLexer()->tokenize('x: -1');

        $this->assertSame(TokenType::Colon, $tokens[1]->type);
        $this->assertSame(TokenType::Number, $tokens[2]->type);
        $this->assertSame('-1', $tokens[2]->value);
    }

    #[Test]
    public function it_tokenizes_negative_after_paren(): void
    {
        $tokens = $this->makeLexer()->tokenize('(-1)');

        $this->assertSame(TokenType::LeftParen, $tokens[0]->type);
        $this->assertSame(TokenType::Number, $tokens[1]->type);
        $this->assertSame('-1', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_negative_after_bracket(): void
    {
        $tokens = $this->makeLexer()->tokenize('[-1]');

        $this->assertSame(TokenType::LeftBracket, $tokens[0]->type);
        $this->assertSame(TokenType::Number, $tokens[1]->type);
        $this->assertSame('-1', $tokens[1]->value);
    }

    #[Test]
    public function it_treats_minus_after_number_as_operator(): void
    {
        $tokens = $this->makeLexer()->tokenize('3-2');

        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('3', $tokens[0]->value);
        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('-', $tokens[1]->value);
        $this->assertSame(TokenType::Number, $tokens[2]->type);
        $this->assertSame('2', $tokens[2]->value);
    }

    #[Test]
    public function it_tokenizes_underscore_at_end_of_source(): void
    {
        $tokens = $this->makeLexer()->tokenize('_');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('_', $tokens[0]->value);
        $this->assertSame(TokenType::Eof, $tokens[1]->type);
    }

    #[Test]
    public function it_tokenizes_underscore_followed_by_space(): void
    {
        $tokens = $this->makeLexer()->tokenize('_ x');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('_', $tokens[0]->value);
        $this->assertSame(TokenType::Ident, $tokens[1]->type);
        $this->assertSame('x', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_underscore_followed_by_dot(): void
    {
        $tokens = $this->makeLexer()->tokenize('_.x');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('_', $tokens[0]->value);
        $this->assertSame(TokenType::Dot, $tokens[1]->type);
        $this->assertSame(TokenType::Ident, $tokens[2]->type);
        $this->assertSame('x', $tokens[2]->value);
    }

    #[Test]
    public function it_tokenizes_carriage_return_as_whitespace(): void
    {
        $tokens = $this->makeLexer()->tokenize("x\r y");

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame('x', $tokens[0]->value);
        $this->assertSame(TokenType::Ident, $tokens[1]->type);
        $this->assertSame('y', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_source_with_only_comment(): void
    {
        $tokens = $this->makeLexer()->tokenize('// just a comment');

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::Eof, $tokens[0]->type);
    }

    #[Test]
    public function it_tokenizes_multiple_comments(): void
    {
        $tokens = $this->makeLexer()->tokenize("x // first\n// second\ny");

        $values = array_map(fn(Token $t) => $t->value, $tokens);
        // Comment doesn't consume its trailing newline
        $this->assertSame(['x', "\n", "\n", 'y', ''], $values);
    }

    #[Test]
    public function it_tokenizes_triple_equals_at_end(): void
    {
        $tokens = $this->makeLexer()->tokenize('a ===');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('===', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_two_char_operator_at_end(): void
    {
        $tokens = $this->makeLexer()->tokenize('a >=');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('>=', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_pipe_at_end(): void
    {
        $tokens = $this->makeLexer()->tokenize('x |>');

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Pipe, $tokens[1]->type);
        $this->assertSame('|>', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_pipe_with_correct_position(): void
    {
        $tokens = $this->makeLexer()->tokenize('x |> y');

        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Pipe, $tokens[1]->type);
        $this->assertSame(TokenType::Ident, $tokens[2]->type);
        $this->assertSame('y', $tokens[2]->value);
        $this->assertSame(6, $tokens[2]->col);
    }

    #[Test]
    public function it_tokenizes_triple_equals_with_correct_position(): void
    {
        $tokens = $this->makeLexer()->tokenize('a === b');

        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('===', $tokens[1]->value);
        $this->assertSame(TokenType::Ident, $tokens[2]->type);
        $this->assertSame('b', $tokens[2]->value);
        $this->assertSame(7, $tokens[2]->col);
    }

    #[Test]
    public function it_builds_three_char_operator_correctly(): void
    {
        $tokens = $this->makeLexer()->tokenize('a !== b');

        $this->assertCount(4, $tokens);
        $this->assertSame('!==', $tokens[1]->value);
        $this->assertSame('b', $tokens[2]->value);
    }

    #[Test]
    public function it_tokenizes_arrow_at_end(): void
    {
        $tokens = $this->makeLexer()->tokenize('"a" =>');

        $this->assertSame(TokenType::String, $tokens[0]->type);
        $this->assertSame(TokenType::Arrow, $tokens[1]->type);
    }

    #[Test]
    public function it_tracks_column_after_tab(): void
    {
        $tokens = $this->makeLexer()->tokenize("\tx");

        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(1, $tokens[0]->line);
        $this->assertSame(2, $tokens[0]->col);
    }

    #[Test]
    public function it_tracks_line_number_at_eof(): void
    {
        $tokens = $this->makeLexer()->tokenize("x\ny\n");

        // Eof should be on line 3 (after two newlines)
        $eof = $tokens[count($tokens) - 1];
        $this->assertSame(TokenType::Eof, $eof->type);
        $this->assertSame(3, $eof->line);
    }

    #[Test]
    public function it_tokenizes_number_at_end_of_source(): void
    {
        $tokens = $this->makeLexer()->tokenize('42');

        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('42', $tokens[0]->value);
        $this->assertSame(TokenType::Eof, $tokens[1]->type);
    }

    #[Test]
    public function it_tokenizes_float_ending_at_source_end(): void
    {
        $tokens = $this->makeLexer()->tokenize('3.14');

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('3.14', $tokens[0]->value);
    }

    #[Test]
    public function it_treats_number_followed_by_dot_without_digit_as_separate(): void
    {
        // "3." should be number "3" followed by dot, not a float
        $tokens = $this->makeLexer()->tokenize('3.x');

        $this->assertSame(TokenType::Number, $tokens[0]->type);
        $this->assertSame('3', $tokens[0]->value);
        $this->assertSame(TokenType::Dot, $tokens[1]->type);
        $this->assertSame(TokenType::Ident, $tokens[2]->type);
    }

    #[Test]
    public function it_tokenizes_string_with_escape_at_end(): void
    {
        // String ending with escaped quote
        $tokens = $this->makeLexer()->tokenize('"a\\""');

        $this->assertSame(TokenType::String, $tokens[0]->type);
        $this->assertSame('a"', $tokens[0]->value);
    }

    #[Test]
    public function it_tokenizes_double_not_equals(): void
    {
        $tokens = $this->makeLexer()->tokenize('a !== b');

        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('!==', $tokens[1]->value);
    }

    #[Test]
    public function it_tokenizes_source_ending_with_whitespace(): void
    {
        $tokens = $this->makeLexer()->tokenize('x   ');

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Eof, $tokens[1]->type);
    }

    #[Test]
    public function it_tokenizes_source_ending_with_comment(): void
    {
        $tokens = $this->makeLexer()->tokenize('x // comment');

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::Ident, $tokens[0]->type);
        $this->assertSame(TokenType::Eof, $tokens[1]->type);
    }
}
