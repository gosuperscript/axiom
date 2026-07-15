<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder;
use Superscript\Axiom\Operators\Signatures\PrefixSignatureBuilder;

/**
 * The front door for declaring operator rules:
 *
 *   Operator::infix('-')
 *       ->signature(new DateType(), new PeriodType())
 *       ->returns(new DateType())
 *       ->evaluate(fn (Date $d, Period $p) => $d->minus($p))
 *
 * The result is a dispatch-table row: it resolves when the operand types
 * fit the declared slots and answers with the declared return type and
 * closure.
 *
 * Rules a fixed row cannot express — answers computed from the operand
 * types, dead-comparison findings, absence-tolerant rules — implement
 * {@see OperatorOverloader}/{@see UnaryOverloader} directly.
 */
final readonly class Operator
{
    public static function infix(string $operator): InfixSignatureBuilder
    {
        return new InfixSignatureBuilder($operator);
    }

    public static function prefix(string $operator): PrefixSignatureBuilder
    {
        return new PrefixSignatureBuilder($operator);
    }
}
