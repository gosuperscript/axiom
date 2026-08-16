<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use Superscript\Axiom\Expression;
use Superscript\Axiom\Source;

/**
 * Rewrites stored source: a rule set, applied deterministically to an
 * immutable tree, returning a new tree and a report of everything that
 * happened. Nothing is mutated, and nothing is applied that was not proved.
 *
 * ```php
 * $run = (new Rewriter([new RemoveDoubleNegation()]))->rewrite($expression);
 *
 * $run->report->describe(); // dry run: read this, take nothing
 * $run->changed;            // false when every subtree came back identical
 * $run->source;             // the tree to store
 * ```
 *
 * Four decisions govern a run:
 *
 *  - **Bottom-up, one pass.** Children are rewritten before their parent is
 *    offered to rules, so a rule sees the shape that will be stored.
 *  - **Exact-class dispatch.** A rule declares the classes it visits and is
 *    indexed by them, so a node costs one array lookup no matter how many
 *    rules the run carries.
 *  - **Prove, then apply.** Every replacement is compiled against the
 *    expression's own declarations and must certify the same type as what it
 *    replaces; a rule may claim more, and the run checks each claim it has an
 *    oracle for. A broken obligation refuses that one site — the rest of the
 *    run proceeds — and the refusal is reported. This is the whole point: a
 *    fold that type-checks can still invert the meaning of a condition, and a
 *    rewrite nobody proved is a rewrite nobody should store.
 *  - **Opaque leaves are reported, never skipped.** A node class with no
 *    descent arm is not descended and not rewritten, and it appears in the
 *    report; silence about a shape the run could not see would read exactly
 *    like a clean tree.
 *
 * A rewriter is a value: it holds no run state, so one can be shared across
 * every expression in a corpus.
 */
final readonly class Rewriter
{
    /** @var array<class-string<Source>, list<RewriteRule>> */
    private array $rules;

    /** @var array<string, Obligation> */
    private array $obligations;

    private SourceDescenders $descenders;

    /**
     * @param list<RewriteRule> $rules Applied in this order at every node they visit.
     * @param list<Obligation> $obligations Oracles beyond type preservation — a
     *        {@see VerdictPreservation} over the host's corpus, typically. One
     *        supplied for a preservation the toolkit already answers replaces it.
     */
    public function __construct(
        array $rules,
        ?SourceDescenders $descenders = null,
        array $obligations = [],
    ) {
        $indexed = [];

        foreach ($rules as $rule) {
            foreach ($rule->visits() as $class) {
                $indexed[$class][] = $rule;
            }
        }

        $checkers = [Preservation::CertifiedType->value => new TypePreservation()];

        foreach ($obligations as $obligation) {
            $checkers[$obligation->preservation()->value] = $obligation;
        }

        $this->rules = $indexed;
        $this->obligations = $checkers;
        $this->descenders = $descenders ?? SourceDescenders::core();
    }

    public function rewrite(Expression $expression): RewriteRun
    {
        return (new RewriteWalk($expression, $this->rules, $this->descenders, $this->obligations))->run();
    }
}
