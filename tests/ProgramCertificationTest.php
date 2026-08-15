<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Analysis\CompilationChild;
use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\UnreachableEvaluation;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Program;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ErrorType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OpaqueType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\UnionType;

use function Superscript\Monads\Result\Ok;

/**
 * A host source whose compiler keeps the type of the child it compiled and
 * claims it back for the next source it is given. A plugin that caches by
 * shape, or memoizes a claim per source class, does exactly this — and once
 * one of those children has failed, what it kept is the compiler's mark for
 * a node that failed.
 */
final readonly class RetainingSource implements Source
{
    public function __construct(public Source $source) {}
}

/** @internal The dialect contribution that compiles a {@see RetainingSource}. */
final class RetainingExtension extends Extension
{
    private ?Type $retained = null;

    public function sourceCompilers(): array
    {
        return [RetainingSource::class => $this->compileRetaining(...)];
    }

    private function compileRetaining(RetainingSource $source, SourceCompilation $compilation): CompiledSource
    {
        $claim = $this->retained ?? new NumberType();
        $this->retained = $compilation->child($source->source, 'source')->returns;

        return $compilation->produces($claim, static fn(): int => 1);
    }
}

/**
 * The line error-tolerant compilation must not cross: a node it gave up on
 * is typed {@see ErrorType}, and a {@see Program} is where a type stops
 * being a claim and starts being a promise.
 */
#[CoversClass(Program::class)]
#[CoversClass(CompilationNode::class)]
#[CoversClass(ErrorType::class)]
#[CoversClass(Expression::class)]
#[CoversClass(TypeEnvironment::class)]
#[CoversClass(Ascription::class)]
#[CoversClass(Coerce::class)]
#[CoversClass(CompiledNode::class)]
#[CoversClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[CoversClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[CoversClass(\Superscript\Axiom\Operators\PrefixOperatorRuleBuilder::class)]
#[CoversClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithOperand::class)]
#[UsesNamespace('Superscript\\Axiom')]
final class ProgramCertificationTest extends TestCase
{
    #[Test]
    public function a_program_cannot_be_minted_from_a_node_that_failed_to_compile(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The node at [$] failed to compile; a Program cannot be certified from it.');

        new Program(CompiledNode::failed());
    }

