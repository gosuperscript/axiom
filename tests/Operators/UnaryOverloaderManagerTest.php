<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Axiom\Operators\UnaryOverloaderManager;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\UnknownType;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

#[CoversClass(UnaryOverloaderManager::class)]
#[UsesClass(Dialect::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\OverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithReturn::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
final class UnaryOverloaderManagerTest extends TestCase
{
    #[Test]
    public function the_manager_requires_unary_overloaders(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        new UnaryOverloaderManager(['not a rule']);
    }

    #[Test]
    public function the_core_rows_resolve_their_operand_types(): void
    {
        $manager = Dialect::core()->unaryOperators();

        $this->assertInstanceOf(BooleanType::class, $manager->resolve('!', new BooleanType())->unwrap()->returns);
        $this->assertInstanceOf(BooleanType::class, $manager->resolve('not', new BooleanType())->unwrap()->returns);
        $this->assertInstanceOf(NumberType::class, $manager->resolve('-', new NumberType())->unwrap()->returns);
    }

    #[Test]
    public function negation_is_boolean_only(): void
    {
        $manager = Dialect::core()->unaryOperators();

        $refused = $manager->resolve('!', new NumberType());

        $this->assertStringContainsString('[!] expects Boolean; got Number.', $refused->unwrapErr()->describe());
    }

    #[Test]
    public function an_inert_unknown_is_refused(): void
    {
        $manager = Dialect::core()->unaryOperators();

        $refused = $manager->resolve('!', new UnknownType());

        $this->assertStringContainsString('An Unknown operand is inert', $refused->unwrapErr()->describe());
    }

    #[Test]
    public function an_option_operand_is_refused_because_rules_see_present_values(): void
    {
        $manager = Dialect::core()->unaryOperators();

        $refused = $manager->resolve('-', new OptionType(new NumberType()));

        $this->assertStringContainsString('the value may be absent', $refused->unwrapErr()->describe());
    }

    #[Test]
    public function an_operator_no_rule_engages_is_unsupported(): void
    {
        $manager = Dialect::core()->unaryOperators();

        $verdict = $manager->resolve('~', new BooleanType());

        $this->assertSame('Unary operator [~] is not supported.', $verdict->unwrapErr()->message);
        $this->assertTrue($verdict->unwrapErr()->unhandled);
    }

    #[Test]
    public function multiple_engaged_refusals_aggregate(): void
    {
        $manager = Dialect::core()->unaryOperators();

        // '!' and 'not' rows both engage a String operand; '-' stays out.
        $verdict = $manager->resolve('!', new StringType());

        $this->assertStringContainsString('[!] expects Boolean; got String.', $verdict->unwrapErr()->describe());

        $aggregate = new UnaryOverloaderManager([
            self::refusing('!', 'first refusal'),
            self::refusing('!', 'second refusal'),
        ]);

        $result = $aggregate->resolve('!', new StringType());
        $this->assertSame('No overload of unary [!] accepts String.', $result->unwrapErr()->message);
        $this->assertCount(2, $result->unwrapErr()->causes);
    }

    #[Test]
    public function two_resolutions_are_an_ambiguity_error_naming_the_owners(): void
    {
        $manager = new UnaryOverloaderManager([
            self::resolving('!', new BooleanType()),
            self::resolving('!', new NumberType()),
        ]);

        $verdict = $manager->resolve('!', new BooleanType());

        $this->assertStringContainsString('Unary operator [!] over Boolean is ambiguous:', $verdict->unwrapErr()->message);
        $this->assertStringContainsString('exactly one owner', $verdict->unwrapErr()->message);
    }

    private static function resolving(string $operator, Type $returns): UnaryOverloader
    {
        return new class ($operator, $returns) implements UnaryOverloader {
            public function __construct(private readonly string $operator, private readonly Type $returns) {}

            public function resolve(string $operator, Type $operand): Result
            {
                return $operator === $this->operator
                    ? Ok(new ResolvedOperation($this->returns, fn(mixed $value) => $value))
                    : Err(new TypeMismatch('Foreign.', unhandled: true));
            }
        };
    }

    private static function refusing(string $operator, string $message): UnaryOverloader
    {
        return new class ($operator, $message) implements UnaryOverloader {
            public function __construct(private readonly string $operator, private readonly string $message) {}

            public function resolve(string $operator, Type $operand): Result
            {
                return $operator === $this->operator
                    ? Err(new TypeMismatch($this->message))
                    : Err(new TypeMismatch('Foreign.', unhandled: true));
            }
        };
    }
}
