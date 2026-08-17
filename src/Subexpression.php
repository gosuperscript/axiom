<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use InvalidArgumentException;

/**
 * A persistable source body whose named parameters are bound by its owner.
 *
 * A Subexpression is not itself a Source: it has no type until the owning
 * source compiler supplies parameter types through
 * {@see SourceCompilation::subprogram()}. Keeping the binder beside the body
 * nevertheless makes its free symbols structurally knowable before that
 * compilation, which keeps parameter and definition analysis complete.
 */
final readonly class Subexpression
{
    /** @param list<string> $parameters */
    public function __construct(
        public array $parameters,
        public Source $body,
    ) {
        foreach ($parameters as $parameter) {
            if (!is_string($parameter) || $parameter === '' || str_contains($parameter, '.')) {
                throw new InvalidArgumentException('Subexpression parameters must be non-empty root symbol names.');
            }
        }

        if (count(array_unique($parameters)) !== count($parameters)) {
            throw new InvalidArgumentException('Subexpression parameter names must be distinct.');
        }
    }
}
