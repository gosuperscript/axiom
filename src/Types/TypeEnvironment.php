<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The symbol face of the compiler: a symbol is a declared input or a
 * defined derived expression, and either way it compiles to a
 * {@see CompiledNode}.
 *
 * A declared symbol is a leaf — its type is the declaration and its
 * evaluation reads the admitted binding (declarations and definitions are
 * disjoint namespaces, enforced by Expression at construction, so nothing
 * else can answer for it). A defined symbol compiles its Source in the
 * same Definitions the program embeds — once; at runtime the compiled
 * definition evaluates lazily and memoizes per invocation ({@see
 * Runtime::slot()}).
 *
 * Termination is not this class's question: well-foundedness of the
 * definition graph is a separate graph pass (see DefinitionGraph) run
 * before compilation; the in-progress guard here only keeps a direct,
 * unguarded use of the compiler from recursing unboundedly.
 *
 * Unbound is an error; a scope that tolerates unknown symbols declares
 * them as UnknownType explicitly.
 */
final class TypeEnvironment
{
    /** @var array<string, Result<CompiledNode, TypeMismatch>> */
    private array $memo = [];

    /** @var list<string> */
    private array $inProgress = [];

    /**
     * A declaration is the type a symbol compiles to, so this is the second
     * door {@see ErrorType} is refused at: an environment can be built
     * without an {@see \Superscript\Axiom\Expression} around it, and a
     * symbol typed with the compiler's mark for a node that failed would
     * fail a node nothing had diagnosed.
     *
     * @param array<string, Type> $declarations
     */
    public function __construct(
        private readonly Definitions $definitions = new Definitions(),
        private readonly array $declarations = [],
    ) {
        foreach ($this->declarations as $key => $type) {
            ErrorType::refuseAuthored($type, sprintf('the declaration of [%s]', $key));
        }
    }

    /**
     * @param string $path Where a defined symbol's source compiles — the edge
     *                     that referenced it. A memoized verdict keeps the path
     *                     of the first reference that compiled it, so a second
     *                     reference to one definition is answered with the
     *                     first reference's location. The memo is scoped to one
     *                     environment and an environment to one compilation
     *                     attempt, so a memoized refusal reaches a second
     *                     reference only within the attempt that made it, and
     *                     only if a compiler captured the abort rather than
     *                     letting it end the attempt.
     * @param ?CompilationRecorder $reads The recorder of the node making this
     *                     read, when one is recording. The name is kept there
     *                     whether or not anything answers for it.
     * @return Result<CompiledNode, TypeMismatch>
     */
    public function nodeOfSymbol(string $name, ?string $namespace, TypeInference $compiler, string $path = '$', ?CompilationRecorder $reads = null): Result
    {
        $key = SymbolSource::key($name, $namespace);

        if (isset($this->declarations[$key])) {
            return Ok(CompiledNode::returning($this->declarations[$key], static function (Runtime $runtime) use ($name, $namespace, $key) {
                // The resolution channel has one representation of null:
                // None. A bound null is still a bound key — the boundary
                // admitted it — but its value is honestly absent.
                $value = $runtime->bindings->get($name, $namespace)->andThen(fn(mixed $v) => Option::from($v));

                $runtime->annotate('label', $key);
                $value->inspect(fn(mixed $v) => $runtime->annotate('result', $v));

                return Ok($value);
            }, references: [$key]));
        }

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        if (in_array($key, $this->inProgress, strict: true)) {
            return Err(new TypeMismatch(sprintf(
                'Cyclic symbol definition: %s.',
                implode(' → ', [...$this->inProgress, $key]),
            )));
        }

        $source = $this->definitions->get($name, $namespace);

        if ($source->isNone()) {
            // Nothing answers for the name, but the expression still depends
            // on it — which is the fact a broken draft is asked about most.
            $reads?->recordReferences([$key]);

            return Err(new TypeMismatch(sprintf(
                'Unbound symbol [%s]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
                $key,
            )));
        }

        $this->inProgress[] = $key;
        $result = $compiler->compile($source->unwrap(), $this, $path, $reads);
        array_pop($this->inProgress);

        return $this->memo[$key] = $result->map(fn(CompiledNode $node) => $node->evaluatedBy(
            static function (Runtime $runtime) use ($node, $key) {
                $result = $runtime->slot($key, fn() => $node->evaluate($runtime));

                $runtime->annotate('label', $key);
                $result->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $runtime->annotate('result', $value)));

                return $result;
            },
        ));
    }
}
