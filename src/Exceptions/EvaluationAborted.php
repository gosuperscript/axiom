<?php

declare(strict_types=1);

namespace Superscript\Axiom\Exceptions;

use RuntimeException;
use Throwable;

/** @internal Propagates an expected child or bound-operation failure. */
final class EvaluationAborted extends RuntimeException
{
    public function __construct(public readonly Throwable $failure)
    {
        parent::__construct($failure->getMessage(), previous: $failure);
    }
}
