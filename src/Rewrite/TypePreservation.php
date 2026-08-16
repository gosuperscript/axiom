<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;

/**
 * Compile both subtrees in the site's declaration scope and demand the same
 * answer: the same certified type, or the same refusal.
 *
 * The same refusal counts as preservation because neither tree can run — but
 * the refusals must match, so a rewrite is never the thing that changes what
 * an author is told is wrong. A rewrite that turns a refusal into a certified
 * program is the interesting failure: `!!count` over a Number never compiled
 * (`!` admits Boolean only), and simplifying it to `count` would hand back a
 * Number program the author never wrote and the checker never blessed.
 */
final readonly class TypePreservation implements Obligation
{
    public function preservation(): Preservation
    {
        return Preservation::CertifiedType;
    }

    public function check(RewriteSite $site): ObligationVerdict
    {
        $before = $site->compileBefore();
        $after = $site->compileAfter();

        if ($before->isErr() && $after->isErr()) {
            $original = $before->unwrapErr()->describe();
            $replacement = $after->unwrapErr()->describe();

            return $original === $replacement
                ? ObligationVerdict::upheld($this->preservation(), sprintf('both refuse: %s', $original))
                : ObligationVerdict::broken($this->preservation(), sprintf('both refuse, but differently: [%s] against [%s]', $original, $replacement));
        }

        if ($before->isErr()) {
            return ObligationVerdict::broken($this->preservation(), sprintf('the original refuses and the replacement compiles: %s', $before->unwrapErr()->describe()));
        }

        if ($after->isErr()) {
            return ObligationVerdict::broken($this->preservation(), sprintf('the original compiles and the replacement refuses: %s', $after->unwrapErr()->describe()));
        }

        $beforeType = $before->unwrap()->returns;
        $afterType = $after->unwrap()->returns;

        return TypeRelations::areEquivalent($beforeType, $afterType)->isOk()
            ? ObligationVerdict::upheld($this->preservation(), sprintf('both compile to %s', TypeDescriber::describe($beforeType)))
            : ObligationVerdict::broken($this->preservation(), sprintf(
                'the original compiles to %s and the replacement to %s',
                TypeDescriber::describe($beforeType),
                TypeDescriber::describe($afterType),
            ));
    }
}
