<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Axiom\Types\UnionType;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * End-to-end pinning of the review findings (RFC 0001, fourth through
 * sixth resolved-questions rounds): every scenario asserts the static
 * verdict AND the runtime outcome, because the PR's central guarantee —
 * what the checker certifies is what the program does — lives exactly at
 * that agreement. Under compile-then-trust the two faces meet in one
 * place: a refused program has no runtime outcome at all, which is the
 * strongest agreement there is. Cycle detection and declared∧defined
 * collisions are pinned in TypedExpressionTest; the boundary-stripping
 * behavior in ExpressionTest; list-bounds validation in ListTypeTest and
 * ShapeTest. Open records are gone from the vocabulary entirely, so their
 * finding has nothing left to pin.
 */
#[CoversNothing]
final class SoundnessRegressionTest extends TestCase
{
    /**
     * A host-owned opaque domain type, the documented pattern: real
     * membership check, OpaqueShape projection.
     */
    private function moneyType(): Type
    {
        return new class implements Type {
            public function assert(mixed $value): Result
            {
                return $value instanceof \stdClass
                    ? Ok(Some($value))
                    : Err(new InvalidArgumentException('Not money.'));
            }

            public function coerce(mixed $value): Result
            {
                return $this->assert($value);
            }

            public function compare(mixed $a, mixed $b): bool
            {
                return $a === $b;
            }

            public function format(mixed $value): string
            {
                return 'money';
            }

            public function shape(): Shape
            {
                return new OpaqueShape('money');
            }
        };
    }

    #[Test]
    public function the_matcher_and_the_exhaustiveness_analysis_share_one_literal_identity(): void
    {
        // Finding 1: coverage said 5.0 covers Literal(5) while the matcher
        // used === — certified exhaustive, failed at runtime. One value
        // equality now serves both faces.
        $expression = new Expression(
            source: new MatchExpression(
                subject: new StaticSource(5),
                arms: [new MatchArm(new LiteralPattern(5.0), new StaticSource('ok'))],
            ),
        );

        $program = $expression->compile();

        $this->assertTrue($program->isOk(), 'statically exhaustive');
        $this->assertSame('ok', $program->unwrap()->call()->unwrap()->unwrap(), 'and the program agrees');
    }

    #[Test]
    public function a_nested_binding_cannot_reach_a_namespaced_definition(): void
    {
        // Finding 2: ['customer' => ['turnover' => 42]] used to shadow the
        // customer.turnover definition through descent, past the top-level
        // shadowing check. Undeclared keys are stripped now.
        $program = (new Expression(
            source: new SymbolSource('turnover', 'customer'),
            definitions: new Definitions(['customer.turnover' => new StaticSource('derived')]),
        ))->compile()->unwrap();

        $this->assertSame('derived', $program()->unwrap()->unwrap());
        $this->assertSame('derived', $program(['customer' => ['turnover' => 42]])->unwrap()->unwrap());
    }

    #[Test]
    public function each_program_embeds_its_own_dialect_resolutions(): void
    {
        // Finding 3, twice removed: the runtime stack first lived on a
        // shared resolver (whoever wired it first decided what BOTH
        // expressions ran), then rode the per-call Context. Compilation
        // ends the question: each program embeds what its own dialect
        // resolved, and there is no dispatch left to miscompose.
        $concatenation = new class extends Extension {
            public function operators(): array
            {
                return [
                    Operator::infix('+')
                        ->takes(new StringType(), new StringType())
                        ->returns(new StringType())
                        ->evaluatesWith(fn(string $a, string $b) => $a . $b),
                ];
            }
        };

        $numeric = (new Expression(
            source: new InfixExpression(new StaticSource(1), '+', new StaticSource(2)),
        ))->compile()->unwrap();

        $textual = (new Expression(
            source: new InfixExpression(new StaticSource('a'), '+', new StaticSource('b')),
            dialect: Dialect::core()->with($concatenation),
        ))->compile()->unwrap();

        $this->assertInstanceOf(NumberType::class, $numeric->returns);
        $this->assertSame(3, $numeric()->unwrap()->unwrap());

        $this->assertInstanceOf(StringType::class, $textual->returns);
        $this->assertSame('ab', $textual()->unwrap()->unwrap());

        // Order independence: the first program still runs core rules
        // after the second was compiled and evaluated.
        $this->assertSame(3, $numeric()->unwrap()->unwrap());
    }

