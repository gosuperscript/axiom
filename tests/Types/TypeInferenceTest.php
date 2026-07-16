<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Tests\Fixtures\HostValueSource;
use Superscript\Axiom\Tests\Fixtures\SourceCompilerExtension;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\LiteralTypeRegistry;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

#[CoversClass(TypeInference::class)]
#[CoversClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[UsesClass(\Superscript\Axiom\UnboundSymbols::class)]
#[UsesClass(TypeEnvironment::class)]
#[UsesClass(CompiledNode::class)]
#[UsesClass(LiteralTypeRegistry::class)]
#[UsesClass(Dialect::class)]
#[UsesClass(\Superscript\Axiom\Extension::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilation::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(Coerce::class)]
#[UsesClass(\Superscript\Axiom\Sources\Ascription::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(UnaryExpression::class)]
#[UsesClass(MatchExpression::class)]
#[UsesClass(MatchArm::class)]
#[UsesClass(LiteralPattern::class)]
#[UsesClass(WildcardPattern::class)]
#[UsesClass(ExpressionPattern::class)]
#[UsesClass(MemberAccessSource::class)]
#[UsesClass(\Superscript\Axiom\Definitions::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(DictType::class)]
#[UsesClass(ListType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(RecordType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(TypeDescriber::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeReifier::class)]
#[UsesClass(\Superscript\Axiom\Types\OpaqueType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OpaqueShape::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\Equality::class)]
#[UsesClass(\Superscript\Axiom\Operators\Has::class)]
#[UsesClass(\Superscript\Axiom\Operators\In::class)]
#[UsesClass(\Superscript\Axiom\Operators\Intersects::class)]
#[UsesClass(\Superscript\Axiom\Operators\SetOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnsupportedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\DeadOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\DictShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ListShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ShapeDomain::class)]
final class TypeInferenceTest extends TestCase
{
    private static function inference(?LiteralTypeRegistry $literals = null, ?Dialect $dialect = null): TypeInference
    {
        $dialect ??= Dialect::core();

        return new TypeInference(
            $dialect->operators(),
            $dialect->unaryOperators(),
            $literals ?? $dialect->literals(),
            $dialect->sourceCompilers(),
        );
    }

    private static function env(array $declarations = [], ?\Superscript\Axiom\Definitions $definitions = null): TypeEnvironment
    {
        return new TypeEnvironment($definitions ?? new \Superscript\Axiom\Definitions(), $declarations);
    }

    private static function describe(Result $result): string
    {
        return $result->isOk() ? TypeDescriber::describe($result->unwrap()) : $result->unwrapErr()->describe();
    }

    #[Test]
    public function scalars_infer_literal_first(): void
    {
        $inference = self::inference();
        $env = self::env();

        $this->assertSame("'shop'", self::describe($inference->infer(new StaticSource('shop'), $env)));
        $this->assertSame('5', self::describe($inference->infer(new StaticSource(5), $env)));
        $this->assertSame('true', self::describe($inference->infer(new StaticSource(true), $env)));
        $this->assertSame('2.5', self::describe($inference->infer(new StaticSource(2.5), $env)));
    }

    #[Test]
    public function the_lower_level_compiler_includes_core_source_compilers_by_default(): void
    {
        $dialect = Dialect::core();
        $inference = new TypeInference($dialect->operators(), $dialect->unaryOperators());

        $this->assertSame("'core'", self::describe($inference->infer(new StaticSource('core'), self::env())));
    }

    #[Test]
    public function the_null_literal_infers_as_the_null_type(): void
    {
        $this->assertSame('Never?', self::describe(self::inference()->infer(new StaticSource(null), self::env())));
    }

    #[Test]
    public function list_literals_unify_by_union_with_exact_bounds(): void
    {
        $inference = self::inference();

        $this->assertSame(
            "List<'shop' | 'office', 2>",
            self::describe($inference->infer(new StaticSource(['shop', 'office']), self::env())),
        );
        $this->assertSame(
            'List<Never, 0>',
            self::describe($inference->infer(new StaticSource([]), self::env())),
        );
        $this->assertSame(
            "List<'a', 2>",
            self::describe($inference->infer(new StaticSource(['a', 'a']), self::env())),
        );
    }

