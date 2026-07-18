<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

/**
 * The front door for declaring operator rules:
 *
 *   Operator::infix('-')
 *       ->takes(new DateType(), new PeriodType())
 *       ->returns(new DateType())
 *       ->evaluatesWith(fn (Date $d, Period $p) => $d->minus($p))
 *
 * The result is a dispatch-table row: it resolves when the operand types
 * fit the declared slots and answers with the declared return type and
 * closure.
 *
 * When a verdict is computed from concrete Type objects, matching() adds a
 * typed class prefilter before resolvesWith(). Fully structural judgments
 * and absence-tolerant rules implement {@see BinaryOperatorRule} or
 * {@see UnaryOperatorRule} directly.
 */
final readonly class Operator
{
    public static function infix(string $operator): InfixOperatorRuleBuilder
    {
        return new InfixOperatorRuleBuilder($operator);
    }

    public static function prefix(string $operator): PrefixOperatorRuleBuilder
    {
        return new PrefixOperatorRuleBuilder($operator);
    }
}
