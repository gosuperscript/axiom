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
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;

#[CoversClass(BinaryOperatorResolver::class)]
#[CoversClass(UnsupportedOperation::class)]
#[CoversClass(DeadOperation::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
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
    public function an_unknown_resolution_variant_is_rejected(): void
    {
        $unknown = new class implements OperatorResolution {};
        $resolver = new BinaryOperatorResolver([self::rule('+', $unknown)]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unknown operator resolution');

        $resolver->resolve('+', new NumberType(), new NumberType());
    }
}
