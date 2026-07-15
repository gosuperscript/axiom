<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\OverloaderManager;
use Superscript\Axiom\Operators\OverloadResolution;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

#[CoversClass(OverloaderManager::class)]
#[CoversClass(OverloadResolution::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
final class OverloaderManagerTest extends TestCase
{
    /**
     * A rule that resolves one operator over one operand type class.
     */
    private static function rule(string $operator, string $operandClass, Type $returns, mixed $result): OperatorOverloader
    {
        return new class ($operator, $operandClass, $returns, $result) implements OperatorOverloader {
            public function __construct(
                private readonly string $operator,
                private readonly string $operandClass,
                private readonly Type $returns,
                private readonly mixed $result,
            ) {}

            public function resolve(string $operator, Type $left, Type $right): Result
            {
                if ($operator !== $this->operator) {
                    return Err(new TypeMismatch(sprintf('This rule does not resolve [%s].', $operator), unhandled: true));
                }

                if (!$left instanceof $this->operandClass || !$right instanceof $this->operandClass) {
                    return Err(new TypeMismatch(sprintf('[%s] wants two %s.', $this->operator, $this->operandClass)));
                }

                return Ok(new ResolvedOperation($this->returns, fn(mixed $l, mixed $r) => $this->result));
            }
        };
    }

    #[Test]
    public function it_asserts_all_overloaders_are_instance_of_interface(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        new OverloaderManager(['not a rule']);
    }

    #[Test]
    public function a_lone_resolution_is_the_verdict(): void
    {
        $manager = new OverloaderManager([
            self::rule('+', NumberType::class, new NumberType(), 3),
            self::rule('+', StringType::class, new StringType(), 'ab'),
        ]);

        $resolution = $manager->resolve('+', new NumberType(), new NumberType())->unwrap();

        $this->assertInstanceOf(NumberType::class, $resolution->returns);
        $this->assertSame(3, $resolution->evaluate(1, 2)->unwrap());
    }

    #[Test]
    public function two_resolutions_are_an_ambiguity_error_naming_the_owners(): void
    {
        $manager = new OverloaderManager([
            self::rule('+', NumberType::class, new NumberType(), 1),
            self::rule('+', NumberType::class, new BooleanType(), 2),
        ]);

        $verdict = $manager->resolve('+', new NumberType(), new NumberType());

        $this->assertTrue($verdict->isErr());
        $this->assertStringContainsString('Operator [+] over Number and Number is ambiguous:', $verdict->unwrapErr()->message);
        $this->assertStringContainsString('exactly one owner', $verdict->unwrapErr()->message);
    }

    #[Test]
    public function an_operator_no_rule_engages_is_unsupported(): void
    {
        $manager = new OverloaderManager([
            self::rule('+', NumberType::class, new NumberType(), 3),
        ]);

        $verdict = $manager->resolve('has', new NumberType(), new NumberType());

        $this->assertSame('Operator [has] is not supported.', $verdict->unwrapErr()->message);
        // Marked unhandled, so a nesting manager keeps it out of its own
        // aggregated diagnostics.
        $this->assertTrue($verdict->unwrapErr()->unhandled);
    }

    #[Test]
    public function a_lone_engaged_refusal_passes_through_directly(): void
    {
        $manager = new OverloaderManager([
            self::rule('+', NumberType::class, new NumberType(), 3),
        ]);

        $verdict = $manager->resolve('+', new StringType(), new StringType());

        $this->assertSame('[+] wants two ' . NumberType::class . '.', $verdict->unwrapErr()->message);
    }

    #[Test]
    public function multiple_engaged_refusals_aggregate_with_causes(): void
    {
        $manager = new OverloaderManager([
            self::rule('+', NumberType::class, new NumberType(), 3),
            self::rule('+', StringType::class, new StringType(), 'ab'),
        ]);

        $verdict = $manager->resolve('+', new BooleanType(), new BooleanType());

        $this->assertSame('No overload of [+] accepts Boolean and Boolean.', $verdict->unwrapErr()->message);
        $this->assertCount(2, $verdict->unwrapErr()->causes);
        $this->assertFalse($verdict->unwrapErr()->unhandled);
    }

    #[Test]
    public function unhandled_refusals_stay_out_of_the_aggregate(): void
    {
        $manager = new OverloaderManager([
            self::rule('-', NumberType::class, new NumberType(), 1),
            self::rule('+', NumberType::class, new NumberType(), 3),
            self::rule('+', StringType::class, new StringType(), 'ab'),
        ]);

        $verdict = $manager->resolve('+', new BooleanType(), new BooleanType());

        // The [-] rule's foreign-operator refusal contributes no cause.
        $this->assertCount(2, $verdict->unwrapErr()->causes);
    }

    #[Test]
    public function a_single_resolving_rule_wins_over_refusing_neighbours(): void
    {
        $manager = new OverloaderManager([
            self::rule('+', StringType::class, new StringType(), 'ab'),
            self::rule('+', NumberType::class, new NumberType(), 3),
        ]);

        $this->assertInstanceOf(NumberType::class, $manager->resolve('+', new NumberType(), new NumberType())->unwrap()->returns);
    }
}
