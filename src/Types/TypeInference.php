<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Fields\OpaqueField;
use Superscript\Axiom\Fields\OpaqueFieldRegistry;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\UnaryOperatorResolver;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\SymbolSource;
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
     * @return Result<CompiledNode, TypeMismatch>
     */
    public function compile(Source $source, TypeEnvironment $environment, string $path = '$'): Result
    {
        $compiler = $this->sourceCompilers[$source::class] ?? null;

        if ($compiler === null) {
            return Err(new TypeMismatch(sprintf(
                'Cannot compile [%s]; register its exact class through Extension::sourceCompilers().',
                $source::class,
            ), path: $path));
        }

        try {
            $recorder = new CompilationRecorder($path);
            $compiled = $compiler($source, $this->compilation($environment, $source, $recorder));
        } catch (CompilationAborted $aborted) {
            // The one place a node's refusal becomes a returned error, whoever
            // made it: a source compiler, an operator no rule resolves, an
            // unbound symbol, a failed relation. Locating it here locates all
            // of them, and at() keeps an already-located refusal from a child
            // pointing at the child.
            return Err($aborted->mismatch->at($path));
        }

        return Ok($compiled->node()->forSource($source, new CompilationNode(
            $source::class,
            $compiled->returns,
            $this->sourceCompilerExtensions[$source::class] ?? 'unattributed',
            $recorder->children(),
            $recorder->operators(),
        )));
    }

    /**
     * The full compiler capability for one environment — what every source
     * compiler receives, first-party and host alike.
     */
    private function compilation(TypeEnvironment $environment, Source $owner, CompilationRecorder $recorder): SourceCompilation
    {
        return new SourceCompilation(
            fn(Source $child, string $path): Result => $this->compile($child, $environment, $path),
            fn(Type $left, string $operator, Type $right): Result => (new InfixExpressionTyping($this->operators))
                ->resolve($operator, $left, $right),
            fn(string $operator, Type $operand): Result => $this->unaryOperators->resolve($operator, $operand),
            fn(SymbolSource $symbol, string $path): Result => $this->compileOwnedSymbol($symbol, $owner, $environment, $path),
            fn(mixed $value): Result => $this->inferValue($value),
            fn(string $identity, string $name): ?OpaqueField => $this->opaqueFields->resolve($identity, $name),
            $recorder,
        );
    }

    /**
     * A symbol dependency must be part of the persisted source tree. That
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
    private function compileOwnedSymbol(SymbolSource $symbol, Source $owner, TypeEnvironment $environment, string $path): Result
    {
        if (!array_any(
            UnboundSymbols::in($owner),
            fn(SymbolSource $candidate) => $candidate->name === $symbol->name && $candidate->namespace === $symbol->namespace,
        )) {
            return Err(new TypeMismatch(sprintf(
                'Symbol [%s] is not represented by a SymbolSource in [%s]; symbol dependencies belong in the persisted source tree so parameter and cycle analysis can see them.',
                SymbolSource::key($symbol->name, $symbol->namespace),
                $owner::class,
            )));
        }

        return $environment->nodeOfSymbol($symbol->name, $symbol->namespace, $this, $path);
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
