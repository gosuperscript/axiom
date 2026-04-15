<?php

declare(strict_types=1);

namespace Superscript\Axiom\Conformance;

/**
 * @param array<string, string> $artifacts
 * @param array<string, mixed> $input
 */
final readonly class ConformanceCase
{
    /**
     * @param array<string, string> $artifacts
     * @param array<string, mixed> $input
     */
    public function __construct(
        public string $name,
        public string $source,
        public string $expressionName,
        public array $artifacts = [],
        public array $input = [],
    ) {}
}
