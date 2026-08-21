<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\CompiledScopedExpression;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Boundary;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Exceptions\InadmissibleBinding;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\ScopedExpression;
use Superscript\Axiom\Tests\Fixtures\SpyObserver;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\Optional;
use Superscript\Axiom\Types\PresentType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;

/** @internal Host fixture: whether any item satisfies one lexically scoped predicate. */
final readonly class ScopedAnySource implements Source
{
    public function __construct(
        public Source $items,
        public ScopedExpression $predicate,
    ) {}
}

/** @internal Host fixture that proves a nested body uses the host dialect once and evaluates repeatedly. */
final readonly class CountedPredicateSource implements Source
{
    public function __construct(public Source $body) {}
}

/** @internal Host fixture for a value-dependent failure inside a scoped predicate. */
final readonly class FailingPredicateSource implements Source
{
    public function __construct(public RuntimeException $failure) {}
}

/** @internal Host fixture exposing direct scoped-expression invocation cases. */
final readonly class InvokeScopedExpressionSource implements Source
{
    /**
     * @param array<string, Type> $parameterTypes
     * @param array<string, mixed> $bindings
     */
    public function __construct(
        public ScopedExpression $expression,
        public array $parameterTypes,
        public array $bindings,
        public ?Type $expectedPresent = null,
    ) {}
}

/** @internal Host fixture returning raw data under one declared type. */
final readonly class DeclaredSource implements Source
{
    public function __construct(
        public Type $returns,
        public mixed $value,
    ) {}
}

/** @internal Dialect fixture implementing the callers the scoped-expression interface is for. */
final class ScopedExpressionExtension extends Extension
{
    public int $anyCompilations = 0;

    public int $predicateCompilations = 0;

    public int $predicateEvaluations = 0;

    public function sourceCompilers(): array
    {
        return [
            ScopedAnySource::class => $this->compileAny(...),
            CountedPredicateSource::class => $this->compileCountedPredicate(...),
            FailingPredicateSource::class => $this->compileFailingPredicate(...),
            InvokeScopedExpressionSource::class => $this->compileInvocation(...),
            DeclaredSource::class => $this->compileDeclared(...),
        ];
    }

    private function compileAny(ScopedAnySource $source, SourceCompilation $compilation): CompiledSource
    {
        $this->anyCompilations++;
        $items = $compilation->child($source->items, 'items');
        $itemsType = PresentType::of($items->returns);

        if (!$itemsType instanceof ListType) {
            $compilation->reject('Any needs a list.');
        }

        if (count($source->predicate->parameters) !== 1) {
            $compilation->reject('Any needs exactly one predicate parameter.');
        }

        $parameter = $source->predicate->parameters[0];
        $predicate = $compilation
            ->scope($source->predicate, [$parameter => $itemsType->type], 'predicate')
            ->expectPresent(new BooleanType());

        return $compilation->custom(new BooleanType(), static function (SourceEvaluation $evaluation) use ($items, $parameter, $predicate): bool {
            foreach ($evaluation->value($items) ?? [] as $item) {
                if ($evaluation->invoke($predicate, [$parameter => $item]) === true) {
                    return true;
                }
            }

            return false;
        });
    }

    private function compileCountedPredicate(CountedPredicateSource $source, SourceCompilation $compilation): CompiledSource
    {
        $this->predicateCompilations++;
        $body = $compilation->child($source->body, 'body');

        return $compilation->custom(
            $body->returns,
            function (SourceEvaluation $evaluation) use ($body): mixed {
                $this->predicateEvaluations++;

                return $evaluation->value($body);
            },
        );
    }

    private function compileFailingPredicate(FailingPredicateSource $source, SourceCompilation $compilation): CompiledSource
    {
        return $compilation->custom(new BooleanType(), fn(): Result => Err($source->failure));
    }

