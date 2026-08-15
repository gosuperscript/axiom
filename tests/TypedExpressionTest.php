<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Boundary;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Exceptions\BoundaryViolation;
use Superscript\Axiom\Exceptions\InadmissibleBinding;
use Superscript\Axiom\Exceptions\MissingRequiredInput;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnaryOperatorRule;
use Superscript\Axiom\Program;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;

/**
 * The typed surface end to end: declarations as the public signature, the
 * boundary as the one runtime type check, extensions as full dialect
 * citizens — all through compile() and the Program it returns.
 */
#[CoversClass(Expression::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\ConstantNode::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\InfixExpressionCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\MatchExpressionCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\MemberAccessSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\FieldAccess::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\StaticSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\SymbolSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\UnaryExpressionCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilation::class)]
#[CoversClass(Program::class)]
#[CoversClass(Dialect::class)]
#[CoversClass(Extension::class)]
#[CoversClass(Boundary::class)]
#[CoversClass(BoundaryViolation::class)]
#[CoversClass(InadmissibleBinding::class)]
#[CoversClass(MissingRequiredInput::class)]
#[UsesClass(\Superscript\Axiom\Bindings::class)]
#[UsesClass(\Superscript\Axiom\CompiledNode::class)]
#[UsesClass(\Superscript\Axiom\CompiledSource::class)]
#[UsesClass(\Superscript\Axiom\BoundOperation::class)]
#[UsesClass(\Superscript\Axiom\SourceEvaluation::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\CompilationAborted::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\EvaluationAborted::class)]
#[UsesClass(\Superscript\Axiom\Runtime::class)]
#[UsesClass(\Superscript\Axiom\DefinitionGraph::class)]
#[UsesClass(Definitions::class)]
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
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\Coalesce::class)]
#[UsesClass(\Superscript\Axiom\Operators\Equality::class)]
#[UsesClass(\Superscript\Axiom\Operators\Has::class)]
#[UsesClass(\Superscript\Axiom\Operators\In::class)]
#[UsesClass(\Superscript\Axiom\Operators\Intersects::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnsupportedOperation::class)]
#[UsesClass(Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeInference::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeEnvironment::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Fields\OpaqueFieldRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeReifier::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(RecordType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralType::class)]
#[UsesClass(\Superscript\Axiom\Types\NeverType::class)]
#[UsesClass(\Superscript\Axiom\Types\UnionType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\TransformValueException::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom\\Analysis')]
#[UsesClass(\Superscript\Axiom\Operators\Connective::class)]
#[UsesClass(\Superscript\Axiom\Types\PresentType::class)]
#[UsesClass(\Superscript\Axiom\Types\InfixExpressionTyping::class)]
final class TypedExpressionTest extends TestCase
{
    private function gate(): Expression
    {
        // quote.turnover > 500000
        return new Expression(
            source: new InfixExpression(
                left: new SymbolSource('turnover', 'quote'),
                operator: '>',
                right: new StaticSource(500000),
            ),
            declarations: ['quote.turnover' => new NumberType()],
        );
    }

    #[Test]
    public function the_expression_types_itself(): void
    {
        $this->assertInstanceOf(BooleanType::class, $this->gate()->infer()->unwrap());
        $this->assertTrue($this->gate()->check(new BooleanType())->isOk());

        $notNumber = $this->gate()->check(new NumberType());
        $this->assertStringContainsString('Boolean is not assignable to Number', $notNumber->unwrapErr()->describe());
    }

    #[Test]
    public function the_boundary_coerces_declared_inputs_before_evaluation(): void
    {
        // A stringly CSV cell — the honest arithmetic rows would never see
        // it; the boundary converts it before evaluation begins.
        $program = $this->gate()->compile()->unwrap();

        $this->assertTrue($program(['quote.turnover' => '600000'])->unwrap()->unwrap());
    }

    #[Test]
    public function boundary_violations_are_aggregated_and_named(): void
    {
        // Both inputs are read: the boundary demands what the program reads,
        // so a second violation needs a second read.
        $program = (new Expression(
            source: new InfixExpression(new SymbolSource('a'), '+', new SymbolSource('b')),
            declarations: ['a' => new NumberType(), 'b' => new NumberType()],
        ))->compile()->unwrap();

        $result = $program(['a' => 'garbage']);

        $violation = $result->unwrapErr();
        $this->assertInstanceOf(BoundaryViolation::class, $violation);
        $this->assertCount(2, $violation->violations);
        $this->assertStringContainsString('binding [a]:', $violation->violations[0]);
        $this->assertStringContainsString('required input [b] is missing', $violation->violations[1]);
        $this->assertSame(['a', 'b'], $violation->inputs);

        // A supplied value that is wrong is a fault whatever else is absent.
        $this->assertInstanceOf(InadmissibleBinding::class, $violation);
    }

