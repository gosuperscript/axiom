<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers\Fixtures;

use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Source;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

final readonly class ResolverWithDependency implements Resolver
{
    public function __construct(
        private Dependency $dependency,
    ) {}

    public function resolve(Source $source): Result
    {
        return Ok(Some($this->dependency->info));
    }
}
