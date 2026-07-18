<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Exceptions\EvaluationAborted;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Types\Type;

/**
 * An operation selected during compilation. Source evaluations invoke it as
 * ordinary PHP; an expected operation failure automatically becomes the
 * enclosing source evaluation's Err result.
 */
final readonly class BoundOperation
{
    public Type $returns;

    /** @internal Constructed by SourceCompilation. */
    public function __construct(private ResolvedOperation $operation)
    {
        $this->returns = $operation->returns;
    }

    public function __invoke(mixed ...$operands): mixed
    {
        // Operations are positional. A source compiler may name children for
        // its own callback, but those names never belong to the rule author.
        $result = $this->operation->evaluate(...array_values($operands));

        if ($result->isErr()) {
            throw new EvaluationAborted($result->unwrapErr());
        }

        return $result->unwrap();
    }
}
