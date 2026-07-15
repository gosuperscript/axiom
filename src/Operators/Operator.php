<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder;
use Superscript\Axiom\Operators\Signatures\PrefixSignatureBuilder;

/**
 * The front door for declaring operator rules: a signature is a row in a
 * dispatch table — one declaration of operand ownership, compiled to a
 * one-verdict rule (admissibility against the declared types; success
 * carries the declared return type and evaluation).
 *
 * Rules that need more than a row — verdicts computed from the operand
 * types, dead findings, absence-tolerant claims — implement
 * {@see OperatorOverloader}/{@see UnaryOverloader} directly: one method,
 * one obligation (the evaluation is total over the types it resolves for).
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
