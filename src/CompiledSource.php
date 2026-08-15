<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use LogicException;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Exceptions\EvaluationAborted;
use Superscript\Axiom\Types\ErrorType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\PresentType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The source-compiler-facing form of a compiled source, in one of two kinds —
 * the same two a {@see \Superscript\Axiom\Analysis\CompilationNode} comes in.
 * Axiom keeps Runtime, Result<Option<...>>, node construction, and observation
 * scopes behind this interface.
 *
 * A **certified** source is the ordinary one: a certified return type coupled
 * to composable evaluation.
 *
 * A **failed** source is one whose compilation was refused, with that refusal
 * recorded as a diagnostic. It carries no type — there is no value for a type
 * to be about — so {@see $returns} refuses, {@see failed()} is the question to
 * ask instead, and the capabilities absorb it rather than judging it. Only
 * error-tolerant compilation produces one: {@see \Superscript\Axiom\Expression::compile()}
 * stops at the first refusal, so a compiler it calls is only ever handed
 * children that compiled.
 *
 * ## Absorption
 *
 * A door that claims a type takes the claim from the compiler and pins it to
 * a value its children produce. Over a child that did not compile there is no
 * such value, so the claim is unfounded and the door must not make it: it
 * answers a failed source instead ({@see absorbed()}), and the compiler's
 * node inherits the failure rather than presenting a checked type over an
 * unchecked subtree.
 *
 * That decision is made in one place — {@see claiming()} — and every such
 * door in the library goes through it: {@see mapPresent()},
 * {@see mapIncludingAbsent()}, {@see apply()} through the first, both doors
 * on {@see CompiledSources}, and the coercion bridge
 * ({@see \Superscript\Axiom\SourceCompilers\AdmissionNode}). A door written
 * later is absorbing by construction if it is built the same way.
 *
 * This is the same absorption {@see SourceCompilation::typeOf()},
 * {@see SourceCompilation::shapeOf()} and {@see SourceCompilation::overlaps()}
 * perform, and it is machinery for the same reason: a compiler must not have
 * to remember.
 *
 * ## The mark is not handed out
 *
 * A failed source is marked internally with {@see ErrorType}, which is the
 * compiler's own and appears nowhere in this library's public surface. A
 * failed child's type was the one channel through which a host could come to
 * hold one, so {@see $returns} refuses rather than answering with the mark.
 * Nothing a compiler legitimately wants is lost: {@see failed()} asks whether
 * it failed, and the judgments on {@see SourceCompilation} answer for it.
 */
final class CompiledSource
{
    /**
     * What this source returns — for a source that compiled. A source that
     * did not has no type to give: error-tolerant compilation marks it with
     * {@see ErrorType}, minted only alongside the diagnostic that explains
     * it, so answering with the mark would let a compiler claim a failure
     * nothing diagnosed. Reading it refuses instead.
     */
    public Type $returns {
        get => $this->failed()
            ? throw new LogicException('A source that did not compile has no return type to read; the compiler marks it with a type of its own, which nothing outside compilation may hold. Ask failed() before reading it, or let the compilation capability answer for it: typeOf(), shapeOf() and overlaps() absorb a failed child, and claiming() composes over one.')
            : $this->node->returns;
    }

    /** @internal Use SourceCompilation to construct compiled sources. */
    public function __construct(private readonly CompiledNode $node) {}

    /**
     * Did the source this came from fail to compile? Error-tolerant
     * compilation types such a source {@see ErrorType} so the walk can carry
     * on around it, which leaves a compiler holding a child with no type to
     * judge. A compiler about to judge that child absorbs instead, which the
     * judgments on {@see SourceCompilation} do for it — this is the question
     * they ask.
     */
    public function failed(): bool
    {
        return $this->node->returns instanceof ErrorType;
    }

    /**
     * Make a claim over compiled children, or absorb their failure. This is
     * the one place absorption is decided: a claim about a type is only
     * honest over children that compiled, so $claim is invoked only then and
     * a failed child answers for the whole door instead.
     *
     * @internal Every door that claims a type is built from this.
     *
     * @param array<array-key, self> $over The children the claim is made over.
     * @param Closure(): self $claim
     */
    public static function claiming(array $over, Closure $claim): self
    {
        if (array_any($over, static fn(self $child): bool => $child->failed())) {
            return self::absorbed();
        }

        return $claim();
    }

