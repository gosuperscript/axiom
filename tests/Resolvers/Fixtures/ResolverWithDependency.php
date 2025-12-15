<?php

declare(strict_types=1);

namespace Superscript\Lookups\Tests\Fixtures;

use Superscript\Lookups\Resolver;
use Superscript\Monads\Result\Result;
use Superscript\Schema\Source;
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