    #[Test]
    public function list_and_dict_comparison_is_live_and_true_exactly_when_both_are_empty(): void
    {
        // Finding 4: overlaps(List, Dict) said "no shared values" — a dead
        // verdict falsified by [] == [] → true. The empty array inhabits
        // both types, so the comparison is live.
        $program = (new Expression(
            source: new InfixExpression(new SymbolSource('xs'), '==', new SymbolSource('ys')),
            declarations: [
                'xs' => new ListType(new NumberType()),
                'ys' => new DictType(new NumberType()),
            ],
        ))->compile();

        $this->assertTrue($program->isOk(), 'a live comparison, not a dead one');

        // The default coercing boundary admits [] as an empty dict (the
        // faces agree on the value domain), so no Assert workaround here.
        $this->assertTrue($program->unwrap()->call(['xs' => [], 'ys' => []])->unwrap()->unwrap());
        $this->assertFalse($program->unwrap()->call(['xs' => [1], 'ys' => ['a' => 1]])->unwrap()->unwrap());
    }

    #[Test]
    public function opaque_equality_is_refused_at_compile_time_so_no_program_exists_to_crash(): void
    {
        // Finding 5a: two Opaque<money> operands type-checked as Boolean
        // (overlap held) while the runtime rejected objects — a certified
        // crash. The totality guard refuses what value equality never
        // claims — and with invocation living only on Program, the refusal
        // leaves nothing to run.
        $money = $this->moneyType();

        $verdict = (new Expression(
            source: new InfixExpression(new SymbolSource('a'), '==', new SymbolSource('b')),
            declarations: ['a' => $money, 'b' => $money],
        ))->compile();

        $this->assertTrue($verdict->isErr(), 'refused at compile time');
        $this->assertFalse($verdict->unwrapErr()->dead, 'unsupported, not dead: no rule owns object equality here');
        $this->assertStringContainsString('package that owns an opaque type define its equality', $verdict->unwrapErr()->describe());
    }

    #[Test]
    public function a_union_needle_with_an_unclaimed_branch_is_refused_at_compile_time(): void
    {
        // Finding 5b: needle: Number | Dict<Number> in List<Number> was
        // certified on the strength of the Number branch alone; a
        // dict-valued needle crashed. Union judgment is universal now —
        // the author narrows with match — and the refused program cannot
        // be constructed, let alone run.
        $verdict = (new Expression(
            source: new InfixExpression(new SymbolSource('needle'), 'in', new StaticSource([1, 2, 3])),
            declarations: ['needle' => new UnionType(new NumberType(), new DictType(new NumberType()))],
        ))->compile();

        $this->assertTrue($verdict->isErr(), 'refused at compile time');
        $this->assertStringContainsString('The needle of [in] must be a scalar or a list', $verdict->unwrapErr()->describe());
    }

    #[Test]
    public function a_missing_binding_for_an_option_shaped_union_is_legal_absence(): void
    {
        // Finding 7a: Union(Option<Number>, String) has canonical shape
        // (Number | String)? but admit() tested instanceof OptionType and
        // demanded the input. Required-ness follows the projection now.
        $program = (new Expression(
            source: new SymbolSource('x'),
            declarations: ['x' => new UnionType(new OptionType(new NumberType()), new StringType())],
        ))->compile()->unwrap();

        $outcome = $program([]);

        $this->assertTrue($outcome->isOk(), 'a missing optional input is legal absence, not a boundary error');
        $this->assertTrue($outcome->unwrap()->isNone());
    }

    #[Test]
    public function unary_optionality_propagates_through_an_option_shaped_union(): void
    {
        // Finding 7b: Union(Option<Number>, Number) canonicalizes to
        // Number?, but the unary rule tested instanceof OptionType and
        // refused. Optionality follows the projection: -x is Number?.
        $program = (new Expression(
            source: new UnaryExpression(operator: '-', operand: new SymbolSource('x')),
            declarations: ['x' => new UnionType(new OptionType(new NumberType()), new NumberType())],
        ))->compile()->unwrap();

        $this->assertTrue(TypeRelations::areEquivalent($program->returns, new OptionType(new NumberType()))->isOk());
        $this->assertSame(-5, $program(['x' => 5])->unwrap()->unwrap());
        $this->assertTrue($program([])->unwrap()->isNone(), 'absence propagates instead of erroring');
    }

