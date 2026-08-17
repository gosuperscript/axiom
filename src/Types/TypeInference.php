<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\Analysis\ErrorRecovery;
use Superscript\Axiom\Analysis\RecoveringCompiler;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Exceptions\CompilationAbsorbed;
use Superscript\Axiom\Fields\OpaqueField;
use Superscript\Axiom\Fields\OpaqueFieldRegistry;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\UnaryOperatorResolver;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\UnboundSymbols;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The compiler: exact-class dispatch to the dialect's source compilers.
 * Every node — the core language's ({@see \Superscript\Axiom\CoreSourceCompilers})
 * and a host's alike — compiles through the same registered rule, which
 * receives the source and a {@see SourceCompilation} carrying this
 * environment, and returns the source's type and evaluation together as one
 * {@see CompiledSource} — inference and evaluation are one walk, so a
 * certified type and the code that runs cannot belong to different
 * programs.
 *
 * Operators resolve through the dialect's composed stacks at compile time;
 * the resolutions are bound into the nodes and the compiled program never
 * dispatches on a value again.
 *
 * Given an {@see ErrorRecovery}, compilation additionally treats the nodes
 * and definitions it names as already failed — they compile to a failed node
 * without being visited, and everything above one absorbs rather than judging
 * it — and reports every symbol it read, whether or not the compilation as a
 * whole succeeds. That is what lets
 * {@see RecoveringCompiler} compile the same expression again and reach past
 * a refusal it already reported; without one, nothing here behaves
 * differently.
 */
