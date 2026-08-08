<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\DeadOperation;
use Superscript\Axiom\Operators\OperatorResolution;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnsupportedOperation;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;

#[CoversClass(BinaryOperatorResolver::class)]
#[CoversClass(UnsupportedOperation::class)]
#[CoversClass(DeadOperation::class)]
#[CoversClass(ResolvedOperation::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\PresentType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom\\Analysis')]
final class BinaryOperatorResolverTest extends TestCase
{
    private static function rule(string $operator, OperatorResolution $resolution, ?int &$calls = null): BinaryOperatorRule
    {
        return new class ($operator, $resolution, $calls) implements BinaryOperatorRule {
            public function __construct(
                private readonly string $symbol,
                private readonly OperatorResolution $resolution,
                private ?int &$calls,
            ) {}

            public function operator(): string
            {
                return $this->symbol;
            }

            public function resolve(Type $left, Type $right): OperatorResolution
            {
                ++$this->calls;

                return $this->resolution;
            }
        };
    }

    /** A second concrete rule class, so owner ordering is observable. */
    private static function alternativeRule(string $operator, OperatorResolution $resolution): BinaryOperatorRule
    {
        return new class ($operator, $resolution) implements BinaryOperatorRule {
            public function __construct(
                private readonly string $symbol,
                private readonly OperatorResolution $resolution,
            ) {}

            public function operator(): string
            {
                return $this->symbol;
            }

            public function resolve(Type $left, Type $right): OperatorResolution
            {
                return $this->resolution;
            }
        };
    }

    #[Test]
    public function it_requires_binary_rules(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        new BinaryOperatorResolver(['not a rule']);
    }

    #[Test]
    public function unknown_symbols_do_not_invoke_unrelated_rules(): void
    {
        $calls = 0;
        $resolver = new BinaryOperatorResolver([
            self::rule('+', new UnsupportedOperation('No.'), $calls),
        ]);

        $verdict = $resolver->resolve('-', new NumberType(), new NumberType());

        $this->assertSame(0, $calls);
        $this->assertSame('Operator [-] is not supported.', $verdict->unwrapErr()->message);
    }

    #[Test]
    public function a_lone_resolution_is_returned(): void
    {
        $operation = new ResolvedOperation(new NumberType(), fn() => 3);
        $resolver = new BinaryOperatorResolver([self::rule('+', $operation)]);

        $this->assertSame($operation, $resolver->resolve('+', new NumberType(), new NumberType())->unwrap());
    }

    #[Test]
    public function an_attributed_resolution_names_its_rule_and_extension(): void
    {
        $operation = new ResolvedOperation(new NumberType(), fn() => 3);
        $rule = self::rule('+', $operation);
        $resolved = (new BinaryOperatorResolver([$rule], ['catalogue.compatibility']))
            ->resolve('+', new NumberType(), new NumberType())
            ->unwrap();

        $this->assertNotSame($operation, $resolved);
        $this->assertSame($rule::class, $resolved->provenance?->identifier);
        $this->assertSame($rule::class, $resolved->provenance?->implementation);
        $this->assertSame('catalogue.compatibility', $resolved->provenance?->extension);
    }

    #[Test]
    public function extension_provenance_must_align_with_binary_rules(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('align one-for-one');

        new BinaryOperatorResolver([self::rule('+', new UnsupportedOperation('No.'))], ['one', 'extra']);
    }

    #[Test]
    public function a_resolution_wins_over_refusals(): void
    {
        $operation = new ResolvedOperation(new NumberType(), fn() => 3);
        $resolver = new BinaryOperatorResolver([
            self::rule('+', new UnsupportedOperation('No.')),
            self::rule('+', $operation),
        ]);

        $this->assertSame($operation, $resolver->resolve('+', new NumberType(), new NumberType())->unwrap());
    }

    #[Test]
    public function a_typed_fallback_applies_only_when_no_overload_resolves(): void
    {
        $fallback = new ResolvedOperation(new BooleanType(), static fn(): bool => false);
        $claimed = new ResolvedOperation(new BooleanType(), static fn(): bool => true);
        $unclaimed = new BinaryOperatorResolver([
            self::rule('==', new UnsupportedOperation('No.')),
        ]);
        $claimedResolver = new BinaryOperatorResolver([
            self::rule('==', $claimed),
        ]);

        self::assertSame(
            $fallback,
            $unclaimed->resolveOr('==', new NumberType(), new StringType(), $fallback)->unwrap(),
        );
        self::assertSame(
            $claimed,
            $claimedResolver->resolveOr('==', new NumberType(), new StringType(), $fallback)->unwrap(),
        );
    }

