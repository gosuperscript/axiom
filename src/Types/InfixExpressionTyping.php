<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Analysis\OperatorRuleProvenance;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

/**
 * The typing judgment for one authored infix expression.
 *
 * Operator overloads describe value operations contributed by a dialect.
 * Comparing with the absence-only type is instead structural elimination:
 * Option<T> is 1 + T and Option<Never> is 1 + 0, so an optional counterpart
 * needs only its outer sum constructor and no equality for T. A known total
 * counterpart is disjoint from absence and therefore gives a constant result.
 *
 * The optional judgment is settled before overloads because it has one core
 * meaning. A total/null theorem is an overload fallback: a compatibility
 * dialect may explicitly retain a published reading, while the strict core
 * takes the constant theorem when no rule claims the pair. Bare Unknown has
 * no statically observable outer constructor and reaches ordinary resolution.
 */
final readonly class InfixExpressionTyping
{
    private const array EqualityNegations = [
        '=' => false,
        '==' => false,
        '===' => false,
        '!=' => true,
        '!==' => true,
    ];

    public function __construct(private BinaryOperatorResolver $overloads) {}

    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $left, Type $right): Result
    {
        if (
            !array_key_exists($operator, self::EqualityNegations)
            || !in_array($operator, $this->overloads->symbols(), true)
        ) {
            return $this->overloads->resolve($operator, $left, $right);
        }

        $leftIsAbsence = self::isAbsenceOnly($left);
        $rightIsAbsence = self::isAbsenceOnly($right);
        $counterpart = match (true) {
            $leftIsAbsence => $right,
            $rightIsAbsence => $left,
            default => null,
        };

        if ($counterpart === null || $counterpart->shape() instanceof UnknownShape) {
            return $this->overloads->resolve($operator, $left, $right);
        }

        $structural = self::nullComparison($operator);

        if ($counterpart->shape() instanceof OptionShape) {
            if ($counterpart->shape()->inner instanceof OptionShape) {
                return Ok(self::nestedNullComparison($operator, counterpartIsLeft: $rightIsAbsence));
            }

            return Ok($structural);
        }

        return $this->overloads->resolveOr($operator, $left, $right, $structural);
    }

    private static function nullComparison(string $operator): ResolvedOperation
    {
        $negated = self::EqualityNegations[$operator];

        return new ResolvedOperation(
            new BooleanType(),
            static fn(mixed $left, mixed $right): bool => $negated
                ? ($left === null) !== ($right === null)
                : ($left === null) === ($right === null),
            new OperatorRuleProvenance(
                identifier: 'axiom.option.null-comparison',
                implementation: self::class,
                extension: 'axiom.core',
            ),
        );
    }

    private static function nestedNullComparison(string $operator, bool $counterpartIsLeft): ResolvedOperation
    {
        $negated = self::EqualityNegations[$operator];

        return (new ResolvedOperation(
            new OptionType(new BooleanType()),
            static function (mixed $left, mixed $right) use ($counterpartIsLeft, $negated): ?bool {
                $counterpart = $counterpartIsLeft ? $left : $right;

                if ($counterpart === null) {
                    return null;
                }

                if (!$counterpart instanceof Option) {
                    return $negated;
                }

                return $negated ? $counterpart->isSome() : $counterpart->isNone();
            },
            new OperatorRuleProvenance(
                identifier: 'axiom.option.nested-null-comparison',
                implementation: self::class,
                extension: 'axiom.core',
            ),
        ))->observingOptionLayers();
    }

    private static function isAbsenceOnly(Type $type): bool
    {
        $shape = $type->shape();

        return $shape instanceof OptionShape && $shape->inner instanceof NeverShape;
    }
}