    #[Test]
    public function an_untypeable_list_element_names_its_index(): void
    {
        $result = self::inference()->infer(new StaticSource(['a', new \stdClass()]), self::env());

        $this->assertStringContainsString('List element 1 cannot be typed.', $result->unwrapErr()->describe());
        $this->assertStringContainsString('No literal type is registered for [stdClass]', $result->unwrapErr()->describe());
    }

    #[Test]
    public function associative_array_literals_infer_as_closed_records(): void
    {
        $result = self::inference()->infer(new StaticSource(['kind' => 'shop', 'floors' => 2]), self::env());

        $this->assertSame("{kind: 'shop', floors: 2}", self::describe($result));
    }

    #[Test]
    public function an_untypeable_record_field_names_its_key(): void
    {
        $result = self::inference()->infer(new StaticSource(['a' => new \stdClass()]), self::env());

        $this->assertStringContainsString('Record field [a] cannot be typed.', $result->unwrapErr()->describe());
        $this->assertStringContainsString('No literal type is registered for [stdClass]', $result->unwrapErr()->describe());
    }

    #[Test]
    public function a_mixed_key_array_has_no_type(): void
    {
        $result = self::inference()->infer(new StaticSource(['a' => 1, 5 => 2]), self::env());

        $this->assertStringContainsString('A record literal requires string field names; got [5].', $result->unwrapErr()->message);
    }

