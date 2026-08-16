<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

/** What became of a replacement a rule offered. */
enum RewriteOutcome: string
{
    case Applied = 'applied';

    /** An obligation was broken, so the site keeps its original node. */
    case Refused = 'refused';
}
