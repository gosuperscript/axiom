<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite\Rules;

use Superscript\Axiom\Rewrite\Preservation;
use Superscript\Axiom\Rewrite\RewriteRule;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\UnaryExpression;

/**
 * `!!x` becomes `x`, and so does any pairing of the core dialect's two
 * spellings of negation (`!` and `not`).
 *
 * Why this is meaning-preserving under the core dialect, and not merely
 * plausible: `!` is a row taking Boolean and returning Boolean, and it is the
 * only row for that symbol — so a `!!x` that compiles at all has an `x` of
 * Boolean or, through the resolver's lift, `Boolean?`. On Boolean, `!` is
 * total and involutive: `!!true` is `true`, `!!false` is `false`, and there is
 * no third value. On `Boolean?` the resolver lifts the same row, and lifting
 * is functorial — absence propagates through each negation untouched, so
 * `!!` is the lift of `!∘!`, which is the identity. Every operand type the
 * rewrite can meet is therefore one it is neutral on.
 *
 * What that argument rests on, and what the run checks anyway:
 *
 *  - The named operators must denote that involutive negation. A host free to
 *    register `!` over its own type is free to make it something else, so the
 *    spellings are a constructor argument rather than a constant, and a
 *    dialect that rebinds them declares which of its own are involutive.
 *  - Type preservation catches the operand the argument excludes. `!!count`
 *    over a Number never compiled — `!` admits no Number — and simplifying it
 *    to `count` would mint a certified Number program out of a refusal. The
 *    obligation compares the two compilations and refuses that site.
 *  - The rule claims verdict preservation as well, so a host with a corpus
 *    gets the argument checked against its own data rather than trusted.
 */
final readonly class RemoveDoubleNegation implements RewriteRule
{
    /** @var list<string> */
    private array $negations;

    /**
     * @param list<string> $negations The operator spellings that denote an
     *        involutive negation in the dialect this rule runs against.
     */
    public function __construct(array $negations = ['!', 'not'])
    {
        $this->negations = $negations;
    }

    public function identifier(): string
    {
        return 'axiom.rewrite.remove-double-negation';
    }

    public function visits(): array
    {
        return [UnaryExpression::class];
    }

    public function preserves(): array
    {
        return [Preservation::Verdict];
    }

    public function rewrite(Source $source): ?Source
    {
        if (! $source instanceof UnaryExpression || ! $this->negates($source->operator)) {
            return null;
        }

        $operand = $source->operand;

        if (! $operand instanceof UnaryExpression || ! $this->negates($operand->operator)) {
            return null;
        }

        return $operand->operand;
    }

    private function negates(string $operator): bool
    {
        return in_array($operator, $this->negations, strict: true);
    }
}
