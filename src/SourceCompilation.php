<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\Analysis\OperatorRuleProvenance;
use Superscript\Axiom\Analysis\OperatorSelection;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Exceptions\CompilationAbsorbed;
use Superscript\Axiom\Fields\OpaqueField;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\ErrorType;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
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
        $node = $this->compiled(($this->compileNode)($source, $this->childPath()), $source, $role);

        $this->recorder?->recordReferences($node->references);

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

    /**
     * Bind one binary operation once from certified operand types. Absorbs
     * over an operand that did not compile, for the reason {@see overlaps()}
     * does: no rule can be selected from a placeholder type, and a refusal
     * saying so would blame this source for the fault below it.
     */
    public function infix(Type $left, string $operator, Type $right): BoundOperation
    {
        if ($left instanceof ErrorType || $right instanceof ErrorType) {
            $this->absorb();
        }

        $resolved = $this->require(($this->compileInfix)($left, $operator, $right));
        $this->recordOperator('infix', $operator, [$left, $right], $resolved);

        return new BoundOperation($resolved);
    }

    /** Bind one unary operation once from a certified operand type; absorbs over a failed operand, as {@see infix()} does. */
    public function prefix(string $operator, Type $operand): BoundOperation
    {
        if ($operand instanceof ErrorType) {
            $this->absorb();
        }

        $resolved = $this->require(($this->compilePrefix)($operator, $operand));
        $this->recordOperator('prefix', $operator, [$operand], $resolved);

        return new BoundOperation($resolved);
    }

    /** Compile a persisted symbol child in the current type environment. */
    public function symbol(SymbolSource $symbol): CompiledSource
    {
        $node = $this->compiled(($this->compileSymbol)($symbol, $this->childPath()), $symbol, 'definition');
        $this->recorder?->recordReferences($node->references);
        $compilation = $node->compilation();

        if ($this->recorder !== null && $compilation !== null) {
            $this->recorder->child($compilation, 'definition');
        }

        return new CompiledSource($node);
    }

    /**
     * Could a value inhabit both of these types? The overlap relation, asked
     * through the capability so it absorbs: a type that failed to compile is
     * a placeholder rather than a claim, and no honest answer exists about
     * it, so the question is not put and this compilation is
     * {@see absorb()}ed instead.
     *
     * @return Result<bool, TypeMismatch>
     */
    public function overlaps(Type $left, Type $right): Result
    {
        if ($left instanceof ErrorType || $right instanceof ErrorType) {
            $this->absorb();
        }

        return TypeRelations::overlaps($left, $right);
    }

    /**
     * The certified type of a compiled child, for a compiler that needs the
     * type itself — to bind an operation from, to claim over, to name in a
     * message. Absorbs for the same reason {@see overlaps()} does: a child
     * that did not compile has no type, and {@see CompiledSource::$returns}
     * refuses rather than hand out the compiler's mark, so this is how a
     * compiler asks for a child's type without first asking whether there is
     * one.
     */
    public function typeOf(CompiledSource $child): Type
    {
        if ($child->failed()) {
            $this->absorb();
        }

        // The node, not the property: the question the property asks before
        // it answers has just been asked here, and this runs once per operand
        // of every operation in every program.
        return $child->node()->returns;
    }

    /**
     * The structural projection of a compiled child — what it promises, for a
     * compiler about to certify something against it (a field, a member, a
     * case). Absorbs for the same reason {@see overlaps()} does: a child that
     * did not compile promises nothing, and refusing on that would blame this
     * source for the fault below it.
     */
    public function shapeOf(CompiledSource $child): Shape
    {
        return $this->typeOf($child)->shape();
    }

    /**
     * Give up on this source without refusing: a child of it already failed
     * ({@see CompiledSource::failed()}), so this source compiles to
     * {@see ErrorType} too and makes no refusal of its own.
     *
     * Absorbing rather than refusing is what keeps one fault to one
     * diagnostic — a refusal made over a placeholder type would report the
     * fault below a second time, under a second message, at a second node.
     * The judgments this capability offers absorb on their own, so a compiler
     * that judges through them never has to remember; call this directly only
     * for a judgment of your own that it cannot make for you.
     */
    public function absorb(): never
    {
        throw new CompilationAbsorbed();
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
     * The node a child compilation produced, or the refusal it made — after
     * marking the child's position either way. Recording a child that refused
     * is what keeps an index naming one child: without it, the next sibling
     * would take the index of a child that refused, and take a different one
     * in an attempt where that child is set aside instead.
     *
     * @param Result<CompiledNode, TypeMismatch> $result
     */
    private function compiled(Result $result, Source $source, ?string $role): CompiledNode
    {
        if ($result->isErr()) {
            $this->recorder?->child(CompilationNode::abandoned($source::class), $role);
            $this->reject($result->unwrapErr());
        }

        return $result->unwrap();
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
            $resolved->provenance ?? new OperatorRuleProvenance(
                'unattributed',
                ResolvedOperation::class,
                null,
            ),
        ));
    }
}
