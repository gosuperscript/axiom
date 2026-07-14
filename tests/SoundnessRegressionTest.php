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
use Superscript\Axiom\Boundary;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Patterns\ExpressionMatcher;
use Superscript\Axiom\Patterns\LiteralMatcher;
use Superscript\Axiom\Patterns\WildcardMatcher;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\MatchResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Resolvers\UnaryResolver;
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
 * End-to-end pinning of the second-opinion review findings (RFC 0001,
 * fourth resolved-questions round): every scenario asserts the static
 * verdict AND the runtime outcome, because the PR's central guarantee —
 * what the checker certifies is what the evaluator does — lives exactly at
 * that agreement. Cycle detection and declared∧defined collisions are
 * pinned in TypedExpressionTest; the boundary-stripping behavior in
 * ExpressionTest.
 */
#[CoversNothing]
final class SoundnessRegressionTest extends TestCase
{
    private function resolver(): DelegatingResolver
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            SymbolSource::class => SymbolResolver::class,
            InfixExpression::class => InfixResolver::class,
            UnaryExpression::class => UnaryResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);

        $resolver->instance(MatchResolver::class, new MatchResolver($resolver, [
            new WildcardMatcher(),
            new LiteralMatcher(),
            new ExpressionMatcher($resolver),
        ]));

        return $resolver;
    }

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
            resolver: $this->resolver(),
        );

        $this->assertTrue($expression->infer()->isOk(), 'statically exhaustive');
        $this->assertSame('ok', $expression()->unwrap()->unwrap(), 'and the runtime agrees');
    }

    #[Test]
    public function a_nested_binding_cannot_reach_a_namespaced_definition(): void
    {
        // Finding 2: ['customer' => ['turnover' => 42]] used to shadow the
        // customer.turnover definition through descent, past the top-level
        // shadowing check. Undeclared keys are stripped now.
        $expression = new Expression(
            source: new SymbolSource('turnover', 'customer'),
            resolver: $this->resolver(),
            definitions: new Definitions(['customer.turnover' => new StaticSource('derived')]),
        );

        $this->assertSame('derived', $expression()->unwrap()->unwrap());
        $this->assertSame('derived', $expression(['customer' => ['turnover' => 42]])->unwrap()->unwrap());
    }

    #[Test]
    public function expressions_sharing_a_resolver_each_run_their_own_dialect(): void
    {
        // Finding 3: the runtime stack lived on the resolver, so of two
        // expressions sharing one resolver, whoever wired it first decided
        // what BOTH ran — while each checked its own dialect. The dialect
        // rides the Context now.
        $concatenation = new class extends Extension {
            public function operators(): array
            {
                return [
                    Operator::infix('+')
                        ->signature(new StringType(), new StringType())
                        ->returns(new StringType())
                        ->evaluate(fn(string $a, string $b) => $a . $b),
                ];
            }
        };

        $resolver = $this->resolver();

        $numeric = new Expression(
            source: new InfixExpression(new StaticSource(1), '+', new StaticSource(2)),
            resolver: $resolver,
        );

        $textual = new Expression(
            source: new InfixExpression(new StaticSource('a'), '+', new StaticSource('b')),
            resolver: $resolver,
            dialect: Dialect::core()->with($concatenation),
        );

        $this->assertInstanceOf(NumberType::class, $numeric->infer()->unwrap());
        $this->assertSame(3, $numeric()->unwrap()->unwrap());

        $this->assertInstanceOf(StringType::class, $textual->infer()->unwrap());
        $this->assertSame('ab', $textual()->unwrap()->unwrap());

        // Order independence: the first expression still runs core rules
        // after the second was constructed and evaluated.
        $this->assertSame(3, $numeric()->unwrap()->unwrap());
    }

    #[Test]
    public function list_and_dict_comparison_is_live_and_true_exactly_when_both_are_empty(): void
    {
        // Finding 4: overlaps(List, Dict) said "no shared values" — a dead
        // verdict falsified by [] == [] → true. The empty array inhabits
        // both types, so the comparison is live.
        $expression = new Expression(
            source: new InfixExpression(new SymbolSource('xs'), '==', new SymbolSource('ys')),
            resolver: $this->resolver(),
            declarations: [
                'xs' => new ListType(new NumberType()),
                'ys' => new DictType(new NumberType()),
            ],
            boundary: Boundary::Assert,
        );

        $this->assertFalse($expression->infer()->isErr(), 'a live comparison, not a dead one');
        $this->assertTrue($expression(['xs' => [], 'ys' => []])->unwrap()->unwrap());
        $this->assertFalse($expression(['xs' => [1], 'ys' => ['a' => 1]])->unwrap()->unwrap());
    }

    #[Test]
    public function opaque_equality_is_refused_statically_exactly_as_the_runtime_refuses_it(): void
    {
        // Finding 5a: two Opaque<money> operands type-checked as Boolean
        // (overlap held) while the runtime rejects objects — a certified
        // crash. The totality guard refuses what the runtime never claims.
        $money = $this->moneyType();

        $expression = new Expression(
            source: new InfixExpression(new SymbolSource('a'), '==', new SymbolSource('b')),
            resolver: $this->resolver(),
            declarations: ['a' => $money, 'b' => $money],
            boundary: Boundary::Assert,
        );

        $verdict = $expression->infer();

        $this->assertTrue($verdict->isErr(), 'refused statically');
        $this->assertFalse($verdict->unwrapErr()->dead, 'unsupported, not dead: the runtime errs rather than evaluating constantly');
        $this->assertStringContainsString('object equality belongs to the rule that owns the type', $verdict->unwrapErr()->describe());

        $outcome = $expression(['a' => new \stdClass(), 'b' => new \stdClass()]);

        $this->assertTrue($outcome->isErr(), 'and the runtime refuses identically');
        $this->assertStringContainsString('No overloader found', $outcome->unwrapErr()->getMessage());
    }

    #[Test]
    public function a_union_needle_with_an_unclaimed_branch_is_refused_statically(): void
    {
        // Finding 5b: needle: Number | Dict<Number> in List<Number> was
        // certified on the strength of the Number branch alone; a
        // dict-valued needle crashed. Union judgment is universal now —
        // the author narrows with match.
        $expression = new Expression(
            source: new InfixExpression(new SymbolSource('needle'), 'in', new StaticSource([1, 2, 3])),
            resolver: $this->resolver(),
            declarations: ['needle' => new UnionType(new NumberType(), new DictType(new NumberType()))],
            boundary: Boundary::Assert,
        );

        $verdict = $expression->infer();

        $this->assertTrue($verdict->isErr(), 'refused statically');
        $this->assertStringContainsString('The needle of [in] must be a scalar or a list', $verdict->unwrapErr()->describe());

        $outcome = $expression(['needle' => ['a' => 1]]);

        $this->assertTrue($outcome->isErr(), 'the unclaimed branch errs at runtime, exactly as the checker warned');
        $this->assertStringContainsString('No overloader found', $outcome->unwrapErr()->getMessage());
    }

    #[Test]
    public function a_missing_binding_for_an_option_shaped_union_is_legal_absence(): void
    {
        // Finding 7a: Union(Option<Number>, String) has canonical shape
        // (Number | String)? but admit() tested instanceof OptionType and
        // demanded the input. Required-ness follows the projection now.
        $expression = new Expression(
            source: new SymbolSource('x'),
            resolver: $this->resolver(),
            declarations: ['x' => new UnionType(new OptionType(new NumberType()), new StringType())],
        );

        $this->assertTrue($expression->infer()->isOk());

        $outcome = $expression([]);

        $this->assertTrue($outcome->isOk(), 'a missing optional input is legal absence, not a boundary error');
        $this->assertTrue($outcome->unwrap()->isNone());
    }

    #[Test]
    public function unary_optionality_propagates_through_an_option_shaped_union(): void
    {
        // Finding 7b: Union(Option<Number>, Number) canonicalizes to
        // Number?, but inferUnary tested instanceof OptionType and refused.
        // Optionality now follows the projection: -x is Number?.
        $expression = new Expression(
            source: new UnaryExpression(operator: '-', operand: new SymbolSource('x')),
            resolver: $this->resolver(),
            declarations: ['x' => new UnionType(new OptionType(new NumberType()), new NumberType())],
        );

        $inferred = $expression->infer()->unwrap();

        $this->assertTrue(TypeRelations::areEquivalent($inferred, new OptionType(new NumberType()))->isOk());
        $this->assertSame(-5, $expression(['x' => 5])->unwrap()->unwrap());
        $this->assertTrue($expression([])->unwrap()->isNone(), 'absence propagates instead of erroring');
    }

    #[Test]
    public function a_null_only_match_over_the_null_literal_is_exhaustive(): void
    {
        // Finding 8: null infers as Option<Never>, and covers(Never) fell
        // through to false — a vacuously exhaustive match was rejected.
        $expression = new Expression(
            source: new MatchExpression(
                subject: new StaticSource(null),
                arms: [new MatchArm(new LiteralPattern(null), new StaticSource('ok'))],
            ),
            resolver: $this->resolver(),
        );

        $this->assertTrue($expression->infer()->isOk(), 'Never is vacuously covered');
        $this->assertSame('ok', $expression()->unwrap()->unwrap());
    }
}
