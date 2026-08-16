<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Boundary;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Exceptions\BoundaryViolation;
use Superscript\Axiom\Exceptions\InadmissibleBinding;
use Superscript\Axiom\Exceptions\MissingRequiredInput;
use Superscript\Axiom\Exceptions\RejectedBinding;
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
use Superscript\Axiom\Types\Optional;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\UnionType;

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
#[CoversClass(Optional::class)]
#[CoversClass(Dialect::class)]
#[CoversClass(Extension::class)]
#[CoversClass(Boundary::class)]
#[CoversClass(BoundaryViolation::class)]
#[CoversClass(InadmissibleBinding::class)]
#[CoversClass(MissingRequiredInput::class)]
#[CoversClass(RejectedBinding::class)]
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
#[UsesClass(\Superscript\Axiom\ReferencePath::class)]
#[UsesClass(\Superscript\Axiom\Types\RecordProperty::class)]
final class TypedExpressionTest extends TestCase
{
    private function gate(): Expression
    {
        // quote.turnover > 500000
        return new Expression(
            source: new InfixExpression(
                left: new MemberAccessSource(new SymbolSource('quote'), 'turnover'),
                operator: '>',
                right: new StaticSource(500000),
            ),
            declarations: ['quote' => new RecordType(['turnover' => new NumberType()])],
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

        $this->assertTrue($program(['quote' => ['turnover' => '600000']])->unwrap()->unwrap());
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

        // Each rejection carries its input beside its message; `$violations`
        // is those messages projected out, in the same order.
        $this->assertSame(['a', 'b'], array_column($violation->rejections, 'input'));
        $this->assertSame($violation->violations, array_column($violation->rejections, 'message'));

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
    public function an_option_declared_input_requires_its_key_but_may_be_null(): void
    {
        $program = (new Expression(
            source: new SymbolSource('note'),
            declarations: ['note' => new OptionType(new StringType())],
        ))->compile()->unwrap();

        $this->assertInstanceOf(MissingRequiredInput::class, $program([])->unwrapErr());
        $this->assertTrue($program(['note' => null])->unwrap()->isNone());
        $this->assertSame('hi', $program(['note' => 'hi'])->unwrap()->unwrap());
    }

    /**
     * The whole classification of an absent input, enumerated. Two facts
     * decide every row, and they are asked about different absences. A
     * supplied value that reads as absent is judged by its value type. An
     * omitted key is judged independently: properties are required unless
     * wrapped in Optional. This gives the four combinations directly.
     *
     * @param class-string<BoundaryViolation>|null $refusal
     * @param array<string, mixed> $bindings
     */
    #[Test]
    #[DataProvider('absenceReadings')]
    public function absence_is_judged_by_property_presence_and_value_type(Type|Optional $declared, array $bindings, ?string $refusal, string $message): void
    {
        $program = (new Expression(
            source: new SymbolSource('x'),
            declarations: ['x' => $declared],
        ))->compile()->unwrap();

        $result = $program($bindings);

        if ($refusal === null) {
            $this->assertTrue($result->unwrap()->isNone(), 'the declaration admits absence, so the value is None');

            return;
        }

        $violation = $result->unwrapErr();
        $this->assertInstanceOf($refusal, $violation);
        $this->assertStringContainsString($message, $violation->getMessage());
    }

    /**
     * @return iterable<string, array{Type|Optional, array<string, mixed>, class-string<BoundaryViolation>|null, string}>
     */
    public static function absenceReadings(): iterable
    {
        // Presence required: '' is a value that does not inhabit String.
        yield 'String, bound ""' => [new StringType(), ['x' => ''], InadmissibleBinding::class, 'binding [x] reads as missing, but String is required'];
        yield 'String, unbound' => [new StringType(), [], MissingRequiredInput::class, 'required input [x] is missing'];
        yield 'String, bound a list' => [new StringType(), ['x' => ['a']], InadmissibleBinding::class, 'binding [x]:'];

        // Absence admitted, by an Option declaration.
        yield 'String?, bound ""' => [new OptionType(new StringType()), ['x' => ''], null, ''];
        yield 'String?, bound null' => [new OptionType(new StringType()), ['x' => null], null, ''];
        yield 'String?, unbound' => [new OptionType(new StringType()), [], MissingRequiredInput::class, 'required input [x] is missing'];
        yield 'String?, bound a list' => [new OptionType(new StringType()), ['x' => ['a']], InadmissibleBinding::class, 'binding [x]:'];

        // Absence admitted, by a union with an absence-admitting member —
        // the same verdict from either member order, because the shape the
        // union projects is the same one.
        yield 'String | Number?, bound ""' => [new UnionType(new StringType(), new OptionType(new NumberType())), ['x' => ''], null, ''];
        yield 'Number? | String, bound ""' => [new UnionType(new OptionType(new NumberType()), new StringType()), ['x' => ''], null, ''];
        yield 'String | Number?, unbound' => [new UnionType(new StringType(), new OptionType(new NumberType())), [], MissingRequiredInput::class, 'required input [x] is missing'];
        yield 'Number? | String, unbound' => [new UnionType(new OptionType(new NumberType()), new StringType()), [], MissingRequiredInput::class, 'required input [x] is missing'];
        yield 'String | Number?, bound a list' => [new UnionType(new StringType(), new OptionType(new NumberType())), ['x' => ['a']], InadmissibleBinding::class, 'binding [x]:'];
        yield 'Number? | String, bound a list' => [new UnionType(new OptionType(new NumberType()), new StringType()), ['x' => ['a']], InadmissibleBinding::class, 'binding [x]:'];

        // Optional controls only key omission.
        yield 'Optional(String), bound ""' => [new Optional(new StringType()), ['x' => ''], InadmissibleBinding::class, 'binding [x] reads as missing, but String is required'];
        yield 'Optional(String), unbound' => [new Optional(new StringType()), [], null, ''];
        yield 'Optional(String), bound null' => [new Optional(new StringType()), ['x' => null], InadmissibleBinding::class, 'binding [x]:'];
        yield 'Optional(String?), bound null' => [new Optional(new OptionType(new StringType())), ['x' => null], null, ''];
        yield 'Optional(String?), unbound' => [new Optional(new OptionType(new StringType())), [], null, ''];
    }

    #[Test]
    public function a_required_option_tells_the_answer_none_from_no_answer_yet(): void
    {
        // A select whose "no value" option is a real answer. The type must
        // admit absence for the chosen answer to be expressible at all, and
        // the binding must be demanded for the unanswered question not to
        // read as that answer.
        $program = (new Expression(
            source: new SymbolSource('excess'),
            declarations: ['excess' => new OptionType(new NumberType())],
        ))->compile()->unwrap();

        $this->assertInstanceOf(MissingRequiredInput::class, $program([])->unwrapErr());
        $this->assertTrue($program(['excess' => null])->unwrap()->isNone());
        $this->assertSame(250, $program(['excess' => '250'])->unwrap()->unwrap());

        $this->assertEquals(new RecordType(['excess' => new OptionType(new NumberType())]), $program->analysis->declarations);
        $this->assertEquals(new OptionType(new NumberType()), $program->returns);
    }

    #[Test]
    public function a_required_declaration_the_program_never_reads_is_still_ignored(): void
    {
        // Demand intersects the reads, like every other boundary fact: an
        // input no symbol node reads has nothing to deliver a value to, so
        // demanding it would manufacture a refusal nothing could act on.
        $program = (new Expression(
            source: new SymbolSource('name'),
            declarations: [
                'name' => new StringType(),
                'excess' => new OptionType(new NumberType()),
            ],
        ))->compile()->unwrap();

        $this->assertSame(['name'], $program->references);
        $this->assertSame('Ada', $program(['name' => 'Ada'])->unwrap()->unwrap());
    }

    #[Test]
    public function union_member_order_does_not_change_a_boundary_verdict(): void
    {
        // `''` reaches the two orders differently — String answers Ok(None)
        // and Option<Number> answers Ok(Some(null)) — and the boundary must
        // not be able to tell, because both readings say the same thing
        // about a declaration whose shape is (String | Number)?.
        $verdict = static function (Type ...$members): string {
            $program = (new Expression(
                source: new SymbolSource('x'),
                declarations: ['x' => new UnionType(...$members)],
            ))->compile()->unwrap();

            $result = $program(['x' => '']);

            return $result->isErr()
                ? $result->unwrapErr()::class
                : ($result->unwrap()->isNone() ? 'None' : 'Some');
        };

        $string = new StringType();
        $number = new OptionType(new NumberType());

        $this->assertSame('None', $verdict($string, $number));
        $this->assertSame($verdict($string, $number), $verdict($number, $string));

        // The same independence where presence *is* required: neither order
        // of a bare union admits '', and both refuse with the same class.
        $this->assertSame(InadmissibleBinding::class, $verdict($string, new NumberType()));
        $this->assertSame($verdict($string, new NumberType()), $verdict(new NumberType(), $string));
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

        // The unread declaration is absent too, and nothing is said about it.
        $this->assertEquals(
            [new RejectedBinding('name', 'required input [name] is missing')],
            $violation->rejections,
        );
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
        $this->assertSame(['employees'], array_column($violation->rejections, 'input'));
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
        $this->assertSame(['staff'], array_column($violation->rejections, 'input'));
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
    public function a_nested_property_and_a_namesake_root_definition_are_distinct(): void
    {
        $expression = new Expression(
            source: new MemberAccessSource(new SymbolSource('customer'), 'turnover'),
            definitions: new Definitions(['turnover' => new StaticSource(1)]),
            declarations: ['customer' => new RecordType(['turnover' => new NumberType()])],
        );

        $program = $expression->compile()->unwrap();

        $this->assertSame(2, $program(['customer' => ['turnover' => 2]])->unwrap()->unwrap());
        $this->assertInstanceOf(NumberType::class, $program->returns);
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

        // The projected record coerces at the boundary ('2' → 2, while the
        // unread note is stripped) and member access — the one structural
        // path — reads the coerced record's property.
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
            declarations: ['rateOverride' => new Optional(new OptionType(new NumberType()))],
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
            declarations: ['maybe' => new Optional(new OptionType(new NumberType()))],
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
    public function deeply_nested_inputs_are_structural_paths(): void
    {
        $program = (new Expression(
            source: new MemberAccessSource(
                new MemberAccessSource(new SymbolSource('root'), 'deep'),
                'key',
            ),
            declarations: ['root' => new RecordType([
                'deep' => new RecordType(['key' => new NumberType()]),
            ])],
        ))->compile()->unwrap();

        $this->assertSame(5, $program(['root' => ['deep' => ['key' => '5']]])->unwrap()->unwrap());
    }
}
