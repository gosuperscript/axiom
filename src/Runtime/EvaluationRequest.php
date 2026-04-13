<?php

declare(strict_types=1);

namespace Superscript\Axiom\Runtime;

/**
 * @param array<string, mixed> $input
 */
final readonly class EvaluationRequest
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public string $expressionName,
        public array $input = [],
    ) {}
}