    #[Test]
    public function assert_mode_refuses_what_coerce_would_convert(): void
    {
        $strict = (new Expression(
            source: new SymbolSource('turnover'),
            declarations: ['turnover' => new NumberType()],
            boundary: Boundary::Assert,
        ))->compile()->unwrap();

        $this->assertStringContainsString('binding [turnover]:', $strict(['turnover' => '600000'])->unwrapErr()->getMessage());
        $this->assertSame(600000, $strict(['turnover' => 600000])->unwrap()->unwrap());
    }

    #[Test]
    public function an_option_declared_input_may_be_missing_or_null(): void
    {
        $program = (new Expression(
            source: new SymbolSource('note'),
            declarations: ['note' => new OptionType(new StringType())],
        ))->compile()->unwrap();

        $this->assertTrue($program([])->unwrap()->isNone());
        $this->assertTrue($program(['note' => null])->unwrap()->isNone());
        $this->assertSame('hi', $program(['note' => 'hi'])->unwrap()->unwrap());
    }

    #[Test]
    public function a_required_input_that_reads_as_missing_is_a_violation(): void
    {
        $program = (new Expression(
            source: new SymbolSource('name'),
            declarations: ['name' => new StringType()],
        ))->compile()->unwrap();

        // '' coerces to absence — required-but-missing at the boundary.
        $result = $program(['name' => '']);

        $violation = $result->unwrapErr();
        $this->assertStringContainsString('reads as missing, but String is required', $violation->getMessage());

        // A value was supplied and does not inhabit String: a fault, not a
        // call still waiting for its inputs.
        $this->assertInstanceOf(InadmissibleBinding::class, $violation);
    }

    #[Test]
    public function the_boundary_demands_only_the_inputs_the_program_reads(): void
    {
        // One scope types a whole page of questions; this program speaks one
        // word of it. Binding the word it reads is binding everything it can
        // observe, whatever the rest of the page has been answered.
        $scope = ['name' => new StringType(), 'employees' => new NumberType()];

        $program = (new Expression(
            source: new SymbolSource('name'),
            declarations: $scope,
        ))->compile()->unwrap();

        $this->assertSame(['name'], $program->references);
        $this->assertSame('Ada', $program(['name' => 'Ada'])->unwrap()->unwrap());
    }

    #[Test]
    public function an_unread_declaration_is_ignored_even_when_it_is_bound(): void
    {
        // Nothing but a symbol node reads an admitted binding, and a symbol
        // node exists only where the compiler recorded a read — so a value
        // under an unread name is unobservable, and passing it through the
        // declared type would only manufacture a refusal nothing could act on.
        $program = (new Expression(
            source: new SymbolSource('name'),
            declarations: ['name' => new StringType(), 'employees' => new NumberType()],
        ))->compile()->unwrap();

        $this->assertSame('Ada', $program(['name' => 'Ada', 'employees' => 'not a number'])->unwrap()->unwrap());
    }

    #[Test]
    public function a_read_input_with_no_binding_is_a_missing_required_input(): void
    {
        $program = (new Expression(
            source: new SymbolSource('name'),
            declarations: ['name' => new StringType(), 'employees' => new NumberType()],
        ))->compile()->unwrap();

        $violation = $program([])->unwrapErr();

        $this->assertInstanceOf(MissingRequiredInput::class, $violation);
        $this->assertSame(['name'], $violation->inputs);

        // The unread declaration is absent too, and nothing is said about it.
        $this->assertSame(['required input [name] is missing'], $violation->violations);
    }

    #[Test]
    public function a_bound_read_input_at_the_wrong_type_is_an_inadmissible_binding(): void
    {
        $program = (new Expression(
            source: new SymbolSource('employees'),
            declarations: ['employees' => new NumberType()],
        ))->compile()->unwrap();

        $violation = $program(['employees' => 'a dozen'])->unwrapErr();

        // The two kinds are distinguishable by class, which is what a host
        // telling "not answerable yet" from "something upstream is wrong"
        // reaches for.
        $this->assertInstanceOf(InadmissibleBinding::class, $violation);
        $this->assertNotInstanceOf(MissingRequiredInput::class, $violation);
        $this->assertInstanceOf(BoundaryViolation::class, $violation);
        $this->assertSame(['employees'], $violation->inputs);
    }

