<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\BoundOperation;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\CompiledSources;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Exceptions\EvaluationAborted;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Fields\Field;
use Superscript\Axiom\Fields\OpaqueField;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Tests\Fixtures\CountingSource;
use Superscript\Axiom\Tests\Fixtures\EvaluationCounter;
use Superscript\Axiom\Tests\Fixtures\SourceCompilerExtension;
use Superscript\Axiom\Tests\Fixtures\SpyObserver;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/** @internal Test fixture for a host source that owns other sources. */
final readonly class FirstSource implements Source
{
    /** @param list<Source> $filters */
    public function __construct(public array $filters) {}

    public function children(): iterable
    {
        return $this->filters;
    }
}

/** @internal Test fixture proving source compiler ownership is exact-class. */
class ParentHostSource implements Source
{
    public function children(): iterable
    {
        return [];
    }
}

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

    public function children(): iterable
    {
        return [];
    }
}

/** @internal Test fixture for a source compiler that binds a prefix operator. */
final readonly class HostPrefixSource implements Source
{
    public function __construct(
        public string $operator,
        public Type $operandType,
        public mixed $operand,
    ) {}

    public function children(): iterable
    {
        return [];
    }
}

/** @internal Test fixture for a source compiler that owns a symbol reference. */
final readonly class HostSymbolSource implements Source
{
    public function __construct(public SymbolSource $symbol) {}

    public function children(): iterable
    {
        return [$this->symbol];
    }
}

/** @internal Test fixture for a source compiler that hides a symbol name. */
final readonly class HiddenSymbolSource implements Source
{
    public function __construct(
        public string $name,
        public SymbolSource $visible,
    ) {}

    public function children(): iterable
    {
        return [$this->visible];
    }
}

/** @internal Test fixture for a source compiler that types an embedded PHP value. */
final readonly class HostLiteralSource implements Source
{
    public function __construct(public mixed $value) {}

    public function children(): iterable
    {
        return [];
    }
}

#[CoversClass(SourceCompilation::class)]
#[CoversClass(CompiledSource::class)]
#[CoversClass(CompiledSources::class)]
#[CoversClass(SourceEvaluation::class)]
#[CoversClass(BoundOperation::class)]
#[CoversClass(CompilationAborted::class)]
#[CoversClass(\Superscript\Axiom\Exceptions\EvaluationAborted::class)]
#[CoversClass(\Superscript\Axiom\Types\TypeInference::class)]
#[CoversClass(\Superscript\Axiom\Analysis\CompilationRecorder::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\ConstantNode::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\StaticSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\SymbolSourceCompiler::class)]
#[CoversClass(CompiledNode::class)]
#[UsesClass(Dialect::class)]
#[UsesClass(Expression::class)]
#[UsesClass(Extension::class)]
#[UsesClass(Runtime::class)]
#[UsesClass(\Superscript\Axiom\Execution\Annotated::class)]
#[UsesClass(\Superscript\Axiom\Execution\Entered::class)]
#[UsesClass(\Superscript\Axiom\Execution\Exited::class)]
#[UsesClass(\Superscript\Axiom\Execution\Node::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(\Superscript\Axiom\Program::class)]
#[UsesClass(\Superscript\Axiom\Bindings::class)]
#[UsesClass(\Superscript\Axiom\DefinitionGraph::class)]
#[UsesClass(\Superscript\Axiom\Definitions::class)]
#[UsesClass(\Superscript\Axiom\UnboundSymbols::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeEnvironment::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Fields\OpaqueFieldRegistry::class)]
#[UsesClass(\Superscript\Axiom\Fields\Field::class)]
#[UsesClass(\Superscript\Axiom\Fields\FieldBuilder::class)]
#[UsesClass(\Superscript\Axiom\Fields\NamedFieldBuilder::class)]
#[UsesClass(\Superscript\Axiom\Fields\TypedFieldBuilder::class)]
#[UsesClass(\Superscript\Axiom\Fields\OpaqueField::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\Coalesce::class)]
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
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeReifier::class)]
#[UsesClass(\Superscript\Axiom\Types\BooleanType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom\\Analysis')]
#[UsesClass(\Superscript\Axiom\Operators\Connective::class)]
#[UsesClass(\Superscript\Axiom\Types\PresentType::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnsupportedOperation::class)]
#[UsesClass(\Superscript\Axiom\Types\InfixExpressionTyping::class)]
final class SourceCompilationTest extends TestCase
{
    private static function compilation(
        ?Closure $compileNode = null,
        ?Closure $compileInfix = null,
        ?Closure $compilePrefix = null,
        ?Closure $compileSymbol = null,
        ?Closure $typeOfValue = null,
        ?\Superscript\Axiom\Analysis\CompilationRecorder $recorder = null,
        ?Closure $resolveOpaqueField = null,
    ): SourceCompilation {
        return new SourceCompilation(
            $compileNode ?? fn(Source $source): Result => Err(new TypeMismatch('No source compilation expected.')),
            $compileInfix ?? fn(Type $left, string $operator, Type $right): Result => Err(new TypeMismatch('No infix operation expected.')),
            $compilePrefix ?? fn(string $operator, Type $operand): Result => Err(new TypeMismatch('No prefix operation expected.')),
            $compileSymbol ?? fn(SymbolSource $symbol): Result => Err(new TypeMismatch('No symbol expected.')),
            $typeOfValue ?? fn(mixed $value): Result => Err(new TypeMismatch('No value typing expected.')),
            $resolveOpaqueField,
            $recorder,
        );
    }

