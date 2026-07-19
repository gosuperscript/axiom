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
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Tests\Fixtures\Money;
use Superscript\Axiom\Tests\Fixtures\MoneyExtension;
use Superscript\Axiom\Tests\Fixtures\MoneyType;
use Superscript\Axiom\Tests\Fixtures\HostValueSource;

#[CoversClass(Dialect::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[CoversClass(Extension::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnsupportedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\Equality::class)]
#[UsesClass(\Superscript\Axiom\Operators\Has::class)]
#[UsesClass(\Superscript\Axiom\Operators\In::class)]
#[UsesClass(\Superscript\Axiom\Operators\Intersects::class)]
#[UsesClass(\Superscript\Axiom\Operators\SetOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ShapeDomain::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(DictType::class)]
#[UsesClass(ListType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\DictShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ListShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OpaqueShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom\\Analysis')]
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
                        ->takes(new StringType(), new StringType())
                        ->returns(new StringType())
                        ->evaluatesWith(fn(string $a, string $b) => $a . $b),
                ];
            }

            public function unaryOperators(): array
            {
                return [
                    Operator::prefix('abs')
                        ->takes(new NumberType())
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(int|float $n) => abs($n)),
                ];
            }
        });

        $concat = $dialect->operators()->resolve('++', new StringType(), new StringType())->unwrap();
        $this->assertSame('ab', $concat->evaluate('a', 'b')->unwrap());

        $absolute = $dialect->unaryOperators()->resolve('abs', new NumberType())->unwrap();
        $this->assertSame(7, $absolute->evaluate(-7)->unwrap());
    }

    #[Test]
    public function a_package_owned_equality_row_resolves_opaque_values_core_refuses(): void
    {
        $dialect = Dialect::core()->with(new MoneyExtension(['GBP', 'EUR']));
        $sterling = new MoneyType('GBP');
        $equality = $dialect->operators()->resolve('==', $sterling, $sterling)->unwrap();
        $inequality = $dialect->operators()->resolve('!=', $sterling, $sterling)->unwrap();

        $onePound = new Money(100, 'GBP');
        $anotherPound = new Money(100, 'GBP');
        $twoPounds = new Money(200, 'GBP');

        $this->assertTrue($equality->evaluate($onePound, $anotherPound)->unwrap());
        $this->assertFalse($equality->evaluate($onePound, $twoPounds)->unwrap());
        $this->assertTrue($inequality->evaluate($onePound, $twoPounds)->unwrap());

        $literalType = $dialect->literals()->resolve($onePound)->unwrap();
        $this->assertEquals($sterling, $literalType);

        $crossCurrency = $dialect->operators()->resolve('==', $sterling, new MoneyType('EUR'));
        $this->assertTrue($crossCurrency->isErr(), 'different currency parameters match no package equality row');
    }

    #[Test]
    public function two_rows_for_one_operator_with_overlapping_operands_are_refused_at_composition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The dialect is ambiguous: two [+] rows collide');

        Dialect::core()->with(new class extends Extension {
            public function operators(): array
            {
                return [
                    // Number ⊂ Option<Number>: a present pair would have two
                    // owners, and which evaluation runs must never depend on
                    // list order.
                    Operator::infix('+')
                        ->takes(new OptionType(new NumberType()), new OptionType(new NumberType()))
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(?int $a, ?int $b) => ($a ?? 0) + ($b ?? 0)),
                ];
            }
        });
    }

    #[Test]
    public function a_list_row_beside_a_dict_row_is_a_legal_pair(): void
    {
        // The empty array inhabits both List and Dict, but dispatch sees
        // operand types, never values: no compilable operand type reaches
        // both rows, so this pair can never tie (RFC item 36). The old
        // value-overlap check wrongly refused it.
        $dialect = Dialect::core()->with(new class extends Extension {
            public function operators(): array
            {
                return [
                    Operator::infix('++')
                        ->takes(new ListType(new NumberType()), new ListType(new NumberType()))
                        ->returns(new ListType(new NumberType()))
                        ->evaluatesWith(fn(array $a, array $b) => [...$a, ...$b]),
                    Operator::infix('++')
                        ->takes(new DictType(new NumberType()), new DictType(new NumberType()))
                        ->returns(new DictType(new NumberType()))
                        ->evaluatesWith(fn(array $a, array $b) => [...$a, ...$b]),
                ];
            }
        });

        $lists = $dialect->operators()->resolve('++', new ListType(new NumberType()), new ListType(new NumberType()))->unwrap();
        $this->assertSame([1, 2], $lists->evaluate([1], [2])->unwrap());

        $dicts = $dialect->operators()->resolve('++', new DictType(new NumberType()), new DictType(new NumberType()))->unwrap();
        $this->assertSame(['a' => 1, 'b' => 2], $dicts->evaluate(['a' => 1], ['b' => 2])->unwrap());
    }

    #[Test]
    public function a_literal_specialization_of_an_existing_row_is_refused(): void
    {
        // A 5-typed operand is admitted by both Number and Literal(5)
        // slots, and no precedence rule exists to pick a winner: ties are
        // refused, never resolved — specialization is not a licence.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The dialect is ambiguous: two [+] rows collide');

        Dialect::core()->with(new class extends Extension {
            public function operators(): array
            {
                return [
                    Operator::infix('+')
                        ->takes(new LiteralType(5), new LiteralType(5))
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(int $a, int $b) => 10),
                ];
            }
        });
    }

    #[Test]
    public function composition_preserves_the_whole_core_rule_list(): void
    {
        $empty = new class extends Extension {};
        $operators = Dialect::core()->with($empty)->operators();

        // Rules from every position of the core list — first, middle, and
        // the trailing type functions — must survive composition intact.
        $this->assertSame(3, $operators->resolve('+', new NumberType(), new NumberType())->unwrap()->evaluate(1, 2)->unwrap());
        $this->assertTrue($operators->resolve('<', new NumberType(), new NumberType())->unwrap()->evaluate(1, 2)->unwrap());
        $this->assertFalse($operators->resolve('xor', new BooleanType(), new BooleanType())->unwrap()->evaluate(true, true)->unwrap());
        $this->assertTrue($operators->resolve('==', new NumberType(), new NumberType())->unwrap()->evaluate(1, 1)->unwrap());
        $this->assertTrue($operators->resolve('intersects', new StringType(), new StringType())->unwrap()->evaluate('a', 'a')->unwrap());
    }

    #[Test]
    public function row_ambiguity_is_detected_behind_non_row_rules(): void
    {
        // The filtered row list must be reindexed: a type-function rule
        // ahead of the rows shifts their keys, and an offset taken from the
        // original keys would skip the very next row — letting two
        // overlapping rows slip through, undetected, adjacent or not.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The dialect is ambiguous: two [++] rows collide');

        Dialect::core()->with(new class extends Extension {
            public function operators(): array
            {
                return [
                    new \Superscript\Axiom\Operators\Equality('==', negated: false),
                    Operator::infix('++')
                        ->takes(new StringType(), new StringType())
                        ->returns(new StringType())
                        ->evaluatesWith(fn(string $a, string $b) => $a . $b),
                    Operator::infix('++')
                        ->takes(new StringType(), new StringType())
                        ->returns(new StringType())
                        ->evaluatesWith(fn(string $a, string $b) => $b . $a),
                ];
            }
        });
    }

    #[Test]
    public function adjacent_overlapping_prefix_rows_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The dialect is ambiguous: two unary [abs] rows collide');

        Dialect::core()->with(new class extends Extension {
            public function unaryOperators(): array
            {
                return [
                    Operator::prefix('abs')
                        ->takes(new NumberType())
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(int|float $n) => abs($n)),
                    Operator::prefix('abs')
                        ->takes(new LiteralType(5))
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(int $n) => 5),
                ];
            }
        });
    }

    #[Test]
    public function prefix_row_ambiguity_is_detected_behind_non_row_rules(): void
    {
        $nonRow = new class implements \Superscript\Axiom\Operators\UnaryOperatorRule {
            public function operator(): string
            {
                return 'abs';
            }

            public function resolve(Type $operand): \Superscript\Axiom\Operators\OperatorResolution
            {
                return new \Superscript\Axiom\Operators\UnsupportedOperation('Nothing.');
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The dialect is ambiguous: two unary [abs] rows collide');

        Dialect::core()->with(new class ($nonRow) extends Extension {
            public function __construct(private readonly \Superscript\Axiom\Operators\UnaryOperatorRule $nonRow) {}

            public function unaryOperators(): array
            {
                return [
                    $this->nonRow,
                    Operator::prefix('abs')
                        ->takes(new NumberType())
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(int|float $n) => abs($n)),
                    Operator::prefix('abs')
                        ->takes(new NumberType())
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(int|float $n) => -abs($n)),
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
                        ->takes(new StringType(), new StringType())
                        ->returns(new StringType())
                        ->evaluatesWith(fn(string $a, string $b) => $a . $b),
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
        $this->expectExceptionMessage('The dialect is ambiguous: two unary [-] rows collide');

        Dialect::core()->with(new class extends Extension {
            public function unaryOperators(): array
            {
                return [
                    Operator::prefix('-')
                        ->takes(new LiteralType(5))
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(int $n) => -$n),
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
    public function duplicate_source_compilers_are_a_loud_configuration_error(): void
    {
        $registering = new class extends Extension {
            public function sourceCompilers(): array
            {
                return [HostValueSource::class => fn() => throw new \LogicException('not compiled')];
            }
        };

        $dialect = Dialect::core()->with($registering);
        $this->assertArrayHasKey(HostValueSource::class, $dialect->sourceCompilers());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Source class [%s] has two compilers', HostValueSource::class));

        $dialect->with(clone $registering);
    }

    #[Test]
    public function the_core_language_owns_its_source_compilers_like_any_extension(): void
    {
        $this->assertArrayHasKey(
            \Superscript\Axiom\Sources\InfixExpression::class,
            Dialect::core()->sourceCompilers(),
        );

        $claiming = new class extends Extension {
            public function sourceCompilers(): array
            {
                return [\Superscript\Axiom\Sources\InfixExpression::class => fn() => throw new \LogicException('not compiled')];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Source class [%s] has two compilers', \Superscript\Axiom\Sources\InfixExpression::class));

        Dialect::core()->with($claiming);
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

    #[Test]
    public function an_extension_identity_cannot_be_empty(): void
    {
        $extension = new class extends Extension {
            public function identifier(): string
            {
                return '';
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('returned an empty identifier');

        Dialect::core()->with($extension);
    }

    #[Test]
    public function operator_provenance_stays_aligned_for_every_core_and_extension_rule(): void
    {
        $extension = new class extends Extension {
            public function operators(): array
            {
                return [
                    \Superscript\Axiom\Operators\Operator::infix('first-binary')
                        ->takes(new StringType(), new StringType())
                        ->returns(new StringType())
                        ->evaluatesWith(fn(string $left, string $right): string => $left . $right),
                    \Superscript\Axiom\Operators\Operator::infix('second-binary')
                        ->takes(new NumberType(), new NumberType())
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(int|float $left, int|float $right): int|float => $left + $right),
                ];
            }

            public function unaryOperators(): array
            {
                return [
                    \Superscript\Axiom\Operators\Operator::prefix('first-unary')
                        ->takes(new StringType())
                        ->returns(new StringType())
                        ->evaluatesWith(fn(string $operand): string => $operand),
                    \Superscript\Axiom\Operators\Operator::prefix('second-unary')
                        ->takes(new NumberType())
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(int|float $operand): int|float => $operand),
                ];
            }
        };

        $dialect = Dialect::core()->with($extension);

        $this->assertInstanceOf(\Superscript\Axiom\Operators\BinaryOperatorResolver::class, $dialect->operators());
        $this->assertInstanceOf(\Superscript\Axiom\Operators\UnaryOperatorResolver::class, $dialect->unaryOperators());
    }
}
