<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Fields\OpaqueField;
use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\UnaryOperatorRule;
use Superscript\Axiom\Types\Type;

/**
 * The package-sized contributor to a {@see Dialect}: what a companion
 * package (money, time) or a host ships to give the language its domain
 * rules — each rule carrying both its runtime and static semantics — and
 * source compilers, whose injected collaborators stay outside persisted
 * Source trees.
 *
 * An abstract class rather than an interface so hooks can be added later
 * (matchers, resolvers) without breaking existing extensions: override
 * only what you contribute.
 */
abstract class Extension
{
    /**
     * Stable owner identity emitted in compilation analysis. Override this
     * when a PHP class name is not a suitable long-lived package identity.
     */
    public function identifier(): string
    {
        return static::class;
    }

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

    /**
     * Computed fields on opaque types: each declares that `identity.name` is
     * certified and how to read it off the concrete value. Any extension may
     * declare a field on any identity, including one introduced by another
     * extension's type — the declarer answers for the extractor being total
     * over that identity's values. Declaring a field another extension already
     * declares — same identity and name — is a loud configuration error,
     * never a precedence question. The
     * declarations are consulted only at the member-access checkpoint and
     * never enter assignability, so a declared field never makes its opaque
     * assignable to a record slot.
     *
     * @return list<OpaqueField>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Exact host Source class → compile-time adapter. The map key must match
     * the concrete Source accepted by the callable. The adapter returns its
     * type claim and evaluation together as one CompiledSource; selection
     * happens once during compilation and list order carries no precedence.
     * The core language's source classes are already owned — Dialect::core()
     * registers them through this same map — so claiming one is the ordinary
     * duplicate-ownership error.
     *
     * @return array<class-string<Source>, callable(Source, SourceCompilation): CompiledSource>
     */
    public function sourceCompilers(): array
    {
        return [];
    }

    /**
     * Exact host Source class → how to descend into it and rebuild it, for
     * {@see \Superscript\Axiom\Rewrite\Rewriter}. Registration mirrors
     * {@see sourceCompilers()} — exact ownership, no precedence — so one
     * package declares both how its node compiles and how a rewrite reaches
     * through it, and the two cannot end up in different places. A class no
     * extension claims is an opaque leaf: never descended, never rewritten,
     * and named in the run's report.
     *
     * An arm receives its source and a {@see \Superscript\Axiom\Rewrite\Descent},
     * asks for each child by the property holding it, and returns the same
     * instance when no child moved.
     *
     * @return array<class-string<Source>, callable(Source, \Superscript\Axiom\Rewrite\Descent): Source>
     */
    public function sourceDescenders(): array
    {
        return [];
    }
}
