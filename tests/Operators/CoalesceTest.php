<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\Coalesce;
use Superscript\Axiom\Operators\DeadOperation;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnsupportedOperation;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

/**
 * The authored default, pinned: what `??` types, what it refuses as
 * constant, and that it answers the fallback exactly when the left operand
 * is absent.
 */
#[CoversClass(Coalesce::class)]
#[UsesClass(DeadOperation::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(UnsupportedOperation::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
#[UsesClass(\Superscript\Axiom\Types\PresentType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeReifier::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ShapeDomain::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
final class CoalesceTest extends TestCase
{
    #[Test]
    public function it_names_its_operator_and_identifier(): void
    {
        $this->assertSame('??', (new Coalesce())->operator());
        $this->assertSame('axiom.option.coalesce', (new Coalesce())->identifier());
    }

    #[Test]
    public function a_present_fallback_discharges_the_absence(): void
    {
        $resolution = (new Coalesce())->resolve(new OptionType(new NumberType()), new LiteralType(0));

        $this->assertInstanceOf(ResolvedOperation::class, $resolution);
        $this->assertTrue(TypeRelations::areEquivalent($resolution->returns, new NumberType())->isOk());
    }

    #[Test]
    public function an_optional_fallback_leaves_the_result_optional(): void
    {
        $resolution = (new Coalesce())->resolve(
            new OptionType(new NumberType()),
            new OptionType(new LiteralType(0)),
        );

        $this->assertInstanceOf(ResolvedOperation::class, $resolution);
        $this->assertTrue(TypeRelations::areEquivalent($resolution->returns, new OptionType(new NumberType()))->isOk());
    }

    /**
     * The result is the left's present type, not the fallback's: a narrow
     * fallback discharges the absence without narrowing what may come back.
     */
    #[Test]
    public function the_result_is_the_lefts_present_type(): void
    {
        $enum = new UnionType(new LiteralType('micro'), new LiteralType('small'));
        $resolution = (new Coalesce())->resolve(new OptionType($enum), new LiteralType('micro'));

        $this->assertInstanceOf(ResolvedOperation::class, $resolution);
        $this->assertTrue(TypeRelations::areEquivalent($resolution->returns, $enum)->isOk());
    }

    #[Test]
    #[DataProvider('evaluationCases')]
    public function it_answers_the_left_when_present_and_the_fallback_otherwise(mixed $left, mixed $expected): void
    {
        $resolution = (new Coalesce())->resolve(new OptionType(new NumberType()), new LiteralType(0));

        $this->assertInstanceOf(ResolvedOperation::class, $resolution);
        $this->assertSame($expected, $resolution->evaluate($left, 0)->unwrap());
    }

    public static function evaluationCases(): Generator
    {
        yield 'a present value survives' => [5, 5];
        yield 'a present zero is not absence' => [0, 0];
        yield 'absence takes the fallback' => [null, 0];
    }

    #[Test]
    public function both_arms_absent_answers_absence(): void
    {
        $resolution = (new Coalesce())->resolve(
            new OptionType(new NumberType()),
            new OptionType(new NumberType()),
        );

        $this->assertInstanceOf(ResolvedOperation::class, $resolution);
        $this->assertNull($resolution->evaluate(null, null)->unwrap());
        $this->assertSame(2, $resolution->evaluate(null, 2)->unwrap());
    }

    #[Test]
    #[DataProvider('constantCases')]
    public function it_refuses_a_constant_operation_as_dead(Type $left, Type $right, string $expected): void
    {
        $resolution = (new Coalesce())->resolve($left, $right);

        $this->assertInstanceOf(DeadOperation::class, $resolution);
        $this->assertSame($expected, $resolution->message);
    }

    public static function constantCases(): Generator
    {
        yield 'a present left can never fall back' => [
            new NumberType(),
            new LiteralType(0),
            '[??] between Number and 0 is constant: Number is always present, so the fallback can never fire.',
        ];
        yield 'a literal left can never fall back either' => [
            new LiteralType(5),
            new LiteralType(0),
            '[??] between 5 and 0 is constant: 5 is always present, so the fallback can never fire.',
        ];
        yield 'a left that is never present always falls back' => [
            new OptionType(new NeverType()),
            new LiteralType(0),
            '[??] between Never? and 0 is constant: Never? is never present, so the result is always the fallback.',
        ];
        yield 'a null fallback discharges nothing' => [
            new OptionType(new NumberType()),
            new OptionType(new NeverType()),
            '[??] between Number? and Never? is constant: the fallback is itself absent, so it discharges nothing.',
        ];
    }

    #[Test]
    public function a_fallback_of_the_wrong_type_is_refused_with_its_cause(): void
    {
        $resolution = (new Coalesce())->resolve(new OptionType(new NumberType()), new StringType());

        $this->assertInstanceOf(UnsupportedOperation::class, $resolution);
        $this->assertSame('[??] expects a fallback assignable to Number; got String.', $resolution->message);
        $this->assertStringContainsString(
            'String is not assignable to Number',
            implode(' ', array_map(fn($cause) => $cause->describe(), $resolution->causes)),
        );
    }

    /**
     * A wider fallback is refused rather than widening the result: a union
     * return would make `??` the only operator whose result type nothing in
     * the expression declares.
     */
    #[Test]
    public function a_fallback_outside_the_present_type_is_refused(): void
    {
        $resolution = (new Coalesce())->resolve(
            new OptionType(new UnionType(new LiteralType('micro'), new LiteralType('small'))),
            new LiteralType('other'),
        );

        $this->assertInstanceOf(UnsupportedOperation::class, $resolution);
        $this->assertSame("[??] expects a fallback assignable to 'micro' | 'small'; got 'other'.", $resolution->message);
    }

    #[Test]
    public function an_inert_unknown_left_is_refused_with_the_fix(): void
    {
        $resolution = (new Coalesce())->resolve(new UnknownType(), new LiteralType(0));

        $this->assertInstanceOf(UnsupportedOperation::class, $resolution);
        $this->assertStringContainsString('Claim its type with an Ascription, or convert it with a Coerce', $resolution->message);
    }

    #[Test]
    public function an_inert_unknown_fallback_is_refused_with_the_fix(): void
    {
        $resolution = (new Coalesce())->resolve(new OptionType(new NumberType()), new UnknownType());

        $this->assertInstanceOf(UnsupportedOperation::class, $resolution);
        $this->assertStringContainsString(
            'An Unknown operand is inert',
            implode(' ', array_map(fn($cause) => $cause->message, $resolution->causes)),
        );
    }

    /**
     * Optionality that arrives as a union rather than an OptionType is the
     * same claim, and `??` reads it the same way.
     */
    #[Test]
    public function union_shaped_optionality_is_read_as_optional(): void
    {
        $resolution = (new Coalesce())->resolve(
            new UnionType(new BooleanType(), new OptionType(new NeverType())),
            new LiteralType(false),
        );

        $this->assertInstanceOf(ResolvedOperation::class, $resolution);
        $this->assertTrue(TypeRelations::areEquivalent($resolution->returns, new BooleanType())->isOk());
        $this->assertFalse($resolution->evaluate(null, false)->unwrap());
    }
}