    #[Test]
    public function object_literals_resolve_through_the_registry(): void
    {
        $registry = new LiteralTypeRegistry([
            \DateTimeImmutable::class => fn(object $value): Type => new NumberType(),
        ]);

        $result = self::inference($registry)->infer(new StaticSource(new \DateTimeImmutable()), self::env());

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function a_value_outside_the_literal_domain_is_an_error(): void
    {
        $result = self::inference()->infer(new StaticSource(fopen('php://memory', 'r')), self::env());

        $this->assertStringContainsString('No literal type exists for a value of type', $result->unwrapErr()->message);
    }

    #[Test]
    public function symbols_resolve_through_the_environment(): void
    {
        $env = self::env(declarations: ['turnover' => new NumberType()]);

        $result = self::inference()->infer(new SymbolSource('turnover'), $env);

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function a_coercion_over_a_typed_source_is_the_authors_override(): void
    {
        $definition = new Coerce(new NumberType(), new StaticSource(5));

        $result = self::inference()->infer($definition, self::env());

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function a_coercion_over_an_untypeable_static_value_is_the_escape_hatch(): void
    {
        // A static value the literal registry cannot type still has a total
        // evaluation — itself — and Coerce discards the inner type anyway.
        $definition = new Coerce(new NumberType(), new StaticSource(new \stdClass()));

        $result = self::inference()->infer($definition, self::env());

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function a_coercion_over_an_uncompilable_expression_propagates_the_error(): void
    {
        // The escape hatch is for static VALUES only: an expression inside a
        // Coerce runs, so it compiles.
        $definition = new Coerce(new NumberType(), new InfixExpression(new SymbolSource('ghost'), '+', new StaticSource(1)));

        $result = self::inference()->infer($definition, self::env());

        $this->assertStringContainsString('Unbound symbol [ghost]', $result->unwrapErr()->describe());
    }

    #[Test]
    public function coerce_is_statically_opaque_by_design(): void
    {
        // '42' does not inhabit Number, but coercion CONVERTS — the boundary
        // node types verbatim and satisfiability is never modeled statically.
        $boundary = new Coerce(new NumberType(), new StaticSource('42'));

        $this->assertInstanceOf(NumberType::class, self::inference()->infer($boundary, self::env())->unwrap());
    }

    #[Test]
    public function an_ascription_refines_unknown(): void
    {
        $env = self::env(declarations: ['blob' => new UnknownType()]);
        $claim = new \Superscript\Axiom\Sources\Ascription(new NumberType(), new SymbolSource('blob'));

        $this->assertInstanceOf(NumberType::class, self::inference()->infer($claim, $env)->unwrap());
    }

    #[Test]
    public function an_ascription_narrows_an_overlapping_type(): void
    {
        $env = self::env(declarations: ['kind' => new StringType()]);
        $claim = new \Superscript\Axiom\Sources\Ascription(new LiteralType('shop'), new SymbolSource('kind'));

        $result = self::inference()->infer($claim, $env);

        $this->assertSame("'shop'", self::describe($result));
    }

    #[Test]
    public function a_disjoint_ascription_is_a_false_claim(): void
    {
        $claim = new \Superscript\Axiom\Sources\Ascription(new NumberType(), new StaticSource('shop'));

        $result = self::inference()->infer($claim, self::env());

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('The claim that this is Number is false', $result->unwrapErr()->describe());
        $this->assertStringContainsString("'shop' and Number share no values.", $result->unwrapErr()->describe());
    }

    #[Test]
    public function an_ascription_over_an_untypeable_source_propagates_the_error(): void
    {
        $claim = new \Superscript\Axiom\Sources\Ascription(new NumberType(), new StaticSource(new \stdClass()));

        $this->assertTrue(self::inference()->infer($claim, self::env())->isErr());
    }

    #[Test]
    public function legacy_impossible_coercion_scenario_now_types_verbatim(): void
    {
        // Under the retired dead-coercion check this was refused; the checker
        // was applying an assert-world relation to the coerce-world node.
        $boundary = new Coerce(new NumberType(), new StaticSource('shop'));

        $this->assertInstanceOf(NumberType::class, self::inference()->infer($boundary, self::env())->unwrap());
    }

    #[Test]
    public function unary_negation_types_through_the_unary_stack(): void
    {
        $inference = self::inference();

        $this->assertInstanceOf(
            BooleanType::class,
            $inference->infer(new UnaryExpression('!', new StaticSource(true)), self::env())->unwrap(),
        );
        $this->assertInstanceOf(
            NumberType::class,
            $inference->infer(new UnaryExpression('-', new StaticSource(5)), self::env())->unwrap(),
        );
    }

    #[Test]
    public function optionality_propagates_through_unary_operators(): void
    {
        $env = self::env(declarations: ['flag' => new OptionType(new BooleanType())]);

        $result = self::inference()->infer(new UnaryExpression('not', new SymbolSource('flag')), $env);

        $this->assertSame('Boolean?', self::describe($result));
    }

    #[Test]
    public function unary_refusals_and_operand_errors_propagate(): void
    {
        $inference = self::inference();

        $refused = $inference->infer(new UnaryExpression('!', new StaticSource(5)), self::env());
        $this->assertStringContainsString('[!] expects Boolean; got 5.', $refused->unwrapErr()->describe());

        $operandError = $inference->infer(new UnaryExpression('!', new SymbolSource('ghost')), self::env());
        $this->assertStringContainsString('Unbound symbol [ghost]', $operandError->unwrapErr()->describe());
    }

    #[Test]
    public function infix_expressions_type_through_the_composed_dialect(): void
    {
        $inference = self::inference();
        $env = self::env(declarations: ['turnover' => new NumberType()]);

        $sum = $inference->infer(
            new InfixExpression(new SymbolSource('turnover', null), '*', new StaticSource(1.2)),
            $env,
        );
        $this->assertInstanceOf(NumberType::class, $sum->unwrap());

        $comparison = $inference->infer(
            new InfixExpression(new SymbolSource('turnover'), '>', new StaticSource(500000)),
            $env,
        );
        $this->assertInstanceOf(BooleanType::class, $comparison->unwrap());
    }

    #[Test]
    public function a_dead_comparison_is_a_compile_error(): void
    {
        $env = self::env(declarations: [
            'kind' => new UnionType(new LiteralType('shop'), new LiteralType('office')),
        ]);

        $result = self::inference()->infer(
            new InfixExpression(new SymbolSource('kind'), '==', new StaticSource('warehouse')),
            $env,
        );

        $this->assertTrue($result->isErr());
        $this->assertTrue($result->unwrapErr()->dead);
        $this->assertStringContainsString('can never hold', $result->unwrapErr()->describe());
    }

    #[Test]
    public function an_inert_unknown_operand_is_a_compile_error(): void
    {
        $env = self::env(declarations: ['blob' => new UnknownType()]);

        $result = self::inference()->infer(
            new InfixExpression(new SymbolSource('blob'), '+', new StaticSource(1)),
            $env,
        );

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('An Unknown operand is inert', $result->unwrapErr()->describe());

        // The bridge: an Ascription re-enters the typed world, and the same
        // program certifies.
        $bridged = self::inference()->infer(
            new InfixExpression(
                new \Superscript\Axiom\Sources\Ascription(new NumberType(), new SymbolSource('blob')),
                '+',
                new StaticSource(1),
            ),
            $env,
        );

        $this->assertInstanceOf(NumberType::class, $bridged->unwrap());
    }

    #[Test]
    public function infix_operand_errors_propagate_from_either_side(): void
    {
        $inference = self::inference();

        $left = $inference->infer(new InfixExpression(new SymbolSource('ghost'), '+', new StaticSource(1)), self::env());
        $this->assertStringContainsString('Unbound symbol [ghost]', $left->unwrapErr()->describe());

        $right = $inference->infer(new InfixExpression(new StaticSource(1), '+', new SymbolSource('ghost')), self::env());
        $this->assertStringContainsString('Unbound symbol [ghost]', $right->unwrapErr()->describe());
    }

    #[Test]
    public function a_null_only_match_over_the_null_literal_is_exhaustive(): void
    {
        // null infers as Option<Never>; the null pattern covers the {null}
        // member and Never is vacuously covered — it has no inhabitants.
        $result = self::inference()->infer(new MatchExpression(
            subject: new StaticSource(null),
            arms: [new MatchArm(new LiteralPattern(null), new StaticSource('ok'))],
        ), self::env());

        $this->assertSame("'ok'", self::describe($result));
    }

    #[Test]
    public function a_match_joins_its_arm_types_by_union(): void
    {
        $env = self::env(declarations: ['flag' => new BooleanType()]);

        $result = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('flag'),
            arms: [
                new MatchArm(new LiteralPattern(true), new StaticSource('low')),
                new MatchArm(new LiteralPattern(false), new StaticSource('high')),
            ],
        ), $env);

        $this->assertSame("'low' | 'high'", self::describe($result));
    }

    #[Test]
    public function agreeing_arms_collapse_to_the_single_type(): void
    {
        $env = self::env(declarations: ['flag' => new BooleanType()]);

        $result = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('flag'),
            arms: [
                new MatchArm(new LiteralPattern(true), new StaticSource('same')),
                new MatchArm(new LiteralPattern(false), new StaticSource('same')),
            ],
        ), $env);

        $this->assertSame("'same'", self::describe($result));
        $this->assertInstanceOf(LiteralType::class, $result->unwrap());
    }

