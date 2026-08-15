<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Analysis\CompilationChild;
use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\CompilationState;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Program;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

/**
 * A host source whose compiler keeps the type of the child it compiled and
 * claims it back for the next source it is given. A plugin that caches by
 * shape, or memoizes a claim per source class, does exactly this — and the
 * question is what it keeps once one of those children has failed.
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

/** A host source whose compiler claims a type handed to it from outside. */
final readonly class ClaimingSource implements Source {}

/** @internal The dialect contribution that compiles a {@see ClaimingSource}. */
final class ClaimingExtension extends Extension
{
    public function __construct(private readonly Type $claim) {}

    public function sourceCompilers(): array
    {
        return [ClaimingSource::class => $this->compileClaiming(...)];
    }

    private function compileClaiming(ClaimingSource $source, SourceCompilation $compilation): CompiledSource
    {
        return $compilation->produces($this->claim, static fn(): int => 1);
    }
}

/**
 * A type of the host's own, wrapping another. Axiom cannot see inside it and
 * does not try to: certification reads the compiler's record of what it did,
 * never the types a host authored.
 *
 * @implements Type<mixed>
 */
final readonly class HostBox implements Type
{
    public function __construct(private Type $inner) {}

    public function assert(mixed $value): Result
    {
        return $this->inner->assert($value);
    }

    public function coerce(mixed $value): Result
    {
        return $this->inner->coerce($value);
    }

    public function format(mixed $value): string
    {
        return $this->inner->format($value);
    }

    public function shape(): Shape
    {
        return $this->inner->shape();
    }
}

/**
 * The line error-tolerant compilation must not cross. A {@see Program} is
 * where a type stops being a claim and starts being a promise, and what it
 * checks is {@see CompilationState}: failure is a state the compiler records,
 * never a type, so there is nothing for a host to obtain and nothing for
 * certification to search a type for.
 */
