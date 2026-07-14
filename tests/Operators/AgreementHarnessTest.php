<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\ComparisonOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\HasOverloader;
use Superscript\Axiom\Operators\InOverloader;
use Superscript\Axiom\Operators\IntersectsOverloader;
use Superscript\Axiom\Operators\LogicalOverloader;
use Superscript\Axiom\Operators\NegateOverloader;
use Superscript\Axiom\Operators\NotOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Axiom\Operators\UnaryOverloaderManager;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

/**
 * The agreement harness: the generative pinning of the drift guarantees.
 *
 * For every overloader and a specimen matrix of typed values:
 *
 * - R1 (soundness): if typeOf certifies Ok(T), every specimen pair the rule
 *   claims and successfully evaluates must produce a value inhabiting T.
 *   Evaluation may still Err on value-dependent partiality — division by
 *   zero is the canonical case; certification promises the type of success,
 *   not totality. (Inhabitance is vacuous when T is Unknown; the whole law
 *   is skipped when an operand type is Unknown — gradual admission is
 *   deliberately unsound.)
 * - R2 (anti-shadowing honesty): if the rule claims EVERY specimen pair of a
 *   type pair but typeOf refuses it, the rule is hiding runtime semantics
 *   from the checker — unless the refusal is marked dead (the runtime
 *   tolerates dead tests; the checker still flags them), or the rule is the
 *   degenerate NullOverloader (see its class doc).
 * - R3 (the dead law): a refusal marked dead claims the operation is
 *   statically CONSTANT — so all claimed specimen pairs of the refused
 *   types must evaluate to one identical boolean (== between disjoint
 *   types is constant-false; != is constant-true). This law exists because
 *   its absence let real bugs ship: dead refusals were exempt from R2 on
 *   the unverified assumption that dead meant constant, and PHP loose
 *   equality (5 == '5' → true) plus array_intersect's string comparison
 *   (true in [1] → true) broke the assumption silently, in four operators.
 *
 * The same two laws run against the composed default dialect, which is
 * itself an OperatorOverloader. Packages and hosts extend this harness by
 * contributing their own specimens and rules.
 */