    #[Test]
    public function opaque_field_resolves_to_null_without_a_resolver(): void
    {
        $this->assertNull(self::compilation()->opaqueField('money', 'amount'));
    }

    #[Test]
    public function opaque_field_delegates_to_the_resolver(): void
    {
        $amount = Field::on('money')->named('amount')->returns(new NumberType())
            ->extractedWith(fn(mixed $value): int => 0);
        $compilation = self::compilation(resolveOpaqueField: fn(string $identity, string $name): ?OpaqueField => $identity === 'money' && $name === 'amount' ? $amount : null);

        $this->assertSame($amount, $compilation->opaqueField('money', 'amount'));
        $this->assertNull($compilation->opaqueField('money', 'currency'));
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

        $this->assertSame($node, $compilation->child($source)->node());
        $this->assertSame($source, $seen);
    }

    #[Test]
    public function compile_all_preserves_order_and_accepts_an_empty_list(): void
    {
        $compilation = self::compilation(fn(StaticSource $source): Result => Ok(
            new CompiledNode(new NumberType(), fn(Runtime $runtime) => Ok(Some($source->value))),
        ));

        $compiled = $compilation->children([
            'first' => new StaticSource(1),
            'second' => new StaticSource(2),
        ])->mapPresent(new NumberType(), fn(int $first, int $second) => $first + $second);

        $this->assertSame(3, $compiled->node()->evaluate(new Runtime())->unwrap()->unwrap());
        $this->assertNull(
            $compilation->children([])
                ->mapPresent(new NumberType(), fn() => null)
                ->node()->evaluate(new Runtime())->unwrap()->unwrapOr(null),
        );
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

        try {
            $compilation->children([
                'first' => new StaticSource(1),
                'second' => new StaticSource(2),
                'third' => new StaticSource(3),
            ]);
            $this->fail('The second child should abort compilation.');
        } catch (CompilationAborted $aborted) {
            $this->assertSame($refusal, $aborted->mismatch);
        }
        $this->assertSame(2, $calls);
    }

