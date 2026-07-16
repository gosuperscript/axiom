<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Tests\Fixtures\CountingSource;
use Superscript\Axiom\Tests\Fixtures\EvaluationCounter;
use Superscript\Axiom\Tests\Fixtures\SourceCompilerExtension;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/** @internal Test fixture for a host source that owns other sources. */
final readonly class FirstSource implements Source
{
    /** @param list<Source> $filters */
    public function __construct(public array $filters) {}
}

/** @internal Test fixture proving source compiler ownership is exact-class. */
class ParentHostSource implements Source {}

/** @internal */
final class ChildHostSource extends ParentHostSource {}

/** @internal Test fixture for a source compiler that binds an operator. */
final readonly class HostInfixSource implements Source
{
    public function __construct(
        public Type $leftType,
        public mixed $left,
        public string $operator,
        public Type $rightType,
        public mixed $right,
    ) {}
}

/** @internal Test fixture for a source compiler that binds a prefix operator. */
final readonly class HostPrefixSource implements Source
{
    public function __construct(
        public string $operator,
        public Type $operandType,
        public mixed $operand,
    ) {}
}

/** @internal Test fixture for a source compiler that owns a symbol reference. */
final readonly class HostSymbolSource implements Source
{
    public function __construct(public SymbolSource $symbol) {}
}

/** @internal Test fixture for a source compiler that hides a symbol name. */
final readonly class HiddenSymbolSource implements Source
{
    public function __construct(
        public string $name,
        public SymbolSource $visible,
    ) {}
}

/** @internal Test fixture for a source compiler that types an embedded PHP value. */
final readonly class HostLiteralSource implements Source
{
    public function __construct(public mixed $value) {}
}

#[CoversClass(SourceCompilation::class)]
#[CoversClass(\Superscript\Axiom\Types\TypeInference::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[UsesClass(CompiledNode::class)]
#[UsesClass(Dialect::class)]
#[UsesClass(Expression::class)]
#[UsesClass(Extension::class)]
#[UsesClass(Runtime::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(\Superscript\Axiom\Program::class)]
#[UsesClass(\Superscript\Axiom\Bindings::class)]
#[UsesClass(\Superscript\Axiom\DefinitionGraph::class)]
#[UsesClass(\Superscript\Axiom\Definitions::class)]
#[UsesClass(\Superscript\Axiom\UnboundSymbols::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeEnvironment::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\Equality::class)]
#[UsesClass(\Superscript\Axiom\Operators\Has::class)]
#[UsesClass(\Superscript\Axiom\Operators\In::class)]
#[UsesClass(\Superscript\Axiom\Operators\Intersects::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithReturn::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(\Superscript\Axiom\Types\BooleanType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
final class SourceCompilationTest extends TestCase
{
    private static function compilation(
        ?Closure $compileNode = null,
        ?Closure $compileInfix = null,
        ?Closure $compilePrefix = null,
        ?Closure $compileSymbol = null,
        ?Closure $typeOfValue = null,
    ): SourceCompilation {
        return new SourceCompilation(
            $compileNode ?? fn(Source $source): Result => Err(new TypeMismatch('No source compilation expected.')),
            $compileInfix ?? fn(Type $left, string $operator, Type $right): Result => Err(new TypeMismatch('No infix operation expected.')),
            $compilePrefix ?? fn(string $operator, Type $operand): Result => Err(new TypeMismatch('No prefix operation expected.')),
            $compileSymbol ?? fn(SymbolSource $symbol): Result => Err(new TypeMismatch('No symbol expected.')),
            $typeOfValue ?? fn(mixed $value): Result => Err(new TypeMismatch('No value typing expected.')),
        );
    }

    #[Test]
    public function compile_delegates_one_source(): void
    {
        $source = new StaticSource(1);
        $seen = null;
        $node = new CompiledNode(new NumberType(), fn(Runtime $runtime) => Ok(Some(1)));
        $compilation = self::compilation(function (Source $candidate) use (&$seen, $node): Result {
            $seen = $candidate;

            return Ok($node);
        });

        $this->assertSame($node, $compilation->compile($source)->unwrap());
        $this->assertSame($source, $seen);
    }

    #[Test]
    public function compile_all_preserves_order_and_accepts_an_empty_list(): void
    {
        $compilation = self::compilation(fn(StaticSource $source): Result => Ok(
            new CompiledNode(new NumberType(), fn(Runtime $runtime) => Ok(Some($source->value))),
        ));

        $compiled = $compilation->compileAll([new StaticSource(1), new StaticSource(2)])->unwrap();

        $this->assertSame(1, $compiled[0]->evaluate(new Runtime())->unwrap()->unwrap());
        $this->assertSame(2, $compiled[1]->evaluate(new Runtime())->unwrap()->unwrap());
        $this->assertSame([], $compilation->compileAll([])->unwrap());
    }

