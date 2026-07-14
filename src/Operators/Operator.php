<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder;
use Superscript\Axiom\Operators\Signatures\PrefixSignatureBuilder;

/**
 * The front door for declaring operator rules: a signature is a row in a
 * dispatch table, and one declaration yields both semantics — the runtime
 * claim (strict membership on the declared operand types) and the static
 * verdict (admissibility against the same types) — so the honesty contract
 * holds by construction instead of by discipline.
 *
 * Rules that need more than a row — overlap-based verdicts, dead findings,
 * return types computed from operand types — implement
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
