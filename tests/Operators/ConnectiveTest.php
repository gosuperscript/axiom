<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\Connective;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnsupportedOperation;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\UnknownType;

/**
 * Kleene's three-valued tables, pinned value by value: a dominant present
 * operand decides alone, and only a result no present operand can decide
 * is absent.
 */
#[CoversClass(Connective::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(UnsupportedOperation::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ShapeDomain::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
final class ConnectiveTest extends TestCase
{
    private static function and(): Connective
    {
        return new Connective('&&', conjunction: true, identifier: 'axiom.boolean.and');
    }

    private static function or(): Connective
    {
        return new Connective('||', conjunction: false, identifier: 'axiom.boolean.or');
    }

    #[Test]
    public function it_names_its_operator_and_identifier(): void
    {
        $this->assertSame('&&', self::and()->operator());
        $this->assertSame('axiom.boolean.and', self::and()->identifier());
    }

    #[Test]
    public function present_booleans_stay_present(): void
    {
        $resolution = self::and()->resolve(new BooleanType(), new BooleanType());

        $this->assertInstanceOf(ResolvedOperation::class, $resolution);
        $this->assertInstanceOf(BooleanType::class, $resolution->returns);
    }

    #[Test]
    public function boolean_literals_are_admitted(): void
    {
        $resolution = self::or()->resolve(new LiteralType(true), new BooleanType());

        $this->assertInstanceOf(ResolvedOperation::class, $resolution);
        $this->assertInstanceOf(BooleanType::class, $resolution->returns);
    }

    #[Test]
    public function an_optional_operand_makes_the_result_optional(): void
    {
        $left = self::and()->resolve(new OptionType(new BooleanType()), new BooleanType());
        $right = self::and()->resolve(new BooleanType(), new OptionType(new BooleanType()));

        $this->assertInstanceOf(ResolvedOperation::class, $left);
        $this->assertInstanceOf(OptionType::class, $left->returns);
        $this->assertInstanceOf(ResolvedOperation::class, $right);
        $this->assertInstanceOf(OptionType::class, $right->returns);
    }

    #[Test]
    public function a_non_boolean_operand_is_refused_with_its_cause(): void
    {
        $resolution = self::and()->resolve(new NumberType(), new BooleanType());

        $this->assertInstanceOf(UnsupportedOperation::class, $resolution);
        $this->assertSame('[&&] expects Boolean and Boolean; got Number and Boolean.', $resolution->message);
        $this->assertNotSame([], $resolution->causes);
    }

    #[Test]
    public function an_inert_unknown_operand_is_refused_with_the_fix(): void
    {
        $resolution = self::or()->resolve(new UnknownType(), new BooleanType());

        $this->assertInstanceOf(UnsupportedOperation::class, $resolution);
        $this->assertStringContainsString(
            'An Unknown operand is inert',
            implode(' ', array_map(fn($cause) => $cause->message, $resolution->causes)),
        );
    }

    #[Test]
    #[DataProvider('kleeneCases')]
    public function it_evaluates_by_the_kleene_tables(Connective $connective, ?bool $left, ?bool $right, ?bool $expected): void
    {
        $resolution = $connective->resolve(
            new OptionType(new BooleanType()),
            new OptionType(new BooleanType()),
        );

        $this->assertInstanceOf(ResolvedOperation::class, $resolution);
        $this->assertSame($expected, $resolution->evaluate($left, $right)->unwrap());
    }

    public static function kleeneCases(): Generator
    {
        $and = self::and();
        yield 'true and true' => [$and, true, true, true];
        yield 'true and false' => [$and, true, false, false];
        yield 'false decides a conjunction over absence' => [$and, false, null, false];
        yield 'false decides a conjunction over absence, either side' => [$and, null, false, false];
        yield 'true decides nothing in a conjunction with absence' => [$and, true, null, null];
        yield 'absence and absence conjoin to absence' => [$and, null, null, null];

        $or = self::or();
        yield 'false or false' => [$or, false, false, false];
        yield 'true or true' => [$or, true, true, true];
        yield 'true decides a disjunction over absence' => [$or, true, null, true];
        yield 'true decides a disjunction over absence, either side' => [$or, null, true, true];
        yield 'false decides nothing in a disjunction with absence' => [$or, false, null, null];
        yield 'absence or absence is absence' => [$or, null, null, null];
    }
}
