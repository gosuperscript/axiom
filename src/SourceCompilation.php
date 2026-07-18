<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * Straight-line compilation capability handed to a source compiler. It
 * compiles persisted children, binds typed operations, and constructs
 * composable CompiledSources while keeping TypeInference, Runtime, Result,
 * and Option behind the compiler seam.
 *
 * A failed child, symbol, operation, or literal judgment aborts only the
 * current source compilation. TypeInference converts it back to the same
 * TypeMismatch returned by Expression::compile().
 */
final readonly class SourceCompilation
{
    /**
     * @internal Constructed by TypeInference for the current environment.
     * @param Closure(Source): Result<CompiledNode, TypeMismatch> $compileNode
     * @param Closure(Type, string, Type): Result<ResolvedOperation, TypeMismatch> $compileInfix
     * @param Closure(string, Type): Result<ResolvedOperation, TypeMismatch> $compilePrefix
     * @param Closure(SymbolSource): Result<CompiledNode, TypeMismatch> $compileSymbol
     * @param Closure(mixed): Result<Type, TypeMismatch> $typeOfValue
     */
    public function __construct(
        private Closure $compileNode,
        private Closure $compileInfix,
        private Closure $compilePrefix,
        private Closure $compileSymbol,
        private Closure $typeOfValue,
    ) {}

    public function child(Source $source): CompiledSource
    {
        return new CompiledSource($this->require(($this->compileNode)($source)));
    }

    /** @param array<array-key, Source> $sources */
    public function children(array $sources): CompiledSources
    {
        $compiled = [];

        foreach ($sources as $name => $source) {
            $compiled[$name] = $this->child($source);
        }

        return new CompiledSources($compiled);
    }

    /** @param array<array-key, CompiledSource> $sources */
    public function combine(array $sources): CompiledSources
    {
        return new CompiledSources($sources);
    }

    /** Bind one binary operation once from certified operand types. */
    public function infix(Type $left, string $operator, Type $right): BoundOperation
    {
        return new BoundOperation($this->require(($this->compileInfix)($left, $operator, $right)));
    }

    /** Bind one unary operation once from a certified operand type. */
    public function prefix(string $operator, Type $operand): BoundOperation
    {
        return new BoundOperation($this->require(($this->compilePrefix)($operator, $operand)));
    }

    /** Compile a persisted symbol child in the current type environment. */
    public function symbol(SymbolSource $symbol): CompiledSource
    {
        return new CompiledSource($this->require(($this->compileSymbol)($symbol)));
    }

    /** Infer the literal-first type of an embedded PHP value. */
    public function typeOfValue(mixed $value): Type
    {
        return $this->require(($this->typeOfValue)($value));
    }

    /** A total embedded value; null represents absence. */
    public function constant(Type $returns, mixed $value): CompiledSource
    {
        return CompiledSource::constant($returns, $value);
    }

    /** A source with no compiled children, commonly backed by an injected collaborator. */
    public function produces(Type $returns, callable $evaluate): CompiledSource
    {
        return CompiledSource::custom($returns, fn() => $evaluate());
    }

    /** Advanced construction for lazy or source-specific control flow. */
    public function custom(Type $returns, callable $evaluate): CompiledSource
    {
        return CompiledSource::custom($returns, $evaluate);
    }

    /**
     * Add source-specific context to any nested compilation refusal.
     *
     * @template T
     * @param callable(): T $compile
     * @return T
     */
    public function within(string $message, callable $compile): mixed
    {
        try {
            return $compile();
        } catch (CompilationAborted $aborted) {
            $this->reject(new TypeMismatch($message, [$aborted->mismatch]));
        }
    }

    /** Refuse the current source compilation. */
    public function reject(TypeMismatch|string $mismatch): never
    {
        throw new CompilationAborted(
            is_string($mismatch) ? new TypeMismatch($mismatch) : $mismatch,
        );
    }

    /**
     * @template T
     * @param Result<T, TypeMismatch> $result
     * @return T
     */
    private function require(Result $result): mixed
    {
        if ($result->isErr()) {
            $this->reject($result->unwrapErr());
        }

        return $result->unwrap();
    }
}