    #[Test]
    public function compile_all_stops_at_the_first_refusal(): void
    {
        $calls = 0;
        $refusal = new TypeMismatch('second child is invalid');
        $compilation = self::compilation(function (Source $source) use (&$calls, $refusal): Result {
            $calls++;

            return $calls === 2
                ? Err($refusal)
                : Ok(new CompiledNode(new NumberType(), fn(Runtime $runtime) => Ok(Some(1))));
        });

        $result = $compilation->compileAll([new StaticSource(1), new StaticSource(2), new StaticSource(3)]);

        $this->assertSame($refusal, $result->unwrapErr());
        $this->assertSame(2, $calls);
    }

    #[Test]
    public function infix_delegates_the_operator_and_operand_types(): void
    {
        $left = new StringType();
        $right = new NumberType();
        $operation = new ResolvedOperation(new NumberType(), fn() => 1);
        $seen = null;
        $compilation = self::compilation(
            compileInfix: function (Type $actualLeft, string $operator, Type $actualRight) use (&$seen, $operation): Result {
                $seen = [$actualLeft, $operator, $actualRight];

                return Ok($operation);
            },
        );

        $this->assertSame($operation, $compilation->infix($left, '<=>', $right)->unwrap());
        $this->assertSame([$left, '<=>', $right], $seen);
    }

    #[Test]
    public function prefix_delegates_the_operator_and_operand_type(): void
    {
        $operand = new NumberType();
        $operation = new ResolvedOperation(new NumberType(), fn() => 1);
        $seen = null;
        $compilation = self::compilation(
            compilePrefix: function (string $operator, Type $actualOperand) use (&$seen, $operation): Result {
                $seen = [$operator, $actualOperand];

                return Ok($operation);
            },
        );

        $this->assertSame($operation, $compilation->prefix('-', $operand)->unwrap());
        $this->assertSame(['-', $operand], $seen);
    }

    #[Test]
    public function symbol_delegates_the_owned_source(): void
    {
        $node = new CompiledNode(new NumberType(), fn(Runtime $runtime) => Ok(Some(1)));
        $seen = [];
        $compilation = self::compilation(
            compileSymbol: function (SymbolSource $symbol) use (&$seen, $node): Result {
                $seen[] = $symbol;

                return Ok($node);
            },
        );

        $symbol = new SymbolSource('amount', 'billing');

        $this->assertSame($node, $compilation->symbol($symbol)->unwrap());
        $this->assertSame([$symbol], $seen);
    }

    #[Test]
    public function type_of_value_delegates_the_value(): void
    {
        $type = new NumberType();
        $seen = null;
        $compilation = self::compilation(
            typeOfValue: function (mixed $value) use (&$seen, $type): Result {
                $seen = $value;

                return Ok($type);
            },
        );

        $this->assertSame($type, $compilation->typeOfValue(42)->unwrap());
        $this->assertSame(42, $seen);
    }

    #[Test]
    public function a_host_compiler_binds_operations_from_the_composed_dialect(): void
    {
        $extension = new class extends Extension {
            public function operators(): array
            {
                return [
                    Operator::infix('at-most')
                        ->takes(new NumberType(), new NumberType())
                        ->returns(new \Superscript\Axiom\Types\BooleanType())
                        ->evaluatesWith(fn(int|float $left, int|float $right): bool => $left <= $right),
                ];
            }

            public function sourceCompilers(): array
            {
                return [
                    HostInfixSource::class => fn(HostInfixSource $source, SourceCompilation $compilation): Result => $compilation
                        ->infix($source->leftType, $source->operator, $source->rightType)
                        ->map(fn(ResolvedOperation $operation) => new CompiledNode(
                            $operation->returns,
                            fn(Runtime $runtime): Result => $operation
                                ->evaluate($source->left, $source->right)
                                ->map(Some(...)),
                        )),
                ];
            }
        };

        $program = (new Expression(
            new HostInfixSource(new NumberType(), 3, 'at-most', new NumberType(), 12),
            dialect: Dialect::core()->with($extension),
        ))->compile()->unwrap();

        $this->assertTrue($program()->unwrap()->unwrap());
    }

    #[Test]
    public function a_host_compiler_binds_prefix_operations_from_the_composed_dialect(): void
    {
        $extension = new class extends Extension {
            public function sourceCompilers(): array
            {
                return [
                    HostPrefixSource::class => fn(HostPrefixSource $source, SourceCompilation $compilation): Result => $compilation
                        ->prefix($source->operator, $source->operandType)
                        ->map(fn(ResolvedOperation $operation) => new CompiledNode(
                            $operation->returns,
                            fn(Runtime $runtime): Result => $operation
                                ->evaluate($source->operand)
                                ->map(Some(...)),
                        )),
                ];
            }
        };

        $program = (new Expression(
            new HostPrefixSource('-', new NumberType(), 7),
            dialect: Dialect::core()->with($extension),
        ))->compile()->unwrap();

        $this->assertSame(-7, $program()->unwrap()->unwrap());
    }

