<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Maps object literal values to their types — the plugin seam for domain
 * literals (a money package registers Money::class; a host registers its
 * own value classes). Scalars and arrays never reach this registry: the
 * inference types them structurally.
 */
final readonly class LiteralTypeRegistry
{
    /**
     * @param array<class-string, callable(object): Type> $mappings
     */
    public function __construct(
        private array $mappings = [],
    ) {}

    /**
     * @return Result<Type, TypeMismatch>
     */
    public function resolve(object $value): Result
    {
        foreach ($this->mappings as $class => $factory) {
            if ($value instanceof $class) {
                return Ok($factory($value));
            }
        }

        return Err(new TypeMismatch(sprintf(
            'No literal type is registered for [%s]; register one, or declare the type explicitly with a Coerce or Ascription node.',
            get_class($value),
        )));
    }
}
