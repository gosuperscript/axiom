<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\DeadOperation;
use Superscript\Axiom\Operators\Equality;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnsupportedOperation;
use Superscript\Axiom\Tests\Fixtures\Band;
use Superscript\Axiom\Tests\Fixtures\BandType;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\IdentityLiteralType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnionType;

/**
 * Core equality over a type whose values carry identity: two values are
 * equal when they key alike, and operand types that share no value make
 * the comparison dead.
 */
#[CoversClass(Equality::class)]
#[UsesClass(BinaryOperatorResolver::class)]
#[UsesClass(DeadOperation::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(UnsupportedOperation::class)]
#[UsesClass(Dialect::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\TransformValueException::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(IdentityLiteralType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\DistinctShapes::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\IdentityLiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OpaqueShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ShapeDomain::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[UsesClass(\Superscript\Axiom\Analysis\OperatorRuleProvenance::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[UsesClass(\Superscript\Axiom\Operators\Coalesce::class)]
#[UsesClass(\Superscript\Axiom\Operators\Connective::class)]
#[UsesClass(\Superscript\Axiom\Operators\Has::class)]
#[UsesClass(\Superscript\Axiom\Operators\In::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\Intersects::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Types\NumberType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
final class IdentityEqualityTest extends TestCase
{
    #[Test]
    public function the_core_dialect_compares_identity_values_without_a_package_rule(): void
    {
        $equality = Dialect::core()->operators()->resolve('===', new BandType(), new BandType())->unwrap();

        $this->assertInstanceOf(BooleanType::class, $equality->returns);
        $this->assertTrue($equality->evaluate(new Band(1, 5), new Band(1.0, 5))->unwrap());
        $this->assertFalse($equality->evaluate(new Band(1, 5), new Band(1, 6))->unwrap());
    }

    #[Test]
    #[DataProvider('equalityOperators')]
    public function disjoint_literals_make_every_alias_dead(string $operator, string $verdict): void
    {
        $resolution = self::equality($operator)->resolve(self::band(1, 5), self::band(5, 10));

        $this->assertInstanceOf(DeadOperation::class, $resolution);
        $this->assertSame(sprintf('[%s] between 1..5 and 5..10 is constant: it %s.', $operator, $verdict), $resolution->message);
        $this->assertSame('1..5 and 5..10 share no values.', $resolution->causes[0]->message);
    }

    public static function equalityOperators(): \Generator
    {
        yield '=' => ['=', 'can never hold'];
        yield '==' => ['==', 'can never hold'];
        yield '===' => ['===', 'can never hold'];
        yield '!=' => ['!=', 'always holds'];
        yield '!==' => ['!==', 'always holds'];
    }

    #[Test]
    public function a_literal_outside_a_union_of_literals_is_dead(): void
    {
        $offered = new UnionType(self::band(1, 5), self::band(5, 10));
        $equality = self::equality('===');

        $this->assertInstanceOf(DeadOperation::class, $equality->resolve($offered, self::band(10, 20)));
        $this->assertInstanceOf(ResolvedOperation::class, $equality->resolve($offered, self::band(5.0, 10)));
        $this->assertInstanceOf(ResolvedOperation::class, $equality->resolve(self::band(5.0, 10), $offered));
        $this->assertInstanceOf(ResolvedOperation::class, $equality->resolve($offered, new BandType()));
        $this->assertInstanceOf(ResolvedOperation::class, $equality->resolve(new UnionType(self::band(1, 5)), new BandType()));
    }

    /**
     * Literal admission and equality read one key, so a comparison the
     * checker keeps live is exactly one that can hold.
     */
    #[Test]
    #[DataProvider('bandPairs')]
    public function evaluation_agrees_with_literal_identity(Band $left, Band $right, bool $equal): void
    {
        $this->assertSame($equal, new IdentityLiteralType($left, new BandType())->assert($right)->isOk());
        $this->assertSame($equal, self::evaluate('===', $left, $right));
        $this->assertSame(!$equal, self::evaluate('!==', $left, $right));
        $this->assertSame(
            $equal,
            self::equality('===')->resolve(new IdentityLiteralType($left, new BandType()), new IdentityLiteralType($right, new BandType())) instanceof ResolvedOperation,
        );
    }

    public static function bandPairs(): \Generator
    {
        yield 'the same band' => [new Band(1, 5), new Band(1, 5), true];
        yield 'an int bound beside a float bound' => [new Band(1, 5), new Band(1.0, 5), true];
        yield 'float bounds on both sides' => [new Band(1.0, 5.0), new Band(1, 5), true];
        yield 'a different upper bound' => [new Band(1, 5), new Band(1, 6), false];
        yield 'a different lower bound' => [new Band(1, 5), new Band(0, 5), false];
        yield 'a fractional bound' => [new Band(1.5, 5), new Band(1, 5), false];
    }

    #[Test]
    public function optional_operands_compare_absence_as_a_value(): void
    {
        $equality = self::equality('===')->resolve(new OptionType(new BandType()), new OptionType(self::band(1, 5)));
        $this->assertInstanceOf(ResolvedOperation::class, $equality);

        $this->assertTrue($equality->evaluate(null, null)->unwrap());
        $this->assertFalse($equality->evaluate(null, new Band(1, 5))->unwrap());
        $this->assertFalse($equality->evaluate(new Band(1, 5), null)->unwrap());
        $this->assertTrue($equality->evaluate(new Band(1.0, 5), new Band(1, 5))->unwrap());
    }

    #[Test]
    #[DataProvider('unsupportedPairs')]
    public function operands_that_are_not_one_identity_type_are_unsupported(Type $left, Type $right): void
    {
        $resolution = self::equality('===')->resolve($left, $right);

        $this->assertInstanceOf(UnsupportedOperation::class, $resolution);
        $this->assertStringStartsWith('[===] Value equality is not defined for ', $resolution->message);
    }

    public static function unsupportedPairs(): \Generator
    {
        yield 'two different identity types' => [new BandType(), new BandType('age-band')];
        yield 'the same identity under different parameters' => [new BandType(unit: 'km'), new BandType(unit: 'mi')];
        yield 'an identity type and a string' => [new BandType(), new StringType()];
        yield 'a string and an identity type' => [new StringType(), new BandType()];
        yield 'a union mixing a band with a string' => [new UnionType(self::band(1, 5), new LiteralType('none')), new BandType()];
        yield 'a union whose first member is a string' => [new UnionType(new LiteralType('none'), self::band(1, 5)), new BandType()];
        yield 'a union across two identity types' => [new UnionType(self::band(1, 5), new IdentityLiteralType(new Band(1, 5), new BandType('age-band'))), new BandType()];
    }

    private static function equality(string $operator): Equality
    {
        return new Equality($operator, in_array($operator, ['!=', '!=='], true));
    }

    private static function evaluate(string $operator, Band $left, Band $right): bool
    {
        $resolution = self::equality($operator)->resolve(new BandType(), new BandType());
        self::assertInstanceOf(ResolvedOperation::class, $resolution);

        $result = $resolution->evaluate($left, $right)->unwrap();
        self::assertIsBool($result);

        return $result;
    }

    private static function band(int|float $lower, int|float $upper): Type
    {
        return new IdentityLiteralType(new Band($lower, $upper), new BandType());
    }
}