    /**
     * Transform a present value. Absence propagates without invoking the
     * callback and makes the result type optional. $returns describes the
     * callback's present result. Plain values succeed; Results pass through.
     */
    public function mapPresent(Type $returns, callable $evaluate): self
    {
        return self::claiming([$this], function () use ($returns, $evaluate): self {
            $evaluate = $evaluate(...);
            $returns = $this->propagateAbsence($returns);

            return new self(CompiledNode::returning($returns, function (Runtime $runtime) use ($evaluate) {
                return $this->node->evaluate($runtime)->andThen(function ($option) use ($evaluate) {
                    if ($option->isNone()) {
                        return Ok(None());
                    }

                    return self::normalize(fn() => $evaluate($option->unwrap()));
                });
            }));
        });
    }

    /**
     * Certify the value seen by mapPresent(). For an optional child this
     * checks its present member; absence remains structural and propagates.
     *
     * A failed child needs no branch of its own here. {@see ErrorType} is
     * Never-shaped, so the check passes without judging anything, and the
     * certification is only ever a precondition for a claim made by the door
     * that follows — which absorbs. A guard here would change nothing an
     * expression can observe. That is why it reads the node's type rather
     * than {@see $returns}: passing vacuously is the behaviour, and the
     * property refuses instead of answering.
     */
    public function expectPresent(Type $expected): self
    {
        $present = PresentType::of($this->node->returns);
        $admitted = TypeRelations::admits($present, $expected);

        if ($admitted->isErr()) {
            throw new CompilationAborted(new TypeMismatch(
                sprintf(
                    'This source must provide %s when present; it provides %s.',
                    TypeDescriber::describe($expected),
                    TypeDescriber::describe($this->node->returns),
                ),
                [$admitted->unwrapErr()],
            ));
        }

        return $this;
    }

    /**
     * Transform a value while representing absence as null. The callback is
     * always invoked after the child successfully evaluates.
     */
    public function mapIncludingAbsent(Type $returns, callable $evaluate): self
    {
        return self::claiming([$this], function () use ($returns, $evaluate): self {
            $evaluate = $evaluate(...);

            return new self(CompiledNode::returning($returns, fn(Runtime $runtime) => $this->node
                ->evaluate($runtime)
                ->andThen(fn($option) => self::normalize(
                    fn() => $evaluate($option->unwrapOr(null)),
                ))));
        });
    }

    /** Apply a unary operation to present values; absence propagates. */
    public function apply(BoundOperation $operation, ?Type $returns = null): self
    {
        return $this->mapPresent(
            $returns ?? $operation->returns,
            fn(mixed $operand) => $operation($operand),
        );
    }

    /**
     * Advanced escape hatch for lazy and source-specific control flow.
     * Already-compiled children are evaluated through SourceEvaluation.
     */
    public static function custom(Type $returns, callable $evaluate): self
    {
        $evaluate = $evaluate(...);

        return new self(CompiledNode::returning($returns, fn(Runtime $runtime) => self::normalize(
            fn() => $evaluate(new SourceEvaluation($runtime)),
        )));
    }

    /** Construct a total constant source; null is absence. */
    public static function constant(Type $returns, mixed $value): self
    {
        return new self(CompiledNode::returning($returns, fn() => Ok(Option::from($value))));
    }

    /**
     * A source that inherits a child's failure — the compiled-source twin of
     * {@see \Superscript\Axiom\Operators\ResolvedOperation::absorbed()}. It carries the same pair a
     * node the compiler gave up on carries — {@see ErrorType} and an
     * evaluation that refuses to run — so a compiler that composed over a
     * broken child produces a node no {@see Program} can be certified from.
     *
     * Nothing outside this class mints one: {@see claiming()} is where the
     * decision to absorb is made, and it is the only caller.
     */
    private static function absorbed(): self
    {
        return new self(CompiledNode::failed());
    }

    /** @internal The compiler and Program consume the execution node. */
    public function node(): CompiledNode
    {
        return $this->node;
    }

    /** Mapping a present value preserves optionality without nesting it. */
    private function propagateAbsence(Type $returns): Type
    {
        return $this->node->returns->shape() instanceof OptionShape
            && !$returns->shape() instanceof OptionShape
                ? new OptionType($returns)
                : $returns;
    }

    /** @return Result<\Superscript\Monads\Option\Option<mixed>, \Throwable> */
    private static function normalize(Closure $evaluate): Result
    {
        try {
            $value = $evaluate();
        } catch (EvaluationAborted $aborted) {
            return Err($aborted->failure);
        }

        if ($value instanceof Result) {
            /** @var Result<mixed, \Throwable> $value */
            return $value->map(Option::from(...));
        }

        return Ok(Option::from($value));
    }
}