    private function compileInvocation(InvokeScopedExpressionSource $source, SourceCompilation $compilation): CompiledSource
    {
        $scoped = $compilation->scope($source->expression, $source->parameterTypes, 'expression');

        if ($source->expectedPresent !== null) {
            $scoped->expectPresent($source->expectedPresent);
        }

        return $compilation->custom(
            $scoped->returns,
            static fn(SourceEvaluation $evaluation): mixed => $evaluation->invoke($scoped, $source->bindings),
        );
    }

    private function compileDeclared(DeclaredSource $source, SourceCompilation $compilation): CompiledSource
    {
        return $compilation->custom($source->returns, static fn(): mixed => $source->value);
    }
}

#[CoversClass(ScopedExpression::class)]
#[CoversClass(CompiledScopedExpression::class)]
#[CoversClass(\Superscript\Axiom\LocalScope::class)]
#[CoversClass(SourceCompilation::class)]
#[CoversClass(SourceEvaluation::class)]
#[CoversClass(\Superscript\Axiom\InputBoundary::class)]
#[CoversClass(\Superscript\Axiom\Types\TypeEnvironment::class)]
#[CoversClass(\Superscript\Axiom\Types\TypeInference::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom')]
#[UsesClass(\Superscript\Axiom\CompiledNode::class)]
#[UsesClass(CompiledSource::class)]
#[UsesClass(\Superscript\Axiom\CompiledSources::class)]
#[UsesClass(\Superscript\Axiom\Program::class)]
#[UsesClass(\Superscript\Axiom\Runtime::class)]
#[UsesClass(\Superscript\Axiom\Bindings::class)]
#[UsesClass(\Superscript\Axiom\Definitions::class)]
#[UsesClass(\Superscript\Axiom\Analysis\CompilationAnalysis::class)]
#[UsesClass(\Superscript\Axiom\Analysis\CompilationNode::class)]
#[UsesClass(\Superscript\Axiom\Analysis\CompilationRecorder::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[UsesClass(Dialect::class)]
#[UsesClass(Expression::class)]
#[UsesClass(Extension::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(MemberAccessSource::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(\Superscript\Axiom\BoundOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\Connective::class)]
#[UsesClass(\Superscript\Axiom\Operators\Equality::class)]
#[UsesClass(\Superscript\Axiom\Operators\Coalesce::class)]
#[UsesClass(\Superscript\Axiom\Operators\Has::class)]
#[UsesClass(\Superscript\Axiom\Operators\In::class)]
#[UsesClass(\Superscript\Axiom\Operators\Intersects::class)]
#[UsesClass(\Superscript\Axiom\Operators\ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\ConstantNode::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\InfixExpressionCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\MemberAccessSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\StaticSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\SymbolSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\Types\BooleanType::class)]
#[UsesClass(ListType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\NumberType::class)]
#[UsesClass(\Superscript\Axiom\Types\PresentType::class)]
#[UsesClass(RecordType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeReifier::class)]
#[UsesClass(\Superscript\Axiom\Types\OptionType::class)]
#[UsesClass(\Superscript\Axiom\Types\NeverType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ListShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordShape::class)]
#[UsesClass(\Superscript\Axiom\Execution\Annotated::class)]
#[UsesClass(\Superscript\Axiom\Execution\Entered::class)]
#[UsesClass(\Superscript\Axiom\Execution\Exited::class)]
#[UsesClass(\Superscript\Axiom\Execution\Node::class)]
#[UsesClass(\Superscript\Axiom\Fields\OpaqueFieldRegistry::class)]
final class ScopedExpressionCompilationTest extends TestCase
{
    #[Test]
    public function a_local_scope_refuses_reads_recorded_after_its_boundary_is_built(): void
    {
        $scope = new \Superscript\Axiom\LocalScope();
        $reference = new ReferencePath('item', 'score');
        $scope->record($reference);

        $this->assertEquals([$reference], $scope->seal());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('after its input boundary is sealed');

        $scope->record(new ReferencePath('item', 'late'));
    }

