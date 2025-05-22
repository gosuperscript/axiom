<?php

declare(strict_types=1);

namespace Superscript\Abacus\Tests\Resolvers\Fixtures;

use Superscript\Abacus\Resolvers\Resolver;
use Superscript\Abacus\Source;
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

    public static function supports(Source $source): bool
    {
        return true;
    }
}