    #[Test]
    public function the_whole_tree_is_checked_not_only_the_root(): void
    {
        // A failed match arm is absorbed into the union of its siblings, so a
        // broken node sits under an ordinary Number.
        $sound = new CompilationNode('Sound', new NumberType(), 'core');
        $broken = new CompilationNode('Broken', ErrorType::shared(), 'core');
        $root = new CompilationNode('Root', new NumberType(), 'core', [
            new CompilationChild($sound, 'arm.0'),
            new CompilationChild($broken, 'arm.1'),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The node at [$.children[1].node] failed to compile');

        new Program(CompiledNode::returning(new NumberType(), UnreachableEvaluation::refuse(...), compilation: $root));
    }

    #[Test]
    public function a_node_carries_whether_anything_under_it_failed(): void
    {
        // The bit is cumulative, and that is what lets the root answer for
        // the whole tree without anyone walking it.
        $sound = new CompilationNode('Sound', new NumberType(), 'core');
        $broken = new CompilationNode('Broken', ErrorType::shared(), 'core');

        $this->assertFalse($sound->failed);
        $this->assertTrue($broken->failed);
        $this->assertFalse(new CompilationNode('Root', new NumberType(), 'core', [
            new CompilationChild($sound, 'left'),
        ])->failed);
        $this->assertTrue(new CompilationNode('Root', new NumberType(), 'core', [
            new CompilationChild($sound, 'left'),
            new CompilationChild($broken, 'right'),
        ])->failed);
    }

    #[Test]
    public function a_sound_tree_is_minted(): void
    {
        $root = new CompilationNode('Root', new NumberType(), 'core', [
            new CompilationChild(new CompilationNode('Child', new StringType(), 'core'), 'left'),
        ]);

        $program = new Program(CompiledNode::returning(new NumberType(), static fn() => Ok(null), compilation: $root));

        $this->assertInstanceOf(NumberType::class, $program->returns);
    }

    /**
     * In a process of its own, because the mark is minted once and this is
     * the test that watches it happen: any earlier compilation in the same
     * process would have minted it already.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function every_node_that_failed_wears_the_same_error_type(): void
    {
        // The mark is stateless and recognised by class, so one instance
        // serves however many nodes the compiler gives up on.
        $this->assertSame(ErrorType::shared(), ErrorType::shared());
    }

    #[Test]
    public function the_error_type_is_bottom(): void
    {
        // Never-shaped is what lets a broken subtree sit anywhere without a
        // second refusal: it is assignable everywhere and covers every match.
        $this->assertInstanceOf(NeverShape::class, ErrorType::shared()->shape());
    }

    /**
     * The other three questions on {@see ErrorType} are unreachable, and say
     * so rather than inventing an answer. Only a certified program holds a
     * value to put to a type, and no program is certified from a tree
     * containing an ErrorType — so each of these is a defect in that guard.
     *
     * @return iterable<string, array{callable(ErrorType): mixed}>
     */
    public static function valueQuestions(): iterable
    {
        yield 'assert' => [static fn(ErrorType $error) => $error->assert(1)];
        yield 'coerce' => [static fn(ErrorType $error) => $error->coerce(1)];
        yield 'format' => [static fn(ErrorType $error) => $error->format(1)];
    }

    #[Test]
    #[DataProvider('valueQuestions')]
    public function the_error_type_answers_nothing_about_a_value(callable $question): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A type that marks a node that failed to compile answers nothing about a value; this program was never certified.');

        $question(ErrorType::shared());
    }

    /**
     * The doors a host authors a type through, each refusing the mark before
     * anything is compiled and naming what was supplied: an authored
     * ErrorType is a defect in the calling code, not a fault of the
     * expression, so it is never a diagnostic. What a declaration would
     * otherwise do differs by door — a symbol declared with the mark
     * compiles to a failure nothing diagnosed, an operator rule declaring it
     * simply never fires, because an operation over a failed operand is
     * absorbed before any rule is looked at — and both are worth hearing
     * about where they were written.
     *
     * @return iterable<string, array{string, callable(Type): mixed}>
     */
    public static function doorsThatIngestAType(): iterable
    {
        yield 'declaration' => [
            'the declaration of [x]',
            static fn(Type $type) => new Expression(new SymbolSource('x'), declarations: ['x' => $type]),
        ];

        yield 'type environment' => [
            'the declaration of [x]',
            static fn(Type $type) => new TypeEnvironment(declarations: ['x' => $type]),
        ];

        yield 'ascription' => [
            'the ascribed type',
            static fn(Type $type) => new Ascription($type, new StaticSource(1)),
        ];

        yield 'coercion' => [
            'the coerced type',
            static fn(Type $type) => new Coerce($type, new StaticSource(1)),
        ];

        yield 'infix rule left operand' => [
            'the left operand of an operator rule',
            static fn(Type $type) => Operator::infix('+')->takes($type, new NumberType()),
        ];

        yield 'infix rule right operand' => [
            'the right operand of an operator rule',
            static fn(Type $type) => Operator::infix('+')->takes(new NumberType(), $type),
        ];

        yield 'infix rule return' => [
            'the return type of an operator rule',
            static fn(Type $type) => Operator::infix('+')->takes(new NumberType(), new NumberType())->returns($type),
        ];

        yield 'prefix rule operand' => [
            'the operand of an operator rule',
            static fn(Type $type) => Operator::prefix('-')->takes($type),
        ];

        yield 'prefix rule return' => [
            'the return type of an operator rule',
            static fn(Type $type) => Operator::prefix('-')->takes(new NumberType())->returns($type),
        ];
    }

    #[Test]
    #[DataProvider('doorsThatIngestAType')]
    public function the_mark_cannot_be_authored_back_into_a_program(string $door, callable $supply): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('The compiler marks a node it gave up on with a type of its own, and %s is or contains one.', $door));