    #[Test]
    public function a_scoped_record_is_admitted_through_only_the_properties_its_body_reads(): void
    {
        $record = new RecordType([
            'score' => new \Superscript\Axiom\Types\NumberType(),
            'unread' => new \Superscript\Axiom\Types\StringType(),
        ]);
        $program = $this->invocationExpression(new InvokeScopedExpressionSource(
            new ScopedExpression(['item'], new ReferencePath('item', 'score')),
            ['item' => $record],
            ['item' => ['score' => '2']],
        ))->compile()->unwrap();

        $this->assertSame(2, $program()->unwrap()->unwrap());
    }

    #[Test]
    public function a_missing_required_scoped_property_makes_that_invocation_absent(): void
    {
        $program = $this->invocationExpression(new InvokeScopedExpressionSource(
            new ScopedExpression(['item'], new ReferencePath('item', 'score')),
            ['item' => new RecordType(['score' => new \Superscript\Axiom\Types\NumberType()])],
            ['item' => []],
        ))->compile()->unwrap();

        $this->assertTrue($program()->unwrap()->isNone());
    }

    #[Test]
    public function an_invalid_present_scoped_property_remains_a_boundary_fault(): void
    {
        $program = $this->invocationExpression(new InvokeScopedExpressionSource(
            new ScopedExpression(['item'], new ReferencePath('item', 'details', 'score')),
            ['item' => new RecordType([
                'details' => new RecordType(['score' => new \Superscript\Axiom\Types\NumberType()]),
            ])],
            ['item' => ['details' => ['score' => 'not a number']]],
        ))->compile()->unwrap();

        $failure = $program()->unwrapErr();

        $this->assertInstanceOf(InadmissibleBinding::class, $failure);
        $this->assertSame('item.details.score', $failure->rejections[0]->input);
        $this->assertStringContainsString('binding [item.details.score]', $failure->getMessage());
    }

    #[Test]
    public function a_scoped_opaque_member_read_admits_the_declared_root_value(): void
    {
        $fieldOwner = new class extends Extension {
            public function fields(): array
            {
                return [
                    \Superscript\Axiom\Fields\Field::on('money')->named('amount')
                        ->returns(new \Superscript\Axiom\Types\NumberType())
                        ->extractedWith(fn(\Superscript\Axiom\Tests\Fixtures\Money $money): int => $money->minor),
                ];
            }
        };
        $source = new InvokeScopedExpressionSource(
            new ScopedExpression(['item'], new ReferencePath('item', 'amount')),
            ['item' => new \Superscript\Axiom\Tests\Fixtures\MoneyType('GBP')],
            ['item' => new \Superscript\Axiom\Tests\Fixtures\Money(500, 'GBP')],
        );
        $program = (new Expression(
            $source,
            dialect: Dialect::core()->with(new ScopedExpressionExtension(), $fieldOwner),
        ))->compile()->unwrap();

        $this->assertSame(500, $program()->unwrap()->unwrap());
    }

    #[Test]
    public function an_invalid_present_sibling_is_a_fault_when_a_nested_required_property_is_missing(): void
    {
        $number = new \Superscript\Axiom\Types\NumberType();
        $program = $this->invocationExpression(new InvokeScopedExpressionSource(
            new ScopedExpression(['item'], new InfixExpression(
                new ReferencePath('item', 'details', 'a'),
                '+',
                new ReferencePath('item', 'b'),
            )),
            ['item' => new RecordType([
                'details' => new RecordType(['a' => $number]),
                'b' => $number,
            ])],
            ['item' => ['details' => [], 'b' => 'not a number']],
        ))->compile()->unwrap();

        $failure = $program()->unwrapErr();

        $this->assertInstanceOf(InadmissibleBinding::class, $failure);
        $this->assertSame('item.b', $failure->rejections[0]->input);
    }

    #[Test]
    public function an_omitted_optional_scoped_property_keeps_option_semantics(): void
    {
        $program = $this->invocationExpression(new InvokeScopedExpressionSource(
            new ScopedExpression(['item'], new ReferencePath('item', 'note')),
            ['item' => new RecordType([
                'note' => new Optional(new \Superscript\Axiom\Types\StringType()),
                'unread' => new \Superscript\Axiom\Types\NumberType(),
            ])],
            ['item' => []],
        ))->compile()->unwrap();

        $this->assertTrue($program()->unwrap()->isNone());
    }

