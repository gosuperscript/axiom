<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\IdentityLiteralType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\PresentType;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Axiom\Types\UnionType;

/**
 * `??` — the authored default for an absent value: the left operand when it
 * is present, the fallback when it is not.
 *
 * This is the one honest way to spell "unanswered counts as X" over an
 * ordering. Ordering rows are strict in present numbers, so
 * `roof_percentage > 0.25` over an optional symbol resolves lifted and
 * types `Boolean?`, which a condition seam requiring a definite verdict
 * rightly refuses. `(roof_percentage ?? 0) > 0.25` discharges the absence
 * before the comparison and types `Boolean`. The assumption lives in the
 * expression the author wrote, not in a dialect that quietly decides what
 * absence means.
 *
 * Typing, with `T` the left's present type:
 * - `T? ?? T → T` — a definite result; the fallback covers absence.
 * - `T? ?? T? → T?` — still optional, so chains collapse left to right and
 *   `a ?? b ?? 0` ends definite exactly when its last arm is.
 * - A fallback outside `T` but of the same kind widens the result to
 *   `T | F`: over a select offering £1,000 and £10,000, `@excess ?? £0`
 *   types `£1,000 | £10,000 | £0`. Narrowing a value to the literals it can
 *   hold must not refuse the default an author gives it; a mistaken
 *   fallback (`@size ?? 'oter'`) is refused where the result is used, by
 *   the comparison or slot that cannot accept it.
 * - The kind is `T` with each literal read as its base type, so a fallback
 *   of another kind is refused: `Option<Number> ?? 'none'` does not compile.
 *
 * Two constant cases are refused as {@see DeadOperation} — the operator is
 * writable only where it does something:
 * - A present left can never fall back.
 * - A left that is never present (the bare `null`, `Option<Never>`) always
 *   falls back, and so does the fallback that is itself `null`.
 *
 * Like equality's `x == null`, this rule reads optional operands on
 * purpose, so it matches in the resolver's first pass and is never lifted
 * ({@see ResolvedOperation::liftedOverAbsence()}) — lifting would strip the
 * very optionality the rule exists to discharge, leaving a present left the
 * rule then calls dead. Evaluation is total: absence is `null` in the
 * operand channel, and every operand pair answers a value.
 *
 * Unlike PHP's `??`, this does not short-circuit. Operands are values by
 * the time a rule sees one, and the infix node evaluates both children
 * before applying the operation, so a fallback that errs (a division by
 * zero) or costs (a host source that calls a service) does so even when the
 * left operand is present. Keep the fallback cheap and total.
 */
final readonly class Coalesce implements BinaryOperatorRule, IdentifiedOperatorRule
{
    public function operator(): string
    {
        return '??';
    }

    public function identifier(): string
    {
        return 'axiom.option.coalesce';
    }

    public function resolve(Type $left, Type $right): OperatorResolution
    {
        $shape = $left->shape();

        if ($shape instanceof UnknownShape) {
            return new UnsupportedOperation(sprintf(
                '[??] cannot discharge absence on %s: Unknown certifies nothing, not even that the value may be absent. Claim its type with an Ascription, or convert it with a Coerce, first.',
                TypeDescriber::describe($left),
            ));
        }

        if (!$shape instanceof OptionShape) {
            return new DeadOperation($this->constant($left, $right, sprintf(
                '%s is always present, so the fallback can never fire',
                TypeDescriber::describe($left),
            )));
        }

        if ($shape->inner instanceof NeverShape) {
            return new DeadOperation($this->constant($left, $right, sprintf(
                '%s is never present, so the result is always the fallback',
                TypeDescriber::describe($left),
            )));
        }

        $rightShape = $right->shape();

        if ($rightShape instanceof OptionShape && $rightShape->inner instanceof NeverShape) {
            return new DeadOperation($this->constant($left, $right, 'the fallback is itself absent, so it discharges nothing'));
        }

        $present = PresentType::of($left);
        $kind = self::kind($present);
        $admitted = TypeRelations::admits($right, new OptionType($kind));

        if ($admitted->isErr()) {
            return new UnsupportedOperation(
                sprintf(
                    '[??] expects a fallback assignable to %s; got %s.',
                    TypeDescriber::describe($kind),
                    TypeDescriber::describe($right),
                ),
                [$admitted->unwrapErr()],
            );
        }

        $result = TypeRelations::admits($right, new OptionType($present))->isOk()
            ? $present
            : UnionType::join($present, PresentType::of($right));

        return new ResolvedOperation(
            $rightShape instanceof OptionShape ? new OptionType($result) : $result,
            static fn(mixed $left, mixed $right) => $left ?? $right,
        );
    }

    /**
     * The kind of value a type holds: each literal read as its base type, so
     * `'micro' | 'small'` is String and `£1,000 | £10,000` is GBP money.
     */
    private static function kind(Type $type): Type
    {
        return match (true) {
            $type instanceof IdentityLiteralType => $type->base,
            $type instanceof LiteralType => match (true) {
                is_string($type->value) => new StringType(),
                is_bool($type->value) => new BooleanType(),
                default => new NumberType(),
            },
            $type instanceof UnionType => UnionType::join(...array_map(self::kind(...), $type->members)),
            default => $type,
        };
    }

    private function constant(Type $left, Type $right, string $reason): string
    {
        return sprintf(
            '[??] between %s and %s is constant: %s.',
            TypeDescriber::describe($left),
            TypeDescriber::describe($right),
            $reason,
        );
    }
}