    #[Test]
    public function a_typed_fallback_never_hides_ambiguity(): void
    {
        $resolver = new BinaryOperatorResolver([
            self::rule('==', new ResolvedOperation(new BooleanType(), static fn(): bool => true)),
            self::alternativeRule('==', new ResolvedOperation(new BooleanType(), static fn(): bool => false)),
        ]);

        $refusal = $resolver->resolveOr(
            '==',
            new NumberType(),
            new StringType(),
            new ResolvedOperation(new BooleanType(), static fn(): bool => false),
        )->unwrapErr();

        self::assertStringContainsString('is ambiguous', $refusal->message);
    }

    #[Test]
    public function ambiguity_is_stable_and_has_no_registration_precedence(): void
    {
        $number = self::rule('+', new ResolvedOperation(new NumberType(), fn() => 1));
        $boolean = self::alternativeRule('+', new ResolvedOperation(new BooleanType(), fn() => true));

        $first = (new BinaryOperatorResolver([$number, $boolean]))->resolve('+', new NumberType(), new NumberType())->unwrapErr()->message;
        $second = (new BinaryOperatorResolver([$boolean, $number]))->resolve('+', new NumberType(), new NumberType())->unwrapErr()->message;

        $this->assertSame($first, $second);
        $this->assertStringContainsString('Operator [+] over Number and Number is ambiguous:', $first);
        $this->assertStringContainsString($number::class, $first);
        $this->assertStringContainsString($boolean::class, $first);
        $this->assertStringContainsString('exactly one owner', $first);
    }

    #[Test]
    public function a_single_refusal_preserves_its_exact_diagnostic(): void
    {
        $cause = new TypeMismatch('Because.');
        $verdict = (new BinaryOperatorResolver([
            self::rule('+', new UnsupportedOperation('Not for these values.', [$cause])),
        ]))->resolve('+', new StringType(), new StringType())->unwrapErr();

        $this->assertSame('Not for these values.', $verdict->message);
        $this->assertSame([$cause], $verdict->causes);
        $this->assertFalse($verdict->dead);
    }

    #[Test]
    public function multiple_refusals_aggregate_their_diagnostics(): void
    {
        $verdict = (new BinaryOperatorResolver([
            self::rule('+', new UnsupportedOperation('First.')),
            self::rule('+', new DeadOperation('Second.')),
        ]))->resolve('+', new BooleanType(), new BooleanType())->unwrapErr();

        $this->assertSame('No overload of [+] accepts Boolean and Boolean.', $verdict->message);
        $this->assertSame(['First.', 'Second.'], array_map(fn(TypeMismatch $cause) => $cause->message, $verdict->causes));
        $this->assertFalse($verdict->dead);
        $this->assertTrue($verdict->causes[1]->dead);
    }

    #[Test]
    public function dead_refusals_preserve_dead_metadata_singly_and_when_aggregated(): void
    {
        $single = (new BinaryOperatorResolver([
            self::rule('+', new DeadOperation('Dead.')),
        ]))->resolve('+', new NumberType(), new StringType())->unwrapErr();

        $aggregate = (new BinaryOperatorResolver([
            self::rule('+', new DeadOperation('First.')),
            self::rule('+', new DeadOperation('Second.')),
        ]))->resolve('+', new NumberType(), new StringType())->unwrapErr();

        $this->assertTrue($single->dead);
        $this->assertTrue($aggregate->dead);
        $this->assertTrue($aggregate->causes[0]->dead);
        $this->assertTrue($aggregate->causes[1]->dead);
    }

    #[Test]
    public function every_claimed_symbol_is_enumerable_once_and_sorted(): void
    {
        $resolver = new BinaryOperatorResolver([
            self::rule('xor', new UnsupportedOperation('No.')),
            self::rule('+', new UnsupportedOperation('No.')),
            self::alternativeRule('+', new UnsupportedOperation('No.')),
        ]);

        $this->assertSame(['+', 'xor'], $resolver->symbols(), 'two rows for one symbol are one offer');
    }

    #[Test]
    public function enumeration_does_not_invoke_any_rule(): void
    {
        $calls = 0;
        $resolver = new BinaryOperatorResolver([self::rule('+', new UnsupportedOperation('No.'), $calls)]);

        $resolver->symbols();
        $resolver->extensions();

        $this->assertSame(0, $calls, 'enumeration reads the index; it never asks a rule to resolve');
    }

