<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Program;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Tests\Fixtures\SpyInspector;
use Superscript\Axiom\TypedSource;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Axiom\Types\UnionType;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * The compiled runtime, node by node: what evaluation still does (absence
 * short-circuits, match arms, the two admission bridges, observability)
 * now that it dispatches nothing — plus the named backstops that catch a
 * host source whose evaluation breaks its own type claim.
 */
#[CoversClass(TypeInference::class)]
#[CoversClass(Program::class)]
#[CoversClass(Runtime::class)]
#[CoversClass(CompiledNode::class)]
#[UsesClass(Expression::class)]
#[UsesClass(\Superscript\Axiom\Bindings::class)]
#[UsesClass(\Superscript\Axiom\DefinitionGraph::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(\Superscript\Axiom\Dialect::class)]
#[UsesClass(\Superscript\Axiom\UnboundSymbols::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(UnaryExpression::class)]
#[UsesClass(MemberAccessSource::class)]
#[UsesClass(MatchExpression::class)]
#[UsesClass(MatchArm::class)]
#[UsesClass(LiteralPattern::class)]
#[UsesClass(WildcardPattern::class)]
#[UsesClass(ExpressionPattern::class)]
#[UsesClass(Coerce::class)]
#[UsesClass(Ascription::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\Equality::class)]
#[UsesClass(\Superscript\Axiom\Operators\Has::class)]
#[UsesClass(\Superscript\Axiom\Operators\In::class)]
#[UsesClass(\Superscript\Axiom\Operators\Intersects::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
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
#[UsesClass(TypeEnvironment::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeReifier::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(RecordType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(\Superscript\Axiom\Types\UnknownType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
#[UsesClass(\Superscript\Axiom\Types\NeverType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\TransformValueException::class)]
final class ProgramTest extends TestCase
{
    /**
     * A host source that claims one type and evaluates to whatever it
     * likes — the dishonest host every runtime backstop exists for.
     */
    private static function lyingSource(Type $claims, mixed $actual): TypedSource
    {
        return new class ($claims, $actual) implements TypedSource {
            public function __construct(private readonly Type $claims, private readonly mixed $actual) {}

            public function compile(TypeEnvironment $environment, TypeInference $compiler): Result
            {
                // One representation of null in the resolution channel.
                return Ok(new CompiledNode(
                    $this->claims,
                    fn(Runtime $runtime) => Ok($this->actual === null ? \Superscript\Monads\Option\None() : Some($this->actual)),
                ));
            }
        };
    }

    #[Test]
    public function a_coerce_bridge_converts_at_runtime(): void
    {
        $program = (new Expression(new Coerce(new NumberType(), new StaticSource('42'))))->compile()->unwrap();

        $this->assertSame(42, $program()->unwrap()->unwrap());
    }

