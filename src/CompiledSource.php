<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use LogicException;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Exceptions\EvaluationAborted;
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
 * The source-compiler-facing form of a compiled source, in one of two states.
 * Axiom keeps Runtime, Result<Option<...>>, node construction, and observation
 * scopes behind this interface.
 *
 * A **certified** source is the ordinary one: a certified return type coupled
 * to composable evaluation.
 *
 * A **failed** source is one whose compilation was refused, with that refusal
 * recorded as a diagnostic. It carries no type and no evaluation — there is no
 * value for a type to be about — so {@see $returns} refuses, {@see failed()}
 * is the question to ask instead, and the capabilities absorb it rather than
 * judging it. Only error-tolerant compilation produces one:
 * {@see \Superscript\Axiom\Expression::compile()} stops at the first refusal,
 * so a compiler it calls is only ever handed children that compiled.
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
 * ## Failure is a state, not a type
 *
 * There is no type standing for failure — nothing to hand out, nothing to
 * wrap in a type of one's own, nothing to claim back on a later expression.
 * A failed source simply has no type, and {@see $returns} says so. Nothing a
 * compiler legitimately wants is lost: {@see failed()} asks whether it
 * failed, and the judgments on {@see SourceCompilation} answer for it.
 */
final class CompiledSource
{
    /**
     * What this source returns — for a source that compiled. A source that
     * did not has no type to give, so reading this refuses rather than
     * inventing one.
     */
    public Type $returns {
        get => $this->node->failed
            ? throw new LogicException('A source that did not compile has no return type to read; compilation records the failure as a state, not as a type anything may hold. Ask failed() before reading it, or let the compilation capability answer for it: typeOf(), shapeOf() and overlaps() absorb a failed child, and claiming() composes over one.')
            : $this->node->returns;
    }

    /** @internal Use SourceCompilation to construct compiled sources. */
    public function __construct(private readonly CompiledNode $node) {}

    /**
     * Did the source this came from fail to compile? Error-tolerant
     * compilation marks such a source and carries on around it, which leaves
     * a compiler holding a child with no type to judge. A compiler about to judge that child absorbs instead, which the
     * judgments on {@see SourceCompilation} do for it — this is the question
     * they ask.
     */
    public function failed(): bool
    {
        return $this->node->failed;
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

                    $value = OptionLayers::collapse($option->unwrap());

                    return $value === null ? Ok(None()) : self::normalize(fn() => $evaluate($value), false);
                });
            }));
        });
    }

    /**
     * Certify the value seen by mapPresent(). For an optional child this
     * checks its present member; absence remains structural and propagates.
     *
     * A failed child is passed through rather than judged, for the reason
     * every judgment absorbs one: it has no type to put the question to, and
     * a refusal made over it would report the fault below a second time. The
     * claim this certification is a precondition for absorbs too, so nothing
     * an expression can observe turns on the pass.
     */
    public function expectPresent(Type $expected): self
    {
        if ($this->node->failed) {
            return $this;
        }

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
                    fn() => $evaluate(OptionLayers::collapse($option->unwrapOr(null))),
                    false,
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
        return self::customWithOptionLayers($returns, $evaluate, false);
    }

    /** @internal Structural core sources may return an Option as a present value. */
    public static function customLayered(Type $returns, callable $evaluate): self
    {
        return self::customWithOptionLayers($returns, $evaluate, true);
    }

    private static function customWithOptionLayers(Type $returns, callable $evaluate, bool $preserveOptionValue): self
    {
        $evaluate = $evaluate(...);

        return new self(CompiledNode::returning($returns, fn(Runtime $runtime) => self::normalize(
            fn() => $evaluate(new SourceEvaluation($runtime)),
            $preserveOptionValue,
        )));
    }

    /** Construct a total constant source; null is absence. */
    public static function constant(Type $returns, mixed $value): self
    {
        return new self(CompiledNode::returning($returns, fn() => Ok(Option::from($value))));
    }

    /**
     * A source that inherits a child's failure. It is in the same state a
     * node the compiler gave up on is in — no type, no evaluation — so a
     * compiler that composed over a broken child produces a node no
     * {@see Program} can be certified from.
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

    /** Mapping over any absence depth produces one optional result layer. */
    private function propagateAbsence(Type $returns): Type
    {
        return $this->node->returns->shape() instanceof OptionShape
            && !$returns->shape() instanceof OptionShape
                ? new OptionType($returns)
                : $returns;
    }

    /** @return Result<\Superscript\Monads\Option\Option<mixed>, \Throwable> */
    private static function normalize(Closure $evaluate, bool $preserveOptionValue): Result
    {
        try {
            $value = $evaluate();
        } catch (EvaluationAborted $aborted) {
            return Err($aborted->failure);
        }

        if ($value instanceof Result) {
            /** @var Result<mixed, \Throwable> $value */
            return $value->map(fn(mixed $result): Option => OptionLayers::normalize($result, $preserveOptionValue));
        }

        return Ok(OptionLayers::normalize($value, $preserveOptionValue));
    }
}