    #[Test]
    public function infix_delegates_the_operator_and_operand_types(): void
    {
        $left = new StringType();
        $right = new NumberType();
        $operation = new ResolvedOperation(new NumberType(), fn() => 1);
        $recorder = new \Superscript\Axiom\Analysis\CompilationRecorder();
        $seen = null;
        $compilation = self::compilation(
            compileInfix: function (Type $actualLeft, string $operator, Type $actualRight) use (&$seen, $operation): Result {
                $seen = [$actualLeft, $operator, $actualRight];

                return Ok($operation);
            },
            recorder: $recorder,
        );

        $bound = $compilation->infix($left, '<=>', $right);

        $this->assertInstanceOf(BoundOperation::class, $bound);
        $this->assertSame($operation->returns, $bound->returns);
        $this->assertSame(1, $bound());
        $this->assertSame([$left, '<=>', $right], $seen);
        $this->assertSame([[
            'path' => '$.operators[0]',
            'kind' => 'infix',
            'operator' => '<=>',
            'operands' => ['String', 'Number'],
            'returns' => 'Number',
            'rule' => [
                'identifier' => 'unattributed',
                'implementation' => ResolvedOperation::class,
                'extension' => null,
            ],
        ]], array_map(
            fn(\Superscript\Axiom\Analysis\OperatorSelection $selection): array => $selection->toArray('$.operators[0]'),
            $recorder->operators(),
        ));

        $withoutRecorder = self::compilation(
            compileInfix: fn(Type $actualLeft, string $operator, Type $actualRight): Result => Ok($operation),
        );

        $this->assertSame(1, ($withoutRecorder->infix($left, '<=>', $right))());
    }

    #[Test]
    public function prefix_delegates_the_operator_and_operand_type(): void
    {
        $operand = new NumberType();
        $operation = new ResolvedOperation(new NumberType(), fn() => 1);
        $recorder = new \Superscript\Axiom\Analysis\CompilationRecorder();
        $seen = null;
        $compilation = self::compilation(
            compilePrefix: function (string $operator, Type $actualOperand) use (&$seen, $operation): Result {
                $seen = [$operator, $actualOperand];

                return Ok($operation);
            },
            recorder: $recorder,
        );

        $this->assertSame(1, ($compilation->prefix('-', $operand))());
        $this->assertSame(['-', $operand], $seen);
        $this->assertSame([[
            'path' => '$.operators[0]',
            'kind' => 'prefix',
            'operator' => '-',
            'operands' => ['Number'],
            'returns' => 'Number',
            'rule' => [
                'identifier' => 'unattributed',
                'implementation' => ResolvedOperation::class,
                'extension' => null,
            ],
        ]], array_map(
            fn(\Superscript\Axiom\Analysis\OperatorSelection $selection): array => $selection->toArray('$.operators[0]'),
            $recorder->operators(),
        ));
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

        $this->assertSame($node, $compilation->symbol($symbol)->node());
        $this->assertSame([$symbol], $seen);
    }

    #[Test]
    public function analyzed_children_and_definitions_are_recorded_with_their_roles(): void
    {
        $source = new StaticSource(1);
        $analysis = new \Superscript\Axiom\Analysis\CompilationNode(
            StaticSource::class,
            new NumberType(),
            'axiom.core',
        );
        $node = (new CompiledNode(
            new NumberType(),
            fn(Runtime $runtime) => Ok(Some(1)),
            references: ['stale'],
        ))
            ->forSource($source, $analysis, ['amount']);
        $recorder = new \Superscript\Axiom\Analysis\CompilationRecorder();
        $compilation = self::compilation(
            compileNode: fn(Source $candidate): Result => Ok($node),
            compileSymbol: fn(SymbolSource $symbol): Result => Ok($node),
            recorder: $recorder,
        );

        $compilation->child($source, 'operand');
        $compilation->children([$source]);
        $compilation->symbol(new SymbolSource('defined'));

        $this->assertSame(['operand', '0', 'definition'], array_map(
            fn(\Superscript\Axiom\Analysis\CompilationChild $child): ?string => $child->role,
            $recorder->children(),
        ));
        $this->assertSame(['amount'], $recorder->references());
    }

