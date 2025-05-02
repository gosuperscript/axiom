<?php

namespace Superscript\Abacus\Resolvers;

use Superscript\Abacus\Source;
use Superscript\Abacus\Sources\StaticSource;
use Superscript\Monads\Result\Result;
use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<StaticSource>
 */
final readonly class StaticResolver implements Resolver
{
    public function resolve(Source $source): Result
    {
        return Ok(is_null($source->value) ? None() : Some($source->value));
    }

    public static function supports(Source $source): bool
    {
        return $source instanceof StaticSource;
    }
}