    #[Test]
    public function full_literal_coverage_of_an_enum_is_exhaustive(): void
    {
        $env = self::env(declarations: [
            'kind' => new UnionType(new LiteralType('shop'), new LiteralType('office')),
        ]);

        $result = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('kind'),
            arms: [
                new MatchArm(new LiteralPattern('shop'), new StaticSource(1.1)),
                new MatchArm(new LiteralPattern('office'), new StaticSource(1.3)),
            ],
        ), $env);

        $this->assertTrue($result->isOk(), self::describe($result));
    }

    #[Test]
    public function an_option_scrutinee_needs_a_null_arm(): void
    {
        $env = self::env(declarations: ['kind' => new OptionType(new LiteralType('shop'))]);

        $withoutNull = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('kind'),
            arms: [new MatchArm(new LiteralPattern('shop'), new StaticSource(1))],
        ), $env);

        $this->assertStringContainsString('may not be exhaustive', $withoutNull->unwrapErr()->describe());

        $withNull = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('kind'),
            arms: [
                new MatchArm(new LiteralPattern('shop'), new StaticSource(1)),
                new MatchArm(new LiteralPattern(null), new StaticSource(0)),
            ],
        ), $env);

        $this->assertTrue($withNull->isOk(), self::describe($withNull));
    }

    #[Test]
    public function unprovable_exhaustiveness_demands_a_wildcard(): void
    {
        $env = self::env(declarations: ['name' => new StringType()]);

        $withoutWildcard = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('name'),
            arms: [new MatchArm(new LiteralPattern('ada'), new StaticSource(1))],
        ), $env);

        $this->assertStringContainsString('add a wildcard arm', $withoutWildcard->unwrapErr()->describe());

        $withWildcard = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('name'),
            arms: [
                new MatchArm(new LiteralPattern('ada'), new StaticSource(1)),
                new MatchArm(new WildcardPattern(), new StaticSource(0)),
            ],
        ), $env);

        $this->assertTrue($withWildcard->isOk());
    }

    #[Test]
    public function expression_patterns_never_count_toward_coverage(): void
    {
        $env = self::env(declarations: ['flag' => new BooleanType()]);

        $result = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('flag'),
            arms: [
                new MatchArm(new LiteralPattern(true), new StaticSource(1)),
                new MatchArm(new ExpressionPattern(new StaticSource(false)), new StaticSource(0)),
            ],
        ), $env);

        $this->assertStringContainsString('may not be exhaustive', $result->unwrapErr()->describe());
    }

    #[Test]
    public function expression_patterns_are_programs_and_compile_like_everything_else(): void
    {
        // Under value-directed evaluation an expression pattern's source ran
        // completely unchecked. It runs, so it compiles.
        $result = self::inference()->infer(new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(new ExpressionPattern(new SymbolSource('ghost')), new StaticSource(1)),
                new MatchArm(new WildcardPattern(), new StaticSource(0)),
            ],
        ), self::env());

        $this->assertStringContainsString('The pattern of match arm 0 cannot be compiled.', $result->unwrapErr()->describe());
        $this->assertStringContainsString('Unbound symbol [ghost]', $result->unwrapErr()->describe());
    }

    #[Test]
    public function an_unknown_pattern_kind_is_a_compile_error(): void
    {
        $foreign = new class implements \Superscript\Axiom\Sources\MatchPattern {};

        $result = self::inference()->infer(new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm($foreign, new StaticSource(1)),
                new MatchArm(new WildcardPattern(), new StaticSource(0)),
            ],
        ), self::env());

        $this->assertStringContainsString('No pattern rule exists for', $result->unwrapErr()->describe());
    }

    #[Test]
    public function a_literal_scrutinee_is_exhausted_by_its_own_literal(): void
    {
        $env = self::env(declarations: ['five' => new LiteralType(5)]);

        $result = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('five'),
            arms: [new MatchArm(new LiteralPattern(5.0), new StaticSource('ok'))],
        ), $env);

        $this->assertTrue($result->isOk(), self::describe($result));
    }

    #[Test]
    public function a_literal_scrutinee_is_not_exhausted_by_a_different_literal(): void
    {
        $env = self::env(declarations: ['five' => new LiteralType(5)]);

        $result = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('five'),
            arms: [new MatchArm(new LiteralPattern(6), new StaticSource('no'))],
        ), $env);

        $this->assertStringContainsString('may not be exhaustive', $result->unwrapErr()->describe());
    }

    #[Test]
    public function a_boolean_literal_scrutinee_ignores_null_patterns_safely(): void
    {
        $env = self::env(declarations: ['flag' => new LiteralType(true)]);

        $result = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('flag'),
            arms: [
                new MatchArm(new LiteralPattern(null), new StaticSource(0)),
                new MatchArm(new LiteralPattern(true), new StaticSource(1)),
            ],
        ), $env);

        $this->assertTrue($result->isOk(), self::describe($result));
    }

    #[Test]
    public function a_partially_covered_enum_is_not_exhaustive(): void
    {
        $env = self::env(declarations: [
            'kind' => new UnionType(new LiteralType('shop'), new LiteralType('office')),
        ]);

        $result = self::inference()->infer(new MatchExpression(
            subject: new SymbolSource('kind'),
            arms: [new MatchArm(new LiteralPattern('shop'), new StaticSource(1))],
        ), $env);

        $this->assertStringContainsString('may not be exhaustive', $result->unwrapErr()->describe());
    }

    #[Test]
    public function match_subject_and_arm_errors_propagate(): void
    {
        $inference = self::inference();

        $subject = $inference->infer(new MatchExpression(
            subject: new SymbolSource('ghost'),
            arms: [new MatchArm(new WildcardPattern(), new StaticSource(1))],
        ), self::env());
        $this->assertStringContainsString('The match subject cannot be typed.', $subject->unwrapErr()->describe());
        $this->assertStringContainsString('Unbound symbol [ghost]', $subject->unwrapErr()->describe());

        $arm = $inference->infer(new MatchExpression(
            subject: new StaticSource(true),
            arms: [new MatchArm(new WildcardPattern(), new SymbolSource('ghost'))],
        ), self::env());
        $this->assertStringContainsString('Match arm 0 cannot be typed.', $arm->unwrapErr()->describe());
        $this->assertStringContainsString('Unbound symbol [ghost]', $arm->unwrapErr()->describe());
    }

    #[Test]
    public function member_access_types_record_fields(): void
    {
        $record = new RecordType(['turnover' => new NumberType()]);
        $env = self::env(declarations: ['customer' => $record]);

        $result = self::inference()->infer(new MemberAccessSource(new SymbolSource('customer'), 'turnover'), $env);

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function optionality_propagates_through_member_access(): void
    {
        $env = self::env(declarations: [
            'customer' => new OptionType(new RecordType(['turnover' => new NumberType()])),
        ]);

        $result = self::inference()->infer(new MemberAccessSource(new SymbolSource('customer'), 'turnover'), $env);

        $this->assertSame('Number?', self::describe($result));
    }

    #[Test]
    public function member_access_on_unknown_is_refused_as_inert(): void
    {
        $env = self::env(declarations: ['blob' => new UnknownType()]);

        $result = self::inference()->infer(new MemberAccessSource(new SymbolSource('blob'), 'anything'), $env);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Unknown is inert', $result->unwrapErr()->describe());

        // The bridge: ascribe a record type first, then reach in.
        $bridged = self::inference()->infer(new MemberAccessSource(
            new \Superscript\Axiom\Sources\Ascription(new RecordType(['anything' => new NumberType()]), new SymbolSource('blob')),
            'anything',
        ), $env);

        $this->assertInstanceOf(NumberType::class, $bridged->unwrap());
    }

    #[Test]
    public function member_access_is_shape_driven_so_extension_types_get_it_for_free(): void
    {
        // A host type whose values genuinely ARE records (a JSON-shaped
        // position) projects record-like — and member access works without
        // core ever knowing the concrete class. This is the review finding:
        // dispatch on projections, not on concrete Type classes.
        $position = new class implements Type {
            public function assert(mixed $value): Result
            {
                return Ok(Some($value));
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
                return '';
            }

            public function shape(): \Superscript\Axiom\Types\Shapes\Shape
            {
                return new \Superscript\Axiom\Types\Shapes\RecordShape([
                    'lat' => new \Superscript\Axiom\Types\Shapes\NumberShape(),
                    'lng' => new \Superscript\Axiom\Types\Shapes\NumberShape(),
                ]);
            }
        };

        $env = self::env(declarations: ['position' => $position]);

        $result = self::inference()->infer(new MemberAccessSource(new SymbolSource('position'), 'lat'), $env);

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function member_access_on_an_opaque_type_is_refused(): void
    {
        $env = self::env(declarations: [
            'price' => new \Superscript\Axiom\Types\OpaqueType('money', ['currency' => new LiteralType('GBP')]),
        ]);

        $result = self::inference()->infer(new MemberAccessSource(new SymbolSource('price'), 'amount'), $env);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('nominal types make no structural claims', $result->unwrapErr()->describe());
        $this->assertStringContainsString("money<currency: 'GBP'>", $result->unwrapErr()->describe());
    }

    #[Test]
    public function member_access_on_a_dict_is_refused(): void
    {
        $env = self::env(declarations: ['bag' => new DictType(new NumberType())]);

        $result = self::inference()->infer(new MemberAccessSource(new SymbolSource('bag'), 'key'), $env);

        $this->assertStringContainsString('Give the value a record type', $result->unwrapErr()->describe());
    }

    #[Test]
    public function undeclared_fields_are_refused(): void
    {
        // Records are exact: a field the record does not declare does not
        // exist, and there is no open variant on which "might have it"
        // could be argued.
        $inference = self::inference();

        $env = self::env(declarations: ['r' => new RecordType(['a' => new NumberType()])]);
        $result = $inference->infer(new MemberAccessSource(new SymbolSource('r'), 'b'), $env);
        $this->assertStringContainsString("Field 'b' does not exist", $result->unwrapErr()->describe());
    }

    #[Test]
    public function member_access_on_a_scalar_is_refused_and_errors_propagate(): void
    {
        $inference = self::inference();

        $scalar = self::env(declarations: ['n' => new NumberType()]);
        $result = $inference->infer(new MemberAccessSource(new SymbolSource('n'), 'x'), $scalar);
        $this->assertStringContainsString("Cannot access field 'x' on Number", $result->unwrapErr()->describe());

        $error = $inference->infer(new MemberAccessSource(new SymbolSource('ghost'), 'x'), self::env());
        $this->assertStringContainsString('Unbound symbol [ghost]', $error->unwrapErr()->describe());
    }

    #[Test]
    public function a_dialect_can_inject_its_own_unary_stack(): void
    {
        $numericNot = new class implements \Superscript\Axiom\Operators\UnaryOperatorRule {
            public function operator(): string
            {
                return '!';
            }

            public function resolve(Type $operand): \Superscript\Axiom\Operators\OperatorResolution
            {
                return new \Superscript\Axiom\Operators\ResolvedOperation(new NumberType(), fn(int $n) => -$n);
            }
        };

        $dialect = Dialect::core();
        $inference = new TypeInference(
            $dialect->operators(),
            new \Superscript\Axiom\Operators\UnaryOperatorResolver([$numericNot]),
            $dialect->literals(),
            $dialect->sourceCompilers(),
        );

        $result = $inference->infer(new UnaryExpression('!', new StaticSource(5)), self::env());

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function host_sources_declare_their_type_and_evaluation_in_one_statement(): void
    {
        $source = new HostValueSource(new NumberType(), 42);
        $dialect = Dialect::core()->with(new SourceCompilerExtension());

        $result = self::inference(dialect: $dialect)->infer($source, self::env());

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function an_unhandled_node_is_an_error_not_a_silent_unknown(): void
    {
        $source = new class implements Source {};

        $result = self::inference()->infer($source, self::env());

        $this->assertStringContainsString('Cannot compile [', $result->unwrapErr()->message);
        $this->assertStringContainsString('register its exact class through Extension::sourceCompilers()', $result->unwrapErr()->message);
    }

    #[Test]
    public function check_is_infer_plus_assignability(): void
    {
        $inference = self::inference();
        $env = self::env(declarations: ['turnover' => new NumberType()]);

        $gate = new InfixExpression(new SymbolSource('turnover'), '>', new StaticSource(500000));
        $this->assertTrue($inference->check($gate, new BooleanType(), $env)->isOk());

        $notBoolean = $inference->check(new SymbolSource('turnover'), new BooleanType(), $env);
        $this->assertStringContainsString('Number is not assignable to Boolean', $notBoolean->unwrapErr()->describe());
    }

    #[Test]
    public function the_bidirectional_special_cases_are_assignability_theorems(): void
    {
        $inference = self::inference();
        $env = self::env();

        // null fills any option slot.
        $this->assertTrue($inference->check(new StaticSource(null), new OptionType(new StringType()), $env)->isOk());

        // A scalar literal fills the enum it belongs to.
        $enum = new UnionType(new LiteralType('shop'), new LiteralType('office'));
        $this->assertTrue($inference->check(new StaticSource('shop'), $enum, $env)->isOk());
        $this->assertTrue($inference->check(new StaticSource('warehouse'), $enum, $env)->isErr());

        // The empty list fills any list that admits emptiness.
        $this->assertTrue($inference->check(new StaticSource([]), new ListType(new StringType()), $env)->isOk());
        $this->assertTrue($inference->check(new StaticSource([]), new ListType(new StringType(), min: 1), $env)->isErr());
    }
}
