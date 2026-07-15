<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;

#[CoversClass(Dialect::class)]
#[CoversClass(Extension::class)]
#[UsesClass(\Superscript\Axiom\Operators\OverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\EqualityOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\HasOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\InOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\IntersectsOverloader::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
final class DialectTest extends TestCase
{
    #[Test]
    public function an_extension_contributes_rules_the_dialect_resolves(): void
    {
        $dialect = Dialect::core()->with(new class extends Extension {
            public function operators(): array
            {
                return [
                    Operator::infix('++')
                        ->signature(new StringType(), new StringType())
                        ->returns(new StringType())
                        ->evaluate(fn(string $a, string $b) => $a . $b),
                ];
            }

            public function unaryOperators(): array
            {
                return [
                    Operator::prefix('abs')
                        ->signature(new NumberType())
                        ->returns(new NumberType())
                        ->evaluate(fn(int|float $n) => abs($n)),
                ];
            }
        });

        $concat = $dialect->operators()->resolve('++', new StringType(), new StringType())->unwrap();
        $this->assertSame('ab', $concat->evaluate('a', 'b')->unwrap());

        $absolute = $dialect->unaryOperators()->resolve('abs', new NumberType())->unwrap();
        $this->assertSame(7, $absolute->evaluate(-7)->unwrap());
    }

    #[Test]
    public function two_rows_for_one_operator_with_overlapping_operands_are_refused_at_composition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The dialect is ambiguous: two [+] rows overlap');

        Dialect::core()->with(new class extends Extension {
            public function operators(): array
            {
                return [
                    // Number ⊂ Option<Number>: a present pair would have two
                    // owners, and which evaluation runs must never depend on
                    // list order.
                    Operator::infix('+')
                        ->signature(new OptionType(new NumberType()), new OptionType(new NumberType()))
                        ->returns(new NumberType())
                        ->evaluate(fn(?int $a, ?int $b) => ($a ?? 0) + ($b ?? 0)),
                ];
            }
        });
    }

    #[Test]
    public function disjoint_rows_for_one_operator_compose_cleanly(): void
    {
        $dialect = Dialect::core()->with(new class extends Extension {
            public function operators(): array
            {
                return [
                    Operator::infix('+')
                        ->signature(new StringType(), new StringType())
                        ->returns(new StringType())
                        ->evaluate(fn(string $a, string $b) => $a . $b),
                ];
            }
        });

        $this->assertSame('ab', $dialect->operators()->resolve('+', new StringType(), new StringType())->unwrap()->evaluate('a', 'b')->unwrap());
        $this->assertSame(3, $dialect->operators()->resolve('+', new NumberType(), new NumberType())->unwrap()->evaluate(1, 2)->unwrap());
    }

    #[Test]
    public function two_prefix_rows_with_overlapping_operands_are_refused_at_composition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The dialect is ambiguous: two unary [-] rows overlap');

        Dialect::core()->with(new class extends Extension {
            public function unaryOperators(): array
            {
                return [
                    Operator::prefix('-')
                        ->signature(new LiteralType(5))
                        ->returns(new NumberType())
                        ->evaluate(fn(int $n) => -$n),
                ];
            }
        });
    }

    #[Test]
    public function duplicate_literal_registrations_are_a_loud_configuration_error(): void
    {
        $registering = new class extends Extension {
            public function literals(): array
            {
                return [\DateTimeImmutable::class => fn(object $value): Type => new StringType()];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('registered by two extensions');

        Dialect::core()->with($registering, clone $registering);
    }

    #[Test]
    public function extension_literals_reach_the_registry(): void
    {
        $dialect = Dialect::core()->with(new class extends Extension {
            public function literals(): array
            {
                return [\DateTimeImmutable::class => fn(object $value): Type => new StringType()];
            }
        });

        $resolved = $dialect->literals()->resolve(new \DateTimeImmutable());

        $this->assertInstanceOf(StringType::class, $resolved->unwrap());
    }
}