    #[Test]
    public function scoped_admission_uses_the_expressions_boundary_policy(): void
    {
        $source = new InvokeScopedExpressionSource(
            new ScopedExpression(['item'], new ReferencePath('item', 'score')),
            ['item' => new RecordType(['score' => new \Superscript\Axiom\Types\NumberType()])],
            ['item' => ['score' => '2']],
        );

        $coercing = $this->invocationExpression($source)->compile()->unwrap();
        $strict = new Expression(
            $source,
            dialect: Dialect::core()->with(new ScopedExpressionExtension()),
            boundary: Boundary::Assert,
        )->compile()->unwrap();

        $this->assertSame(2, $coercing()->unwrap()->unwrap());
        $this->assertInstanceOf(InadmissibleBinding::class, $strict()->unwrapErr());
    }

    #[Test]
    public function quantifiers_over_one_list_admit_rows_for_their_own_predicate_reads(): void
    {
        $number = new \Superscript\Axiom\Types\NumberType();
        $rows = new DeclaredSource(
            new ListType(new RecordType(['left' => $number, 'right' => $number])),
            [['left' => 1], ['right' => 1]],
        );
        $left = new ScopedAnySource(new SymbolSource('rows'), new ScopedExpression(
            ['item'],
            new InfixExpression(new ReferencePath('item', 'left'), '>', new StaticSource(0)),
        ));
        $right = new ScopedAnySource(new SymbolSource('rows'), new ScopedExpression(
            ['item'],
            new InfixExpression(new ReferencePath('item', 'right'), '>', new StaticSource(0)),
        ));
        $program = new Expression(
            new InfixExpression($left, '&&', $right),
            definitions: new Definitions(['rows' => $rows]),
            dialect: Dialect::core()->with(new ScopedExpressionExtension()),
        )->compile()->unwrap();

        $this->assertTrue($program()->unwrap()->unwrap());
    }

    #[Test]
    public function it_compiles_once_invokes_repeatedly_and_short_circuits(): void
    {
        $extension = new ScopedExpressionExtension();
        $expression = new Expression(
            new ScopedAnySource(
                new SymbolSource('items'),
                new ScopedExpression(['item'], new CountedPredicateSource(new InfixExpression(
                    new SymbolSource('item'),
                    '>',
                    new StaticSource(1),
                ))),
            ),
            dialect: Dialect::core()->with($extension),
            declarations: ['items' => new ListType(new \Superscript\Axiom\Types\NumberType())],
        );

        $program = $expression->compile()->unwrap();
        $observer = new SpyObserver();

        $this->assertTrue($program(['items' => [0, 2, 3]], $observer)->unwrap()->unwrap());
        $this->assertFalse($program(['items' => [0, 1]])->unwrap()->unwrap());
        $this->assertSame(1, $extension->anyCompilations);
        $this->assertSame(1, $extension->predicateCompilations);
        $this->assertSame(4, $extension->predicateEvaluations);
        $this->assertSame(['items'], $expression->parameters());
        $this->assertEquals([new ReferencePath('items')], $program->references);
        $this->assertContains(CountedPredicateSource::class, array_map(
            static fn($event): string => $event->node->sourceType,
            $observer->events,
        ));

        $children = $program->analysis->toArray()['root']['children'];
        $this->assertSame(['items', 'predicate'], array_column($children, 'role'));
        $this->assertSame(CountedPredicateSource::class, $children[1]['node']['source']);
    }

