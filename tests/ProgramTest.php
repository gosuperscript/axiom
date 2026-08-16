<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Fields\Field;
use Superscript\Axiom\Tests\Fixtures\Money;
use Superscript\Axiom\Tests\Fixtures\MoneyType;
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
use Superscript\Axiom\Tests\Fixtures\CountingSource;
use Superscript\Axiom\Tests\Fixtures\EvaluationCounter;
use Superscript\Axiom\Tests\Fixtures\HostValueSource;
use Superscript\Axiom\Tests\Fixtures\SourceCompilerExtension;
use Superscript\Axiom\Tests\Fixtures\SpyObserver;
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

/**
 * The compiled runtime, node by node: what evaluation still does (absence
 * short-circuits, match arms, the two admission bridges, observability)
 * now that it dispatches nothing — plus the named backstops that catch a
 * host source whose evaluation breaks its own type claim.
 */
#[CoversClass(TypeInference::class)]
#[CoversClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\AdmissionNode::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\AscriptionSourceCompiler::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\CoerceSourceCompiler::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\ConstantNode::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\InfixExpressionCompiler::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\MatchExpressionCompiler::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\MemberAccessSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\FieldAccess::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\StaticSourceCompiler::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\SymbolSourceCompiler::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\UnaryExpressionCompiler::class)]
#[CoversClass(Program::class)]
#[CoversClass(Runtime::class)]
#[CoversClass(CompiledNode::class)]
#[UsesClass(\Superscript\Axiom\CompiledSource::class)]
#[UsesClass(\Superscript\Axiom\BoundOperation::class)]
#[UsesClass(\Superscript\Axiom\SourceEvaluation::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\CompilationAborted::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\EvaluationAborted::class)]
#[UsesClass(\Superscript\Axiom\Execution\Node::class)]
#[UsesClass(\Superscript\Axiom\Execution\Entered::class)]
#[UsesClass(\Superscript\Axiom\Execution\Annotated::class)]
#[UsesClass(\Superscript\Axiom\Execution\Exited::class)]
#[UsesClass(Expression::class)]
#[UsesClass(\Superscript\Axiom\Types\Optional::class)]
#[UsesClass(\Superscript\Axiom\Bindings::class)]
#[UsesClass(\Superscript\Axiom\DefinitionGraph::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(\Superscript\Axiom\Dialect::class)]
#[UsesClass(\Superscript\Axiom\Extension::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilation::class)]
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
#[UsesClass(\Superscript\Axiom\Operators\Coalesce::class)]
#[UsesClass(\Superscript\Axiom\Operators\Equality::class)]
#[UsesClass(\Superscript\Axiom\Operators\Has::class)]
#[UsesClass(\Superscript\Axiom\Operators\In::class)]
#[UsesClass(\Superscript\Axiom\Operators\Intersects::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
#[UsesClass(\Superscript\Axiom\Operators\ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithReturn::class)]
#[UsesClass(TypeEnvironment::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Fields\OpaqueFieldRegistry::class)]
#[UsesClass(\Superscript\Axiom\Fields\Field::class)]
#[UsesClass(\Superscript\Axiom\Fields\FieldBuilder::class)]
#[UsesClass(\Superscript\Axiom\Fields\NamedFieldBuilder::class)]
#[UsesClass(\Superscript\Axiom\Fields\TypedFieldBuilder::class)]
#[UsesClass(\Superscript\Axiom\Fields\OpaqueField::class)]
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
#[UsesClass(\Superscript\Axiom\Types\Shapes\OpaqueShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\TransformValueException::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom\\Analysis')]
#[UsesClass(\Superscript\Axiom\Operators\Connective::class)]
#[UsesClass(\Superscript\Axiom\Types\PresentType::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnsupportedOperation::class)]
#[UsesClass(\Superscript\Axiom\Types\InfixExpressionTyping::class)]
#[UsesClass(\Superscript\Axiom\ReferencePath::class)]
#[UsesClass(\Superscript\Axiom\Types\RecordProperty::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordPropertyShape::class)]
final class ProgramTest extends TestCase
{
    /**
     * A host source that claims one type and evaluates to whatever it
     * likes — the dishonest host every runtime backstop exists for.
     */
    private static function lyingSource(Type $claims, mixed $actual): HostValueSource
    {
        return new HostValueSource($claims, $actual);
    }

    private static function dialect(?EvaluationCounter $counter = null): Dialect
    {
        return Dialect::core()->with(new SourceCompilerExtension($counter));
    }

