<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers\Fixtures;

use Superscript\Axiom\Context;
use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Source;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<CustomSource>
 */
final readonly class CustomSourceResolver implements Resolver
{
    public function resolve(Source $source, Context $context): Result
    {
        return Ok(Some('custom:' . $source->tag));
    }
}
