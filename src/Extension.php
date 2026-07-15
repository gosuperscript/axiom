<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Axiom\Types\Type;

/**
 * The package-sized contributor to a {@see Dialect}: what a companion
 * package (money, time) or a host ships to give the language its domain
 * rules — each rule carrying both its runtime and static semantics.
 *
 * An abstract class rather than an interface so hooks can be added later
 * (matchers, resolvers) without breaking existing extensions: override
 * only what you contribute.
 */
abstract class Extension
{
    /**
     * Binary operator rules. Prepended to the dialect's existing rules,
     * so a specialization wins ties over core.
     *
     * @return list<OperatorOverloader>
     */
    public function operators(): array
    {
        return [];
    }

    /**
     * Unary operator rules, same prepend semantics.
     *
     * @return list<UnaryOverloader>
     */
    public function unaryOperators(): array
    {
        return [];
    }

    /**
     * Literal registrations: PHP value class → type factory. Registering a
     * class another extension already registered is a loud configuration
     * error, never a precedence question.
     *
     * @return array<class-string, callable(object): Type>
     */
    public function literals(): array
    {
        return [];
    }
}
