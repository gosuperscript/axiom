<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Analysis\OperatorRuleProvenance;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\Equality;
use Superscript\Axiom\Operators\OperatorResolution;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnsupportedOperation;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\Tests\Fixtures\Money;
use Superscript\Axiom\Tests\Fixtures\MoneyType;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\InfixExpressionTyping;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\PresentType;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\ShapeDomain;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Axiom\Types\UnknownType;

/**
 * Comparing an equality alias with the absence-only type is a core structural judgment.
 * It observes only whether the other operand's outer value is null, independently of value
 * equality for its payload and independently of how many ordinary equality overloads exist.
 */
#[CoversClass(InfixExpressionTyping::class)]
#[CoversClass(Equality::class)]
#[UsesClass(BinaryOperatorResolver::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(ListShape::class)]
#[UsesClass(ListType::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(NeverShape::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OpaqueShape::class)]
#[UsesClass(OptionShape::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(OperatorRuleProvenance::class)]
#[UsesClass(PresentType::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(ShapeDomain::class)]
#[UsesClass(TypeDescriber::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(TypeRelations::class)]
#[UsesClass(UnknownShape::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(UnsupportedOperation::class)]
#[UsesClass(ValueEquality::class)]
final class PresenceComparisonTest extends TestCase
{
    #[Test]
    public function an_optional_opaque_operand_can_be_asked_whether_it_is_absent(): void
    {
        $operation = self::typing()->resolve(
            '===',
            new OptionType(new MoneyType('GBP')),
            new OptionType(new NeverType()),
        )->unwrap();

        self::assertTrue($operation->evaluate(null, null)->unwrap());
        self::assertFalse($operation->evaluate(new Money(500, 'GBP'), null)->unwrap());
    }

    #[Test]
    public function a_negated_alias_asks_whether_the_operand_is_present(): void
    {
        $operation = self::typing('!==', negated: true)
            ->resolve(
                '!==',
                new OptionType(new MoneyType('GBP')),
                new OptionType(new NeverType()),
            )
            ->unwrap();

        self::assertFalse($operation->evaluate(null, null)->unwrap());
        self::assertTrue($operation->evaluate(new Money(500, 'GBP'), null)->unwrap());
    }

    #[Test]
    public function the_absence_only_operand_may_be_on_either_side(): void
    {
        $operation = self::typing()->resolve(
            '===',
            new OptionType(new NeverType()),
            new OptionType(new MoneyType('GBP')),
        )->unwrap();

        self::assertTrue($operation->evaluate(null, null)->unwrap());
        self::assertFalse($operation->evaluate(null, new Money(500, 'GBP'))->unwrap());
    }

    #[Test]
    public function absence_compared_with_itself_has_the_structural_answer(): void
    {
        $operation = self::typing()->resolve(
            '===',
            new OptionType(new NeverType()),
            new OptionType(new NeverType()),
        )->unwrap();

        self::assertTrue($operation->evaluate(null, null)->unwrap());
    }

    #[Test]
    public function structural_presence_is_settled_once_before_all_overloads(): void
    {
        $calls = 0;
        $resolver = new BinaryOperatorResolver([
            self::extensionRule($calls),
            new Equality('===', negated: false),
            new Equality('===', negated: false),
        ], ['host.equality', 'axiom.core', 'axiom.duplicate']);

        $operation = new InfixExpressionTyping($resolver)->resolve(
            '===',
            new OptionType(new MoneyType('GBP')),
            new OptionType(new NeverType()),
        )->unwrap();

        self::assertSame(0, $calls);
        self::assertSame('axiom.option.null-comparison', $operation->provenance?->identifier);
        self::assertSame(InfixExpressionTyping::class, $operation->provenance?->implementation);
        self::assertSame('axiom.core', $operation->provenance?->extension);
    }

    #[Test]
    public function a_total_operand_reaches_ordinary_dialect_resolution(): void
    {
        $calls = 0;
        $resolver = new BinaryOperatorResolver([
            self::extensionRule($calls),
            new Equality('===', negated: false),
        ]);

        $operation = new InfixExpressionTyping($resolver)->resolve(
            '===',
            new MoneyType('GBP'),
            new OptionType(new NeverType()),
        )->unwrap();

        self::assertSame(1, $calls);
        self::assertFalse($operation->evaluate(new Money(500, 'GBP'), null)->unwrap());
    }