    #[Test]
    public function absence_cannot_cross_a_non_optional_coercion(): void
    {
        $program = (new Expression(new Coerce(new NumberType(), new StaticSource(''))))->compile()->unwrap();

        $result = $program();

        $this->assertStringContainsString('The coerced value reads as missing, but Number is required; coerce to Number? instead', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function an_absence_reading_inhabits_an_optional_coercion(): void
    {
        $program = (new Expression(new Coerce(new OptionType(new NumberType()), new StaticSource(''))))->compile()->unwrap();

        $this->assertTrue($program()->unwrap()->isNone());
    }

    #[Test]
    public function an_absent_inner_value_inhabits_an_optional_coercion(): void
    {
        $program = (new Expression(new Coerce(new OptionType(new NumberType()), new StaticSource(null))))->compile()->unwrap();

        $this->assertTrue($program()->unwrap()->isNone());
    }

    #[Test]
    public function an_uncoercible_value_errs_at_the_bridge(): void
    {
        $program = (new Expression(new Coerce(new NumberType(), new StaticSource('lots'))))->compile()->unwrap();

        $this->assertTrue($program()->isErr());
    }

    #[Test]
    public function an_ascription_verifies_membership_at_runtime(): void
    {
        $blob = self::lyingSource(new \Superscript\Axiom\Types\UnknownType(), 42);

        $honest = (new Expression(new Ascription(new NumberType(), $blob)))->compile()->unwrap();
        $this->assertSame(42, $honest()->unwrap()->unwrap());

        $lying = (new Expression(new Ascription(new StringType(), self::lyingSource(new \Superscript\Axiom\Types\UnknownType(), 42))))->compile()->unwrap();
        $this->assertTrue($lying()->isErr(), 'a false claim is a tripwire, not a rot vector');
    }

    #[Test]
    public function absence_cannot_cross_a_non_optional_claim(): void
    {
        // Statically, a null literal under a Number claim is already false:
        // {null} ∩ Number = ∅, refused at compile time.
        $program = (new Expression(new Ascription(new NumberType(), new StaticSource(null))))->compile();
        $this->assertTrue($program->isErr());

        // The runtime guard exists for what the checker cannot see: an
        // Unknown-typed source that reads absent under a required claim.
        $absent = self::lyingSource(new \Superscript\Axiom\Types\UnknownType(), null);
        $viaUnknown = (new Expression(new Ascription(new NumberType(), $absent)))->compile()->unwrap();
        $result = $viaUnknown();

        $this->assertStringContainsString('The ascribed value reads as missing, but the claim Number is required; claim Number? instead', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function an_absent_value_inhabits_an_optional_claim(): void
    {
        $absent = self::lyingSource(new \Superscript\Axiom\Types\UnknownType(), null);

        $program = (new Expression(new Ascription(new OptionType(new NumberType()), $absent)))->compile()->unwrap();

        $this->assertTrue($program()->unwrap()->isNone());
    }

    #[Test]
    public function member_access_reads_arrays_and_objects(): void
    {
        $record = new RecordType(['turnover' => new NumberType()]);
        $program = (new Expression(
            new MemberAccessSource(new SymbolSource('customer'), 'turnover'),
            declarations: ['customer' => $record],
        ))->compile()->unwrap();

        $this->assertSame(42, $program(['customer' => ['turnover' => 42]])->unwrap()->unwrap());

        // An object with a true record projection reads by property.
        $projected = self::lyingSource($record, (object) ['turnover' => 7]);
        $object = (new Expression(new MemberAccessSource($projected, 'turnover')))->compile()->unwrap();

        $this->assertSame(7, $object()->unwrap()->unwrap());
    }

    #[Test]
    public function member_access_propagates_absence(): void
    {
        $program = (new Expression(
            new MemberAccessSource(new SymbolSource('customer'), 'turnover'),
            declarations: ['customer' => new OptionType(new RecordType(['turnover' => new NumberType()]))],
        ))->compile()->unwrap();

        $this->assertTrue($program([])->unwrap()->isNone());
        $this->assertSame(2, $program(['customer' => ['turnover' => 2]])->unwrap()->unwrap());
    }

    #[Test]
    public function a_field_a_lying_host_source_never_delivered_errs_by_name(): void
    {
        // The projection claims the field; the value lacks it. Shape truth
        // is census law for types, but a TypedSource answers for itself —
        // so the structural read errs instead of inventing absence.
        $lying = self::lyingSource(new RecordType(['turnover' => new NumberType()]), ['other' => 1]);

        $program = (new Expression(new MemberAccessSource($lying, 'turnover')))->compile()->unwrap();

        $result = $program();

        $this->assertStringContainsString("Property 'turnover' does not exist", $result->unwrapErr()->getMessage());

        $scalar = self::lyingSource(new RecordType(['turnover' => new NumberType()]), 5);
        $onScalar = (new Expression(new MemberAccessSource($scalar, 'turnover')))->compile()->unwrap();

        $this->assertStringContainsString("Property 'turnover' does not exist on int", $onScalar()->unwrapErr()->getMessage());
    }

    #[Test]
    public function unary_operators_short_circuit_absence(): void
    {
        $program = (new Expression(
            new UnaryExpression('not', new SymbolSource('flag')),
            declarations: ['flag' => new OptionType(new BooleanType())],
        ))->compile()->unwrap();

        $this->assertTrue($program([])->unwrap()->isNone());
        $this->assertFalse($program(['flag' => true])->unwrap()->unwrap());
        $this->assertTrue($program(['flag' => false])->unwrap()->unwrap());
    }

    #[Test]
    public function match_arms_run_in_order_and_the_first_match_wins(): void
    {
        $program = (new Expression(
            new MatchExpression(
                subject: new SymbolSource('tier'),
                arms: [
                    new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                    new MatchArm(new WildcardPattern(), new StaticSource(1.0)),
                ],
            ),
            declarations: ['tier' => new StringType()],
        ))->compile()->unwrap();

        $this->assertSame(1.3, $program(['tier' => 'micro'])->unwrap()->unwrap());
        $this->assertSame(1.0, $program(['tier' => 'other'])->unwrap()->unwrap());
    }

    #[Test]
    public function expression_patterns_compare_against_the_subject(): void
    {
        $program = (new Expression(
            new MatchExpression(
                subject: new StaticSource(true),
                arms: [
                    new MatchArm(
                        new ExpressionPattern(new InfixExpression(new SymbolSource('n'), '>', new StaticSource(2))),
                        new StaticSource('big'),
                    ),
                    new MatchArm(new WildcardPattern(), new StaticSource('small')),
                ],
            ),
            declarations: ['n' => new NumberType()],
        ))->compile()->unwrap();

        $this->assertSame('big', $program(['n' => 3])->unwrap()->unwrap());
        $this->assertSame('small', $program(['n' => 1])->unwrap()->unwrap());
    }

    #[Test]
    public function a_failing_expression_pattern_propagates_its_error(): void
    {
        $program = (new Expression(
            new MatchExpression(
                subject: new StaticSource(true),
                arms: [
                    new MatchArm(
                        new ExpressionPattern(new InfixExpression(new SymbolSource('n'), '/', new StaticSource(0))),
                        new StaticSource('never'),
                    ),
                    new MatchArm(new WildcardPattern(), new StaticSource('fallback')),
                ],
            ),
            declarations: ['n' => new NumberType()],
        ))->compile()->unwrap();

        $this->assertTrue($program(['n' => 1])->isErr());
    }

    #[Test]
    public function a_subject_a_lying_host_source_pushed_outside_its_enum_errs_by_name(): void
    {
        // Exhaustiveness was proven over the CLAIMED type; a host source
        // that evaluates outside it meets the fall-through error, not
        // silence. This is the one way a compiled match can fail to match.
        $tier = new UnionType(new LiteralType('micro'), new LiteralType('small'));
        $lying = self::lyingSource($tier, 'enormous');

        $program = (new Expression(
            new MatchExpression(
                subject: $lying,
                arms: [
                    new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                    new MatchArm(new LiteralPattern('small'), new StaticSource(1.1)),
                ],
            ),
        ))->compile()->unwrap();

        $result = $program();

        $this->assertStringContainsString('No match arm matched the subject', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function a_division_by_zero_is_a_value_dependent_runtime_error(): void
    {
        $program = (new Expression(
            new InfixExpression(new SymbolSource('a'), '/', new SymbolSource('b')),
            declarations: ['a' => new NumberType(), 'b' => new NumberType()],
        ))->compile()->unwrap();

        $this->assertSame(2, $program(['a' => 6, 'b' => 3])->unwrap()->unwrap());
        $this->assertInstanceOf(\DivisionByZeroError::class, $program(['a' => 6, 'b' => 0])->unwrapErr());
    }

    #[Test]
    public function definitions_evaluate_lazily_and_fresh_per_invocation(): void
    {
        $counting = new class implements TypedSource {
            public int $evaluations = 0;

            public function compile(TypeEnvironment $environment, TypeInference $compiler): Result
            {
                return Ok(new CompiledNode(new NumberType(), function (Runtime $runtime) {
                    $this->evaluations++;

                    return Ok(Some(10));
                }));
            }
        };

        $program = (new Expression(
            source: new InfixExpression(new SymbolSource('base'), '+', new SymbolSource('base')),
            definitions: new Definitions(['base' => $counting]),
        ))->compile()->unwrap();

        $this->assertSame(20, $program()->unwrap()->unwrap());
        $this->assertSame(1, $counting->evaluations, 'one slot per invocation');

        $this->assertSame(20, $program()->unwrap()->unwrap());
        $this->assertSame(2, $counting->evaluations, 'a fresh invocation evaluates afresh');
    }

    #[Test]
    public function the_inspector_observes_the_compiled_evaluation(): void
    {
        $inspector = new SpyInspector();

        $program = (new Expression(
            source: new InfixExpression(new SymbolSource('base'), '*', new Coerce(new NumberType(), new StaticSource('4'))),
            definitions: new Definitions(['base' => new StaticSource(3)]),
            inspector: $inspector,
        ))->compile()->unwrap();

        $this->assertSame(12, $program()->unwrap()->unwrap());

        $this->assertSame('*', $inspector->annotations['label']);
        $this->assertSame(3, $inspector->annotations['left']);
        $this->assertSame(4, $inspector->annotations['right']);
        $this->assertSame(12, $inspector->annotations['result']);
        $this->assertSame('string -> int', $inspector->annotations['coercion']);
        $this->assertContains(['label', 'base'], $inspector->timeline);
        $this->assertContains(['memo', 'miss'], $inspector->timeline);
        $this->assertContains(['label', 'Number'], $inspector->timeline);
        $this->assertContains(['label', 'static(int)'], $inspector->timeline);
    }

    #[Test]
    public function the_inspector_observes_memo_hits_and_match_arms(): void
    {
        $inspector = new SpyInspector();

        $program = (new Expression(
            source: new MatchExpression(
                subject: new SymbolSource('flag'),
                arms: [
                    new MatchArm(new LiteralPattern(true), new InfixExpression(new SymbolSource('base'), '+', new SymbolSource('base'))),
                    new MatchArm(new LiteralPattern(false), new StaticSource(0)),
                ],
            ),
            definitions: new Definitions(['base' => new StaticSource(5)]),
            declarations: ['flag' => new BooleanType()],
            inspector: $inspector,
        ))->compile()->unwrap();

        $this->assertSame(10, $program(['flag' => true])->unwrap()->unwrap());

        $this->assertSame('match', $inspector->annotations['label']);
        $this->assertSame(true, $inspector->annotations['subject']);
        $this->assertSame(0, $inspector->annotations['matched_arm']);
        $this->assertContains(['memo', 'miss'], $inspector->timeline);
        $this->assertContains(['memo', 'hit'], $inspector->timeline);
    }

    #[Test]
    public function symbol_nodes_annotate_their_resolved_values(): void
    {
        $inspector = new SpyInspector();

        // A declared symbol annotates the bound value it read...
        $declared = (new Expression(
            source: new SymbolSource('turnover'),
            declarations: ['turnover' => new NumberType()],
            inspector: $inspector,
        ))->compile()->unwrap();

        $declared(['turnover' => 600000]);

        $this->assertContains(['result', 600000], $inspector->timeline);

        // ...and a defined symbol annotates the value its slot produced.
        $inspector = new SpyInspector();
        $defined = (new Expression(
            source: new SymbolSource('base'),
            definitions: new Definitions(['base' => new StaticSource(7)]),
            inspector: $inspector,
        ))->compile()->unwrap();

        $defined();

        $this->assertContains(['label', 'base'], $inspector->timeline);
        $this->assertContains(['result', 7], $inspector->timeline);
    }

    #[Test]
    public function no_coercion_annotation_when_the_value_is_unchanged(): void
    {
        $inspector = new SpyInspector();

        $program = (new Expression(
            source: new Coerce(new NumberType(), new StaticSource(42)),
            inspector: $inspector,
        ))->compile()->unwrap();

        $this->assertSame(42, $program()->unwrap()->unwrap());
        $this->assertArrayNotHasKey('coercion', $inspector->annotations);
        $this->assertSame('Number', $inspector->annotations['label']);
    }

    #[Test]
    public function the_ascription_annotates_its_claim(): void
    {
        $inspector = new SpyInspector();

        $program = (new Expression(
            source: new Ascription(new NumberType(), self::lyingSource(new \Superscript\Axiom\Types\UnknownType(), 42)),
            inspector: $inspector,
        ))->compile()->unwrap();

        $this->assertSame(42, $program()->unwrap()->unwrap());
        $this->assertSame('is Number', $inspector->annotations['label']);
    }

    #[Test]
    public function member_access_annotates_the_property_and_result(): void
    {
        $inspector = new SpyInspector();

        $program = (new Expression(
            source: new MemberAccessSource(new SymbolSource('customer'), 'age'),
            declarations: ['customer' => new RecordType(['age' => new NumberType()])],
            inspector: $inspector,
        ))->compile()->unwrap();

        $this->assertSame(30, $program(['customer' => ['age' => 30]])->unwrap()->unwrap());
        $this->assertSame('.age', $inspector->annotations['label']);
        $this->assertSame(30, $inspector->annotations['result']);
    }

    #[Test]
    public function unary_nodes_annotate_operator_and_result(): void
    {
        $inspector = new SpyInspector();

        $program = (new Expression(
            source: new UnaryExpression('-', new StaticSource(7)),
            inspector: $inspector,
        ))->compile()->unwrap();

        $this->assertSame(-7, $program()->unwrap()->unwrap());
        $this->assertSame('-', $inspector->annotations['label']);
        $this->assertSame(-7, $inspector->annotations['result']);
    }
}