        $supply(ErrorType::shared());
    }

    /**
     * The mark nested inside a composite is the same fault: a symbol
     * declared `Option<Error>` compiles to a failure nothing diagnosed just
     * as surely as one declared `Error`.
     *
     * @return iterable<string, array{callable(): Type}>
     */
    public static function compositesContainingTheMark(): iterable
    {
        yield 'option' => [static fn(): Type => new OptionType(ErrorType::shared())];
        yield 'list' => [static fn(): Type => new ListType(ErrorType::shared())];
        yield 'dict' => [static fn(): Type => new DictType(ErrorType::shared())];
        yield 'record field' => [static fn(): Type => new RecordType(['premium' => ErrorType::shared()])];
        yield 'union member' => [static fn(): Type => new UnionType(new NumberType(), ErrorType::shared())];
        yield 'opaque parameter' => [static fn(): Type => new OpaqueType('Money', ['amount' => ErrorType::shared()])];
        yield 'nested twice' => [static fn(): Type => new RecordType(['quotes' => new ListType(new OptionType(ErrorType::shared()))])];
    }

    #[Test]
    #[DataProvider('compositesContainingTheMark')]
    public function a_composite_that_contains_the_mark_is_refused_too(callable $compose): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the declaration of [x] is or contains one');

        new Expression(new SymbolSource('x'), declarations: ['x' => $compose()]);
    }

    /**
     * The claim a source compiler makes is not authored through any of those
     * doors, and it is where a host most easily comes by the mark: it is
     * handed one for every child that failed. Every claim becomes a node's
     * return type, so the node is where the claim is answered for.
     */
    #[Test]
    public function a_compiler_cannot_claim_the_mark_it_was_handed_for_a_failed_child(): void
    {
        $extension = new RetainingExtension();
        $dialect = Dialect::core()->with($extension);

        // One broken expression, diagnosed. The compiler is handed the mark
        // for the child that failed, and keeps it.
        $broken = new Expression(new RetainingSource(new SymbolSource('missing')), dialect: $dialect)->diagnose();

        $this->assertCount(1, $broken->diagnostics);

        // A second expression, sound, compiled by the same extension — which
        // now claims what it kept. The claim is refused where it is made,
        // rather than certification refusing an expression with nothing
        // wrong with it.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the type this compiled node returns is or contains one');

        new Expression(new RetainingSource(new StaticSource(1)), dialect: $dialect)->diagnose();
    }

    #[Test]
    public function a_compiler_that_claims_nothing_it_was_not_given_certifies(): void
    {
        // The same expression, the same compiler, with nothing kept from a
        // failure: zero diagnostics, and a program.
        $diagnosis = new Expression(
            new RetainingSource(new StaticSource(1)),
            dialect: Dialect::core()->with(new RetainingExtension()),
        )->diagnose();

        $this->assertSame([], $diagnosis->diagnostics);
        $this->assertInstanceOf(NumberType::class, $diagnosis->returns);
        $this->assertTrue($diagnosis->program()->isOk());
    }

    #[Test]
    public function an_ordinary_declaration_passes_every_door(): void
    {
        // The guard reads the type it is given and nothing else: every
        // composite it walks into passes when no mark is in it.
        $diagnosis = new Expression(
            new Coerce(new NumberType(), new SymbolSource('x')),
            declarations: [
                'x' => new RecordType(['premium' => new OptionType(new NumberType())]),
                'members' => new UnionType(new NumberType(), new StringType()),
                'money' => new OpaqueType('Money', ['amount' => new NumberType()]),
                'quotes' => new ListType(new DictType(new NumberType())),
            ],
        )->diagnose();

        $this->assertSame([], $diagnosis->diagnostics);
        $this->assertTrue($diagnosis->program()->isOk());
    }
}