    #[Test]
    public function a_known_total_operand_uses_the_structural_fallback_when_no_overload_owns_it(): void
    {
        $equality = self::typing()->resolve(
            '===',
            new MoneyType('GBP'),
            new OptionType(new NeverType()),
        )->unwrap();
        $inequality = self::typing('!==', negated: true)->resolve(
            '!==',
            new MoneyType('GBP'),
            new OptionType(new NeverType()),
        )->unwrap();

        self::assertFalse($equality->evaluate(new Money(500, 'GBP'), null)->unwrap());
        self::assertTrue($inequality->evaluate(new Money(500, 'GBP'), null)->unwrap());
    }

    #[Test]
    public function an_unknown_payload_does_not_hide_the_known_option_constructor(): void
    {
        $unknown = new OptionType(new UnknownType());
        $nestedUnknown = new OptionType(new ListType(new UnknownType()));

        $unknownOperation = self::typing()->resolve(
            '===',
            $unknown,
            new OptionType(new NeverType()),
        )->unwrap();
        $nestedOperation = self::typing()->resolve(
            '===',
            $nestedUnknown,
            new OptionType(new NeverType()),
        )->unwrap();

        self::assertTrue($unknownOperation->evaluate(null, null)->unwrap());
        self::assertFalse($unknownOperation->evaluate(new \stdClass(), null)->unwrap());
        self::assertFalse($nestedOperation->evaluate([new \stdClass()], null)->unwrap());
    }

    #[Test]
    public function a_bare_unknown_has_no_observable_outer_constructor(): void
    {
        $refusal = self::typing()->resolve(
            '===',
            new UnknownType(),
            new OptionType(new NeverType()),
        )->unwrapErr();

        self::assertStringContainsString('Unknown', $refusal->message);
    }

    #[Test]
    public function ordinary_equality_no_longer_owns_the_structural_reading(): void
    {
        $resolution = new Equality('===', negated: false)->resolve(
            new OptionType(new MoneyType('GBP')),
            new OptionType(new NeverType()),
        );

        self::assertInstanceOf(UnsupportedOperation::class, $resolution);
    }

    #[Test]
    public function comparing_two_opaque_values_still_needs_the_owning_package(): void
    {
        $refusal = self::typing()
            ->resolve('===', new MoneyType('GBP'), new MoneyType('GBP'))
            ->unwrapErr();

        self::assertStringContainsString('Value equality is not defined', $refusal->message);
    }

    #[Test]
    public function two_optional_opaque_values_are_not_mistaken_for_absence_only(): void
    {
        $calls = 0;
        $resolver = new BinaryOperatorResolver([
            self::extensionRule($calls),
            new Equality('===', negated: false),
        ]);

        new InfixExpressionTyping($resolver)->resolve(
            '===',
            new OptionType(new MoneyType('GBP')),
            new OptionType(new MoneyType('GBP')),
        )->unwrap();

        self::assertSame(1, $calls);
    }

    #[Test]
    public function supported_operands_still_compare_by_value(): void
    {
        $operation = self::typing()->resolve(
            '===',
            new OptionType(new NumberType()),
            new NumberType(),
        )->unwrap();

        self::assertTrue($operation->evaluate(5, 5)->unwrap());
        self::assertFalse($operation->evaluate(5, 6)->unwrap());
        self::assertFalse($operation->evaluate(null, 5)->unwrap());
    }

    #[Test]
    public function non_equality_symbols_delegate_directly_to_overload_resolution(): void
    {
        $typing = new InfixExpressionTyping(new BinaryOperatorResolver([
            new Equality('same', negated: false),
        ]));

        $operation = $typing->resolve('same', new NumberType(), new NumberType())->unwrap();

        self::assertTrue($operation->evaluate(5, 5)->unwrap());
    }

    #[Test]
    public function structural_elaboration_never_manufactures_an_unoffered_equality_alias(): void
    {
        $typing = new InfixExpressionTyping(new BinaryOperatorResolver([
            new Equality('same', negated: false),
        ]));

        $refusal = $typing->resolve(
            '===',
            new OptionType(new MoneyType('GBP')),
            new OptionType(new NeverType()),
        )->unwrapErr();

        self::assertSame('Operator [===] is not supported.', $refusal->message);
    }

    private static function typing(string $operator = '===', bool $negated = false): InfixExpressionTyping
    {
        return new InfixExpressionTyping(new BinaryOperatorResolver([new Equality($operator, $negated)]));
    }

    private static function extensionRule(?int &$calls): BinaryOperatorRule
    {
        return new class ($calls) implements BinaryOperatorRule {
            public function __construct(
                private ?int &$calls,
            ) {}

            public function operator(): string
            {
                return '===';
            }

            public function resolve(Type $left, Type $right): OperatorResolution
            {
                ++$this->calls;

                return new ResolvedOperation(
                    new BooleanType(),
                    static fn(mixed $left, mixed $right): bool => $left === $right,
                );
            }
        };
    }
}