#[CoversNothing]
final class AgreementHarnessTest extends TestCase
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
            // Objects deliberately excluded: Unknown is the sanctioned hole.
            'Unknown' => [new UnknownType(), [true, 5, 'a', null, ['a']]],
        ];
    }

    private const binaryOperators = [
        '+', '-', '*', '/',
        '=', '==', '===', '!=', '!==', '<', '<=', '>', '>=',
        '&&', '||', 'xor',
        'has', 'in', 'intersects',
    ];

    private const unaryOperators = ['!', 'not', '-'];

    /**
     * @return \Generator<string, array{OperatorOverloader, bool}>
     */
    public static function binaryRules(): \Generator
    {
        yield 'BinaryOverloader' => [new BinaryOverloader(), false];
        yield 'ComparisonOverloader' => [new ComparisonOverloader(), false];
        yield 'LogicalOverloader' => [new LogicalOverloader(), false];
        yield 'NullOverloader' => [new NullOverloader(), true];
        yield 'HasOverloader' => [new HasOverloader(), false];
        yield 'InOverloader' => [new InOverloader(), false];
        yield 'IntersectsOverloader' => [new IntersectsOverloader(), false];
        yield 'DefaultOverloader (the composed dialect)' => [new DefaultOverloader(), true];

        // A builder-generated row: the laws are claimed to hold by
        // construction — this entry is that claim, verified.
        yield 'InfixSignature (builder-generated string concatenation)' => [
            Operator::infix('+')
                ->signature(new StringType(), new StringType())
                ->returns(new StringType())
                ->evaluate(fn(string $a, string $b) => $a . $b),
            false,
        ];
    }

    #[Test]
    #[DataProvider('binaryRules')]
    public function evaluate_and_typeOf_agree(OperatorOverloader $rule, bool $exemptFromAntiShadowing): void
    {
        $specimens = self::specimens();

        foreach (self::binaryOperators as $operator) {
            if (!$rule->handles($operator)) {
                continue;
            }

            foreach ($specimens as $leftLabel => [$leftType, $leftValues]) {
                foreach ($specimens as $rightLabel => [$rightType, $rightValues]) {
                    $context = sprintf('%s [%s] %s', $leftLabel, $operator, $rightLabel);
                    $verdict = $rule->typeOf($operator, $leftType, $rightType);

                    $claimed = [];
                    $unclaimed = 0;

                    foreach ($leftValues as $left) {
                        foreach ($rightValues as $right) {
                            $rule->supportsOverloading($left, $right, $operator)
                                ? $claimed[] = [$left, $right]
                                : $unclaimed++;
                        }
                    }

                    $gradual = $leftType->shape() instanceof UnknownShape || $rightType->shape() instanceof UnknownShape;

                    if ($verdict->isOk() && !$gradual) {
                        $returnType = $verdict->unwrap();

                        foreach ($claimed as [$left, $right]) {
                            $result = $rule->evaluate($left, $right, $operator);

                            if ($result->isErr() || $returnType->shape() instanceof UnknownShape) {
                                continue;
                            }

                            $this->assertTrue(
                                $returnType->assert($result->unwrap())->isOk(),
                                sprintf(
                                    'R1: %s produced a value outside the certified %s',
                                    $context,
                                    TypeDescriber::describe($returnType),
                                ),
                            );
                        }
                    }

                    if ($verdict->isErr() && !$verdict->unwrapErr()->dead && !$exemptFromAntiShadowing) {
                        $this->assertTrue(
                            $claimed === [] || $unclaimed > 0,
                            sprintf(
                                'R2: %s is refused ("%s") but the rule claims every specimen pair — it is hiding runtime semantics from the checker',
                                $context,
                                $verdict->unwrapErr()->message,
                            ),
                        );
                    }

                    if ($verdict->isErr() && $verdict->unwrapErr()->dead) {
                        $outcomes = [];

                        foreach ($claimed as [$left, $right]) {
                            $result = $rule->evaluate($left, $right, $operator);

                            if ($result->isOk()) {
                                $outcomes[$result->unwrap() ? 'true' : 'false'] = true;
                            }
                        }

                        $this->assertLessThanOrEqual(
                            1,
                            count($outcomes),
                            sprintf(
                                'R3: %s is refused as dead ("statically constant") but claimed pairs evaluated to both true and false — the dead verdict is a lie',
                                $context,
                            ),
                        );
                    }
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @return \Generator<string, array{UnaryOverloader, bool}>
     */
    public static function unaryRules(): \Generator
    {
        yield 'NotOverloader' => [new NotOverloader(), false];
        yield 'NegateOverloader' => [new NegateOverloader(), false];
        yield 'UnaryOverloaderManager (the composed dialect)' => [UnaryOverloaderManager::default(), false];

        yield 'PrefixSignature (builder-generated negation)' => [
            Operator::prefix('-')
                ->signature(new NumberType())
                ->returns(new NumberType())
                ->evaluate(fn(int|float $n) => -$n),
            false,
        ];
    }

    #[Test]
    #[DataProvider('unaryRules')]
    public function unary_evaluate_and_typeOf_agree(UnaryOverloader $rule, bool $exemptFromAntiShadowing): void
    {
        foreach (self::unaryOperators as $operator) {
            if (!$rule->handles($operator)) {
                continue;
            }

            foreach (self::specimens() as $label => [$type, $values]) {
                $context = sprintf('[%s] %s', $operator, $label);
                $verdict = $rule->typeOf($operator, $type);

                $claimed = array_values(array_filter($values, fn(mixed $value) => $rule->supportsOverloading($value, $operator)));
                $gradual = $type->shape() instanceof UnknownShape;

                if ($verdict->isOk() && !$gradual) {
                    $returnType = $verdict->unwrap();

                    foreach ($claimed as $value) {
                        $result = $rule->evaluate($value, $operator);

                        if ($result->isErr()) {
                            continue;
                        }

                        $this->assertTrue(
                            $returnType->assert($result->unwrap())->isOk(),
                            sprintf('R1: %s produced a value outside the certified %s', $context, TypeDescriber::describe($returnType)),
                        );
                    }
                }

                if ($verdict->isErr() && !$verdict->unwrapErr()->dead && !$exemptFromAntiShadowing) {
                    $this->assertTrue(
                        $claimed === [] || count($claimed) < count($values),
                        sprintf(
                            'R2: %s is refused ("%s") but the rule claims every specimen value',
                            $context,
                            $verdict->unwrapErr()->message,
                        ),
                    );
                }

                if ($verdict->isErr() && $verdict->unwrapErr()->dead) {
                    $outcomes = [];

                    foreach ($claimed as $value) {
                        $result = $rule->evaluate($value, $operator);

                        if ($result->isOk()) {
                            $outcomes[var_export($result->unwrap(), true)] = true;
                        }
                    }

                    $this->assertLessThanOrEqual(
                        1,
                        count($outcomes),
                        sprintf('R3: %s is refused as dead but claimed values evaluated to differing outcomes', $context),
                    );
                }
            }
        }

        $this->addToAssertionCount(1);
    }
}