    #[Test]
    public function a_host_compiler_resolves_symbols_in_the_current_environment(): void
    {
        $extension = new class extends Extension {
            public function sourceCompilers(): array
            {
                return [
                    HostSymbolSource::class => fn(HostSymbolSource $source, SourceCompilation $compilation): Result => $compilation
                        ->symbol($source->symbol),
                ];
            }
        };

        $expression = new Expression(
            new HostSymbolSource(new SymbolSource('amount')),
            dialect: Dialect::core()->with($extension),
            declarations: ['amount' => new NumberType()],
        );
        $program = $expression->compile()->unwrap();

        $this->assertSame(['amount'], $expression->parameters());
        $this->assertInstanceOf(NumberType::class, $program->returns);
        $this->assertSame(42, $program(['amount' => 42])->unwrap()->unwrap());
    }

    #[Test]
    public function a_host_compiler_cannot_hide_a_symbol_dependency_from_source_analysis(): void
    {
        $extension = new class extends Extension {
            public function sourceCompilers(): array
            {
                return [
                    HiddenSymbolSource::class => fn(HiddenSymbolSource $source, SourceCompilation $compilation): Result => $compilation
                        ->symbol(new SymbolSource($source->name)),
                ];
            }
        };

        $expression = new Expression(
            new HiddenSymbolSource('amount', new SymbolSource('amount', 'billing')),
            dialect: Dialect::core()->with($extension),
            declarations: ['amount' => new NumberType()],
        );
        $result = $expression->compile();

        $this->assertSame(['billing.amount'], $expression->parameters());
        $this->assertStringContainsString(
            'symbol dependencies belong in the persisted source tree',
            $result->unwrapErr()->message,
        );
    }

    #[Test]
    public function a_host_compiler_types_embedded_values_literal_first(): void
    {
        $extension = new class extends Extension {
            public function sourceCompilers(): array
            {
                return [
                    HostLiteralSource::class => fn(HostLiteralSource $source, SourceCompilation $compilation): Result => $compilation
                        ->typeOfValue($source->value)
                        ->map(fn(Type $type) => new CompiledNode(
                            $type,
                            fn(Runtime $runtime): Result => Ok(Some($source->value)),
                        )),
                ];
            }
        };

        $program = (new Expression(
            new HostLiteralSource(5),
            dialect: Dialect::core()->with($extension),
        ))->compile()->unwrap();

        $this->assertInstanceOf(\Superscript\Axiom\Types\LiteralType::class, $program->returns);
        $this->assertSame(5, $program()->unwrap()->unwrap());
    }

    #[Test]
    public function a_host_compiler_can_compile_dynamic_children_without_bootstrapping_the_compiler(): void
    {
        $extension = new class extends Extension {
            public function sourceCompilers(): array
            {
                return [
                    FirstSource::class => function (FirstSource $source, SourceCompilation $compilation): Result {
                        $children = $compilation->compileAll($source->filters);

                        if ($children->isErr()) {
                            return $children;
                        }

                        $first = $children->unwrap()[0];

                        return Ok(new CompiledNode(
                            $first->returns,
                            fn(Runtime $runtime): Result => $first->evaluate($runtime),
                        ));
                    },
                ];
            }
        };

        $program = (new Expression(
            new FirstSource([new SymbolSource('amount'), new StaticSource(0)]),
            dialect: Dialect::core()->with($extension),
            declarations: ['amount' => new NumberType()],
        ))->compile()->unwrap();

        $this->assertSame(42, $program(['amount' => 42])->unwrap()->unwrap());
    }

    #[Test]
    public function persisted_sources_do_not_carry_their_live_dependency(): void
    {
        $stored = serialize(new CountingSource(7));
        $source = unserialize($stored, ['allowed_classes' => [CountingSource::class]]);
        $counter = new EvaluationCounter();

        $program = (new Expression(
            $source,
            dialect: Dialect::core()->with(new SourceCompilerExtension($counter)),
        ))->compile()->unwrap();

        $this->assertSame(0, $counter->evaluations, 'compilation does not execute the dependency');
        $this->assertSame(7, $program()->unwrap()->unwrap());
        $this->assertSame(1, $counter->evaluations);
    }

    #[Test]
    public function source_compiler_ownership_does_not_extend_to_subclasses(): void
    {
        $extension = new class extends Extension {
            public function sourceCompilers(): array
            {
                return [
                    ParentHostSource::class => fn(ParentHostSource $source, SourceCompilation $compilation): Result => Ok(
                        new CompiledNode(new NumberType(), fn(Runtime $runtime) => Ok(Some(1))),
                    ),
                ];
            }
        };

        $result = (new Expression(
            new ChildHostSource(),
            dialect: Dialect::core()->with($extension),
        ))->compile();

        $this->assertStringContainsString(ChildHostSource::class, $result->unwrapErr()->message);
    }
}
