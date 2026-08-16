<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

/**
 * The host's evidence that two programs answer alike: bindings sets drawn
 * from data the host believes representative — stored applications, recorded
 * requests, a generated sweep. Only the host knows what its programs are fed,
 * so the toolkit asks for the cases rather than inventing them.
 *
 * A case is labelled because a disagreement has to be reproducible: the
 * report names the case that disagreed, not the ordinal of an anonymous row.
 */
interface BindingsCorpus
{
    /** @return iterable<string, array<string, mixed>> label => bindings */
    public function cases(): iterable;
}
