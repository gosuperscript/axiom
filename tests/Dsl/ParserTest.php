<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Dsl\Associativity;
use Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IdentifierNode;
use Superscript\Axiom\Dsl\Ast\Expressions\InfixExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IndexExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ListLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MatchArmNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MatchExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MemberExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\PipeExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\UnaryExpressionNode;
use Superscript\Axiom\Dsl\Ast\Location;
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
use Superscript\Axiom\Dsl\CoreDslPlugin;
use Superscript\Axiom\Dsl\FunctionEntry;
use Superscript\Axiom\Dsl\FunctionParam;
use Superscript\Axiom\Dsl\FunctionRegistry;
use Superscript\Axiom\Dsl\Lexer;
use Superscript\Axiom\Dsl\OperatorEntry;
use Superscript\Axiom\Dsl\OperatorPosition;
use Superscript\Axiom\Dsl\OperatorRegistry;
use Superscript\Axiom\Dsl\Parser;
use Superscript\Axiom\Dsl\Token;
use Superscript\Axiom\Dsl\TokenType;
use Superscript\Axiom\Dsl\TypeRegistry;

#[CoversClass(Parser::class)]
#[UsesClass(Lexer::class)]
#[UsesClass(Token::class)]
#[UsesClass(TokenType::class)]
#[UsesClass(OperatorRegistry::class)]
#[UsesClass(OperatorEntry::class)]
#[UsesClass(OperatorPosition::class)]
#[UsesClass(Associativity::class)]
#[UsesClass(CoreDslPlugin::class)]
#[UsesClass(FunctionRegistry::class)]
#[UsesClass(FunctionEntry::class)]
#[UsesClass(FunctionParam::class)]
#[UsesClass(TypeRegistry::class)]
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
#[UsesClass(PipeExpressionNode::class)]
#[UsesClass(ListLiteralNode::class)]
#[UsesClass(\Superscript\Axiom\Dsl\Ast\Expressions\CallExpressionNode::class)]
#[UsesClass(WildcardPatternNode::class)]
#[UsesClass(LiteralPatternNode::class)]
#[UsesClass(ExpressionPatternNode::class)]
#[UsesClass(SymbolDeclarationNode::class)]
#[UsesClass(SchemaDeclarationNode::class)]
#[UsesClass(NamespaceDeclarationNode::class)]
#[UsesClass(InputDeclarationNode::class)]
#[UsesClass(AssertStatementNode::class)]
#[UsesClass(TypeAnnotationNode::class)]
#[UsesClass(Location::class)]
class ParserTest extends TestCase
{
    private function parse(string $source): ProgramNode
    {
        $operators = new OperatorRegistry();
        $functions = new FunctionRegistry();
        $plugin = new CoreDslPlugin();
        $plugin->operators($operators);

        $lexer = new Lexer($operators);
        $tokens = $lexer->tokenize($source);

        $parser = new Parser($operators, $functions);

        return $parser->parse($tokens);
    }

    private function parseWithFunctions(string $source, FunctionRegistry $functions): ProgramNode
    {
        $operators = new OperatorRegistry();
        $plugin = new CoreDslPlugin();
        $plugin->operators($operators);

        $lexer = new Lexer($operators);
        $tokens = $lexer->tokenize($source);

        $parser = new Parser($operators, $functions);

        return $parser->parse($tokens);
    }

    // --- Symbol Declarations ---

    #[Test]
    public function it_parses_simple_symbol_declaration(): void
    {
        $program = $this->parse('x: number = 42');

        $this->assertCount(1, $program->body);
        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertSame('x', $stmt->name);
        $this->assertSame('number', $stmt->type->keyword);
        $this->assertInstanceOf(LiteralNode::class, $stmt->expression);
        $this->assertSame(42, $stmt->expression->value);
    }

    #[Test]
    public function it_parses_symbol_with_expression(): void
    {
        $program = $this->parse('total: number = a + b * c');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $expr = $stmt->expression;
        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('+', $expr->operator);
        $this->assertInstanceOf(IdentifierNode::class, $expr->left);
        $this->assertInstanceOf(InfixExpressionNode::class, $expr->right);
        $this->assertSame('*', $expr->right->operator);
    }

