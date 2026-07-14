<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Definitions;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The typing environment mirrors symbol resolution: at runtime a symbol is
 * satisfied by a per-call binding or by resolving the Source it names in
 * Definitions. Statically, a declaration gives the type of a binding, and a
 * defined symbol's type is inferred from the same Definitions the evaluator
 * will use — one registry, both semantics.
 *
 * Declarations terminate recursion and, like bindings at runtime, take
 * precedence over definitions. Results are memoized; a cyclic definition —
 * which the evaluator cannot even survive — is reported as a mismatch
 * naming the cycle.
 *
 * Unbound is an error; a scope that tolerates unknown symbols declares them
 * as UnknownType explicitly.
 */
final class TypeEnvironment
{
    /** @var array<string, Result<Type, TypeMismatch>> */
    private array $memo = [];

    /** @var list<string> */
    private array $inProgress = [];

    /**
     * @param array<string, Type> $declarations
     */
    public function __construct(
        private readonly Definitions $definitions = new Definitions(),
        private readonly array $declarations = [],
    ) {}

    /**
     * @return Result<Type, TypeMismatch>
     */
    public function typeOfSymbol(string $name, ?string $namespace, TypeInference $inference): Result
    {
        $key = $namespace !== null ? "{$namespace}.{$name}" : $name;

        if (isset($this->declarations[$key])) {
            return Ok($this->declarations[$key]);
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
            return Err(new TypeMismatch(sprintf(
                'Unbound symbol [%s]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
                $key,
            )));
        }

        $this->inProgress[] = $key;
        $result = $inference->infer($source->unwrap(), $this);
        array_pop($this->inProgress);

        return $this->memo[$key] = $result;
    }
}