    #[Test]
    public function each_symbol_names_the_extensions_that_claim_it(): void
    {
        $resolver = new BinaryOperatorResolver(
            [
                self::rule('xor', new UnsupportedOperation('No.')),
                self::rule('-', new UnsupportedOperation('No.')),
                self::alternativeRule('-', new UnsupportedOperation('No.')),
                // Two rows one extension owns, over different operand types.
                self::rule('+', new UnsupportedOperation('No.')),
                self::alternativeRule('+', new UnsupportedOperation('No.')),
            ],
            ['axiom.core', 'axiom.date', 'axiom.core', 'axiom.core', 'axiom.core'],
        );

        $this->assertSame([
            '+' => ['axiom.core'],
            '-' => ['axiom.core', 'axiom.date'],
            'xor' => ['axiom.core'],
        ], $resolver->extensions(), 'an owner of several rows for one symbol is named once');
        $this->assertSame($resolver->symbols(), array_keys($resolver->extensions()));
    }

    #[Test]
    public function rules_registered_without_provenance_are_unattributed(): void
    {
        $resolver = new BinaryOperatorResolver([self::rule('+', new UnsupportedOperation('No.'))]);

        $this->assertSame(['+' => ['unattributed']], $resolver->extensions());
    }

    #[Test]
    public function an_unknown_resolution_variant_is_rejected(): void
    {
        $unknown = new class implements OperatorResolution {};
        $resolver = new BinaryOperatorResolver([self::rule('+', $unknown)]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unknown operator resolution');

        $resolver->resolve('+', new NumberType(), new NumberType());
    }

    /** A rule that resolves present operand types only, like every dispatch-table row. */
    private static function presentOnlyRule(string $operator, ResolvedOperation $operation, ?int &$calls = null): BinaryOperatorRule
    {
        return new class ($operator, $operation, $calls) implements BinaryOperatorRule {
            public function __construct(
                private readonly string $symbol,
                private readonly ResolvedOperation $operation,
                private ?int &$calls,
            ) {}

            public function operator(): string
            {
                return $this->symbol;
            }

            public function resolve(Type $left, Type $right): OperatorResolution
            {
                ++$this->calls;

                if ($left instanceof OptionType || $right instanceof OptionType) {
                    return new UnsupportedOperation('Absent operands refused.');
                }

                return $this->operation;
            }
        };
    }

    /** The distinct-class twin of presentOnlyRule, so ambiguity is observable. */
    private static function alternativePresentOnlyRule(string $operator, ResolvedOperation $operation): BinaryOperatorRule
    {
        return new class ($operator, $operation) implements BinaryOperatorRule {
            public function __construct(
                private readonly string $symbol,
                private readonly ResolvedOperation $operation,
            ) {}

            public function operator(): string
            {
                return $this->symbol;
            }

            public function resolve(Type $left, Type $right): OperatorResolution
            {
                if ($left instanceof OptionType || $right instanceof OptionType) {
                    return new UnsupportedOperation('Absent operands refused.');
                }

                return $this->operation;
            }
        };
    }

    #[Test]
    public function an_optional_operand_lifts_a_present_rule(): void
    {
        $calls = 0;
        $resolver = new BinaryOperatorResolver([
            self::presentOnlyRule('+', new ResolvedOperation(new NumberType(), fn(int $left, int $right) => $left + $right), $calls),
        ]);

        $lifted = $resolver->resolve('+', new OptionType(new NumberType()), new NumberType())->unwrap();

        $this->assertSame(2, $calls, 'the exact attempt runs before the lifted one');
        $this->assertInstanceOf(OptionType::class, $lifted->returns);
        $this->assertSame(5, $lifted->evaluate(2, 3)->unwrap());
        $this->assertSame(3, $lifted->evaluate(0, 3)->unwrap(), 'a falsy present operand is not absence');
        $this->assertNull($lifted->evaluate(null, 3)->unwrap(), 'an absent left operand answers absence');
        $this->assertNull($lifted->evaluate(2, null)->unwrap(), 'an absent right operand answers absence');
    }

    #[Test]
    public function either_or_both_optional_operands_lift(): void
    {
        $resolver = new BinaryOperatorResolver([
            self::presentOnlyRule('+', new ResolvedOperation(new NumberType(), fn(int $left, int $right) => $left + $right)),
        ]);

        $rightOnly = $resolver->resolve('+', new NumberType(), new OptionType(new NumberType()))->unwrap();
        $both = $resolver->resolve('+', new OptionType(new NumberType()), new OptionType(new NumberType()))->unwrap();

        $this->assertInstanceOf(OptionType::class, $rightOnly->returns);
        $this->assertNull($rightOnly->evaluate(2, null)->unwrap());
        $this->assertInstanceOf(OptionType::class, $both->returns);
        $this->assertSame(5, $both->evaluate(2, 3)->unwrap());
    }

    #[Test]
    public function present_operands_never_attempt_a_lift(): void
    {
        $calls = 0;
        $operation = new ResolvedOperation(new NumberType(), fn() => 3);
        $resolver = new BinaryOperatorResolver([self::presentOnlyRule('+', $operation, $calls)]);

        $this->assertSame($operation, $resolver->resolve('+', new NumberType(), new NumberType())->unwrap());
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function a_rule_reading_optional_operands_wins_untouched(): void
    {
        // Equality reads `x == null` deliberately; a rule that resolves the
        // optional types as given is never second-guessed by the lift.
        $calls = 0;
        $operation = new ResolvedOperation(new BooleanType(), fn() => true);
        $resolver = new BinaryOperatorResolver([self::rule('==', $operation, $calls)]);

        $this->assertSame($operation, $resolver->resolve('==', new OptionType(new NumberType()), new NumberType())->unwrap());
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function lifting_preserves_provenance(): void
    {
        $rule = self::presentOnlyRule('+', new ResolvedOperation(new NumberType(), fn() => 3));
        $lifted = (new BinaryOperatorResolver([$rule], ['axiom.interval']))
            ->resolve('+', new OptionType(new NumberType()), new NumberType())
            ->unwrap();

        $this->assertSame('axiom.interval', $lifted->provenance?->extension);
        $this->assertSame($rule::class, $lifted->provenance?->implementation);
    }

    #[Test]
    public function a_lifted_optional_return_is_not_doubly_wrapped(): void
    {
        $resolver = new BinaryOperatorResolver([
            self::presentOnlyRule('+', new ResolvedOperation(new OptionType(new NumberType()), fn() => null)),
        ]);

        $returns = $resolver->resolve('+', new OptionType(new NumberType()), new NumberType())->unwrap()->returns;

        $this->assertInstanceOf(OptionType::class, $returns);
        $this->assertInstanceOf(NumberType::class, $returns->inner);
    }

    #[Test]
    public function lifted_ambiguity_names_the_present_types(): void
    {
        $resolver = new BinaryOperatorResolver([
            self::presentOnlyRule('+', new ResolvedOperation(new NumberType(), fn() => 1)),
            self::alternativePresentOnlyRule('+', new ResolvedOperation(new BooleanType(), fn() => true)),
        ]);

        $message = $resolver->resolve('+', new OptionType(new NumberType()), new NumberType())->unwrapErr()->message;

        $this->assertStringContainsString('Operator [+] over Number and Number is ambiguous:', $message);
    }

    #[Test]
    public function a_failed_lift_reports_the_exact_refusals(): void
    {
        $resolver = new BinaryOperatorResolver([
            self::rule('+', new UnsupportedOperation('Not for these.')),
        ]);

        $verdict = $resolver->resolve('+', new OptionType(new StringType()), new NumberType())->unwrapErr();

        $this->assertSame('Not for these.', $verdict->message, 'stage-one diagnostics stand when the lift resolves nothing');
    }

    #[Test]
    public function constant_absence_does_not_lift(): void
    {
        // A bare null types as Option<Never>: it can never be present, so
        // there is nothing to lift over and the exact refusal stands.
        $calls = 0;
        $resolver = new BinaryOperatorResolver([
            self::presentOnlyRule('+', new ResolvedOperation(new NumberType(), fn() => 3), $calls),
        ]);

        $verdict = $resolver->resolve('+', new OptionType(new NeverType()), new NumberType());

        $this->assertSame('Absent operands refused.', $verdict->unwrapErr()->message);
        $this->assertSame(1, $calls, 'the lifted attempt is never made');

        $rightSide = $resolver->resolve('+', new OptionType(new NumberType()), new OptionType(new NeverType()));

        $this->assertSame('Absent operands refused.', $rightSide->unwrapErr()->message, 'constant absence on either side declines the lift');
    }
}