    #[Test]
    public function nested_scopes_capture_outer_parameters_without_conventions(): void
    {
        $extension = new ScopedExpressionExtension();
        $number = new \Superscript\Axiom\Types\NumberType();
        $expression = new Expression(
            new ScopedAnySource(
                new SymbolSource('groups'),
                new ScopedExpression(['group'], new ScopedAnySource(
                    new MemberAccessSource(new SymbolSource('group'), 'values'),
                    new ScopedExpression(['item'], new InfixExpression(
                        new SymbolSource('item'),
                        '>',
                        new MemberAccessSource(new SymbolSource('group'), 'minimum'),
                    )),
                )),
            ),
            dialect: Dialect::core()->with($extension),
            declarations: ['groups' => new ListType(new RecordType([
                'minimum' => $number,
                'values' => new ListType($number),
            ]))],
        );

        $program = $expression->compile()->unwrap();

        $this->assertTrue($program(['groups' => [
            ['minimum' => 1, 'values' => [0, 1]],
            ['minimum' => 2, 'values' => [0, 3]],
        ]])->unwrap()->unwrap());
        $this->assertSame(2, $extension->anyCompilations);
        $this->assertSame(['groups'], $expression->parameters());
    }

    #[Test]
    public function it_locates_a_wrong_return_type_at_the_subexpression(): void
    {
        $expression = $this->anyExpression(new ScopedExpression(['item'], new StaticSource('not boolean')));
        $refusal = $expression->compile()->unwrapErr();
        $this->assertSame('$.children[1].node', $refusal->path);
        $this->assertStringContainsString('must provide Boolean', $refusal->describe());
    }

    #[Test]
    public function a_free_symbol_is_captured_from_the_enclosing_program(): void
    {
        $expression = new Expression(
            new ScopedAnySource(
                new SymbolSource('items'),
                new ScopedExpression(['item'], new InfixExpression(
                    new SymbolSource('item'),
                    '>',
                    new SymbolSource('threshold'),
                )),
            ),
            dialect: Dialect::core()->with(new ScopedExpressionExtension()),
            declarations: [
                'items' => new ListType(new \Superscript\Axiom\Types\NumberType()),
                'threshold' => new \Superscript\Axiom\Types\NumberType(),
            ],
        );

        $program = $expression->compile()->unwrap();

        $this->assertSame(['items', 'threshold'], $expression->parameters());
        $this->assertEquals([new ReferencePath('items'), new ReferencePath('threshold')], $program->references);
        $this->assertTrue($program(['items' => [1, 3], 'threshold' => 2])->unwrap()->unwrap());
    }

    #[Test]
    public function an_undeclared_free_symbol_refuses_as_an_enclosing_input(): void
    {
        $expression = new Expression(
            new ScopedAnySource(
                new SymbolSource('items'),
                new ScopedExpression(['candidate'], new InfixExpression(
                    new SymbolSource('candidate'),
                    '>',
                    new SymbolSource('threshold'),
                )),
            ),
            dialect: Dialect::core()->with(new ScopedExpressionExtension()),
            declarations: ['items' => new ListType(new \Superscript\Axiom\Types\NumberType())],
        );

        $refusal = $expression->compile()->unwrapErr();
        $this->assertSame(['items', 'threshold'], $expression->parameters());
        $this->assertSame('$.children[1].node.children[1].node', $refusal->path);
        $this->assertStringStartsWith('Unbound symbol [threshold]', $refusal->message);
    }

    #[Test]
    public function an_enclosing_definition_is_shared_across_every_invocation(): void
    {
        $extension = new ScopedExpressionExtension();
        $expression = new Expression(
            new ScopedAnySource(
                new SymbolSource('items'),
                new ScopedExpression(['candidate'], new InfixExpression(
                    new SymbolSource('candidate'),
                    '>',
                    new SymbolSource('threshold'),
                )),
            ),
            definitions: new Definitions([
                'threshold' => new CountedPredicateSource(new StaticSource(1)),
            ]),
            dialect: Dialect::core()->with($extension),
            declarations: ['items' => new ListType(new \Superscript\Axiom\Types\NumberType())],
        );

        $program = $expression->compile()->unwrap();

        $this->assertTrue($program(['items' => [0, 1, 2]])->unwrap()->unwrap());
        $this->assertSame(1, $extension->predicateCompilations);
        $this->assertSame(1, $extension->predicateEvaluations);
    }

