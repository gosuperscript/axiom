<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use InvalidArgumentException;

/**
 * A persistable source body whose named parameters are bound by its owner.
 *
 * A ScopedExpression is not itself a Source: it has no type until the owning
 * source compiler supplies parameter types through
 * {@see SourceCompilation::scope()}. The compiler then resolves its named
 * parameters locally and records every other symbol as a lexical read from
 * the enclosing expression.
 */
final readonly class ScopedExpression
{
    /** @param list<string> $parameters */
    public function __construct(
        public array $parameters,
        public Source $body,
    ) {
        foreach ($parameters as $parameter) {
            if (!is_string($parameter) || $parameter === '' || str_contains($parameter, '.')) {
                throw new InvalidArgumentException('Scoped expression parameters must be non-empty root symbol names.');
            }
        }

        if (count(array_unique($parameters)) !== count($parameters)) {
            throw new InvalidArgumentException('Scoped expression parameter names must be distinct.');
        }
    }
}
