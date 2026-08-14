<?php

declare(strict_types=1);

namespace Superscript\Axiom\Spike;

use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\Analysis\OperatorRuleProvenance;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\DefinitionGraph;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Fields\OpaqueField;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\InfixExpressionTyping;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\UnboundSymbols;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

/**
 * SPIKE ONLY. An error-tolerant twin of {@see \Superscript\Axiom\Types\TypeInference}:
 * one walk that never stops, accumulates every diagnostic, keeps collecting
 * references through broken regions, and still hands back the very node a
 * clean strict compile would have produced.
 *
 * ## How it stays tolerant without forking the language
 *
 * Nothing about the *sources* changes. Every node still compiles through the
 * dialect's own registered source compiler, receiving the same
 * {@see SourceCompilation}. The trick is that SourceCompilation's capabilities
 * are injected closures, so this class hands the compilers tolerant versions
 * of the four seams where the strict walk would refuse:
 *
 *  - **child** — compiles the child tolerantly; a broken child comes back as
 *    an ErrorType node instead of aborting the parent.
 *  - **infix / prefix** — if any operand is already ErrorType, resolve
 *    silently to ErrorType with no diagnostic. Otherwise resolve for real and
 *    diagnose a genuine failure once.
 *  - **symbol** — an unbound or poisoned symbol diagnoses once, records the
 *    name as a reference anyway, and yields ErrorType.
 *  - **typeOfValue** — an untypeable literal diagnoses once and yields ErrorType.
 *
 * A source compiler may still `reject()` on its own account (member access on
 * a missing field, a false ascription claim). That throw is caught here at the
 * node that made it: one diagnostic, one ErrorType node, and the parent carries
 * on. So the abort mechanism the strict compiler uses to *stop* is exactly the
 * mechanism this one uses to *resume*, one node higher.
 *
 * ## No cascades
 *
 * ErrorType's shape is Never, so it is assignable everywhere, drops out of
 * UnionType::join, and satisfies match exhaustiveness — every judgment it
 * touches succeeds vacuously rather than refusing again. Concretely, given
 * `unknown_symbol > 10 and postcode == "SW1"` with `unknown_symbol` undeclared:
 * the symbol yields one diagnostic and ErrorType; `>` sees an ErrorType operand
 * and resolves silently to ErrorType; `and` sees ErrorType on the left and
 * resolves silently to ErrorType; the right-hand comparison is still fully
 * checked, and `postcode` is still collected as a reference. One real error,
 * exactly one diagnostic.
 *
 * ## Definition cycles
 *
 * A cycle is a property of the graph, not of a node, so it is diagnosed once up
 * front by {@see DefinitionGraph::cycles()} and every name on a cycle is
 * *poisoned*: resolving it yields ErrorType with no further diagnostic and no
 * descent, which is what makes the walk terminate.
 *
 * ## The soundness invariant
 *
 * `fail()` is the only place an ErrorType is minted, and it always appends a
 * diagnostic first. So ErrorType anywhere implies a diagnostic, and
 * {@see SpikeAnalysis::program()} refuses whenever there is one. A callable
 * Program from a broken tree is unrepresentable.
 */
final class TolerantCompiler
{
    /** @var list<SpikeDiagnostic> */
    private array $diagnostics = [];

    /** @var array<string, string> path => described type */
    private array $types = [];

    /** @var array<string, string> */
    private array $references = [];

    /** @var array<string, CompiledNode> memoized definition nodes */
    private array $memo = [];

    /** @var list<string> */
    private array $inProgress = [];

    /** @var array<string, true> names on a definition cycle */
    private array $poisoned = [];

    private Definitions $definitions;

    /** @var array<string, Type> */
    private array $declarations;

    /** @var array<class-string<Source>, callable(Source, SourceCompilation): \Superscript\Axiom\CompiledSource> */
    private array $sourceCompilers;

    /** @var array<class-string<Source>, string> */
    private array $sourceCompilerExtensions;

    public function __construct(private readonly Expression $expression)
    {
        $this->definitions = $expression->definitions;
        $this->declarations = $expression->declarations;
        $this->sourceCompilers = $expression->dialect->sourceCompilers();
        $this->sourceCompilerExtensions = $expression->dialect->sourceCompilerExtensions();
    }

    public function analyse(): SpikeAnalysis
    {
        foreach (DefinitionGraph::cycles($this->definitions) as $cycle) {
            $this->diagnostics[] = new SpikeDiagnostic('$', $cycle);

            foreach ($this->definitions->keys() as $key) {
                if (str_contains($cycle->message, $key)) {
                    $this->poisoned[$key] = true;
                }
            }
        }

        $root = $this->compile($this->expression->source, '$');

        return new SpikeAnalysis(
            $this->diagnostics,
            array_values($this->references),
            $root->returns,
            $this->types,
            $root,
            $this->declarations,
            $this->expression->boundary,
        );
    }

