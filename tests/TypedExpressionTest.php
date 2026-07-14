<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Boundary;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Exceptions\BoundaryViolation;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Resolvers\UnaryResolver;
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
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

#[CoversClass(Expression::class)]
#[CoversClass(Dialect::class)]
#[CoversClass(Extension::class)]
#[CoversClass(Boundary::class)]
#[CoversClass(BoundaryViolation::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Context::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(\Superscript\Axiom\UnboundSymbols::class)]
#[UsesClass(DelegatingResolver::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(SymbolResolver::class)]
#[UsesClass(InfixResolver::class)]
#[UsesClass(UnaryResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(UnaryExpression::class)]
#[UsesClass(MemberAccessSource::class)]
#[UsesClass(\Superscript\Axiom\Resolvers\MemberAccessResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\OverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\DefaultOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\ComparisonOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\HasOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\InOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\IntersectsOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\LogicalOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\NullOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\NotOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\NegateOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
#[UsesClass(Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignature::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeInference::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeEnvironment::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeOrder::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeReifier::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(RecordType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralType::class)]
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
final class TypedExpressionTest extends TestCase
{
    private function resolver(): DelegatingResolver
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            SymbolSource::class => SymbolResolver::class,
            InfixExpression::class => InfixResolver::class,
            UnaryExpression::class => UnaryResolver::class,
            MemberAccessSource::class => \Superscript\Axiom\Resolvers\MemberAccessResolver::class,
            MatchExpression::class => \Superscript\Axiom\Resolvers\MatchResolver::class,
        ]);

        $resolver->instance(\Superscript\Axiom\Resolvers\MatchResolver::class, new \Superscript\Axiom\Resolvers\MatchResolver($resolver, [
            new \Superscript\Axiom\Patterns\WildcardMatcher(),
            new \Superscript\Axiom\Patterns\LiteralMatcher(),
            new \Superscript\Axiom\Patterns\ExpressionMatcher($resolver),
        ]));

        return $resolver;
    }

    private function gate(): Expression
    {
        // quote.turnover > 500000
        return new Expression(
            source: new InfixExpression(
                left: new SymbolSource('turnover', 'quote'),
                operator: '>',
                right: new StaticSource(500000),
            ),
            resolver: $this->resolver(),
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
        // A stringly CSV cell — Phase 0's honest arithmetic would refuse it
        // mid-expression; the boundary converts it before evaluation begins.
        $result = ($this->gate())(['quote' => ['turnover' => '600000']]);

        $this->assertTrue($result->unwrap()->unwrap());
    }

    #[Test]
    public function boundary_violations_are_aggregated_and_named(): void
    {
        $expression = new Expression(
            source: new SymbolSource('a'),
            resolver: $this->resolver(),
            declarations: ['a' => new NumberType(), 'b' => new NumberType()],
        );

        $result = $expression(['a' => 'garbage']);

        $violation = $result->unwrapErr();
        $this->assertInstanceOf(BoundaryViolation::class, $violation);
        $this->assertCount(2, $violation->violations);
        $this->assertStringContainsString('binding [a]:', $violation->violations[0]);
        $this->assertStringContainsString('required input [b] is missing', $violation->violations[1]);
    }

    #[Test]
    public function assert_mode_refuses_what_coerce_would_convert(): void
    {
        $strict = new Expression(
            source: new SymbolSource('turnover'),
            resolver: $this->resolver(),
            declarations: ['turnover' => new NumberType()],
            boundary: Boundary::Assert,
        );

        $this->assertStringContainsString('binding [turnover]:', $strict(['turnover' => '600000'])->unwrapErr()->getMessage());
        $this->assertSame(600000, $strict(['turnover' => 600000])->unwrap()->unwrap());
    }

    #[Test]
    public function an_option_declared_input_may_be_missing_or_null(): void
    {
        $expression = new Expression(
            source: new SymbolSource('note'),
            resolver: $this->resolver(),
            declarations: ['note' => new OptionType(new StringType())],
        );

        $this->assertTrue($expression([])->unwrap()->isNone());
        $this->assertTrue($expression(['note' => null])->unwrap()->isNone());
        $this->assertSame('hi', $expression(['note' => 'hi'])->unwrap()->unwrap());
    }

    #[Test]
    public function a_required_input_that_reads_as_missing_is_a_violation(): void
    {
        $expression = new Expression(
            source: new SymbolSource('name'),
            resolver: $this->resolver(),
            declarations: ['name' => new StringType()],
        );

        // '' coerces to absence — required-but-missing at the boundary.
        $result = $expression(['name' => '']);

        $this->assertStringContainsString('reads as missing, but String is required', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function a_symbol_cannot_be_both_declared_and_defined(): void
    {
        // Disjoint namespaces: a symbol is a parameter or a derived value,
        // never both — the collision is a construction error, before any
        // call. An override is modeled in-language instead: an Option-typed
        // parameter the definition consults.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[rate] is both declared and defined');

        new Expression(
            source: new SymbolSource('rate'),
            resolver: $this->resolver(),
            definitions: new Definitions(['rate' => new StaticSource(1.2)]),
            declarations: ['rate' => new NumberType()],
        );
    }

    #[Test]
    public function a_record_declaration_collides_with_a_definition_through_the_record_view(): void
    {
        // Declaring customer as a record with a turnover field declares
        // customer.turnover — so a definition there is the same collision,
        // and the nested-binding side door (finding: a raw array binding
        // silently shadowing a namespaced definition) is unrepresentable.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[customer.turnover] is both declared and defined');

        new Expression(
            source: new SymbolSource('turnover', 'customer'),
            resolver: $this->resolver(),
            definitions: new Definitions(['customer.turnover' => new StaticSource('derived')]),
            declarations: ['customer' => new RecordType(['turnover' => new NumberType()])],
        );
    }

    #[Test]
    public function record_bindings_are_coerced_whole_and_namespaces_descend(): void
    {
        $record = new RecordType([
            'turnover' => new NumberType(),
            'note' => new OptionType(new StringType()),
        ]);

        $expression = new Expression(
            source: new InfixExpression(
                left: new SymbolSource('turnover', 'customer'),
                operator: '*',
                right: new StaticSource(2),
            ),
            resolver: $this->resolver(),
            declarations: ['customer' => $record],
        );

        // The whole record coerces at the boundary ('2' → 2, missing
        // optional note canonicalizes) and the namespaced symbol reads
        // through the coerced record.
        $this->assertSame(4, $expression(['customer' => ['turnover' => '2']])->unwrap()->unwrap());

        // Statically, the namespaced symbol types as the record's field.
        $this->assertInstanceOf(NumberType::class, $expression->infer()->unwrap());

        // Field errors are named under the input.
        $bad = $expression(['customer' => ['turnover' => 'lots']]);
        $this->assertStringContainsString('binding [customer]:', $bad->unwrapErr()->getMessage());
    }

    #[Test]
    public function an_override_is_modeled_as_an_option_typed_parameter(): void
    {
        // The blessed replacement for binding-over-definition shadowing:
        // the override is an explicit, typed, optional parameter and the
        // derived value consults it — both paths certified, nothing
        // implicit. rate = match rateOverride { null => 1.2, _ => override }
        $expression = new Expression(
            source: new SymbolSource('rate'),
            resolver: $this->resolver(),
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
        );

        $this->assertSame(1.2, $expression()->unwrap()->unwrap());
        $this->assertSame(2.5, $expression(['rateOverride' => 2.5])->unwrap()->unwrap());
    }

    #[Test]
    public function extensions_contribute_rules_and_specializations_win_ties(): void
    {
        $absenceAsZero = new class implements OperatorOverloader {
            public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
            {
                return $operator === '+' && ($left === null xor $right === null);
            }

            public function evaluate(mixed $left, mixed $right, string $operator): Result
            {
                return Ok(($left ?? 0) + ($right ?? 0));
            }

            public function handles(string $operator): bool
            {
                return $operator === '+';
            }

            public function typeOf(string $operator, Type $left, Type $right): Result
            {
                return Ok(new NumberType());
            }
        };

        $extension = new class($absenceAsZero) extends Extension {
            public function __construct(private readonly OperatorOverloader $rule) {}

            public function operators(): array
            {
                return [$this->rule];
            }
        };

        $expression = new Expression(
            source: new InfixExpression(
                left: new SymbolSource('maybe'),
                operator: '+',
                right: new StaticSource(2),
            ),
            resolver: $this->resolver(),
            dialect: Dialect::core()->with($extension),
            declarations: ['maybe' => new OptionType(new NumberType())],
        );

        // Runtime and checker consume the same dialect: absence-as-zero
        // evaluates AND certifies, through one composed list.
        $this->assertSame(2, $expression([])->unwrap()->unwrap());
        $this->assertInstanceOf(NumberType::class, $expression->infer()->unwrap());
    }

    #[Test]
    public function duplicate_literal_registrations_are_loud(): void
    {
        $money = fn() => new class extends Extension {
            public function literals(): array
            {
                return [\DateTimeImmutable::class => fn(object $value) => new NumberType()];
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('registered by two extensions');

        Dialect::core()->with($money(), $money());
    }

    #[Test]
    public function the_core_dialect_carries_every_core_rule(): void
    {
        $dialect = Dialect::core();

        // The null rule (first in the stack) and the unary rules survive
        // composition — removal of any is observable here.
        $this->assertNull($dialect->operators()->evaluate(null, null, '+')->unwrap());
        $this->assertFalse($dialect->unaryOperators()->evaluate(true, '!')->unwrap());
        $this->assertSame(-5, $dialect->unaryOperators()->evaluate(5, '-')->unwrap());
    }

    #[Test]
    public function extension_composition_preserves_the_whole_core_stack(): void
    {
        $empty = new class extends Extension {};
        $dialect = Dialect::core()->with($empty);

        $this->assertNull($dialect->operators()->evaluate(null, null, '+')->unwrap());
        $this->assertSame(3, $dialect->operators()->evaluate(1, 2, '+')->unwrap());
        $this->assertTrue($dialect->operators()->evaluate('a', ['a'], 'in')->unwrap());
        $this->assertTrue($dialect->operators()->evaluate(['a'], 'a', 'has')->unwrap());
        $this->assertFalse($dialect->unaryOperators()->evaluate(true, '!')->unwrap());
        $this->assertSame(-5, $dialect->unaryOperators()->evaluate(5, '-')->unwrap());
    }

    #[Test]
    public function extension_unary_rules_reach_the_resolver_through_the_dialect(): void
    {
        $absValue = new class implements UnaryOverloader {
            public function supportsOverloading(mixed $operand, string $operator): bool
            {
                return $operator === 'abs' && (is_int($operand) || is_float($operand));
            }

            public function evaluate(mixed $operand, string $operator): Result
            {
                return Ok(abs($operand));
            }

            public function handles(string $operator): bool
            {
                return $operator === 'abs';
            }

            public function typeOf(string $operator, Type $operand): Result
            {
                return Ok(new NumberType());
            }
        };

        $extension = new class($absValue) extends Extension {
            public function __construct(private readonly UnaryOverloader $rule) {}

            public function unaryOperators(): array
            {
                return [$this->rule];
            }
        };

        $expression = new Expression(
            source: new UnaryExpression('abs', new StaticSource(-7)),
            resolver: $this->resolver(),
            dialect: Dialect::core()->with($extension),
        );

        // Runtime and checker both see the extension's unary rule — one
        // dialect wired into the resolver graph and the inference alike.
        $this->assertSame(7, $expression()->unwrap()->unwrap());
        $this->assertInstanceOf(NumberType::class, $expression->infer()->unwrap());
    }

    #[Test]
    public function signature_built_rules_are_full_dialect_citizens(): void
    {
        $extension = new class extends Extension {
            public function operators(): array
            {
                return [
                    Operator::infix('++')
                        ->signature(new StringType(), new StringType())
                        ->returns(new StringType())
                        ->evaluate(fn(string $a, string $b) => $a . $b),
                ];
            }
        };

        $expression = new Expression(
            source: new InfixExpression(
                left: new SymbolSource('greeting'),
                operator: '++',
                right: new StaticSource('!'),
            ),
            resolver: $this->resolver(),
            dialect: Dialect::core()->with($extension),
            declarations: ['greeting' => new StringType()],
        );

        // One declared row, both faces: the evaluator dispatches it and the
        // checker certifies it, through the same composed dialect.
        $this->assertSame('hi!', $expression(['greeting' => 'hi'])->unwrap()->unwrap());
        $this->assertInstanceOf(StringType::class, $expression->infer()->unwrap());
    }

    #[Test]
    public function a_plain_resolver_works_without_dialect_wiring(): void
    {
        $expression = new Expression(
            source: new StaticSource(42),
            resolver: new StaticResolver(),
        );

        $this->assertSame(42, $expression()->unwrap()->unwrap());
    }

    #[Test]
    public function boundary_violations_carry_the_banner(): void
    {
        $expression = new Expression(
            source: new SymbolSource('a'),
            resolver: $this->resolver(),
            declarations: ['a' => new NumberType()],
        );

        $message = $expression([])->unwrapErr()->getMessage();

        $this->assertStringStartsWith("Bindings rejected at the boundary:\n- ", $message);
    }

    #[Test]
    public function violations_after_a_skipped_optional_input_are_still_reported(): void
    {
        $expression = new Expression(
            source: new SymbolSource('b'),
            resolver: $this->resolver(),
            declarations: [
                'a' => new OptionType(new StringType()),   // missing, fine — must not end the sweep
                'b' => new NumberType(),
            ],
        );

        $result = $expression(['b' => 'garbage']);

        $this->assertStringContainsString('binding [b]:', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function violations_after_a_reads_as_missing_input_are_still_reported(): void
    {
        $expression = new Expression(
            source: new SymbolSource('a'),
            resolver: $this->resolver(),
            declarations: [
                'a' => new StringType(),   // '' reads as missing → violation, sweep continues
                'b' => new NumberType(),   // absent → violation
            ],
        );

        $violation = $expression(['a' => ''])->unwrapErr();

        $this->assertInstanceOf(BoundaryViolation::class, $violation);
        $this->assertCount(2, $violation->violations);
    }

    #[Test]
    public function multi_dot_declaration_keys_split_on_the_first_dot(): void
    {
        // Definitions flatten one namespace level, so a declared key may
        // carry dots in its name part: ns + 'deep.key'.
        $expression = new Expression(
            source: new SymbolSource('deep.key', 'ns'),
            resolver: $this->resolver(),
            declarations: ['ns.deep.key' => new NumberType()],
        );

        $this->assertSame(5, $expression(['ns.deep.key' => '5'])->unwrap()->unwrap());
    }

    #[Test]
    public function a_manually_bound_overloader_is_inert_because_the_dialect_rides_the_context(): void
    {
        // The legacy configuration path: an overloader bound directly on the
        // resolver container. It used to win at runtime while the checker
        // read the dialect — the exact miscomposition the RFC forbids. Now
        // the rules travel with each call, so the binding is dead weight.
        $hijacker = new class implements OperatorOverloader {
            public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
            {
                return true;
            }

            public function evaluate(mixed $left, mixed $right, string $operator): Result
            {
                return Ok('hijacked');
            }

            public function handles(string $operator): bool
            {
                return true;
            }

            public function typeOf(string $operator, Type $left, Type $right): Result
            {
                return Ok(new StringType());
            }
        };

        $resolver = $this->resolver();
        $resolver->instance(OperatorOverloader::class, $hijacker);

        $expression = new Expression(
            source: new InfixExpression(new StaticSource(1), '+', new StaticSource(2)),
            resolver: $resolver,
        );

        $this->assertInstanceOf(NumberType::class, $expression->infer()->unwrap());
        $this->assertSame(3, $expression()->unwrap()->unwrap());
    }
}
