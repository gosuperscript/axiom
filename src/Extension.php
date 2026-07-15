<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\UnaryOperatorRule;
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
     * Binary operator rules, joined to the dialect's existing rules. Order
     * carries no meaning: no tie is ever resolvable — two rules for one
     * operator with jointly admissible slots are refused at construction,
     * whichever contributed them.
     *
     * @return list<BinaryOperatorRule>
     */
    public function operators(): array
    {
        return [];
    }

    /**
     * Unary operator rules, same join semantics.
     *
     * @return list<UnaryOperatorRule>
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