    #[Test]
    public function a_child_is_compiled_at_the_path_its_position_gives_it(): void
    {
        $source = new StaticSource(1);
        $analysis = new \Superscript\Axiom\Analysis\CompilationNode(
            StaticSource::class,
            new NumberType(),
            'axiom.core',
        );
        $node = (new CompiledNode(new NumberType(), fn(Runtime $runtime) => Ok(Some(1))))
            ->forSource($source, $analysis);
        $paths = [];
        $record = function (string $path) use (&$paths, $node): Result {
            $paths[] = $path;

            return Ok($node);
        };

        $located = self::compilation(
            compileNode: fn(Source $candidate, string $path): Result => $record($path),
            compileSymbol: fn(SymbolSource $symbol, string $path): Result => $record($path),
            recorder: new \Superscript\Axiom\Analysis\CompilationRecorder('$.children[7].node'),
        );

        $located->child($source);
        $located->child($source);
        $located->symbol(new SymbolSource('defined'));

        $this->assertSame([
            '$.children[7].node.children[0].node',
            '$.children[7].node.children[1].node',
            '$.children[7].node.children[2].node',
        ], $paths, 'each child is numbered off the children recorded before it');

        // Without a recorder there is no numbering to descend from, so the
        // child compiles as its own root.
        $paths = [];
        self::compilation(compileNode: fn(Source $candidate, string $path): Result => $record($path))
            ->child($source);

        $this->assertSame(['$'], $paths);
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

        $this->assertSame($type, $compilation->typeOfValue(42));
        $this->assertSame(42, $seen);
    }

    #[Test]
    public function compiled_sources_compose_present_and_absent_values_without_runtime_plumbing(): void
    {
        $type = new NumberType();
        $calls = 0;

        $present = CompiledSource::constant($type, 2)
            ->expectPresent($type)
            ->mapPresent($type, fn(int $value): Result => Ok($value * 2));

        $this->assertSame($type, $present->returns);
        $this->assertSame(4, $present->node()->evaluate(new Runtime())->unwrap()->unwrap());

        $absent = CompiledSource::constant(new OptionType($type), null)
            ->expectPresent($type)
            ->mapPresent($type, function () use (&$calls) {
                $calls++;

                return 1;
            });

        $this->assertTrue($absent->node()->evaluate(new Runtime())->unwrap()->isNone());
        $this->assertInstanceOf(OptionType::class, $absent->returns);
        $this->assertSame($type, $absent->returns->inner);
        $this->assertTrue(TypeRelations::admits($absent->returns, $type)->isErr());
        $this->assertSame(0, $calls);

        $optionalReturns = new OptionType($type);
        $alreadyOptional = CompiledSource::constant(new OptionType($type), null)
            ->mapPresent($optionalReturns, fn(int $value) => $value);
        $this->assertSame($optionalReturns, $alreadyOptional->returns);

        $includingAbsent = CompiledSource::constant(new OptionType($type), null)
            ->mapIncludingAbsent($type, fn(mixed $value) => $value ?? 7);

        $this->assertSame(7, $includingAbsent->node()->evaluate(new Runtime())->unwrap()->unwrap());
    }

    #[Test]
    public function a_present_value_can_be_certified_before_a_native_callback_receives_it(): void
    {
        $source = CompiledSource::constant(new StringType(), 'not a number');

        try {
            $source->expectPresent(new NumberType());
            $this->fail('The incompatible present value should refuse compilation.');
        } catch (CompilationAborted $aborted) {
            $this->assertStringContainsString('must provide Number when present', $aborted->mismatch->message);
            $this->assertCount(1, $aborted->mismatch->causes);
        }
    }

