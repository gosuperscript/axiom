<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\ComparisonOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\OverloaderManager;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\UnknownType;
use Superscript\Monads\Result\Result;
use Webmozart\Assert\InvalidArgumentException;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

#[CoversClass(OverloaderManager::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(ComparisonOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\LogicalOverloader::class)]
#[UsesClass(NullOverloader::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
class OverloaderManagerTest extends TestCase
{
    /**
     * A stub rule certifying one operator with a fixed return type.
     */
    private static function rule(string $operator, Type $returns): OperatorOverloader
    {
        return new class($operator, $returns) implements OperatorOverloader {
            public function __construct(private readonly string $operator, private readonly Type $returns) {}

            public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
            {
                return $operator === $this->operator;
            }

            public function evaluate(mixed $left, mixed $right, string $operator): Result
            {
                return Ok(null);
            }

            public function handles(string $operator): bool
            {
                return $operator === $this->operator;
            }

            public function typeOf(string $operator, Type $left, Type $right): Result
            {
                return Ok($this->returns);
            }
        };
    }
    #[Test]
    public function it_asserts_all_overloaders_are_instance_of_interface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OverloaderManager([new stdClass()]);
    }

    #[Test]
    public function it_evaluates_an_expression_if_an_overloader_is_found(): void
    {
        $manager = new OverloaderManager([
            new DefaultOverloader(),
        ]);

        $this->assertTrue($manager->supportsOverloading(1, 1, '+'));

        $result = $manager->evaluate(1, 1, '+');
        $this->assertTrue($result->isOk());
        $this->assertEquals(2, $result->unwrap());
    }

    #[Test]
    public function it_returns_an_error_if_no_supported_overloader_is_found(): void
    {
        $manager = new OverloaderManager([]);
        $this->assertFalse($manager->supportsOverloading(1, 1, '+'));

        $result = $manager->evaluate(1, 1, '+');
        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(RuntimeException::class, $result->unwrapErr());
        $this->assertEquals('No overloader found for [1] + [1]', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function it_handles_an_operator_when_any_member_does(): void
    {
        $manager = new OverloaderManager([new BinaryOverloader()]);

        $this->assertTrue($manager->handles('+'));
        $this->assertFalse($manager->handles('has'));
    }

    #[Test]
    public function an_unhandled_operator_is_a_mismatch(): void
    {
        $manager = new OverloaderManager([new BinaryOverloader()]);

        $result = $manager->typeOf('has', new NumberType(), new NumberType());

        $this->assertTrue($result->isErr());
        $this->assertSame('Operator [has] is not supported.', $result->unwrapErr()->message);
    }

    #[Test]
    public function agreeing_verdicts_resolve_to_the_agreed_type(): void
    {
        $manager = new OverloaderManager([
            self::rule('+', new NumberType()),
            self::rule('+', new NumberType()),
        ]);

        $result = $manager->typeOf('+', new NumberType(), new NumberType());

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function disagreeing_verdicts_resolve_to_unknown(): void
    {
        $manager = new OverloaderManager([
            self::rule('-', new NumberType()),
            self::rule('-', new StringType()),
        ]);

        $result = $manager->typeOf('-', new NumberType(), new NumberType());

        $this->assertInstanceOf(UnknownType::class, $result->unwrap());
    }

    #[Test]
    public function a_lone_refusal_passes_through_directly(): void
    {
        $manager = new OverloaderManager([new BinaryOverloader()]);

        $result = $manager->typeOf('+', new StringType(), new NumberType());

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('[+] requires two present numbers', $result->unwrapErr()->message);
    }

    #[Test]
    public function multiple_refusals_aggregate_with_causes(): void
    {
        $manager = new OverloaderManager([new NullOverloader(), new BinaryOverloader()]);

        $result = $manager->typeOf('+', new StringType(), new NumberType());

        $this->assertTrue($result->isErr());
        $this->assertSame('No overload of [+] accepts String and Number.', $result->unwrapErr()->message);
        $this->assertCount(2, $result->unwrapErr()->causes);
    }

    #[Test]
    public function a_single_certifying_rule_wins_over_refusing_neighbours(): void
    {
        $manager = new OverloaderManager([new NullOverloader(), new BinaryOverloader()]);

        $result = $manager->typeOf('+', new NumberType(), new NumberType());

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function non_handlers_are_skipped_without_ending_resolution(): void
    {
        $manager = new OverloaderManager([
            new BinaryOverloader(),
            new \Superscript\Axiom\Operators\LogicalOverloader(),
        ]);

        $result = $manager->typeOf('&&', new BooleanType(), new BooleanType());

        $this->assertInstanceOf(BooleanType::class, $result->unwrap());
    }
}
