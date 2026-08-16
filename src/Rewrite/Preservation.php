<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

/**
 * What a {@see RewriteRule} claims its replacement keeps. A claim is not a
 * proof: each case names an {@see Obligation} the run discharges at every
 * site the rule touches, and a claim with no checker supplied is reported
 * unchecked rather than believed.
 */
enum Preservation: string
{
    /**
     * The rewritten source compiles to the same certified type in the same
     * declaration scope — or both refuse with the same verdict. Always
     * checked, whether or not a rule claims it: the toolkit has the oracle
     * for it in every run, and a rewrite that changes a program's type is
     * never one a host asked for.
     */
    case CertifiedType = 'certified-type';

    /**
     * Both programs answer the same thing on every bindings set of a host
     * corpus. Only the host owns inputs representative of its data, so this
     * is checked exactly when the run is given a {@see BindingsCorpus}.
     */
    case Verdict = 'verdict';

    public function describe(): string
    {
        return match ($this) {
            self::CertifiedType => 'type preservation',
            self::Verdict => 'verdict preservation',
        };
    }
}
