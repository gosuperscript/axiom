<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

/**
 * The oracle for one {@see Preservation}. A run holds at most one obligation
 * per preservation — supplying a second for the same one replaces it, the way
 * a host swaps an oracle rather than ranking two.
 */
interface Obligation
{
    /** Which claim this obligation discharges. */
    public function preservation(): Preservation;

    public function check(RewriteSite $site): ObligationVerdict;
}