    #[Test]
    public function compiled_children_combine_by_name_with_explicit_absence_semantics(): void
    {
        $type = new NumberType();
        $compilation = self::compilation();
        $rightCalls = 0;
        $allPresent = $compilation->combine([
            'left' => CompiledSource::constant($type, 1),
            'right' => CompiledSource::constant($type, 2),
        ])->mapPresent($type, fn(int $left, int $right) => $left + $right);

        $this->assertSame($type, $allPresent->returns);

        $left = CompiledSource::constant(new OptionType($type), null);
        $right = CompiledSource::custom($type, function () use (&$rightCalls) {
            $rightCalls++;

            return 2;
        });

        $present = $compilation->combine(['left' => $left, 'right' => $right])
            ->mapPresent($type, fn(int $left, int $right) => $left + $right);

        $this->assertTrue($present->node()->evaluate(new Runtime())->unwrap()->isNone());
        $this->assertInstanceOf(OptionType::class, $present->returns);
        $this->assertSame($type, $present->returns->inner);
        $this->assertSame(0, $rightCalls, 'the first absence short-circuits later present-only children');

        $optionalReturns = new OptionType($type);
        $alreadyOptional = $compilation->combine(['left' => $left, 'right' => $right])
            ->mapPresent($optionalReturns, fn(int $left, int $right) => $left + $right);
        $this->assertSame($optionalReturns, $alreadyOptional->returns);

        $includingAbsent = $compilation->combine(['left' => $left, 'right' => $right])
            ->mapIncludingAbsent($type, fn(?int $left, int $right) => ($left ?? 0) + $right);

        $this->assertSame(2, $includingAbsent->node()->evaluate(new Runtime())->unwrap()->unwrap());
        $this->assertSame(1, $rightCalls);
    }

    #[Test]
    public function compiled_sources_apply_bound_operations_without_exposing_operation_results(): void
    {
        $type = new NumberType();
        $double = new BoundOperation(new ResolvedOperation($type, fn(int $value) => $value * 2));
        $sumAbsentAsZero = new BoundOperation(new ResolvedOperation(
            $type,
            fn(?int $a, ?int $b) => ($a ?? 0) + ($b ?? 0),
        ));

        $unary = CompiledSource::constant($type, 3)->apply($double);
        $this->assertSame(6, $unary->node()->evaluate(new Runtime())->unwrap()->unwrap());

        $overriddenReturns = new StringType();
        $overridden = CompiledSource::constant($type, 3)->apply($double, $overriddenReturns);
        $this->assertSame($overriddenReturns, $overridden->returns);

        $binary = new CompiledSources([
            'left' => CompiledSource::constant(new OptionType($type), null),
            'right' => CompiledSource::constant($type, 4),
        ]);
        $this->assertSame(
            4,
            $binary->applyIncludingAbsent($sumAbsentAsZero)->node()->evaluate(new Runtime())->unwrap()->unwrap(),
        );
        $this->assertSame(3, $sumAbsentAsZero(...['left' => 1, 'right' => 2]));
    }

    #[Test]
    public function expected_child_and_operation_failures_exit_normally_but_defects_throw(): void
    {
        $failure = new \RuntimeException('expected failure');
        $aborted = new EvaluationAborted($failure);
        $this->assertSame('expected failure', $aborted->getMessage());
        $this->assertSame($failure, $aborted->getPrevious());

        $type = new NumberType();
        $child = CompiledSource::custom($type, fn() => Err($failure));
        $parent = CompiledSource::custom($type, fn(SourceEvaluation $evaluation) => $evaluation->value($child));

        $this->assertSame($failure, $parent->node()->evaluate(new Runtime())->unwrapErr());

        $operation = new BoundOperation(new ResolvedOperation($type, fn() => Err($failure)));
        $applied = CompiledSource::custom($type, fn() => $operation());
        $this->assertSame($failure, $applied->node()->evaluate(new Runtime())->unwrapErr());

        $defect = new \LogicException('compiler defect');
        $throwing = CompiledSource::custom($type, fn() => throw $defect);

        $this->expectExceptionObject($defect);
        $throwing->node()->evaluate(new Runtime());
    }

