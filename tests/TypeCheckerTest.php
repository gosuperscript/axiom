<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Exceptions\TypeCheckException;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Sources\CoerceSource;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\TypeChecker;

#[CoversClass(TypeChecker::class)]
#[CoversClass(TypeCheckException::class)]
class TypeCheckerTest extends TestCase
{
    private function ctx(Bindings $bindings = new Bindings(), Definitions $definitions = new Definitions()): Context
    {
        return new Context(
            bindings: $bindings,
            definitions: $definitions,
            operators: new DefaultOverloader(),
        );
    }

    #[Test]
    public function static_number_source_types_as_number(): void
    {
        $result = TypeChecker::check(new StaticSource(42), $this->ctx());

        $this->assertTrue($result->isOk());
        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function static_string_source_types_as_string(): void
    {
        $result = TypeChecker::check(new StaticSource('hello'), $this->ctx());

        $this->assertInstanceOf(StringType::class, $result->unwrap());
    }

    #[Test]
    public function numeric_infix_types_as_number(): void
    {
        $source = new InfixExpression(new StaticSource(1), '+', new StaticSource(2));

        $this->assertInstanceOf(NumberType::class, TypeChecker::check($source, $this->ctx())->unwrap());
    }

    #[Test]
    public function adding_a_string_to_a_number_is_a_type_error(): void
    {
        $source = new InfixExpression(new StaticSource('hello'), '+', new StaticSource(5));

        $result = TypeChecker::check($source, $this->ctx());

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(TypeCheckException::class, $result->unwrapErr());
        $this->assertStringContainsString('no operator overload for String + Number', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function comparing_any_two_types_is_boolean(): void
    {
        $source = new InfixExpression(new StaticSource('a'), '==', new StaticSource('b'));

        $this->assertInstanceOf(BooleanType::class, TypeChecker::check($source, $this->ctx())->unwrap());
    }

    #[Test]
    public function logical_and_on_booleans_is_boolean(): void
    {
        $source = new InfixExpression(new StaticSource(true), '&&', new StaticSource(false));

        $this->assertInstanceOf(BooleanType::class, TypeChecker::check($source, $this->ctx())->unwrap());
    }

    #[Test]
    public function logical_and_on_non_booleans_is_a_type_error(): void
    {
        $source = new InfixExpression(new StaticSource(true), '&&', new StaticSource(3));

        $this->assertTrue(TypeChecker::check($source, $this->ctx())->isErr());
    }

    #[Test]
    public function unknown_symbol_is_a_type_error(): void
    {
        $source = new SymbolSource('missing');

        $err = TypeChecker::check($source, $this->ctx())->unwrapErr();

        $this->assertStringContainsString("unknown symbol 'missing'", $err->getMessage());
        $this->assertSame($source, $err->node);
    }

    #[Test]
    public function declared_parameter_is_used_for_symbol_type(): void
    {
        $source = new InfixExpression(new SymbolSource('radius'), '*', new StaticSource(2));

        $result = TypeChecker::check(
            $source,
            $this->ctx(new Bindings([], ['radius' => new NumberType()])),
        );

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function wrongly_typed_parameter_propagates_a_type_error(): void
    {
        // parameter is declared as string, but expression multiplies it
        $source = new InfixExpression(new SymbolSource('name'), '*', new StaticSource(2));

        $result = TypeChecker::check(
            $source,
            $this->ctx(new Bindings([], ['name' => new StringType()])),
        );

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('String * Number', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function definition_type_is_resolved_recursively(): void
    {
        $source = new InfixExpression(new SymbolSource('PI'), '*', new StaticSource(2));

        $result = TypeChecker::check(
            $source,
            $this->ctx(
                new Bindings(),
                new Definitions(['PI' => new StaticSource(3.14)]),
            ),
        );

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function coerce_source_advertises_target_type(): void
    {
        $source = new CoerceSource(new StaticSource('5'), new NumberType());

        $this->assertInstanceOf(NumberType::class, TypeChecker::check($source, $this->ctx())->unwrap());
    }

    #[Test]
    public function unary_bang_yields_boolean(): void
    {
        $source = new UnaryExpression('!', new StaticSource(true));

        $this->assertInstanceOf(BooleanType::class, TypeChecker::check($source, $this->ctx())->unwrap());
    }

    #[Test]
    public function unary_minus_on_string_is_a_type_error(): void
    {
        $source = new UnaryExpression('-', new StaticSource('hello'));

        $this->assertTrue(TypeChecker::check($source, $this->ctx())->isErr());
    }

    #[Test]
    public function member_access_on_dict_yields_inner_type(): void
    {
        $source = new MemberAccessSource(
            new StaticSource([], new DictType(new NumberType())),
            'claims',
        );

        $this->assertInstanceOf(NumberType::class, TypeChecker::check($source, $this->ctx())->unwrap());
    }

    #[Test]
    public function member_access_on_scalar_is_a_type_error(): void
    {
        $source = new MemberAccessSource(new StaticSource(42), 'anything');

        $this->assertTrue(TypeChecker::check($source, $this->ctx())->isErr());
    }

    #[Test]
    public function match_with_uniform_arm_types_yields_that_type(): void
    {
        $source = new MatchExpression(
            subject: new StaticSource('x'),
            arms: [
                new MatchArm(new LiteralPattern('a'), new StaticSource(1)),
                new MatchArm(new LiteralPattern('b'), new StaticSource(2)),
                new MatchArm(new WildcardPattern(), new StaticSource(3)),
            ],
        );

        $this->assertInstanceOf(NumberType::class, TypeChecker::check($source, $this->ctx())->unwrap());
    }

    #[Test]
    public function match_with_mixed_arm_types_is_a_type_error(): void
    {
        $source = new MatchExpression(
            subject: new StaticSource('x'),
            arms: [
                new MatchArm(new LiteralPattern('a'), new StaticSource(1)),
                new MatchArm(new WildcardPattern(), new StaticSource('fallback')),
            ],
        );

        $err = TypeChecker::check($source, $this->ctx())->unwrapErr();
        $this->assertStringContainsString('not compatible', $err->getMessage());
    }

    #[Test]
    public function match_bad_pattern_expression_is_reported(): void
    {
        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(
                    new ExpressionPattern(new InfixExpression(new StaticSource('foo'), '+', new StaticSource(1))),
                    new StaticSource(1),
                ),
            ],
        );

        $this->assertTrue(TypeChecker::check($source, $this->ctx())->isErr());
    }

    #[Test]
    public function list_of_numbers_preserves_inner_type(): void
    {
        $source = new StaticSource([], new ListType(new NumberType()));

        $type = TypeChecker::check($source, $this->ctx())->unwrap();
        $this->assertInstanceOf(ListType::class, $type);
        $this->assertInstanceOf(NumberType::class, $type->type);
    }

    #[Test]
    public function list_has_with_wrong_left_side_is_type_error(): void
    {
        // "foo" has "bar" — left side is a string, not a list
        $source = new InfixExpression(new StaticSource('foo'), 'has', new StaticSource('bar'));

        $this->assertTrue(TypeChecker::check($source, $this->ctx())->isErr());
    }
}