    /**
     * Compile one node, always returning a node. The recorder's references and
     * children survive an abort, which is what keeps "find references" working
     * through a broken region: the names the node had already resolved before
     * it failed are still reported.
     */
    private function compile(Source $source, string $path): CompiledNode
    {
        $compiler = $this->sourceCompilers[$source::class] ?? null;

        if ($compiler === null) {
            return $this->fail($path, sprintf('Cannot compile [%s]; register its exact class through Extension::sourceCompilers().', $source::class), $source);
        }

        $recorder = new CompilationRecorder($path);

        try {
            $compiled = $compiler($source, $this->compilation($source, $recorder, $path));
        } catch (CompilationAborted $aborted) {
            $this->recordReferences($recorder->references());

            return $this->fail($path, $aborted->mismatch->at($path), $source, $recorder);
        }

        $this->recordReferences($recorder->references());
        $this->types[$path] = SpikeTypes::describe($compiled->returns);

        return $compiled->node()->forSource($source, new CompilationNode(
            $source::class,
            $compiled->returns,
            $this->sourceCompilerExtensions[$source::class] ?? 'unattributed',
            $recorder->children(),
            $recorder->operators(),
        ), $recorder->references());
    }

    /** The tolerant capability set. Same seams as TypeInference::compilation(), different refusals. */
    private function compilation(Source $owner, CompilationRecorder $recorder, string $path): SourceCompilation
    {
        return new SourceCompilation(
            fn(Source $child, string $childPath): Result => Ok($this->compile($child, $childPath)),
            fn(Type $left, string $operator, Type $right): Result => $this->infix($left, $operator, $right, $path),
            fn(string $operator, Type $operand): Result => $this->prefix($operator, $operand, $path),
            fn(SymbolSource $symbol, string $childPath): Result => Ok($this->symbol($symbol, $owner, $childPath)),
            fn(mixed $value): Result => Ok($this->typeOfValue($value, $path)),
            fn(string $identity, string $name): ?OpaqueField => $this->expression->dialect->opaqueFields()->resolve($identity, $name),
            $recorder,
        );
    }

    /** @return Result<ResolvedOperation, TypeMismatch> */
    private function infix(Type $left, string $operator, Type $right, string $path): Result
    {
        if (ErrorType::isErrorType($left) || ErrorType::isErrorType($right)) {
            return Ok($this->silentOperation());
        }

        $resolved = (new InfixExpressionTyping($this->expression->dialect->operators()))->resolve($operator, $left, $right);

        if ($resolved->isErr()) {
            $this->diagnostics[] = new SpikeDiagnostic($path, $resolved->unwrapErr()->at($path));

            return Ok($this->silentOperation());
        }

        return $resolved;
    }

    /** @return Result<ResolvedOperation, TypeMismatch> */
    private function prefix(string $operator, Type $operand, string $path): Result
    {
        if (ErrorType::isErrorType($operand)) {
            return Ok($this->silentOperation());
        }

        $resolved = $this->expression->dialect->unaryOperators()->resolve($operator, $operand);

        if ($resolved->isErr()) {
            $this->diagnostics[] = new SpikeDiagnostic($path, $resolved->unwrapErr()->at($path));

            return Ok($this->silentOperation());
        }

        return $resolved;
    }

    private function typeOfValue(mixed $value, string $path): Type
    {
        // Literal typing is pure and self-contained, so the strict inference is
        // reused wholesale and only its refusal is intercepted. Reflection is
        // the spike's shortcut around inferValue being private; promoting it to
        // a public judgment is the one-line change the real version needs.
        $inferred = (new \ReflectionMethod(\Superscript\Axiom\Types\TypeInference::class, 'inferValue'))
            ->invoke($this->strictInference(), $value);

        if ($inferred->isErr()) {
            $this->diagnostics[] = new SpikeDiagnostic($path, $inferred->unwrapErr()->at($path));

            return new ErrorType();
        }

        return $inferred->unwrap();
    }

