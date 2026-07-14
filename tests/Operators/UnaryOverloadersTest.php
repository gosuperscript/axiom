<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Superscript\Axiom\Operators\NegateOverloader;
use Superscript\Axiom\Operators\NotOverloader;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Axiom\Operators\UnaryOverloaderManager;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnknownType;
use Superscript\Monads\Result\Result;
use Webmozart\Assert\InvalidArgumentException;

use function Superscript\Monads\Result\Ok;

#[CoversClass(NotOverloader::class)]
#[CoversClass(NegateOverloader::class)]
#[CoversClass(UnaryOverloaderManager::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
final class UnaryOverloadersTest extends TestCase
{
    #[Test]
    public function not_negates_booleans_only(): void
    {
        $not = new NotOverloader();

        $this->assertTrue($not->supportsOverloading(true, '!'));
        $this->assertTrue($not->supportsOverloading(false, 'not'));
        $this->assertFalse($not->supportsOverloading(1, '!'));
        $this->assertFalse($not->supportsOverloading('a', 'not'));
        $this->assertFalse($not->supportsOverloading(true, '-'));

        $this->assertFalse($not->evaluate(true, '!')->unwrap());
        $this->assertTrue($not->evaluate(false, 'not')->unwrap());

        $this->assertTrue($not->handles('!'));
        $this->assertTrue($not->handles('not'));
        $this->assertFalse($not->handles('-'));
    }

    #[Test]
    public function not_certifies_present_booleans(): void
    {
        $not = new NotOverloader();

        $this->assertInstanceOf(BooleanType::class, $not->typeOf('!', new BooleanType())->unwrap());
        $this->assertInstanceOf(BooleanType::class, $not->typeOf('not', new UnknownType())->unwrap());

        $refused = $not->typeOf('!', new NumberType());
        $this->assertStringContainsString('requires a present boolean', $refused->unwrapErr()->describe());

        $optional = $not->typeOf('not', new OptionType(new BooleanType()));
        $this->assertStringContainsString('the value may be absent', $optional->unwrapErr()->describe());

        $unhandled = $not->typeOf('-', new BooleanType());
        $this->assertStringContainsString('Negation does not handle [-].', $unhandled->unwrapErr()->describe());
    }

    #[Test]
    public function negate_flips_numbers_only(): void
    {
        $negate = new NegateOverloader();

        $this->assertTrue($negate->supportsOverloading(5, '-'));
        $this->assertTrue($negate->supportsOverloading(2.5, '-'));
        $this->assertFalse($negate->supportsOverloading('5', '-'));
        $this->assertFalse($negate->supportsOverloading(5, '!'));

        $this->assertSame(-5, $negate->evaluate(5, '-')->unwrap());

        $this->assertTrue($negate->handles('-'));
        $this->assertFalse($negate->handles('!'));
    }

    #[Test]
    public function negate_certifies_present_numbers(): void
    {
        $negate = new NegateOverloader();

        $this->assertInstanceOf(NumberType::class, $negate->typeOf('-', new NumberType())->unwrap());
        $this->assertInstanceOf(NumberType::class, $negate->typeOf('-', new UnknownType())->unwrap());

        $refused = $negate->typeOf('-', new StringType());
        $this->assertStringContainsString('requires a present number', $refused->unwrapErr()->describe());
        $this->assertStringContainsString('String is not assignable to Number', $refused->unwrapErr()->describe());

        $unhandled = $negate->typeOf('!', new NumberType());
        $this->assertStringContainsString('Arithmetic negation does not handle [!].', $unhandled->unwrapErr()->describe());
    }

    #[Test]
    public function the_manager_dispatches_first_honest_claim(): void
    {
        $manager = UnaryOverloaderManager::default();

        $this->assertTrue($manager->supportsOverloading(true, '!'));
        $this->assertTrue($manager->supportsOverloading(5, '-'));
        $this->assertFalse($manager->supportsOverloading('a', '!'));

        $this->assertFalse($manager->evaluate(true, '!')->unwrap());
        $this->assertSame(-5, $manager->evaluate(5, '-')->unwrap());

        $unclaimed = $manager->evaluate('a', '!');
        $this->assertInstanceOf(RuntimeException::class, $unclaimed->unwrapErr());
        $this->assertStringContainsString("No overloader found for ! ['a']", $unclaimed->unwrapErr()->getMessage());
    }

    #[Test]
    public function the_manager_requires_unary_overloaders(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UnaryOverloaderManager([new stdClass()]);
    }

    #[Test]
    public function the_manager_composes_typing_verdicts(): void
    {
        $manager = UnaryOverloaderManager::default();

        $this->assertTrue($manager->handles('!'));
        $this->assertTrue($manager->handles('-'));
        $this->assertFalse($manager->handles('~'));

        $this->assertInstanceOf(BooleanType::class, $manager->typeOf('!', new BooleanType())->unwrap());
        $this->assertInstanceOf(NumberType::class, $manager->typeOf('-', new NumberType())->unwrap());

        $unhandled = $manager->typeOf('~', new NumberType());
        $this->assertSame('Unary operator [~] is not supported.', $unhandled->unwrapErr()->message);

        $refused = $manager->typeOf('!', new NumberType());
        $this->assertStringContainsString('requires a present boolean', $refused->unwrapErr()->message);
    }

    #[Test]
    public function multiple_unary_refusals_aggregate(): void
    {
        $manager = new UnaryOverloaderManager([
            new class implements UnaryOverloader {
                public function supportsOverloading(mixed $operand, string $operator): bool
                {
                    return false;
                }

                public function evaluate(mixed $operand, string $operator): Result
                {
                    return Ok(null);
                }

                public function handles(string $operator): bool
                {
                    return $operator === '!';
                }

                public function typeOf(string $operator, Type $operand): Result
                {
                    return \Superscript\Monads\Result\Err(new \Superscript\Axiom\Types\TypeMismatch('stub refusal'));
                }
            },
            new NotOverloader(),
        ]);

        $result = $manager->typeOf('!', new StringType());

        $this->assertStringContainsString('No overload of unary [!] accepts String.', $result->unwrapErr()->message);
        $this->assertCount(2, $result->unwrapErr()->causes);
    }

    #[Test]
    public function disagreeing_unary_verdicts_resolve_to_unknown(): void
    {
        $certifying = new class implements UnaryOverloader {
            public function supportsOverloading(mixed $operand, string $operator): bool
            {
                return is_bool($operand);
            }

            public function evaluate(mixed $operand, string $operator): Result
            {
                return Ok(0);
            }

            public function handles(string $operator): bool
            {
                return $operator === '!';
            }

            public function typeOf(string $operator, Type $operand): Result
            {
                return Ok(new NumberType());
            }
        };

        $manager = new UnaryOverloaderManager([new NotOverloader(), $certifying]);

        $this->assertInstanceOf(UnknownType::class, $manager->typeOf('!', new BooleanType())->unwrap());
    }
}
