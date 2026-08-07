<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\DeadOperation;
use Superscript\Axiom\Operators\Equality;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnsupportedOperation;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\Tests\Fixtures\Money;
use Superscript\Axiom\Tests\Fixtures\MoneyType;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\ShapeDomain;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Axiom\Types\UnknownType;

/**
 * Comparing against the null literal asks whether the other operand holds a value at all.
 *
 * Presence is structural: whether an option holds a value does not depend on what equality means
 * for the value inside it. An opaque type that has deliberately not defined equality can still be
 * present or absent, so the question stays answerable where value equality is not — and it is
 * asked of opaque types most, because those are the ones no sentinel value can stand in for.
 *
 * Unknown is the one operand this does not extend to: it is inert by construction, and presence
 * would be the single question askable of a value the engine knows nothing else about.
 */
#[CoversClass(Equality::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(DeadOperation::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(NeverShape::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OpaqueShape::class)]
#[UsesClass(OptionShape::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(ShapeDomain::class)]
#[UsesClass(TypeDescriber::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(TypeRelations::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(UnsupportedOperation::class)]
#[UsesClass(ValueEquality::class)]
final class AbsenceEqualityTest extends TestCase
{
    #[Test]
    public function an_opaque_operand_can_be_asked_whether_it_is_absent(): void
    {
        $resolution = self::resolve('===', negated: false, left: self::optionalMoney(), right: self::absence());

        self::assertInstanceOf(ResolvedOperation::class, $resolution);
        self::assertTrue($resolution->evaluate(null, null)->unwrap());
        self::assertFalse($resolution->evaluate(new Money(500, 'GBP'), null)->unwrap());
    }

    #[Test]
    public function an_opaque_operand_can_be_asked_whether_it_is_present(): void
    {
        $resolution = self::resolve('!==', negated: true, left: self::optionalMoney(), right: self::absence());

        self::assertInstanceOf(ResolvedOperation::class, $resolution);
        self::assertFalse($resolution->evaluate(null, null)->unwrap());
        self::assertTrue($resolution->evaluate(new Money(500, 'GBP'), null)->unwrap());
    }

    /**
     * The literal on the left is the same question asked the other way round, so the reading has to
     * look at both operands rather than assume where the author put the null.
     */
    #[Test]
    public function the_null_literal_may_be_either_operand(): void
    {
        $resolution = self::resolve('===', negated: false, left: self::absence(), right: self::optionalMoney());

        self::assertInstanceOf(ResolvedOperation::class, $resolution);
        self::assertTrue($resolution->evaluate(null, null)->unwrap());
        self::assertFalse($resolution->evaluate(null, new Money(500, 'GBP'))->unwrap());
    }

    /**
     * Unknown stays inert. Presence is answerable for an opaque value because the engine knows what
     * it holds and only declines to say when two of them are equal; of an Unknown it knows nothing,
     * and answering one question about it would be the first crack in that. Asked from both sides,
     * because the reading looks at both.
     */
    #[Test]
    public function an_unknown_operand_is_not_admitted_from_either_side(): void
    {
        $unknown = new OptionType(new UnknownType());

        self::assertInstanceOf(
            UnsupportedOperation::class,
            self::resolve('===', negated: false, left: $unknown, right: self::absence()),
        );

        self::assertInstanceOf(
            UnsupportedOperation::class,
            self::resolve('===', negated: false, left: self::absence(), right: $unknown),
        );
    }

    /**
     * An operand that cannot be absent is answered with the constant it is: making absence
     * answerable must not make a question that has only one answer look like a live one.
     */
    #[Test]
    public function an_operand_that_admits_no_absence_is_refused_as_constant(): void
    {
        self::assertInstanceOf(
            DeadOperation::class,
            self::resolve('===', negated: false, left: new MoneyType('GBP'), right: self::absence()),
        );
    }

    /**
     * Absence is not special-cased into equality generally: comparing two values still needs
     * equality defined for them, and an opaque operand still has none.
     */
    #[Test]
    public function comparing_two_opaque_values_is_still_refused(): void
    {
        self::assertInstanceOf(
            UnsupportedOperation::class,
            self::resolve('===', negated: false, left: new MoneyType('GBP'), right: new MoneyType('GBP')),
        );
    }

    #[Test]
    public function a_supported_operand_still_compares_by_value(): void
    {
        $resolution = self::resolve(
            '===',
            negated: false,
            left: new OptionType(new NumberType()),
            right: new NumberType(),
        );

        self::assertInstanceOf(ResolvedOperation::class, $resolution);
        self::assertTrue($resolution->evaluate(5, 5)->unwrap());
        self::assertFalse($resolution->evaluate(5, 6)->unwrap());
        self::assertFalse($resolution->evaluate(null, 5)->unwrap());
    }

    private static function resolve(string $operator, bool $negated, Type $left, Type $right): mixed
    {
        return new Equality($operator, $negated)->resolve($left, $right);
    }

    /** The type the `null` literal infers to: permission to hold nothing, and nothing to hold. */
    private static function absence(): Type
    {
        return new OptionType(new NeverType());
    }

    private static function optionalMoney(): Type
    {
        return new OptionType(new MoneyType('GBP'));
    }
}
