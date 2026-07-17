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
        $result = $this->operation->evaluate(...$operands);

        if ($result->isErr()) {
            throw new EvaluationAborted($result->unwrapErr());
        }

        return $result->unwrap();
    }
}