    #[Test]
    public function a_definition_read_demands_the_inputs_under_it(): void
    {
        // The program names `headcount`, a definition; the input it demands
        // is the one that definition reads. `employees` is declared, read by
        // nothing, and demanded by nothing.
        $expression = new Expression(
            source: new SymbolSource('headcount'),
            definitions: new Definitions([
                'headcount' => new InfixExpression(new SymbolSource('staff'), '+', new StaticSource(1)),
            ]),
            declarations: ['staff' => new NumberType(), 'employees' => new NumberType()],
        );

        $program = $expression->compile()->unwrap();

        $this->assertSame(['staff'], $program->references);
        $this->assertSame(4, $program(['staff' => 3])->unwrap()->unwrap());

        $violation = $program([])->unwrapErr();
        $this->assertInstanceOf(MissingRequiredInput::class, $violation);
        $this->assertSame(['staff'], $violation->inputs);
    }

    #[Test]
    public function an_unread_option_declaration_is_ignored_like_any_other(): void
    {
        // A read Option with no binding is None; an unread one is not even
        // that, because nothing can ask.
        $program = (new Expression(
            source: new SymbolSource('name'),
            declarations: ['name' => new StringType(), 'note' => new OptionType(new StringType())],
        ))->compile()->unwrap();

        $this->assertSame(['name'], $program->references);
        $this->assertSame('Ada', $program(['name' => 'Ada'])->unwrap()->unwrap());
    }

    #[Test]
    public function assert_mode_demands_the_reads_and_admits_them_strictly(): void
    {
        // The demand set is what changed; per-input admission is untouched,
        // so a strict host still refuses a stringly number it reads and still
        // ignores one it does not.
        $strict = (new Expression(
            source: new SymbolSource('turnover'),
            declarations: ['turnover' => new NumberType(), 'staff' => new NumberType()],
            boundary: Boundary::Assert,
        ))->compile()->unwrap();

        $this->assertSame(600000, $strict(['turnover' => 600000, 'staff' => '3'])->unwrap()->unwrap());
        $this->assertInstanceOf(InadmissibleBinding::class, $strict(['turnover' => '600000'])->unwrapErr());
        $this->assertInstanceOf(MissingRequiredInput::class, $strict(['staff' => 3])->unwrapErr());
    }

    #[Test]
    public function a_program_certified_by_a_diagnosis_has_the_same_boundary(): void
    {
        $expression = new Expression(
            source: new SymbolSource('name'),
            declarations: ['name' => new StringType(), 'employees' => new NumberType()],
        );

        $diagnosed = $expression->diagnose()->program()->unwrap();

        $this->assertSame($expression->compile()->unwrap()->references, $diagnosed->references);
        $this->assertSame('Ada', $diagnosed(['name' => 'Ada'])->unwrap()->unwrap());
        $this->assertInstanceOf(MissingRequiredInput::class, $diagnosed([])->unwrapErr());
    }

    #[Test]
    public function a_record_declaration_and_a_namesake_definition_are_distinct_programs(): void
    {
        // No descent: Symbol('turnover', ns: 'customer') is the exact key
        // customer.turnover — a definition — while the declared customer
        // record is reachable only by member access. The caller's record
        // content can never answer the symbol, so it can never shadow the
        // definition (the second-opinion finding, closed structurally).
        $expression = new Expression(
            source: new SymbolSource('turnover', 'customer'),
            definitions: new Definitions(['customer.turnover' => new StaticSource(1)]),
            declarations: ['customer' => new RecordType(['name' => new StringType()])],
        );

        $program = $expression->compile()->unwrap();

        $this->assertSame(1, $program(['customer' => ['name' => 'Ada']])->unwrap()->unwrap());
        $this->assertInstanceOf(\Superscript\Axiom\Types\LiteralType::class, $program->returns);
    }

