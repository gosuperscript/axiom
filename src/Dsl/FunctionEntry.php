<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

final readonly class FunctionEntry
{
    /**
     * @param list<FunctionParam> $params
     * @param \Closure(mixed ...): mixed $factory
     */
    public function __construct(
        public string $name,
        public array $params,
        public \Closure $factory,
    ) {}
}
