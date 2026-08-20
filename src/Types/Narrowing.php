<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\ReferencePath;

/**
 * One reference retyped for a scope that proved it: the claim a match
 * arm's type pattern makes about the subject its runtime guard admits.
 * The reference and the narrower type travel together because neither
 * means anything without the other.
 *
 * @internal Match compilation owns narrowing; see TypeEnvironment::narrowed().
 */
final readonly class Narrowing
{
    public function __construct(
        public ReferencePath $reference,
        public Type $type,
    ) {}

    public function narrows(ReferencePath $reference): bool
    {
        return $reference->key() === $this->reference->key();
    }
}