    #[Test]
    public function a_local_parameter_does_not_rebind_an_outer_definition_dependency(): void
    {
        $number = new \Superscript\Axiom\Types\NumberType();
        $expression = new Expression(
            new ScopedAnySource(
                new SymbolSource('items'),
                new ScopedExpression(['item'], new InfixExpression(
                    new SymbolSource('item'),
                    '<',
                    new SymbolSource('outer'),
                )),
            ),
            definitions: new Definitions(['outer' => new SymbolSource('item')]),
            dialect: Dialect::core()->with(new ScopedExpressionExtension()),
            declarations: [
                'item' => $number,
                'items' => new ListType($number),
            ],
        );

        $program = $expression->compile()->unwrap();

        $this->assertTrue($program(['item' => 10, 'items' => [2]])->unwrap()->unwrap());
    }

    #[Test]
    public function a_scoped_expression_runtime_failure_is_the_outer_programs_failure(): void
    {
        $failure = new RuntimeException('predicate failed');
        $program = $this->anyExpression(new ScopedExpression(['item'], new FailingPredicateSource($failure)))
            ->compile()
            ->unwrap();

        $this->assertSame($failure, $program(['items' => [1]])->unwrapErr());
    }

    #[Test]
    public function invocation_binding_order_carries_no_meaning(): void
    {
        $number = new \Superscript\Axiom\Types\NumberType();
        $program = $this->invocationExpression(new InvokeScopedExpressionSource(
            new ScopedExpression(['right', 'left', 'middle'], new InfixExpression(
                new SymbolSource('left'),
                '+',
                new SymbolSource('right'),
            )),
            ['middle' => $number, 'right' => $number, 'left' => $number],
            ['middle' => 0, 'right' => 2, 'left' => 1],
            $number,
        ))->compile()->unwrap();

        $this->assertSame(3, $program()->unwrap()->unwrap());
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function inexactBindings(): iterable
    {
        yield 'missing' => [[]];
        yield 'extra' => [['item' => 1, 'extra' => 2]];
    }

    #[Test]
    #[DataProvider('inexactBindings')]
    public function invocation_requires_exactly_the_declared_bindings(array $bindings): void
    {
        $number = new \Superscript\Axiom\Types\NumberType();
        $program = $this->invocationExpression(new InvokeScopedExpressionSource(
            new ScopedExpression(['item'], new SymbolSource('item')),
            ['item' => $number],
            $bindings,
        ))->compile()->unwrap();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exactly its declared bindings');

        $program();
    }

    #[Test]
    public function parameter_types_must_name_the_subexpressions_parameters(): void
    {
        $number = new \Superscript\Axiom\Types\NumberType();
        $expression = $this->invocationExpression(new InvokeScopedExpressionSource(
            new ScopedExpression(['item'], new SymbolSource('item')),
            ['other' => $number],
            ['other' => 1],
        ));

        $refusal = $expression->compile()->unwrapErr();

        $this->assertSame('$.children[0].node', $refusal->path);
        $this->assertStringContainsString('parameters [item]', $refusal->message);
        $this->assertStringContainsString('received [other]', $refusal->message);
    }

    #[Test]
    public function a_parameterless_scope_can_return_absence(): void
    {
        $program = $this->invocationExpression(new InvokeScopedExpressionSource(
            new ScopedExpression([], new StaticSource(null)),
            [],
            [],
        ))->compile()->unwrap();

        $this->assertTrue($program()->unwrap()->isNone());
    }

    private function anyExpression(ScopedExpression $predicate): Expression
    {
        return new Expression(
            new ScopedAnySource(new SymbolSource('items'), $predicate),
            dialect: Dialect::core()->with(new ScopedExpressionExtension()),
            declarations: ['items' => new ListType(new \Superscript\Axiom\Types\NumberType())],
        );
    }

    private function invocationExpression(InvokeScopedExpressionSource $source): Expression
    {
        return new Expression(
            $source,
            dialect: Dialect::core()->with(new ScopedExpressionExtension()),
        );
    }
}