    #[Test]
    public function program_preserves_attached_compilation_analysis_and_falls_back_only_for_bare_nodes(): void
    {
        $source = new StaticSource(1);
        $analysis = \Superscript\Axiom\Analysis\CompilationNode::certified(
            StaticSource::class,
            new NumberType(),
            'axiom.core',
        );
        $attached = (CompiledNode::returning(
            new NumberType(),
            fn(Runtime $runtime) => \Superscript\Monads\Result\Ok(\Superscript\Monads\Option\Some(1)),
        ))->forSource($source, $analysis);

        $this->assertSame(StaticSource::class, (new Program($attached))->analysis->root->source);
        $this->assertSame(
            CompiledNode::class,
            (new Program(CompiledNode::returning(
                new NumberType(),
                fn(Runtime $runtime) => \Superscript\Monads\Result\Ok(\Superscript\Monads\Option\Some(1)),
            )))->analysis->root->source,
        );
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

        $honest = (new Expression(new Ascription(new NumberType(), $blob), dialect: self::dialect()))->compile()->unwrap();
        $this->assertSame(42, $honest()->unwrap()->unwrap());

        $lying = (new Expression(new Ascription(new StringType(), self::lyingSource(new \Superscript\Axiom\Types\UnknownType(), 42)), dialect: self::dialect()))->compile()->unwrap();
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
        $viaUnknown = (new Expression(new Ascription(new NumberType(), $absent), dialect: self::dialect()))->compile()->unwrap();
        $result = $viaUnknown();

        $this->assertStringContainsString('The ascribed value reads as missing, but the claim Number is required; claim Number? instead', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function an_absent_value_inhabits_an_optional_claim(): void
    {
        $absent = self::lyingSource(new \Superscript\Axiom\Types\UnknownType(), null);

        $program = (new Expression(new Ascription(new OptionType(new NumberType()), $absent), dialect: self::dialect()))->compile()->unwrap();

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

        $this->assertSame(['customer.turnover'], $program->references);
        $this->assertSame(42, $program(['customer' => ['turnover' => 42]])->unwrap()->unwrap());

        // An object with a true record projection reads by property.
        $projected = self::lyingSource($record, (object) ['turnover' => 7]);
        $object = (new Expression(new MemberAccessSource($projected, 'turnover'), dialect: self::dialect()))->compile()->unwrap();

        $this->assertSame(7, $object()->unwrap()->unwrap());

        // A host-projected record uses ordinary structural access rather
        // than declared-input path compilation, for arrays as well as objects.
        $projectedArray = self::lyingSource($record, ['turnover' => 8]);
        $array = (new Expression(new MemberAccessSource($projectedArray, 'turnover'), dialect: self::dialect()))->compile()->unwrap();
        $this->assertSame(8, $array()->unwrap()->unwrap());

        $optional = new RecordType(['turnover' => new \Superscript\Axiom\Types\Optional(new NumberType())]);
        $omitted = self::lyingSource($optional, []);
        $optionalAccess = (new Expression(new MemberAccessSource($omitted, 'turnover'), dialect: self::dialect()))->compile()->unwrap();
        $this->assertTrue($optionalAccess()->unwrap()->isNone());
    }

    #[Test]
    public function member_access_propagates_absence(): void
    {
        $program = (new Expression(
            new MemberAccessSource(new SymbolSource('customer'), 'turnover'),
            declarations: ['customer' => new \Superscript\Axiom\Types\Optional(new RecordType(['turnover' => new NumberType()]))],
        ))->compile()->unwrap();

        $this->assertTrue($program([])->unwrap()->isNone());
        $this->assertSame(2, $program(['customer' => ['turnover' => 2]])->unwrap()->unwrap());
    }

    #[Test]
    public function a_field_a_lying_host_source_never_delivered_errs_by_name(): void
    {
        // The projection claims the field; the value lacks it. Shape truth
        // is census law for types, but a host compiler answers for it —
        // so the structural read errs instead of inventing absence.
        $lying = self::lyingSource(new RecordType(['turnover' => new NumberType()]), ['other' => 1]);

        $program = (new Expression(new MemberAccessSource($lying, 'turnover'), dialect: self::dialect()))->compile()->unwrap();

        $result = $program();

        $this->assertStringContainsString("Property 'turnover' does not exist", $result->unwrapErr()->getMessage());

        $scalar = self::lyingSource(new RecordType(['turnover' => new NumberType()]), 5);
        $onScalar = (new Expression(new MemberAccessSource($scalar, 'turnover'), dialect: self::dialect()))->compile()->unwrap();

        $this->assertStringContainsString("Property 'turnover' does not exist on int", $onScalar()->unwrapErr()->getMessage());
    }

    #[Test]
    public function an_opaque_field_reads_through_the_declared_extractor(): void
    {
        $program = (new Expression(
            new MemberAccessSource(new SymbolSource('price'), 'amount'),
            declarations: ['price' => new MoneyType('GBP')],
            dialect: self::moneyAmountDialect(),
        ))->compile()->unwrap();

        $this->assertSame(500, $program(['price' => new Money(500, 'GBP')])->unwrap()->unwrap());
    }

    #[Test]
    public function an_opaque_field_extractor_that_errs_propagates_the_failure(): void
    {
        $dialect = Dialect::core()->with(new class extends Extension {
            public function fields(): array
            {
                return [
                    Field::on('money')->named('amount')->returns(new NumberType())
                        ->extractedWith(fn(Money $money) => \Superscript\Monads\Result\Err(new \RuntimeException('no amount'))),
                ];
            }
        });

        $program = (new Expression(
            new MemberAccessSource(new SymbolSource('price'), 'amount'),
            declarations: ['price' => new MoneyType('GBP')],
            dialect: $dialect,
        ))->compile()->unwrap();

        $this->assertStringContainsString('no amount', $program(['price' => new Money(500, 'GBP')])->unwrapErr()->getMessage());
    }

    #[Test]
    public function an_opaque_field_on_an_absent_optional_short_circuits_to_absence(): void
    {
        $program = (new Expression(
            new MemberAccessSource(new SymbolSource('price'), 'amount'),
            declarations: ['price' => new OptionType(new MoneyType('GBP'))],
            dialect: self::moneyAmountDialect(),
        ))->compile()->unwrap();

        $this->assertTrue($program(['price' => null])->unwrap()->isNone());
    }

    #[Test]
    public function an_opaque_field_extractor_returning_null_on_a_value_certifying_field_fails_evaluation(): void
    {
        // The certificate promised a Number; silently reading null as absence
        // would hand downstream a value the type never admitted.
        $dialect = Dialect::core()->with(new class extends Extension {
            public function fields(): array
            {
                return [
                    Field::on('money')->named('amount')->returns(new NumberType())
                        ->extractedWith(fn(Money $money) => null),
                ];
            }
        });

        $program = (new Expression(
            new MemberAccessSource(new SymbolSource('price'), 'amount'),
            declarations: ['price' => new MoneyType('GBP')],
            dialect: $dialect,
        ))->compile()->unwrap();

        $this->assertStringContainsString(
            'Field [money.amount] is declared Number but its extractor returned null',
            $program(['price' => new Money(500, 'GBP')])->unwrapErr()->getMessage(),
        );
    }

    #[Test]
    public function an_option_typed_opaque_field_reads_null_as_absence(): void
    {
        $dialect = Dialect::core()->with(new class extends Extension {
            public function fields(): array
            {
                return [
                    Field::on('money')->named('amount')->returns(new OptionType(new NumberType()))
                        ->extractedWith(fn(Money $money) => null),
                ];
            }
        });

        $program = (new Expression(
            new MemberAccessSource(new SymbolSource('price'), 'amount'),
            declarations: ['price' => new MoneyType('GBP')],
            dialect: $dialect,
        ))->compile()->unwrap();

        $this->assertTrue($program(['price' => new Money(500, 'GBP')])->unwrap()->isNone());
    }

    private static function moneyAmountDialect(): Dialect
    {
        return Dialect::core()->with(new class extends Extension {
            public function fields(): array
            {
                return [
                    Field::on('money')->named('amount')->returns(new NumberType())
                        ->extractedWith(fn(Money $money): int => $money->minor),
                ];
            }
        });
    }

    #[Test]
    public function unary_operators_short_circuit_absence(): void
    {
        $program = (new Expression(
            new UnaryExpression('not', new SymbolSource('flag')),
            declarations: ['flag' => new \Superscript\Axiom\Types\Optional(new OptionType(new BooleanType()))],
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
            dialect: self::dialect(),
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
        $counting = new CountingSource(10);
        $counter = new EvaluationCounter();

        $program = (new Expression(
            source: new InfixExpression(new SymbolSource('base'), '+', new SymbolSource('base')),
            definitions: new Definitions(['base' => $counting]),
            dialect: self::dialect($counter),
        ))->compile()->unwrap();

        $this->assertSame(20, $program()->unwrap()->unwrap());
        $this->assertSame(1, $counter->evaluations, 'one slot per invocation');

        $this->assertSame(20, $program()->unwrap()->unwrap());
        $this->assertSame(2, $counter->evaluations, 'a fresh invocation evaluates afresh');
    }

    #[Test]
    public function an_observer_observes_one_program_invocation(): void
    {
        $observer = new SpyObserver();

        $program = (new Expression(
            source: new InfixExpression(new SymbolSource('base'), '*', new Coerce(new NumberType(), new StaticSource('4'))),
            definitions: new Definitions(['base' => new StaticSource(3)]),
        ))->compile()->unwrap();

        $this->assertSame(12, $program(observer: $observer)->unwrap()->unwrap());

        $this->assertSame('*', $observer->annotations['label']);
        $this->assertSame(3, $observer->annotations['left']);
        $this->assertSame(4, $observer->annotations['right']);
        $this->assertSame(12, $observer->annotations['result']);
        $this->assertSame('string -> int', $observer->annotations['coercion']);
        $this->assertContains(['label', 'base'], $observer->timeline);
        $this->assertContains(['memo', 'miss'], $observer->timeline);
        $this->assertContains(['label', 'Number'], $observer->timeline);
        $this->assertContains(['label', 'static(int)'], $observer->timeline);
    }

    #[Test]
    public function an_observer_observes_memo_hits_and_match_arms(): void
    {
        $observer = new SpyObserver();

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
        ))->compile()->unwrap();

        $this->assertSame(10, $program(['flag' => true], $observer)->unwrap()->unwrap());

        $this->assertSame('match', $observer->annotations['label']);
        $this->assertSame(true, $observer->annotations['subject']);
        $this->assertSame(0, $observer->annotations['matched_arm']);
        $this->assertContains(['memo', 'miss'], $observer->timeline);
        $this->assertContains(['memo', 'hit'], $observer->timeline);
        $this->assertSame(
            2,
            count(array_filter($observer->timeline, fn(array $annotation): bool => $annotation === ['result', 10])),
            'both the infix body and the enclosing match annotate their result',
        );
    }

    #[Test]
    public function symbol_nodes_annotate_their_resolved_values(): void
    {
        $observer = new SpyObserver();

        // A declared symbol annotates the bound value it read...
        $declared = (new Expression(
            source: new SymbolSource('turnover'),
            declarations: ['turnover' => new NumberType()],
        ))->compile()->unwrap();

        $declared(['turnover' => 600000], $observer);

        $this->assertContains(['result', 600000], $observer->timeline);

        // ...and a defined symbol annotates the value its slot produced.
        $observer = new SpyObserver();
        $defined = (new Expression(
            source: new SymbolSource('base'),
            definitions: new Definitions(['base' => new StaticSource(7)]),
        ))->compile()->unwrap();

        $defined(observer: $observer);

        $this->assertContains(['label', 'base'], $observer->timeline);
        $this->assertContains(['result', 7], $observer->timeline);
    }

    #[Test]
    public function no_coercion_annotation_when_the_value_is_unchanged(): void
    {
        $observer = new SpyObserver();

        $program = (new Expression(
            source: new Coerce(new NumberType(), new StaticSource(42)),
        ))->compile()->unwrap();

        $this->assertSame(42, $program(observer: $observer)->unwrap()->unwrap());
        $this->assertArrayNotHasKey('coercion', $observer->annotations);
        $this->assertSame('Number', $observer->annotations['label']);
    }

    #[Test]
    public function the_ascription_annotates_its_claim(): void
    {
        $observer = new SpyObserver();

        $program = (new Expression(
            source: new Ascription(new NumberType(), self::lyingSource(new \Superscript\Axiom\Types\UnknownType(), 42)),
            dialect: self::dialect(),
        ))->compile()->unwrap();

        $this->assertSame(42, $program(observer: $observer)->unwrap()->unwrap());
        $this->assertSame('is Number', $observer->annotations['label']);
    }

    #[Test]
    public function member_access_annotates_the_property_and_result(): void
    {
        $observer = new SpyObserver();
        $customer = self::lyingSource(new RecordType(['age' => new NumberType()]), ['age' => 30]);

        $program = (new Expression(
            source: new MemberAccessSource($customer, 'age'),
            dialect: self::dialect(),
        ))->compile()->unwrap();

        $this->assertSame(30, $program(observer: $observer)->unwrap()->unwrap());
        $this->assertSame('.age', $observer->annotations['label']);
        $this->assertSame(30, $observer->annotations['result']);
    }

    #[Test]
    public function unary_nodes_annotate_operator_and_result(): void
    {
        $observer = new SpyObserver();

        $program = (new Expression(
            source: new UnaryExpression('-', new StaticSource(7)),
        ))->compile()->unwrap();

        $this->assertSame(-7, $program(observer: $observer)->unwrap()->unwrap());
        $this->assertSame('-', $observer->annotations['label']);
        $this->assertSame(-7, $observer->annotations['result']);
    }
}