    /**
     * A symbol: a declared input (leaf), a definition (compiled once, memoized),
     * or unbound. Unbound records the reference before it fails — a broken
     * draft's "find references" is the point of the whole exercise.
     */
    private function symbol(SymbolSource $symbol, Source $owner, string $path): CompiledNode
    {
        $key = SymbolSource::key($symbol->name, $symbol->namespace);

        if (!array_any(
            UnboundSymbols::in($owner),
            fn(SymbolSource $candidate) => $candidate->name === $symbol->name && $candidate->namespace === $symbol->namespace,
        )) {
            return $this->fail($path, sprintf(
                'Symbol [%s] is not represented by a SymbolSource in [%s]; symbol dependencies belong in the persisted source tree so parameter and cycle analysis can see them.',
                $key,
                $owner::class,
            ));
        }

        if (isset($this->declarations[$key])) {
            $this->references[$key] = $key;
            $this->types[$path] = SpikeTypes::describe($this->declarations[$key]);

            return $this->declaredNode($key, $symbol, $this->declarations[$key]);
        }

        // A poisoned name is on a cycle already diagnosed once as a graph
        // property; descending would not terminate and re-diagnosing would
        // echo. Record the reference, yield ErrorType, stop.
        if (isset($this->poisoned[$key]) || in_array($key, $this->inProgress, strict: true)) {
            $this->references[$key] = $key;
            $this->types[$path] = SpikeTypes::ErrorLabel;

            return $this->errorNode();
        }

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $source = $this->definitions->get($symbol->name, $symbol->namespace);

        if ($source->isNone()) {
            // The reference is recorded even though nothing answers for it.
            $this->references[$key] = $key;

            return $this->fail($path, sprintf(
                'Unbound symbol [%s]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
                $key,
            ));
        }

        $this->inProgress[] = $key;
        $node = $this->compile($source->unwrap(), $path);
        array_pop($this->inProgress);

        return $this->memo[$key] = new CompiledNode(
            $node->returns,
            static function (Runtime $runtime) use ($node, $key) {
                $result = $runtime->slot($key, fn() => $node->evaluate($runtime));
                $runtime->annotate('label', $key);
                $result->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $runtime->annotate('result', $value)));

                return $result;
            },
            compilation: $node->compilation(),
            references: $node->references,
        );
    }

    private function declaredNode(string $key, SymbolSource $symbol, Type $type): CompiledNode
    {
        return new CompiledNode($type, static function (Runtime $runtime) use ($symbol, $key) {
            $value = $runtime->bindings->get($symbol->name, $symbol->namespace)->andThen(fn(mixed $v) => Option::from($v));
            $runtime->annotate('label', $key);
            $value->inspect(fn(mixed $v) => $runtime->annotate('result', $v));

            return Ok($value);
        }, references: [$key]);
    }

    /**
     * The ONLY mint of an ErrorType: a diagnostic is appended first, always.
     * That is the soundness invariant, expressed as a single choke point.
     */
    private function fail(string $path, TypeMismatch|string $mismatch, ?Source $source = null, ?CompilationRecorder $recorder = null): CompiledNode
    {
        $mismatch = is_string($mismatch) ? new TypeMismatch($mismatch, path: $path) : $mismatch;

        $this->diagnostics[] = new SpikeDiagnostic($mismatch->path ?? $path, $mismatch);
        $this->types[$path] = SpikeTypes::ErrorLabel;

        return $this->errorNode($source, $recorder);
    }

    /**
     * A broken node still presents as a compiled child so sibling paths keep
     * counting correctly — without it, the child after a broken one would claim
     * the broken one's index and every downstream path would be a lie.
     */
    private function errorNode(?Source $source = null, ?CompilationRecorder $recorder = null): CompiledNode
    {
        $node = new CompiledNode(
            new ErrorType(),
            static fn(Runtime $runtime): Result => throw new \LogicException('An ErrorType node is not runnable; this program never certified.'),
            references: $recorder?->references() ?? [],
        );

        if ($source === null) {
            return $node;
        }

        return $node->forSource($source, new CompilationNode(
            $source::class,
            new ErrorType(),
            'spike.error',
            $recorder?->children() ?? [],
            $recorder?->operators() ?? [],
        ), $recorder?->references() ?? []);
    }

    /** An operation over an already-failed operand: no rule, no diagnostic, no value. */
    private function silentOperation(): ResolvedOperation
    {
        return new ResolvedOperation(
            new ErrorType(),
            static fn(mixed ...$operands) => throw new \LogicException('An absorbed operation is not runnable; this program never certified.'),
            new OperatorRuleProvenance('spike.error-absorption', self::class, 'spike'),
        );
    }

    /** @param list<string> $references */
    private function recordReferences(array $references): void
    {
        foreach ($references as $reference) {
            $this->references[$reference] = $reference;
        }
    }

    private function strictInference(): \Superscript\Axiom\Types\TypeInference
    {
        return new \Superscript\Axiom\Types\TypeInference(
            $this->expression->dialect->operators(),
            $this->expression->dialect->unaryOperators(),
            $this->expression->dialect->literals(),
            $this->sourceCompilers,
            $this->sourceCompilerExtensions,
            $this->expression->dialect->opaqueFields(),
        );
    }
}