    #[Test]
    public function record_bindings_are_coerced_whole_and_fields_are_member_access(): void
    {
        $record = new RecordType([
            'turnover' => new NumberType(),
            'note' => new OptionType(new StringType()),
        ]);

        $program = (new Expression(
            source: new InfixExpression(
                left: new MemberAccessSource(new SymbolSource('customer'), 'turnover'),
                operator: '*',
                right: new StaticSource(2),
            ),
            declarations: ['customer' => $record],
        ))->compile()->unwrap();

        // The whole record coerces at the boundary ('2' → 2, missing
        // optional note canonicalizes) and member access — the one
        // structural path — reads the coerced record's field.
        $this->assertSame(4, $program(['customer' => ['turnover' => '2']])->unwrap()->unwrap());

        // Statically, the member access types as the record's field.
        $this->assertInstanceOf(NumberType::class, $program->returns);

        // Field errors are named under the input.
        $bad = $program(['customer' => ['turnover' => 'lots']]);
        $this->assertStringContainsString('binding [customer]:', $bad->unwrapErr()->getMessage());
    }

    #[Test]
    public function an_override_is_modeled_as_an_option_typed_parameter(): void
    {
        // The blessed replacement for binding-over-definition shadowing:
        // the override is an explicit, typed, optional parameter and the
        // derived value consults it — both paths certified, nothing
        // implicit. rate = match rateOverride { null => 1.2, _ => override }
        $program = (new Expression(
            source: new SymbolSource('rate'),
            definitions: new Definitions([
                'rate' => new MatchExpression(
                    subject: new SymbolSource('rateOverride'),
                    arms: [
                        new MatchArm(new LiteralPattern(null), new StaticSource(1.2)),
                        new MatchArm(new WildcardPattern(), new SymbolSource('rateOverride')),
                    ],
                ),
            ]),
            declarations: ['rateOverride' => new OptionType(new NumberType())],
        ))->compile()->unwrap();

        $this->assertSame(1.2, $program()->unwrap()->unwrap());
        $this->assertSame(2.5, $program(['rateOverride' => 2.5])->unwrap()->unwrap());
    }

    #[Test]
    public function cyclic_definitions_are_a_compile_diagnostic(): void
    {
        // Termination is a graph property of the Definitions alone — no
        // declaration can repair it. And because invocation lives only on
        // the compiled Program, a cyclic program is not merely diagnosed:
        // it is unrunnable.
        $expression = new Expression(
            source: new SymbolSource('a'),
            definitions: new Definitions([
                'a' => new SymbolSource('b'),
                'b' => new SymbolSource('a'),
            ]),
        );

        $result = $expression->compile();

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('not well-founded', $result->unwrapErr()->describe());
        $this->assertStringContainsString('Cyclic symbol definition: a → b → a.', $result->unwrapErr()->describe());
    }

    #[Test]
    public function an_absence_policy_extension_is_a_type_function_that_refuses_present_pairs(): void
    {
        // Absence-as-zero, spelled honestly under ambiguity refusal: the
        // rule resolves ONLY operand types where a side can be absent, so
        // core's (Number, Number) row keeps sole ownership of present
        // pairs and no operand types ever have two owners.
        $absenceAsZero = new class implements BinaryOperatorRule {
            public function operator(): string
            {
                return '+';
            }

            public function resolve(Type $left, Type $right): \Superscript\Axiom\Operators\OperatorResolution
            {
                if (!($left->shape() instanceof OptionShape) && !($right->shape() instanceof OptionShape)) {
                    return new \Superscript\Axiom\Operators\UnsupportedOperation('Present pairs belong to the core row.');
                }

                return new ResolvedOperation(
                    new NumberType(),
                    fn(?float $l, ?float $r) => ($l ?? 0) + ($r ?? 0),
                );
            }
        };

        $extension = new class ($absenceAsZero) extends Extension {
            public function __construct(private readonly BinaryOperatorRule $rule) {}

            public function operators(): array
            {
                return [$this->rule];
            }
        };

        $program = (new Expression(
            source: new InfixExpression(
                left: new SymbolSource('maybe'),
                operator: '+',
                right: new StaticSource(2),
            ),
            dialect: Dialect::core()->with($extension),
            declarations: ['maybe' => new OptionType(new NumberType())],
        ))->compile()->unwrap();

        // The compiled program embeds the extension's resolution: absence
        // evaluates as zero AND certifies, through one composed dialect.
        $this->assertSame(2.0, $program([])->unwrap()->unwrap());
        $this->assertSame(5.0, $program(['maybe' => 3])->unwrap()->unwrap());
        $this->assertInstanceOf(NumberType::class, $program->returns);
    }

