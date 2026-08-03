<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\Analysis\OperatorSelection;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Fields\OpaqueField;
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
     * @param Closure(Source, string): Result<CompiledNode, TypeMismatch> $compileNode
     * @param Closure(Type, string, Type): Result<ResolvedOperation, TypeMismatch> $compileInfix
     * @param Closure(string, Type): Result<ResolvedOperation, TypeMismatch> $compilePrefix
     * @param Closure(SymbolSource, string): Result<CompiledNode, TypeMismatch> $compileSymbol
     * @param Closure(mixed): Result<Type, TypeMismatch> $typeOfValue
     * @param ?Closure(string, string): ?OpaqueField $resolveOpaqueField
     */
    public function __construct(
        private Closure $compileNode,
        private Closure $compileInfix,
        private Closure $compilePrefix,
        private Closure $compileSymbol,
        private Closure $typeOfValue,
        private ?Closure $resolveOpaqueField = null,
        private ?CompilationRecorder $recorder = null,
    ) {}

    public function child(Source $source, ?string $role = null): CompiledSource
    {
        $node = $this->require(($this->compileNode)($source, $this->childPath()));

        if ($this->recorder !== null && ($compilation = $node->compilation()) !== null) {
            $this->recorder->child($compilation, $role);
        }

        return new CompiledSource($node);
    }

    /** @param array<array-key, Source> $sources */
    public function children(array $sources): CompiledSources
    {
        $compiled = [];

        foreach ($sources as $name => $source) {
            $compiled[$name] = $this->child($source, (string) $name);
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
        $resolved = $this->require(($this->compileInfix)($left, $operator, $right));
        $this->recordOperator('infix', $operator, [$left, $right], $resolved);

        return new BoundOperation($resolved);
    }

    /** Bind one unary operation once from a certified operand type. */
    public function prefix(string $operator, Type $operand): BoundOperation
    {
        $resolved = $this->require(($this->compilePrefix)($operator, $operand));
        $this->recordOperator('prefix', $operator, [$operand], $resolved);

        return new BoundOperation($resolved);
    }

    /** Compile a persisted symbol child in the current type environment. */
    public function symbol(SymbolSource $symbol): CompiledSource
    {
        $node = $this->require(($this->compileSymbol)($symbol, $this->childPath()));
        $compilation = $node->compilation();

        if ($this->recorder !== null && $compilation !== null) {
            $this->recorder->child($compilation, 'definition');
        }

        return new CompiledSource($node);
    }

    /** Infer the literal-first type of an embedded PHP value. */
    public function typeOfValue(mixed $value): Type
    {
        return $this->require(($this->typeOfValue)($value));
    }

    /**
     * The field the owner of this opaque identity declared under this name,
     * or null when none exists — the member-access checkpoint decides whether
     * the absence is a refusal. A dialect with no field declarations resolves
     * every lookup to null, so every opaque access stays refused.
     */
    public function opaqueField(string $identity, string $name): ?OpaqueField
    {
        if ($this->resolveOpaqueField === null) {
            return null;
        }

        return ($this->resolveOpaqueField)($identity, $name);
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

    /**
     * Where the child about to be compiled sits. Without a recorder there is
     * no child numbering to derive one from, so the child compiles as its own
     * root — a standalone compilation, which is what a recorderless
     * SourceCompilation is.
     */
    private function childPath(): string
    {
        return $this->recorder?->childPath() ?? '$';
    }

    /** @param non-empty-list<Type> $operands */
    private function recordOperator(string $kind, string $operator, array $operands, ResolvedOperation $resolved): void
    {
        if ($this->recorder === null) {
            return;
        }

        $this->recorder->operator(new OperatorSelection(
            $kind,
            $operator,
            $operands,
            $resolved->returns,
            $resolved->provenance ?? new \Superscript\Axiom\Analysis\OperatorRuleProvenance(
                'unattributed',
                \Superscript\Axiom\Operators\ResolvedOperation::class,
                null,
            ),
        ));
    }
}