    #[Test]
    public function it_parses_member_access(): void
    {
        $program = $this->parse('x: number = quote.claims');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertInstanceOf(MemberExpressionNode::class, $stmt->expression);
        $this->assertSame('claims', $stmt->expression->property);
    }

    #[Test]
    public function it_parses_chained_member_access(): void
    {
        $program = $this->parse('x: number = a.b.c');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $expr = $stmt->expression;
        $this->assertInstanceOf(MemberExpressionNode::class, $expr);
        $this->assertSame('c', $expr->property);
        $this->assertInstanceOf(MemberExpressionNode::class, $expr->object);
    }

    #[Test]
    public function it_parses_index_access(): void
    {
        $program = $this->parse('x: number = items[0]');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertInstanceOf(IndexExpressionNode::class, $stmt->expression);
    }

    #[Test]
    public function it_parses_boolean_literals(): void
    {
        $program = $this->parse('x: bool = true');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertInstanceOf(LiteralNode::class, $stmt->expression);
        $this->assertTrue($stmt->expression->value);
    }

    #[Test]
    public function it_parses_false_literal(): void
    {
        $program = $this->parse('x: bool = false');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertFalse($stmt->expression->value);
    }

    #[Test]
    public function it_parses_null_literal(): void
    {
        $program = $this->parse('x: string = null');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertNull($stmt->expression->value);
    }

    #[Test]
    public function it_parses_string_literal(): void
    {
        $program = $this->parse('x: string = "hello"');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertInstanceOf(LiteralNode::class, $stmt->expression);
        $this->assertSame('hello', $stmt->expression->value);
    }

    #[Test]
    public function it_parses_float_literal(): void
    {
        $program = $this->parse('x: number = 3.14');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertSame(3.14, $stmt->expression->value);
    }

    #[Test]
    public function it_parses_unary_not(): void
    {
        $program = $this->parse('x: bool = !valid');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertInstanceOf(UnaryExpressionNode::class, $stmt->expression);
        $this->assertSame('!', $stmt->expression->operator);
    }

    #[Test]
    public function it_parses_unary_not_keyword(): void
    {
        $program = $this->parse('x: bool = not valid');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertInstanceOf(UnaryExpressionNode::class, $stmt->expression);
        $this->assertSame('not', $stmt->expression->operator);
    }

    #[Test]
    public function it_parses_coercion(): void
    {
        $program = $this->parse('x: number = val as number');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertInstanceOf(CoercionExpressionNode::class, $stmt->expression);
        $this->assertSame('number', $stmt->expression->targetType->keyword);
    }

    #[Test]
    public function it_parses_pipe(): void
    {
        $program = $this->parse('x: number = a |> b');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertInstanceOf(PipeExpressionNode::class, $stmt->expression);
    }

    #[Test]
    public function it_parses_list_literal(): void
    {
        $program = $this->parse('x: list = [1, 2, 3]');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertInstanceOf(ListLiteralNode::class, $stmt->expression);
        $this->assertCount(3, $stmt->expression->elements);
    }

    #[Test]
    public function it_parses_grouped_expression(): void
    {
        $program = $this->parse('x: number = (a + b) * c');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $expr = $stmt->expression;
        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('*', $expr->operator);
        $this->assertInstanceOf(InfixExpressionNode::class, $expr->left);
        $this->assertSame('+', $expr->left->operator);
    }

    // --- If/Then/Else ---

    #[Test]
    public function it_parses_simple_if_then_else(): void
    {
        $program = $this->parse("x: number = if a > 2\n    then b\n    else 0");

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);