final readonly class TypeInference
{
    /** @var array<class-string<Source>, callable(Source, SourceCompilation): CompiledSource> */
    private array $sourceCompilers;

    /** @var array<class-string<Source>, string> */
    private array $sourceCompilerExtensions;

    private OpaqueFieldRegistry $opaqueFields;

    /**
     * @param array<class-string<Source>, callable(Source, SourceCompilation): CompiledSource> $sourceCompilers
     * @param array<class-string<Source>, string> $sourceCompilerExtensions
     */
    public function __construct(
        private BinaryOperatorResolver $operators,
        private UnaryOperatorResolver $unaryOperators,
        private LiteralTypeRegistry $literals,
        array $sourceCompilers,
        array $sourceCompilerExtensions = [],
        ?OpaqueFieldRegistry $opaqueFields = null,
        private ?ErrorRecovery $recovery = null,
    ) {
        $this->sourceCompilers = $sourceCompilers;
        $this->sourceCompilerExtensions = $sourceCompilerExtensions;
        $this->opaqueFields = $opaqueFields ?? new OpaqueFieldRegistry();
    }

    /**
     * @param string $path Where this source sits in the tree being compiled,
     *                     in {@see CompilationNode::toArray()}'s language. Every
     *                     refusal made here is stamped with it, so a failed
     *                     compile names the node that failed in the same terms
     *                     a successful one names the nodes that passed.
     * @param ?CompilationRecorder $parent The recorder of the node this source
     *                     compiles under. Reads travel up it: a source that
     *                     compiles hands its reads to the parent as it
     *                     finishes, and a source that refuses hands up what it
     *                     had read before it did, so the names a broken region
     *                     touched survive it in the order they were read.
     * @return Result<CompiledNode, TypeMismatch>
     */
    public function compile(Source $source, TypeEnvironment $environment, string $path = '$', ?CompilationRecorder $parent = null): Result
    {
        if ($this->recovery?->isQuarantined($path) === true) {
            return Ok($this->failedNode($source));
        }

        $compiler = $this->sourceCompilers[$source::class] ?? null;

        if ($compiler === null) {
            return Err(new TypeMismatch(sprintf(
                'Cannot compile [%s]; register its exact class through Extension::sourceCompilers().',
                $source::class,
            ), path: $path));
        }

        $recorder = new CompilationRecorder($path);

        try {
            $compiled = $compiler($source, $this->compilation($environment, $source, $recorder));
        } catch (CompilationAborted $aborted) {
            // The one place a node's refusal becomes a returned error, whoever
            // made it: a source compiler, an operator no rule resolves, an
            // unbound symbol, a failed relation. Locating it here locates all
            // of them, and at() keeps an already-located refusal from a child
            // pointing at the child.
            //
            // Carrying up what the node read before it refused is error
            // recovery's alone: recovery is what compiles on past a refusal
            // and reports the reads of a broken region, so it is the only
            // compilation in which they are read again. Without it a refusal
            // aborts every compilation above it and the tree being recorded
            // is discarded, so recording into it is work nobody collects.
            if ($this->recovery !== null && $parent !== null) {
                $parent->recordReferences($recorder->references());
            }

            return Err($aborted->mismatch->at($path));
        } catch (CompilationAbsorbed) {
            // A judgment with no subject: a child of this source already
            // failed and was already reported, so this source inherits the
            // failure instead of refusing over a placeholder type. What it
            // compiled and read before the judgment is kept, so the reads
            // survive and the node still describes what it got through.
            $compiled = new CompiledSource(CompiledNode::failed());
        }

        // The node, not the compiled source: the walk records what every node
        // was typed as, and a node that failed has no type to record — it is
        // recorded as failed, keeping the children and operators the compiler
        // got through before it gave up.
        $node = $compiled->node();

        return Ok($node->forSource($source, $node->failed
            ? CompilationNode::failed($source::class, $recorder->children(), $recorder->operators())
            : CompilationNode::certified(
                $source::class,
                $node->returns,
                $this->sourceCompilerExtensions[$source::class] ?? 'unattributed',
                $recorder->children(),
                $recorder->operators(),
            ), $recorder->references()));
    }

    /**
     * The full compiler capability for one environment — what every source
     * compiler receives, first-party and host alike.
     */
    private function compilation(TypeEnvironment $environment, Source $owner, CompilationRecorder $recorder): SourceCompilation
    {
        return new SourceCompilation(
            fn(Source $child, string $path): Result => $this->compile($child, $environment, $path, $recorder),
            fn(Type $left, string $operator, Type $right): Result => (new InfixExpressionTyping($this->operators))->resolve($operator, $left, $right),
            fn(string $operator, Type $operand): Result => $this->unaryOperators->resolve($operator, $operand),
            fn(ReferencePath $reference, string $path): Result => $this->compileOwnedReference($reference, $owner, $environment, $path, $recorder),
            fn(ReferencePath $reference): ?Result => $environment->nodeOfInputPath($reference),
            fn(mixed $value): Result => $this->inferValue($value),
            fn(string $identity, string $name): ?OpaqueField => $this->opaqueFields->resolve($identity, $name),
            $recorder,
        );
    }

    /**
     * A rooted dependency must be part of the persisted source tree. That
     * keeps parameter discovery and definition-cycle analysis complete;
     * constructing a reference inside a compiler would hide an edge from
     * both structural passes.
     *
     * @param string $path Where a defined symbol's own source compiles: the
     *                     referencing edge, the same edge the analysis records
     *                     with role `definition`. Two references to one
     *                     definition therefore address it by two paths, as the
     *                     success path already does.
     * @return Result<CompiledNode, TypeMismatch>
     */
    private function compileOwnedReference(ReferencePath $reference, Source $owner, TypeEnvironment $environment, string $path, CompilationRecorder $reads): Result
    {
        if (!array_any(
            UnboundSymbols::in($owner),
            fn(ReferencePath $candidate) => $candidate->key() === $reference->key(),
        )) {
            return Err(new TypeMismatch(sprintf(
                'Reference [%s] is not represented by a ReferencePath in [%s]; dependencies belong in the persisted source tree so parameter and cycle analysis can see them.',
                $reference->describe(),
                $owner::class,
            )));
        }

        $key = $reference->root();

        // A definition on a cycle has already been reported as a property of
        // the graph, and descending into one would not terminate.
        if ($this->recovery?->isPoisoned($key) === true) {
            $reads->recordReferences([new ReferencePath($key)]);

            return Ok($this->failedNode($reference));
        }

        return $environment->nodeOfSymbol($key, $this, $path, $reads);
    }

    /**
     * A node that did not compile, and still a compiled child so its siblings
     * keep their positions — without one, the child after a failed child
     * would claim the failed child's index and every path below it would name
     * the wrong node.
     */
    private function failedNode(Source $source): CompiledNode
    {
        return CompiledNode::failed()->forSource($source, CompilationNode::failed($source::class));
    }

    /**
     * What does this source return? The typing face of compile().
     *
     * @return Result<Type, TypeMismatch>
     */
    public function infer(Source $source, TypeEnvironment $environment): Result
    {
        return $this->compile($source, $environment)->map(fn(CompiledNode $node) => $node->returns);
    }

    /**
     * check() is infer() plus assignability — literal-first inference and
     * value-set Option semantics dissolve bidirectional special cases into
     * assignability theorems. The API stays separate because lambda
     * inference will genuinely need expected-type propagation later.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function check(Source $source, Type $expected, TypeEnvironment $environment): Result
    {
        return $this->infer($source, $environment)
            ->andThen(fn(Type $actual) => TypeRelations::isTypeAssignableTo($actual, $expected)->map(fn() => $actual));
    }

    /**
     * The literal-first type of a PHP value, exposed to source compilers as
     * {@see SourceCompilation::typeOfValue()}.
     *
     * @return Result<Type, TypeMismatch>
     */
    private function inferValue(mixed $value): Result
    {
        if ($value === null) {
            return Ok(new OptionType(new NeverType()));
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return Ok(new LiteralType($value));
        }

        if (is_array($value)) {
            return array_is_list($value) ? $this->inferList($value) : $this->inferRecord($value);
        }

        if (is_object($value)) {
            return $this->literals->resolve($value);
        }

        return Err(new TypeMismatch(sprintf('No literal type exists for a value of type [%s].', get_debug_type($value))));
    }

    /**
     * A list literal infers with union element unification and exact
     * bounds: ['shop', 'office'] is List<'shop' | 'office', 2>.
     *
     * @param list<mixed> $values
     * @return Result<Type, TypeMismatch>
     */
    private function inferList(array $values): Result
    {
        $elements = [];

        foreach ($values as $index => $value) {
            $element = $this->inferValue($value);

            if ($element->isErr()) {
                return Err(new TypeMismatch(sprintf('List element %d cannot be typed.', $index), [$element->unwrapErr()]));
            }

            $elements[] = $element->unwrap();
        }

        $count = count($values);

        return Ok(new ListType(UnionType::join(...$elements), $count, $count));
    }

    /**
     * @param array<array-key, mixed> $values
     * @return Result<Type, TypeMismatch>
     */
    private function inferRecord(array $values): Result
    {
        $fields = [];

        foreach ($values as $key => $value) {
            if (is_int($key)) {
                return Err(new TypeMismatch(sprintf(
                    'A record literal requires string field names; got [%d]. A value that is neither a list nor a record has no type.',
                    $key,
                )));
            }

            $field = $this->inferValue($value);

            if ($field->isErr()) {
                return Err(new TypeMismatch(sprintf('Record field [%s] cannot be typed.', $key), [$field->unwrapErr()]));
            }

            $fields[$key] = $field->unwrap();
        }

        return Ok(new RecordType($fields));
    }
}