    #[Test]
    public function extension_unary_rules_reach_the_compiler_through_the_dialect(): void
    {
        $absValue = new class implements UnaryOperatorRule {
            public function operator(): string
            {
                return 'abs';
            }

            public function resolve(Type $operand): \Superscript\Axiom\Operators\OperatorResolution
            {
                $admitted = \Superscript\Axiom\Types\TypeRelations::admits($operand, new NumberType());

                return $admitted->isOk()
                    ? new ResolvedOperation(new NumberType(), fn(int|float $n) => abs($n))
                    : new \Superscript\Axiom\Operators\UnsupportedOperation('Only numbers.', [$admitted->unwrapErr()]);
            }
        };

        $extension = new class ($absValue) extends Extension {
            public function __construct(private readonly UnaryOperatorRule $rule) {}

            public function unaryOperators(): array
            {
                return [$this->rule];
            }
        };

        $program = (new Expression(
            source: new UnaryExpression('abs', new StaticSource(-7)),
            dialect: Dialect::core()->with($extension),
        ))->compile()->unwrap();

        $this->assertSame(7, $program()->unwrap()->unwrap());
        $this->assertInstanceOf(NumberType::class, $program->returns);
    }

    #[Test]
    public function builder_declared_rules_are_full_dialect_citizens(): void
    {
        $extension = new class extends Extension {
            public function operators(): array
            {
                return [
                    Operator::infix('++')
                        ->takes(new StringType(), new StringType())
                        ->returns(new StringType())
                        ->evaluatesWith(fn(string $a, string $b) => $a . $b),
                ];
            }
        };

        $program = (new Expression(
            source: new InfixExpression(
                left: new SymbolSource('greeting'),
                operator: '++',
                right: new StaticSource('!'),
            ),
            dialect: Dialect::core()->with($extension),
            declarations: ['greeting' => new StringType()],
        ))->compile()->unwrap();

        // One declared row, one verdict: the compiler certifies it and the
        // program runs its closure.
        $this->assertSame('hi!', $program(['greeting' => 'hi'])->unwrap()->unwrap());
        $this->assertInstanceOf(StringType::class, $program->returns);
    }

    #[Test]
    public function boundary_violations_carry_the_banner(): void
    {
        $program = (new Expression(
            source: new SymbolSource('a'),
            declarations: ['a' => new NumberType()],
        ))->compile()->unwrap();

        $message = $program([])->unwrapErr()->getMessage();

        $this->assertStringStartsWith("Bindings rejected at the boundary:\n- ", $message);
    }

    #[Test]
    public function violations_after_a_skipped_optional_input_are_still_reported(): void
    {
        $program = (new Expression(
            source: new MatchExpression(new SymbolSource('a'), [
                new MatchArm(new WildcardPattern(), new SymbolSource('b')),
            ]),
            declarations: [
                'a' => new OptionType(new StringType()),   // read, missing, fine — must not end the sweep
                'b' => new NumberType(),
            ],
        ))->compile()->unwrap();

        $result = $program(['b' => 'garbage']);

        $this->assertStringContainsString('binding [b]:', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function violations_after_a_reads_as_missing_input_are_still_reported(): void
    {
        $program = (new Expression(
            source: new MatchExpression(new SymbolSource('a'), [
                new MatchArm(new WildcardPattern(), new SymbolSource('b')),
            ]),
            declarations: [
                'a' => new StringType(),   // '' reads as missing → violation, sweep continues
                'b' => new NumberType(),   // absent → violation
            ],
        ))->compile()->unwrap();

        $violation = $program(['a' => ''])->unwrapErr();

        $this->assertInstanceOf(BoundaryViolation::class, $violation);
        $this->assertCount(2, $violation->violations);
    }

    #[Test]
    public function multi_dot_declaration_keys_split_on_the_first_dot(): void
    {
        // Definitions flatten one namespace level, so a declared key may
        // carry dots in its name part: ns + 'deep.key'.
        $program = (new Expression(
            source: new SymbolSource('deep.key', 'ns'),
            declarations: ['ns.deep.key' => new NumberType()],
        ))->compile()->unwrap();

        $this->assertSame(5, $program(['ns.deep.key' => '5'])->unwrap()->unwrap());
    }
}
