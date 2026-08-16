<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use Superscript\Axiom\Source;

/**
 * One transformation of stored source: which node shapes it looks at, what it
 * replaces one with, and what it claims the replacement keeps.
 *
 * Structural knowledge lives here rather than on {@see Source}. A source is
 * data a host persists, and every method the language puts on it is a method
 * every host node must implement forever; matching and reconstruction are the
 * rule's business, and a rule that only rewrites `!!x` needs to know about
 * exactly two nodes. Descent — the walk that reaches every node and rebuilds
 * the tree around a replacement — is the toolkit's business, and a rule never
 * writes any of it.
 *
 * A rule is dispatched by exact class, the same ownership model source
 * compilers use: {@see visits()} lists the classes, and the rewriter indexes
 * rules by them, so visiting a node costs one array lookup however many rules
 * a run carries. Exact means exact — a rule listing a parent class is never
 * offered a subclass.
 */
interface RewriteRule
{
    /**
     * Stable identity, reported at every site this rule touches. A class name
     * will do until a rule outlives one.
     */
    public function identifier(): string;

    /**
     * The exact source classes this rule is offered. Only these; a rule is
     * never called with anything else.
     *
     * @return non-empty-list<class-string<Source>>
     */
    public function visits(): array;

    /**
     * The replacement for this node, or null for "nothing to do here" — the
     * common answer, and the one that keeps the tree's identity intact.
     *
     * The node arrives with its children already rewritten. The replacement is
     * taken as final: the rewriter does not descend into it, so a rule that
     * synthesises new structure owns the shape of what it returns.
     */
    public function rewrite(Source $source): ?Source;

    /**
     * What the replacement keeps, beyond the type preservation every rewrite
     * is held to. Each claim is checked at each site the rule fires, and a
     * claim the run has no oracle for is reported unchecked.
     *
     * @return list<Preservation>
     */
    public function preserves(): array;
}
