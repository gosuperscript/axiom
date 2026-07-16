<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

/**
 * Recursive compilation capability handed to a source compiler. It keeps
 * TypeInference and TypeEnvironment behind the compiler seam while allowing
 * a source compiler to compile the Source children it owns, resolve symbols
 * in the current environment, bind the typed infix and prefix operations it
 * owns, and type the PHP values it embeds. The core language's own source
 * compilers ({@see CoreSourceCompilers}) consume exactly this capability —
 * nothing a host source compiler receives is a reduced copy.
 */
final readonly class SourceCompilation
{
    /**
     * @internal Constructed by TypeInference for the current environment.
     * @param Closure(Source): Result<CompiledNode, TypeMismatch> $compileNode
     * @param Closure(Type, string, Type): Result<ResolvedOperation, TypeMismatch> $compileInfix
     * @param Closure(string, Type): Result<ResolvedOperation, TypeMismatch> $compilePrefix
     * @param Closure(string, ?string): Result<CompiledNode, TypeMismatch> $compileSymbol
     * @param Closure(mixed): Result<Type, TypeMismatch> $typeOfValue
     */
    public function __construct(
        private Closure $compileNode,
        private Closure $compileInfix,
        private Closure $compilePrefix,
        private Closure $compileSymbol,
        private Closure $typeOfValue,
    ) {}

    /** @return Result<CompiledNode, TypeMismatch> */
    public function compile(Source $source): Result
    {
        return ($this->compileNode)($source);
    }

    /**
     * @param list<Source> $sources
     * @return Result<list<CompiledNode>, TypeMismatch>
     */
    public function compileAll(array $sources): Result
    {
        $compiled = [];

        foreach ($sources as $source) {
            $node = $this->compile($source);

            if ($node->isErr()) {
                return $node;
            }

            $compiled[] = $node->unwrap();
        }

        return Ok($compiled);
    }

    /**
     * Bind one infix operation from the composed Dialect, once, using the
     * operand types the source compiler certifies. The returned operation is
     * the same type-and-evaluation pair an ordinary InfixExpression embeds;
     * evaluating it later performs no value-directed dispatch.
     *
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    public function infix(Type $left, string $operator, Type $right): Result
    {
        return ($this->compileInfix)($left, $operator, $right);
    }

    /**
     * Bind one prefix operation from the composed Dialect, same contract as
     * {@see infix()}: honest operand type in, the resolved return type and
     * evaluation out, no value-directed dispatch left for runtime.
     *
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    public function prefix(string $operator, Type $operand): Result
    {
        return ($this->compilePrefix)($operator, $operand);
    }

    /**
     * Compile a symbol reference in the current environment: a declared
     * input reads its admitted binding, a defined symbol compiles its
     * definition once and evaluates it memoized per invocation. This is the
     * same node an ordinary SymbolSource compiles to, so a host source that
     * names a symbol embeds exactly the language's symbol semantics.
     *
     * @return Result<CompiledNode, TypeMismatch>
     */
    public function symbol(string $name, ?string $namespace = null): Result
    {
        return ($this->compileSymbol)($name, $namespace);
    }

    /**
     * The literal-first type of a PHP value: scalars type as their literal,
     * lists unify their elements with exact bounds, string-keyed arrays type
     * as records, and objects resolve through the dialect's literal
     * registry. This is the same judgment a StaticSource compiles with; a
     * value the registry cannot type is a refusal, not Unknown.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function typeOfValue(mixed $value): Result
    {
        return ($this->typeOfValue)($value);
    }
}