        $match = $stmt->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);
        $this->assertNull($match->subject);
        $this->assertCount(2, $match->arms);

        $this->assertInstanceOf(ExpressionPatternNode::class, $match->arms[0]->pattern);
        $this->assertInstanceOf(WildcardPatternNode::class, $match->arms[1]->pattern);
    }

    #[Test]
    public function it_parses_chained_else_if(): void
    {
        $source = <<<'DSL'
        x: number = if a == 0
            then 0.9
            else if a <= 2
            then 1.0
            else 1.5
        DSL;

        $program = $this->parse($source);
        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);

        $match = $stmt->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);
        $this->assertNull($match->subject);
        $this->assertCount(3, $match->arms);

        $this->assertInstanceOf(ExpressionPatternNode::class, $match->arms[0]->pattern);
        $this->assertInstanceOf(ExpressionPatternNode::class, $match->arms[1]->pattern);
        $this->assertInstanceOf(WildcardPatternNode::class, $match->arms[2]->pattern);
    }

    #[Test]
    public function it_throws_on_missing_then(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'then'");
        $this->parse('x: number = if a > 2 b');
    }

    #[Test]
    public function it_throws_on_missing_else(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'else'");
        $this->parse("x: number = if a > 2\nthen b");
    }

    // --- Match with subject ---

    #[Test]
    public function it_parses_match_with_literal_patterns(): void
    {
        $source = <<<'DSL'
        x: number = match tier {
            "micro" => 1.3,
            "small" => 1.1,
            _ => 0.85
        }
        DSL;

        $program = $this->parse($source);
        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);

        $match = $stmt->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);
        $this->assertInstanceOf(IdentifierNode::class, $match->subject);
        $this->assertSame('tier', $match->subject->name);
        $this->assertCount(3, $match->arms);

        $this->assertInstanceOf(LiteralPatternNode::class, $match->arms[0]->pattern);
        $this->assertSame('micro', $match->arms[0]->pattern->value);
        $this->assertInstanceOf(LiteralPatternNode::class, $match->arms[1]->pattern);
        $this->assertSame('small', $match->arms[1]->pattern->value);
        $this->assertInstanceOf(WildcardPatternNode::class, $match->arms[2]->pattern);
    }

    #[Test]
    public function it_parses_match_with_numeric_patterns(): void
    {
        $source = <<<'DSL'
        x: string = match count {
            0 => "none",
            1 => "one",
            _ => "many"
        }
        DSL;

        $program = $this->parse($source);
        $match = $program->body[0]->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);

        $this->assertInstanceOf(LiteralPatternNode::class, $match->arms[0]->pattern);
        $this->assertSame(0, $match->arms[0]->pattern->value);
    }

    #[Test]
    public function it_parses_match_with_boolean_pattern(): void
    {
        $source = <<<'DSL'
        x: string = match flag {
            true => "yes",
            false => "no"
        }
        DSL;

        $program = $this->parse($source);
        $match = $program->body[0]->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);

        $this->assertInstanceOf(LiteralPatternNode::class, $match->arms[0]->pattern);
        $this->assertTrue($match->arms[0]->pattern->value);
        $this->assertInstanceOf(LiteralPatternNode::class, $match->arms[1]->pattern);
        $this->assertFalse($match->arms[1]->pattern->value);
    }

    #[Test]
    public function it_parses_match_with_null_pattern(): void
    {
        $source = <<<'DSL'
        x: string = match val {
            null => "empty",
            _ => "filled"
        }
        DSL;

        $program = $this->parse($source);
        $match = $program->body[0]->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);

        $this->assertInstanceOf(LiteralPatternNode::class, $match->arms[0]->pattern);
        $this->assertNull($match->arms[0]->pattern->value);
    }

    #[Test]
    public function it_allows_trailing_comma_in_match(): void
    {
        $source = <<<'DSL'
        x: number = match tier {
            "micro" => 1.3,
            _ => 1.0,
        }
        DSL;

        $program = $this->parse($source);
        $match = $program->body[0]->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);
        $this->assertCount(2, $match->arms);
    }

    #[Test]
    public function it_allows_no_trailing_comma_in_match(): void
    {
        $source = <<<'DSL'
        x: number = match tier {
            "micro" => 1.3,
            _ => 1.0
        }
        DSL;

        $program = $this->parse($source);
        $match = $program->body[0]->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);
        $this->assertCount(2, $match->arms);
    }

    #[Test]
    public function it_throws_on_missing_arrow_in_match(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Arrow');
        $this->parse('x: number = match tier { "a" 1 }');
    }

    // --- Subjectless match ---

    #[Test]
    public function it_parses_subjectless_match(): void
    {
        $source = <<<'DSL'
        x: number = match {
            a == 0 => 0.90,
            a <= 2 => 1.00,
            _ => 1.50
        }
        DSL;

        $program = $this->parse($source);
        $match = $program->body[0]->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);
        $this->assertNull($match->subject);
        $this->assertCount(3, $match->arms);

        $this->assertInstanceOf(ExpressionPatternNode::class, $match->arms[0]->pattern);
        $this->assertInstanceOf(ExpressionPatternNode::class, $match->arms[1]->pattern);
        $this->assertInstanceOf(WildcardPatternNode::class, $match->arms[2]->pattern);
    }

    // --- Schema ---

    #[Test]
    public function it_parses_schema_with_inputs_privates_publics_and_asserts(): void
    {
        $source = <<<'DSL'
        schema PremiumCalc {
            input quote: dict
            input rates: dict

            private base: number = rates.base * quote.sum_insured / 1000
            gross: number = base * 1.1

            assert gross >= 500
        }
        DSL;

        $program = $this->parse($source);
        $this->assertCount(1, $program->body);

        $schema = $program->body[0];
        $this->assertInstanceOf(SchemaDeclarationNode::class, $schema);
        $this->assertSame('PremiumCalc', $schema->name);
        $this->assertCount(5, $schema->members);

        $this->assertInstanceOf(InputDeclarationNode::class, $schema->members[0]);
        $this->assertSame('quote', $schema->members[0]->name);
        $this->assertSame('dict', $schema->members[0]->type->keyword);

        $this->assertInstanceOf(InputDeclarationNode::class, $schema->members[1]);
        $this->assertSame('rates', $schema->members[1]->name);

        $this->assertInstanceOf(SymbolDeclarationNode::class, $schema->members[2]);
        $this->assertSame('base', $schema->members[2]->name);
        $this->assertSame('private', $schema->members[2]->visibility);

        $this->assertInstanceOf(SymbolDeclarationNode::class, $schema->members[3]);
        $this->assertSame('gross', $schema->members[3]->name);
        $this->assertSame('public', $schema->members[3]->visibility);

        $this->assertInstanceOf(AssertStatementNode::class, $schema->members[4]);
    }

    // --- Assert ---

    #[Test]
    public function it_parses_assert_statement(): void
    {
        $program = $this->parse('assert x >= 500');

        $this->assertCount(1, $program->body);
        $assert = $program->body[0];
        $this->assertInstanceOf(AssertStatementNode::class, $assert);
        $this->assertInstanceOf(InfixExpressionNode::class, $assert->expression);
    }

    // --- Namespace ---

    #[Test]
    public function it_parses_namespace(): void
    {
        $source = <<<'DSL'
        namespace premium {
            base: number = 100
            total: number = base * 1.1
        }
        DSL;

        $program = $this->parse($source);
        $this->assertCount(1, $program->body);

        $ns = $program->body[0];
        $this->assertInstanceOf(NamespaceDeclarationNode::class, $ns);
        $this->assertSame('premium', $ns->name);
        $this->assertCount(2, $ns->body);
    }

    // --- Multiple statements ---

    #[Test]
    public function it_parses_multiple_statements(): void
    {
        $source = <<<'DSL'
        a: number = 1
        b: number = 2
        c: number = a + b
        DSL;

        $program = $this->parse($source);
        $this->assertCount(3, $program->body);
    }

    // --- Function calls ---

    #[Test]
    public function it_parses_function_call(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('max', [new FunctionParam('a'), new FunctionParam('b')], fn($a, $b) => max($a, $b));

        $program = $this->parseWithFunctions('x: number = max(a, b)', $functions);

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $call = $stmt->expression;
        $this->assertInstanceOf(\Superscript\Axiom\Dsl\Ast\Expressions\CallExpressionNode::class, $call);
        $this->assertSame('max', $call->callee);
        $this->assertCount(2, $call->positionalArgs);
    }

    // --- Error cases ---

    #[Test]
    public function it_throws_on_unexpected_token_in_primary(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected token');
        $this->parse('x: number = )');
    }

    #[Test]
    public function it_throws_on_unexpected_pattern(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected pattern');
        // Use a non-literal token as a pattern in a match with subject
        $this->parse('x: number = match tier { + => 1 }');
    }

    #[Test]
    public function it_parses_type_with_args(): void
    {
        $program = $this->parse('x: list(number) = [1, 2]');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertSame('list', $stmt->type->keyword);
        $this->assertCount(1, $stmt->type->args);
        $this->assertSame('number', $stmt->type->args[0]->keyword);
    }

    #[Test]
    public function it_parses_type_with_multiple_args(): void
    {
        $program = $this->parse('x: dict(string, number) = null');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertSame('dict', $stmt->type->keyword);
        $this->assertCount(2, $stmt->type->args);
        $this->assertSame('string', $stmt->type->args[0]->keyword);
        $this->assertSame('number', $stmt->type->args[1]->keyword);
    }

    #[Test]
    public function it_parses_function_call_with_named_args(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('clamp', [new FunctionParam('val'), new FunctionParam('min'), new FunctionParam('max')], fn($v, $min, $max) => max($min, min($max, $v)));

        $program = $this->parseWithFunctions('x: number = clamp(val: 10, min: 0, max: 100)', $functions);

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $call = $stmt->expression;
        $this->assertInstanceOf(\Superscript\Axiom\Dsl\Ast\Expressions\CallExpressionNode::class, $call);
        $this->assertSame('clamp', $call->callee);
        $this->assertCount(0, $call->positionalArgs);
        $this->assertCount(3, $call->namedArgs);
        $this->assertArrayHasKey('val', $call->namedArgs);
    }

    #[Test]
    public function it_parses_right_associative_operators(): void
    {
        $program = $this->parse('x: bool = !(!valid)');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $expr = $stmt->expression;
        $this->assertInstanceOf(UnaryExpressionNode::class, $expr);
        $this->assertInstanceOf(UnaryExpressionNode::class, $expr->operand);
    }

    #[Test]
    public function it_stops_infix_parsing_at_prefix_only_operator(): void
    {
        // `!b` triggers prefix parsing for the second statement,
        // but `a` is returned before `!` is seen in infix loop.
        // In subjectless match, `!x` in a pattern exercises the break.
        $source = <<<'DSL'
        x: number = match {
            !valid => 0,
            _ => 1
        }
        DSL;

        $program = $this->parse($source);
        $match = $program->body[0]->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);
        $pattern = $match->arms[0]->pattern;
        $this->assertInstanceOf(ExpressionPatternNode::class, $pattern);
        $this->assertInstanceOf(UnaryExpressionNode::class, $pattern->expression);
    }

    #[Test]
    public function it_parses_with_right_associative_infix_operator(): void
    {
        // Register a custom right-associative infix operator
        $operators = new OperatorRegistry();
        (new CoreDslPlugin())->operators($operators);
        $operators->register('**', 65, \Superscript\Axiom\Dsl\Associativity::Right);

        $functions = new FunctionRegistry();
        $lexer = new Lexer($operators);
        $tokens = $lexer->tokenize('x: number = 2 ** 3 ** 4');

        $parser = new Parser($operators, $functions);
        $program = $parser->parse($tokens);

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $expr = $stmt->expression;
        // Right-associative: 2 ** (3 ** 4)
        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('**', $expr->operator);
        $this->assertInstanceOf(LiteralNode::class, $expr->left);
        $this->assertSame(2, $expr->left->value);
        $this->assertInstanceOf(InfixExpressionNode::class, $expr->right);
        $this->assertSame('**', $expr->right->operator);
    }

    #[Test]
    public function it_stops_infix_at_lower_precedence(): void
    {
        // Exercises the precedence < minPrecedence break
        $program = $this->parse('x: number = a + b * c + d');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        // (a + (b * c)) + d — the * binds tighter
        $expr = $stmt->expression;
        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('+', $expr->operator);
        // Right side is d
        $this->assertInstanceOf(IdentifierNode::class, $expr->right);
        $this->assertSame('d', $expr->right->name);
        // Left is (a + (b * c))
        $this->assertInstanceOf(InfixExpressionNode::class, $expr->left);
        $this->assertSame('+', $expr->left->operator);
    }

    #[Test]
    public function it_parses_program_with_leading_newlines(): void
    {
        $program = $this->parse("\n\nx: number = 1");

        $this->assertCount(1, $program->body);
        $this->assertInstanceOf(SymbolDeclarationNode::class, $program->body[0]);
    }

    #[Test]
    public function it_parses_string_literal_with_raw_representation(): void
    {
        $program = $this->parse('x: string = "hello"');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $literal = $stmt->expression;
        $this->assertInstanceOf(LiteralNode::class, $literal);
        $this->assertSame('hello', $literal->value);
        $this->assertSame('"hello"', $literal->raw);
    }

    #[Test]
    public function it_parses_string_pattern_with_raw_representation(): void
    {
        $source = <<<'DSL'
        x: number = match tier {
            "micro" => 1.3,
            _ => 1.0
        }
        DSL;

        $program = $this->parse($source);
        $match = $program->body[0]->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);
        $pattern = $match->arms[0]->pattern;
        $this->assertInstanceOf(LiteralPatternNode::class, $pattern);
        $this->assertSame('"micro"', $pattern->raw);
    }

    #[Test]
    public function it_parses_float_pattern_as_float_type(): void
    {
        $source = <<<'DSL'
        x: string = match rate {
            3.14 => "pi",
            _ => "other"
        }
        DSL;

        $program = $this->parse($source);
        $match = $program->body[0]->expression;
        $this->assertInstanceOf(MatchExpressionNode::class, $match);
        $pattern = $match->arms[0]->pattern;
        $this->assertInstanceOf(LiteralPatternNode::class, $pattern);
        $this->assertIsFloat($pattern->value);
        $this->assertSame(3.14, $pattern->value);
    }

    #[Test]
    public function it_parses_chained_index_access(): void
    {
        $program = $this->parse('x: number = items[0][1]');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $expr = $stmt->expression;
        $this->assertInstanceOf(IndexExpressionNode::class, $expr);
        $this->assertInstanceOf(IndexExpressionNode::class, $expr->object);
    }

    #[Test]
    public function it_parses_chained_pipes(): void
    {
        $program = $this->parse('x: number = a |> b |> c');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        // Right-associative: a |> (b |> c)
        $expr = $stmt->expression;
        $this->assertInstanceOf(PipeExpressionNode::class, $expr);
        $this->assertInstanceOf(IdentifierNode::class, $expr->left);
        $this->assertSame('a', $expr->left->name);
        $this->assertInstanceOf(PipeExpressionNode::class, $expr->right);
    }

    #[Test]
    public function it_parses_coercion_followed_by_operator(): void
    {
        $program = $this->parse('x: number = val as number + 1');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        // The coercion binds tighter: (val as number) + 1
        $expr = $stmt->expression;
        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('+', $expr->operator);
        $this->assertInstanceOf(CoercionExpressionNode::class, $expr->left);
    }

    #[Test]
    public function it_parses_left_associative_subtraction(): void
    {
        $program = $this->parse('x: number = a - b - c');

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        // (a - b) - c
        $expr = $stmt->expression;
        $this->assertInstanceOf(InfixExpressionNode::class, $expr);
        $this->assertSame('-', $expr->operator);
        $this->assertInstanceOf(IdentifierNode::class, $expr->right);
        $this->assertSame('c', $expr->right->name);
        $this->assertInstanceOf(InfixExpressionNode::class, $expr->left);
        $this->assertSame('-', $expr->left->operator);
    }

    #[Test]
    public function it_parses_function_name_without_parens_as_identifier(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('max', [new FunctionParam('a'), new FunctionParam('b')], fn($a, $b) => max($a, $b));

        // max without parens should be treated as an identifier
        $program = $this->parseWithFunctions('x: number = max', $functions);

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertInstanceOf(IdentifierNode::class, $stmt->expression);
        $this->assertSame('max', $stmt->expression->name);
    }

    #[Test]
    public function it_parses_function_call_with_identifier_arg(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('abs', [new FunctionParam('val')], fn($v) => abs($v));

        $program = $this->parseWithFunctions('x: number = abs(y)', $functions);

        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $call = $stmt->expression;
        $this->assertInstanceOf(\Superscript\Axiom\Dsl\Ast\Expressions\CallExpressionNode::class, $call);
        $this->assertCount(1, $call->positionalArgs);
        $this->assertInstanceOf(IdentifierNode::class, $call->positionalArgs[0]);
    }
}
