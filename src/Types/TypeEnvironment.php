<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\LocalScope;
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
 * Termination is this class's question. A definition that reads itself —
 * directly or through other definitions — would evaluate forever, so the
 * descent carries the chain of definitions it is currently inside and
 * refuses the moment a name reappears on it, naming the cycle it closed:
 * `Cyclic symbol definition: a → b → a.` Only definitions the compilation
 * actually reads are ever descended into, so a cyclic definition nothing
 * references refuses nothing — an expression answers for the symbols it
 * reads, not for the health of every entry in the bag.
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

    private ?self $parent = null;

    private ?LocalScope $localScope = null;

    /** @param array<string, Type> $declarations */
    public function __construct(
        private readonly Definitions $definitions = new Definitions(),
        private readonly array $declarations = [],
    ) {}

    /**
     * @internal Scoped expression compilation owns lexical environments.
     * @param array<string, Type> $declarations
     */
    public function nested(LocalScope $scope, array $declarations): self
    {
        $nested = new self(declarations: $declarations);
        $nested->parent = $this;
        $nested->localScope = $scope;

        return $nested;
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
            $scope = $this->localScope;

            return Ok(CompiledNode::returning($this->declarations[$key], static function (Runtime $runtime) use ($name, $namespace, $key, $scope) {
                $binding = $scope === null
                    ? $runtime->bindings->get($name, $namespace)
                    : $runtime->local($scope, $name);
                $value = $binding->andThen(fn(mixed $item) => Option::from($item));

                $runtime->annotate('label', $key);
                $value->inspect(fn(mixed $item) => $runtime->annotate('result', $item));

                return Ok($value);
            }, references: $scope === null ? [$key] : []));
        }

        if ($this->parent !== null) {
            return $this->parent->nodeOfSymbol($name, $namespace, $compiler, $path, $reads);
        }

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $position = array_search($key, $this->inProgress, strict: true);

        if ($position !== false) {
            // The name is still a dependency of whatever read it, even though
            // nothing can ever answer for it — reported like an unbound name.
            $reads?->recordReferences([$key]);

            // The chain starts where the cycle does: definitions merely
            // passed through on the way to it lie on the path, not the cycle.
            return Err(new TypeMismatch(sprintf(
                'Cyclic symbol definition: %s.',
                implode(' → ', [...array_slice($this->inProgress, $position), $key]),
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
