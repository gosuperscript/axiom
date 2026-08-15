<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Analysis\CompilationChild;
use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\UnreachableEvaluation;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Program;
use Superscript\Axiom\Types\ErrorType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\StringType;

use function Superscript\Monads\Result\Ok;

/**
 * The line error-tolerant compilation must not cross: a node it gave up on
 * is typed {@see ErrorType}, and a {@see Program} is where a type stops
 * being a claim and starts being a promise.
 */
#[CoversClass(Program::class)]
#[CoversClass(ErrorType::class)]
#[UsesNamespace('Superscript\\Axiom')]
final class ProgramCertificationTest extends TestCase
{
    private static function node(mixed $returns): CompiledNode
    {
        return new CompiledNode($returns, UnreachableEvaluation::refuse(...));
    }

    #[Test]
    public function a_program_cannot_be_minted_from_a_node_that_failed_to_compile(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The node at [$] failed to compile; a Program cannot be certified from it.');

        new Program(self::node(new ErrorType()));
    }

    #[Test]
    public function the_whole_tree_is_checked_not_only_the_root(): void
    {
        // A failed match arm is absorbed into the union of its siblings, so a
        // broken node sits under an ordinary Number.
        $sound = new CompilationNode('Sound', new NumberType(), 'core');
        $broken = new CompilationNode('Broken', new ErrorType(), 'core');
        $root = new CompilationNode('Root', new NumberType(), 'core', [
            new CompilationChild($sound, 'arm.0'),
            new CompilationChild($broken, 'arm.1'),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The node at [$.children[1].node] failed to compile');

        new Program(new CompiledNode(new NumberType(), UnreachableEvaluation::refuse(...), compilation: $root));
    }

    #[Test]
    public function a_sound_tree_is_minted(): void
    {
        $root = new CompilationNode('Root', new NumberType(), 'core', [
            new CompilationChild(new CompilationNode('Child', new StringType(), 'core'), 'left'),
        ]);

        $program = new Program(new CompiledNode(new NumberType(), static fn() => Ok(null), compilation: $root));

        $this->assertInstanceOf(NumberType::class, $program->returns);
    }

    #[Test]
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
}
