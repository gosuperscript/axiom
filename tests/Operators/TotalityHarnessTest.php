<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

/**
 * The totality harness: the generative pinning of the one obligation a
 * rule author carries under the one-verdict contract.
 *
 * For every operator of the composed core dialect and every specimen pair
 * of typed values: if resolve() certifies the operand types, then every
 * specimen value pair of those types must evaluate WITHOUT ESCAPING — no
 * PHP TypeError from a closure narrower than its claim, no throw — and
 * every successful result must inhabit the resolved return type.
 * Evaluation may still Err on value-dependent partiality (division by
 * zero); certification promises totality of the attempt and the type of
 * success.
 *
 * The old dual-face agreement laws (soundness of runtime claims,
 * anti-shadowing, the dead law) are unstatable now: there is one face and
 * no dispatch order. What they protected is carried by this law plus the
 * admission-honesty law in the shape census (coerce output passes assert)
 * — together, the entire trust chain of compile-then-trust.
 *
 * Packages and hosts run the same harness over their own dialects by
 * contributing specimens.
 */
#[CoversNothing]
final class TotalityHarnessTest extends TestCase
{
    /**
     * @return array<string, array{Type, list<mixed>}>
     */
    private static function specimens(): array
    {
        return [
            'Boolean' => [new BooleanType(), [true, false]],
            'Number' => [new NumberType(), [0, 1, 2.5, -3]],
            'String' => [new StringType(), ['a', 'b', '2024-01-01']],
            'literal 5' => [new LiteralType(5), [5]],
            'enum' => [new UnionType(new LiteralType('a'), new LiteralType('b')), ['a', 'b']],
            'null literal' => [new OptionType(new NeverType()), [null]],
            'Number?' => [new OptionType(new NumberType()), [null, 5]],
            'Boolean?' => [new OptionType(new BooleanType()), [null, true]],
            'List<String>' => [new ListType(new StringType()), [['a', 'b'], []]],
            'List<Number>' => [new ListType(new NumberType()), [[1, 2], []]],
            'Dict<Number>' => [new DictType(new NumberType()), [['a' => 1]]],
            'Number | Dict<Number>' => [new UnionType(new NumberType(), new DictType(new NumberType())), [5, ['a' => 1]]],
            // Inert: resolutions must refuse it, so no evaluation ever sees
            // these values. Included so a rule that certifies Unknown anyway
            // is caught by the totality sweep.
            'Unknown' => [new UnknownType(), [true, 5, 'a', null, ['a']]],
        ];
    }

    private const binaryOperators = [
        '+', '-', '*', '/',
        '=', '==', '===', '!=', '!==', '<', '<=', '>', '>=',
        '&&', '||', 'xor', '??',
        'has', 'in', 'intersects',
    ];

    private const unaryOperators = ['!', 'not', '-'];

    #[Test]
    public function every_binary_resolution_is_total_over_its_operand_types(): void
    {
        $dialect = Dialect::core()->operators();
        $specimens = self::specimens();

        foreach (self::binaryOperators as $operator) {
            foreach ($specimens as $leftLabel => [$leftType, $leftValues]) {
                foreach ($specimens as $rightLabel => [$rightType, $rightValues]) {
                    $context = sprintf('%s [%s] %s', $leftLabel, $operator, $rightLabel);
                    $resolution = $dialect->resolve($operator, $leftType, $rightType);

                    if ($resolution->isErr()) {
                        continue;
                    }

                    $operation = $resolution->unwrap();

                    foreach ($leftValues as $left) {
                        foreach ($rightValues as $right) {
                            try {
                                $result = $operation->evaluate($left, $right);
                            } catch (\Throwable $escaped) {
                                $this->fail(sprintf(
                                    'Totality: %s is certified %s, but the evaluation escaped on a specimen pair: %s',
                                    $context,
                                    TypeDescriber::describe($operation->returns),
                                    $escaped->getMessage(),
                                ));
                            }

                            if ($result->isErr()) {
                                continue; // value-dependent partiality
                            }

                            $this->assertTrue(
                                $operation->returns->assert($result->unwrap())->isOk(),
                                sprintf(
                                    'Totality: %s produced a value outside the certified %s',
                                    $context,
                                    TypeDescriber::describe($operation->returns),
                                ),
                            );
                        }
                    }
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function every_unary_resolution_is_total_over_its_operand_type(): void
    {
        $dialect = Dialect::core()->unaryOperators();

        foreach (self::unaryOperators as $operator) {
            foreach (self::specimens() as $label => [$type, $values]) {
                $context = sprintf('[%s] %s', $operator, $label);
                $resolution = $dialect->resolve($operator, $type);

                if ($resolution->isErr()) {
                    continue;
                }

                $operation = $resolution->unwrap();

                foreach ($values as $value) {
                    try {
                        $result = $operation->evaluate($value);
                    } catch (\Throwable $escaped) {
                        $this->fail(sprintf(
                            'Totality: %s is certified %s, but the evaluation escaped on a specimen: %s',
                            $context,
                            TypeDescriber::describe($operation->returns),
                            $escaped->getMessage(),
                        ));
                    }

                    if ($result->isErr()) {
                        continue;
                    }

                    $this->assertTrue(
                        $operation->returns->assert($result->unwrap())->isOk(),
                        sprintf('Totality: %s produced a value outside the certified %s', $context, TypeDescriber::describe($operation->returns)),
                    );
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Inert Unknown, swept explicitly: no operator of the core dialect may
     * resolve an Unknown operand — the bridges (Coerce, Ascription) are the
     * only ways out, and a rule that quietly admits Unknown would feed its
     * closure values nobody checked.
     */
    #[Test]
    public function no_core_resolution_admits_an_unknown_operand(): void
    {
        $binary = Dialect::core()->operators();
        $unary = Dialect::core()->unaryOperators();
        $unknown = new UnknownType();

        foreach (self::binaryOperators as $operator) {
            foreach (self::specimens() as $label => [$type, $values]) {
                $this->assertTrue(
                    $binary->resolve($operator, $unknown, $type)->isErr(),
                    sprintf('Unknown [%s] %s resolved — Unknown must be inert', $operator, $label),
                );
                $this->assertTrue(
                    $binary->resolve($operator, $type, $unknown)->isErr(),
                    sprintf('%s [%s] Unknown resolved — Unknown must be inert', $label, $operator),
                );
            }
        }

        foreach (self::unaryOperators as $operator) {
            $this->assertTrue(
                $unary->resolve($operator, $unknown)->isErr(),
                sprintf('[%s] Unknown resolved — Unknown must be inert', $operator),
            );
        }
    }
}