#[CoversClass(Program::class)]
#[CoversClass(CompilationNode::class)]
#[CoversClass(CompilationState::class)]
#[CoversClass(Expression::class)]
#[CoversClass(CompiledNode::class)]
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
        $sound = CompilationNode::certified('Sound', new NumberType(), 'core');
        $broken = CompilationNode::failed('Broken');
        $root = CompilationNode::certified('Root', new NumberType(), 'core', [
            new CompilationChild($sound, 'arm.0'),
            new CompilationChild($broken, 'arm.1'),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The node at [$.children[1].node] failed to compile');

        new Program(CompiledNode::returning(new NumberType(), static fn() => Ok(null), compilation: $root));
    }

    #[Test]
    public function a_node_carries_whether_anything_under_it_failed(): void
    {
        // The bit is cumulative, and that is what lets the root answer for
        // the whole tree without anyone walking it.
        $sound = CompilationNode::certified('Sound', new NumberType(), 'core');
        $broken = CompilationNode::failed('Broken');

        $this->assertFalse($sound->containsFailure);
        $this->assertTrue($broken->containsFailure);
        $this->assertFalse(CompilationNode::certified('Root', new NumberType(), 'core', [
            new CompilationChild($sound, 'left'),
        ])->containsFailure);
        $this->assertTrue(CompilationNode::certified('Root', new NumberType(), 'core', [
            new CompilationChild($sound, 'left'),
            new CompilationChild($broken, 'right'),
        ])->containsFailure);
    }

    #[Test]
    public function a_sound_tree_is_minted(): void
    {
        $root = CompilationNode::certified('Root', new NumberType(), 'core', [
            new CompilationChild(CompilationNode::certified('Child', new StringType(), 'core'), 'left'),
        ]);

        $program = new Program(CompiledNode::returning(new NumberType(), static fn() => Ok(null), compilation: $root));

        $this->assertInstanceOf(NumberType::class, $program->returns);
    }

    /**
     * The state is the whole of what a node that failed carries. There is no
     * type standing for failure — no class a host could be handed, wrap, and
     * claim back on a later, blameless expression — which is why the guards
     * that used to hunt for one are gone rather than multiplied.
     */
    #[Test]
    public function failure_is_a_state_and_no_type_stands_for_it(): void
    {
        $this->assertFalse(class_exists('Superscript\\Axiom\\Types\\ErrorType'));

        $failed = CompilationNode::failed('Broken');

        $this->assertSame(CompilationState::Failed, $failed->state);
        $this->assertTrue($failed->containsFailure);
    }

    /**
     * The two things a node that failed never settled. Reading either is a
     * reader treating the states alike, and it says so rather than answering
     * with something invented.
     */
    #[Test]
    public function a_node_that_failed_claims_neither_a_type_nor_a_compiler(): void
    {
        $failed = CompilationNode::failed('Broken');

        try {
            $failed->returns;
            $this->fail('A node that failed has no return type to read.');
        } catch (LogicException $refused) {
            $this->assertStringContainsString('so it has no return type', $refused->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('so it has no owning compiler');

        $failed->extension;
    }

    /**
     * A host may compile to any type it likes, including a composite of its
     * own that Axiom cannot see inside. Certification never inspects one: it
     * reads the state the compiler recorded, so there is no containment walk
     * to write and no host type shape that can defeat it.
     */
    #[Test]
    public function an_arbitrary_host_type_certifies_without_being_inspected(): void
    {
        $boxed = new HostBox(new NumberType());

        $produced = new Expression(
            new ClaimingSource(),
            dialect: Dialect::core()->with(new ClaimingExtension($boxed)),
        )->diagnose();

        $this->assertSame([], $produced->diagnostics);
        $this->assertSame($boxed, $produced->returns);
        $this->assertTrue($produced->program()->isOk());

        // The other door a type is claimed through, with a host type nested
        // two deep, which a walk looking for a marker would have to descend.
        $nested = new HostBox(new HostBox(new NumberType()));

        $this->assertSame($nested, new Program(
            CompiledSource::constant($nested, 1)->node(),
        )->returns);
    }

    /**
     * The claim a source compiler makes was the one way a host could come by
     * a failure at all: a compiler is handed a child for every source it
     * compiles, and reading the type of one that failed would have to answer
     * with something. It refuses instead, so there is nothing to keep and
     * nothing to claim back.
     */
    #[Test]
    public function the_type_of_a_child_that_failed_cannot_be_taken(): void
    {
        // A compiler that keeps the type of the child it compiled — a plugin
        // caching a claim per source class does exactly this — over an
        // expression whose child does not compile. The read is where that
        // goes wrong, so the read is where it is stopped.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A source that did not compile has no return type to read');

        new Expression(
            new RetainingSource(new SymbolSource('missing')),
            dialect: Dialect::core()->with(new RetainingExtension()),
        )->diagnose();
    }

    /**
     * An expression whose root failed returns nothing: `null`, rather than
     * some type standing in for the failure.
     */
    #[Test]
    public function a_diagnosis_of_a_failed_root_carries_no_type(): void
    {
        $diagnosis = new Expression(new SymbolSource('missing'))->diagnose();

        $this->assertNull($diagnosis->returns);
        $this->assertTrue($diagnosis->program()->isErr());
    }

    /**
     * A diagnosis carries a real type whenever the root itself compiled,
     * which a non-empty diagnostics list does not rule out: a broken match
     * arm is absorbed into the union of its siblings, so the expression is
     * refused and the root type is sound at the same time. What it never
     * carries alongside a diagnostic is a program.
     */
    #[Test]
    public function a_root_that_compiled_keeps_its_type_alongside_a_diagnostic(): void
    {
        $diagnosis = new Expression(
            new MatchExpression(new SymbolSource('band'), [
                new MatchArm(new LiteralPattern('a'), new SymbolSource('unknown_rate')),
                new MatchArm(new WildcardPattern(), new StaticSource(2)),
            ]),
            declarations: ['band' => new StringType()],
        )->diagnose();

        $this->assertCount(1, $diagnosis->diagnostics);
        $this->assertInstanceOf(LiteralType::class, $diagnosis->returns);
        $this->assertTrue($diagnosis->program()->isErr());
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
}