    #[Test]
    public function source_compilation_can_frame_and_explicitly_reject_a_source(): void
    {
        $compilation = self::compilation();

        try {
            $compilation->within('The host source cannot compile.', fn() => $compilation->reject('The child is invalid.'));
            $this->fail('The explicit refusal should abort compilation.');
        } catch (CompilationAborted $aborted) {
            $this->assertSame('The host source cannot compile.', $aborted->getMessage());
            $this->assertSame('The host source cannot compile.', $aborted->mismatch->message);
            $this->assertSame('The child is invalid.', $aborted->mismatch->causes[0]->message);
        }

        $observer = new SpyObserver();
        $custom = $compilation->custom(new NumberType(), function (SourceEvaluation $evaluation) {
            $evaluation->annotate('host', 'custom');

            return 9;
        });
        $this->assertSame(9, $custom->node()->evaluate(new Runtime(observer: $observer))->unwrap()->unwrap());
        $this->assertSame('custom', $observer->annotations['host']);
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
                    HostInfixSource::class => function (HostInfixSource $source, SourceCompilation $compilation): CompiledSource {
                        $operation = $compilation->infix($source->leftType, $source->operator, $source->rightType);

                        return $compilation->produces(
                            $operation->returns,
                            fn() => $operation($source->left, $source->right),
                        );
                    },
                ];
            }
        };

        $program = (new Expression(
            new HostInfixSource(new NumberType(), 3, 'at-most', new NumberType(), 12),
            dialect: Dialect::core()->with($extension),
        ))->compile()->unwrap();

        $this->assertTrue($program()->unwrap()->unwrap());
        $this->assertSame('infix', $program->analysis->operators()[0]->selection->kind);
        $this->assertSame(['Number', 'Number'], $program->analysis->operators()[0]->selection->toArray('$')['operands']);
        $this->assertSame($extension::class, $program->analysis->operators()[0]->selection->rule->extension);
    }

    #[Test]
    public function a_host_compiler_binds_prefix_operations_from_the_composed_dialect(): void
    {
        $extension = new class extends Extension {
            public function sourceCompilers(): array
            {
                return [
                    HostPrefixSource::class => function (HostPrefixSource $source, SourceCompilation $compilation): CompiledSource {
                        $operation = $compilation->prefix($source->operator, $source->operandType);

                        return $compilation->produces($operation->returns, fn() => $operation($source->operand));
                    },
                ];
            }
        };

        $program = (new Expression(
            new HostPrefixSource('-', new NumberType(), 7),
            dialect: Dialect::core()->with($extension),
        ))->compile()->unwrap();

        $this->assertSame(-7, $program()->unwrap()->unwrap());
        $this->assertSame('prefix', $program->analysis->operators()[0]->selection->kind);
        $this->assertSame(['Number'], $program->analysis->operators()[0]->selection->toArray('$')['operands']);
        $this->assertSame('axiom.core', $program->analysis->operators()[0]->selection->rule->extension);
    }

    #[Test]
    public function a_host_compiler_resolves_symbols_in_the_current_environment(): void
    {
        $extension = new class extends Extension {
            public function sourceCompilers(): array
            {
                return [
                    HostSymbolSource::class => fn(HostSymbolSource $source, SourceCompilation $compilation): CompiledSource => $compilation
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
        $this->assertSame(['amount'], $program->references);
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
                    HiddenSymbolSource::class => fn(HiddenSymbolSource $source, SourceCompilation $compilation): CompiledSource => $compilation
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
                    HostLiteralSource::class => fn(HostLiteralSource $source, SourceCompilation $compilation): CompiledSource => $compilation
                        ->constant($compilation->typeOfValue($source->value), $source->value),
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
                    FirstSource::class => function (FirstSource $source, SourceCompilation $compilation): CompiledSource {
                        return $compilation->children([
                            'first' => $source->filters[0],
                            'fallback' => $source->filters[1],
                        ])->mapPresent(new NumberType(), fn(int|float $first, int|float $fallback) => $first);
                    },
                ];
            }
        };

        $program = (new Expression(
            new FirstSource([new SymbolSource('amount'), new StaticSource(0)]),
            dialect: Dialect::core()->with($extension),
            declarations: ['amount' => new NumberType()],
        ))->compile()->unwrap();

        $this->assertSame(['amount'], $program->references);
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
                    ParentHostSource::class => fn(ParentHostSource $source, SourceCompilation $compilation): CompiledSource => $compilation
                        ->constant(new NumberType(), 1),
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