    #[Test]
    public function a_null_only_match_over_the_null_literal_is_exhaustive(): void
    {
        // Finding 8: null infers as Option<Never>, and covers(Never) fell
        // through to false — a vacuously exhaustive match was rejected.
        $program = (new Expression(
            source: new MatchExpression(
                subject: new StaticSource(null),
                arms: [new MatchArm(new LiteralPattern(null), new StaticSource('ok'))],
            ),
        ))->compile();

        $this->assertTrue($program->isOk(), 'Never is vacuously covered');
        $this->assertSame('ok', $program->unwrap()->call()->unwrap()->unwrap());
    }

    #[Test]
    public function absence_cannot_cross_a_non_optional_coerce_node(): void
    {
        // Fifth round, finding 1: Coerce(Number, '') + 1 certified Number
        // statically while the runtime passed None through, crashing later
        // with "No overloader found for [null] + [1]". Compilation still
        // takes the declared type verbatim (the boundary is statically
        // opaque by design); the evaluation errs at the node, by name,
        // instead of leaking absence into a certified expression.
        $expression = new Expression(
            source: new InfixExpression(
                left: new Coerce(new NumberType(), new StaticSource('')),
                operator: '+',
                right: new StaticSource(1),
            ),
        );

        $this->assertTrue($expression->check(new NumberType())->isOk(), 'still certified Number');

        $outcome = $expression->compile()->unwrap()->call();

        $this->assertTrue($outcome->isErr());
        $this->assertStringContainsString('reads as missing, but Number is required', $outcome->unwrapErr()->getMessage());
    }

    #[Test]
    public function a_caller_binding_can_never_answer_for_a_definition(): void
    {
        // Fifth round, finding 4 generalized: with descent, ANY
        // un-enumerated width — a dict's keys as much as a record extra —
        // could answer a symbol lookup the checker refuses, shadowing a
        // definition (999 beat 1). Symbols are exact keys now: the
        // definition is the only reading, statically and at runtime.
        $program = (new Expression(
            source: new SymbolSource('turnover', 'customer'),
            definitions: new Definitions(['customer.turnover' => new StaticSource(1)]),
            declarations: ['customer' => new DictType(new NumberType())],
        ))->compile()->unwrap();

        $this->assertSame(1, $program(['customer' => ['turnover' => 999]])->unwrap()->unwrap());
    }

    #[Test]
    public function the_coercing_boundary_never_admits_a_value_outside_its_declared_type(): void
    {
        // Fifth round, finding 3: Dict::coerce([1, 2]) returned [1, 2]
        // (PHP re-normalizes stringified numeric keys), so the default
        // boundary admitted a value the certified Dict's own assert
        // refuses. Both faces now agree: lists are rejected, and [] is an
        // empty dict rather than an absence reading. Under compile-then-
        // trust this agreement is the entire trust chain — see the
        // admission-honesty law in the shape census.
        $program = (new Expression(
            source: new SymbolSource('d'),
            declarations: ['d' => new DictType(new NumberType())],
        ))->compile()->unwrap();

        $rejected = $program(['d' => [1, 2]]);
        $this->assertStringContainsString('binding [d]:', $rejected->unwrapErr()->getMessage());

        $this->assertSame([], $program(['d' => []])->unwrap()->unwrap(), '[] is a value of the type, not absence');
    }

    #[Test]
    public function empty_list_and_empty_record_comparison_is_live(): void
    {
        // Fifth round, finding 5: overlaps(List, Record{}) said dead while
        // [] == [] evaluates true — the same one-value-two-types theorem
        // as list/dict. The empty record's canonical member is exactly [].
        $program = (new Expression(
            source: new InfixExpression(new SymbolSource('xs'), '==', new SymbolSource('r')),
            declarations: [
                'xs' => new ListType(new NumberType()),
                'r' => new \Superscript\Axiom\Types\RecordType([]),
            ],
        ))->compile();

        $this->assertTrue($program->isOk(), 'a live comparison, not a dead one');
        $this->assertTrue($program->unwrap()->call(['xs' => [], 'r' => []])->unwrap()->unwrap());
        $this->assertFalse($program->unwrap()->call(['xs' => [1], 'r' => []])->unwrap()->unwrap());
    }

    #[Test]
    public function an_unchecked_program_is_unrepresentable(): void
    {
        // Sixth round, the keystone: invocation lives only on Program, and
        // the only way to a Program is a compile() that succeeded. The
        // value-directed dispatch that used to double-check every operator
        // at runtime existed because evaluation could not assume a check —
        // now it can, structurally.
        $this->assertFalse(method_exists(Expression::class, 'call'));
        $this->assertFalse(method_exists(Expression::class, '__invoke'));
    }
}
