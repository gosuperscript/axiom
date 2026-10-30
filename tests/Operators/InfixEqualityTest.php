<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\DeadOperation;
use Superscript\Axiom\Operators\InfixOperatorRule;
use Superscript\Axiom\Operators\Operator;
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
 * Equality rules a package declares through the ordinary builder, over a
 * type whose values carry identity: the rule refutes operand types that
 * share no value, and leaves every other rule to admissibility alone.
 */
#[CoversClass(InfixOperatorRule::class)]
#[UsesClass(BinaryOperatorResolver::class)]
#[UsesClass(DeadOperation::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(UnsupportedOperation::class)]
#[UsesClass(Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\TransformValueException::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(IdentityLiteralType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(\Superscript\Axiom\Types\PresentType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\DistinctShapes::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\IdentityLiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OpaqueShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
final class InfixEqualityTest extends TestCase
{
    #[Test]
    public function equality_is_dead_between_disjoint_singletons(): void
    {
        $resolution = self::equality('===')->resolve(self::band(1, 5), self::band(5, 10));

        $this->assertInstanceOf(DeadOperation::class, $resolution);
        $this->assertSame('[===] between 1..5 and 5..10 is constant: it can never hold.', $resolution->message);
        $this->assertSame('1..5 and 5..10 share no values.', $resolution->causes[0]->message);
    }

    #[Test]
    #[DataProvider('equalityTokens')]
    public function every_equality_token_is_judged_and_a_negation_always_holds(string $operator, string $verdict): void
    {
        $resolution = self::equality($operator)->resolve(self::band(1, 5), self::band(5, 10));

        $this->assertInstanceOf(DeadOperation::class, $resolution);
        $this->assertSame(sprintf('[%s] between 1..5 and 5..10 is constant: it %s.', $operator, $verdict), $resolution->message);
    }

    public static function equalityTokens(): \Generator
    {
        yield '=' => ['=', 'can never hold'];
        yield '==' => ['==', 'can never hold'];
        yield '===' => ['===', 'can never hold'];
        yield '!=' => ['!=', 'always holds'];
        yield '!==' => ['!==', 'always holds'];
    }

    #[Test]
    public function a_singleton_outside_a_union_of_singletons_is_dead(): void
    {
        $offered = new UnionType(self::band(1, 5), self::band(5, 10));
        $rule = self::equality('===');

        $this->assertInstanceOf(DeadOperation::class, $rule->resolve($offered, self::band(10, 20)));
        $this->assertInstanceOf(ResolvedOperation::class, $rule->resolve($offered, self::band(5.0, 10)));
        $this->assertInstanceOf(ResolvedOperation::class, $rule->resolve($offered, new BandType()));
    }

    #[Test]
    public function it_refuses_operands_outside_its_type(): void
    {
        $resolution = self::equality('===')->resolve(new BandType(), new StringType());

        $this->assertInstanceOf(UnsupportedOperation::class, $resolution);
        $this->assertSame('[===] expects band and band; got band and String.', $resolution->message);
    }

    #[Test]
    public function absent_operands_lift_through_the_resolver(): void
    {
        $resolver = new BinaryOperatorResolver([self::equality('===')]);
        $equality = $resolver->resolve('===', new OptionType(new BandType()), self::band(1, 5))->unwrap();

        $this->assertNull($equality->evaluate(null, new Band(1, 5))->unwrap());
        $this->assertTrue($equality->evaluate(new Band(1.0, 5), new Band(1, 5))->unwrap());
    }

    /**
     * The package's own evaluation and the key literal identity reads
     * agree, so a comparison the checker calls dead can never hold.
     */
    #[Test]
    #[DataProvider('bandPairs')]
    public function runtime_equality_agrees_with_literal_identity(Band $left, Band $right): void
    {
        $identical = new IdentityLiteralType($left, new BandType())->assert($right)->isOk();

        $this->assertSame($identical, self::evaluate(self::equality('==='), $left, $right));
        $this->assertSame(!$identical, self::evaluate(self::equality('!=='), $left, $right));
        $this->assertSame(
            $identical,
            self::equality('===')->resolve(new IdentityLiteralType($left, new BandType()), new IdentityLiteralType($right, new BandType())) instanceof ResolvedOperation,
        );
    }

    public static function bandPairs(): \Generator
    {
        yield 'the same band' => [new Band(1, 5), new Band(1, 5)];
        yield 'an int bound beside a float bound' => [new Band(1, 5), new Band(1.0, 5)];
        yield 'float bounds on both sides' => [new Band(1.0, 5.0), new Band(1, 5)];
        yield 'a different upper bound' => [new Band(1, 5), new Band(1, 6)];
        yield 'a different lower bound' => [new Band(1, 5), new Band(0, 5)];
        yield 'a fractional bound' => [new Band(1.5, 5), new Band(1, 5)];
    }

    #[Test]
    public function a_rule_over_a_type_without_identity_is_unchanged(): void
    {
        $rule = Operator::infix('===')
            ->takes(new StringType(), new StringType())
            ->returns(new BooleanType())
            ->evaluatesWith(fn(string $left, string $right) => $left === $right);

        $this->assertInstanceOf(ResolvedOperation::class, $rule->resolve(new LiteralType('a'), new LiteralType('b')));
    }

    #[Test]
    public function a_rule_that_is_not_an_equality_is_unchanged(): void
    {
        $rule = Operator::infix('<')
            ->takes(new BandType(), new BandType())
            ->returns(new BooleanType())
            ->evaluatesWith(fn(Band $left, Band $right) => $left->upper <= $right->lower);

        $this->assertInstanceOf(ResolvedOperation::class, $rule->resolve(self::band(1, 5), self::band(5, 10)));
    }

    #[Test]
    public function a_rule_across_two_identity_types_is_unchanged(): void
    {
        $rule = Operator::infix('===')
            ->takes(new BandType(), new BandType('age-band'))
            ->returns(new BooleanType())
            ->evaluatesWith(fn(Band $left, Band $right) => $left->isEqualTo($right));

        $this->assertInstanceOf(ResolvedOperation::class, $rule->resolve(
            self::band(1, 5),
            new IdentityLiteralType(new Band(1, 5), new BandType('age-band')),
        ));
    }

    private static function equality(string $operator): InfixOperatorRule
    {
        $negated = in_array($operator, ['!=', '!=='], true);

        return Operator::infix($operator)
            ->takes(new BandType(), new BandType())
            ->returns(new BooleanType())
            ->evaluatesWith(fn(Band $left, Band $right) => $negated !== $left->isEqualTo($right));
    }

    private static function evaluate(InfixOperatorRule $rule, Band $left, Band $right): bool
    {
        $resolution = $rule->resolve(new BandType(), new BandType());
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
