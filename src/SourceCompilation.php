<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use RuntimeException;
use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\Analysis\OperatorRuleProvenance;
use Superscript\Axiom\Analysis\OperatorSelection;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Exceptions\CompilationAbsorbed;
use Superscript\Axiom\Fields\OpaqueField;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\SourceCompilers\FieldAccess;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeReifier;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Straight-line compilation capability handed to a source compiler. It
 * compiles persisted children, binds typed operations, and constructs
 * composable CompiledSources while keeping TypeInference, Runtime, Result,
 * and Option behind the compiler seam.
 *
 * A failed child, reference, operation, or literal judgment aborts only the
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
     * @param Closure(ReferencePath, string): Result<CompiledNode, TypeMismatch> $compileReference
     * @param Closure(ReferencePath): ?Result<CompiledNode, TypeMismatch> $compileInputPath
     * @param Closure(SymbolSource, string): ?Result<CompiledNode, TypeMismatch> $compileLegacyDefinition
     * @param Closure(mixed): Result<Type, TypeMismatch> $typeOfValue
     * @param ?Closure(string, string): ?OpaqueField $resolveOpaqueField
     */
    public function __construct(
        private Closure $compileNode,
        private Closure $compileInfix,
        private Closure $compilePrefix,
        private Closure $compileReference,
        private Closure $compileInputPath,
        private Closure $compileLegacyDefinition,
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
     * Bind one binary operation once from certified operand types. Every
     * operand type a compiler can hold is one {@see typeOf()} answered with,
     * and that door absorbs a failed child before answering — so an operand
     * here is certified by construction and there is nothing to guard.
     */
    public function infix(Type $left, string $operator, Type $right): BoundOperation
    {
        $resolved = $this->require(($this->compileInfix)($left, $operator, $right));
        $this->recordOperator('infix', $operator, [$left, $right], $resolved);

        return new BoundOperation($resolved);
    }

    /** Bind one unary operation once from a certified operand type, which by {@see infix()}'s reasoning is the only kind there is. */
    public function prefix(string $operator, Type $operand): BoundOperation
    {
        $resolved = $this->require(($this->compilePrefix)($operator, $operand));
        $this->recordOperator('prefix', $operator, [$operand], $resolved);

        return new BoundOperation($resolved);
    }

    /** Compile a persisted rooted reference in the current type environment. */
    public function reference(ReferencePath $reference): CompiledSource
    {
        $root = $this->compiled(($this->compileReference)($reference, $this->childPath()), $reference, 'definition');
        $input = ($this->compileInputPath)($reference);

        if ($input === null) {
            $this->recorder?->recordReferences($root->references);
            $compilation = $root->compilation();

            if ($this->recorder !== null && $compilation !== null) {
                $this->recorder->child($compilation, 'definition');
            }

            $node = $root;

            foreach ($reference->properties() as $property) {
                $node = $this->member(new CompiledSource($node), $property)->node();
            }
        } else {
            $node = $this->compiled($input, $reference, null);

            $this->recorder?->recordReferences($node->references);
        }

        return new CompiledSource($node);
    }

    /**
     * @deprecated Persist a {@see ReferencePath} child and call {@see reference()} instead.
     */
    public function symbol(SymbolSource $symbol): CompiledSource
    {
        $definition = ($this->compileLegacyDefinition)($symbol, $this->childPath());

        if ($definition === null) {
            return $this->reference($symbol->reference());
        }

        $node = $this->compiled($definition, $symbol, 'definition');
        $this->recorder?->recordReferences($node->references);
        $compilation = $node->compilation();

        if ($this->recorder !== null && $compilation !== null) {
            $this->recorder->child($compilation, 'definition');
        }

        return new CompiledSource($node);
    }

    /** Project one certified structural or declared opaque member. */
    public function member(CompiledSource $object, string $property): CompiledSource
    {
        $access = $this->resolveMember($this->shapeOf($object), $property);

        if ($access->isErr()) {
            $this->reject($access->unwrapErr());
        }

        $field = $access->unwrap();

        return $this->custom($field->returns, static function (SourceEvaluation $evaluation) use ($object, $property, $field) {
            try {
                $value = $evaluation->value($object);

                if ($value === null) {
                    return null;
                }

                return ($field->read)($value)
                    ->map(function (Option $option) use ($evaluation) {
                        $option->inspect(fn(mixed $result) => $evaluation->annotate('result', $result));

                        return $option->unwrapOr(null);
                    });
            } finally {
                $evaluation->annotate('label', ".{$property}");
            }
        });
    }

    /**
     * Read a field promised by a structural projection: arrays by key,
     * objects by property.
     *
     * @return Result<Option<mixed>, Throwable>
     */
    private static function accessMember(mixed $value, string $property, bool $optional): Result
    {
        if (is_array($value) && array_key_exists($property, $value)) {
            return Ok(Option::from($value[$property]));
        }

        if (is_object($value) && property_exists($value, $property)) {
            return Ok(Option::from($value->{$property}));
        }

        return $optional
            ? Ok(Option::from(null))
            : Err(new RuntimeException(sprintf("Property '%s' does not exist on %s.", $property, get_debug_type($value))));
    }

    /** @return Result<FieldAccess, TypeMismatch> */
    private function resolveMember(Shape $object, string $property): Result
    {
        if ($object instanceof OptionShape) {
            return $this->resolveMember($object->inner, $property)
                ->map(fn(FieldAccess $inner) => new FieldAccess(new OptionType($inner->returns), $inner->read));
        }

        if ($object instanceof Shapes\UnknownShape) {
            return Err(new TypeMismatch(
                'Member access on Unknown is not certified: Unknown is inert — claim a record type with an Ascription, or convert with a Coerce, first.',
            ));
        }

        if ($object instanceof Shapes\RecordShape) {
            if (isset($object->properties[$property])) {
                $recordProperty = $object->properties[$property];

                return Ok(new FieldAccess(
                    TypeReifier::reify($recordProperty->accessed()),
                    static fn(mixed $value): Result => self::accessMember($value, $property, $recordProperty->optional),
                ));
            }

            return Err(new TypeMismatch(sprintf("Field '%s' does not exist on %s.", $property, TypeDescriber::describeShape($object))));
        }

        if ($object instanceof Shapes\DictShape) {
            return Err(new TypeMismatch(sprintf(
                'Member access on %s is not certified: dict keys are statically unknown and a missing key is a runtime error. Give the value a record type.',
                TypeDescriber::describeShape($object),
            )));
        }

        if ($object instanceof Shapes\OpaqueShape) {
            $field = $this->opaqueField($object->identity, $property);

            if ($field !== null) {
                return Ok(new FieldAccess(
                    $field->returns,
                    static fn(mixed $value): Result => $field->extract($value),
                ));
            }

            return Err(new TypeMismatch(sprintf(
                'Member access on %s is not certified: nominal types make no structural claims.',
                TypeDescriber::describeShape($object),
            )));
        }

        return Err(new TypeMismatch(sprintf("Cannot access field '%s' on %s.", $property, TypeDescriber::describeShape($object))));
    }

    /**
     * Could a value inhabit both of these types? The overlap relation, asked
     * through the capability so a compiler has one place to ask every
     * judgment. Both operands are types {@see typeOf()} answered with, and a
     * failed child is absorbed there rather than typed, so the question is
     * only ever put about types compilation certified.
     *
     * @return Result<bool, TypeMismatch>
     */
    public function overlaps(Type $left, Type $right): Result
    {
        return TypeRelations::overlaps($left, $right);
    }

    /**
     * The certified type of a compiled child, for a compiler that needs the
     * type itself — to bind an operation from, to claim over, to name in a
     * message. A child that did not compile has no type, so reading it is
     * absorption: this compilation gives up rather than judging over an
     * answer that does not exist. {@see CompiledSource::$returns} refuses
     * outright, and this is how a
     * compiler asks for a child's type without first asking whether there is
     * one. It is the only door through which a compiler comes to hold a
     * child's type at all, which is what makes the judgments below safe
     * without guards of their own.
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
     * ({@see CompiledSource::failed()}), so this source compiles to a failed
     * source too and makes no refusal of its own.
     *
     * Absorbing rather than refusing is what keeps one fault to one
     * diagnostic — a refusal made over a child that already failed would
     * report the fault below a second time, under a second message, at a
     * second node.
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
