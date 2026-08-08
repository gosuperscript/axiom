<?php

declare(strict_types=1);

namespace Superscript\Axiom\Predicates;

use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;

/**
 * The propositional shape of a persisted expression.
 *
 * Projection recognizes Axiom's conjunction and disjunction syntax. Every
 * other source remains an opaque atom, including sources contributed by host
 * extensions. Predicate relations can therefore use boolean structure without
 * learning the meaning or internals of domain sources.
 */
abstract readonly class Predicate
{
    public static function fromSource(Source $source): self
    {
        if ($source instanceof InfixExpression && $source->operator === '&&') {
            return AllOf::of(self::fromSource($source->left), self::fromSource($source->right));
        }

        if ($source instanceof InfixExpression && $source->operator === '||') {
            return AnyOf::of(self::fromSource($source->left), self::fromSource($source->right));
        }

        return new Atom($source);
    }

    abstract public function equals(self $other): bool;
}
