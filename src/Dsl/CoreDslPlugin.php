<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Patterns\LiteralMatcher;
use Superscript\Axiom\Patterns\PatternMatcher;
use Superscript\Axiom\Patterns\WildcardMatcher;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

final readonly class CoreDslPlugin implements DslPlugin
{
    public function operators(OperatorRegistry $operators): void
    {
        // Logical OR
        $operators->register('||', 10, Associativity::Left);

        // Logical AND
        $operators->register('&&', 20, Associativity::Left);

        // Logical XOR
        $operators->register('xor', 15, Associativity::Left, isKeyword: true);

        // Equality
        $operators->register('=', 30, Associativity::Left);
        $operators->register('==', 30, Associativity::Left);
        $operators->register('===', 30, Associativity::Left);
        $operators->register('!=', 30, Associativity::Left);
        $operators->register('!==', 30, Associativity::Left);

        // Comparison
        $operators->register('<', 40, Associativity::Left);
        $operators->register('<=', 40, Associativity::Left);
        $operators->register('>', 40, Associativity::Left);
        $operators->register('>=', 40, Associativity::Left);

        // Collection / keyword operators
        $operators->register('in', 40, Associativity::Left, isKeyword: true);
        $operators->register('has', 40, Associativity::Left, isKeyword: true);
        $operators->register('intersects', 40, Associativity::Left, isKeyword: true);

        // Additive
        $operators->register('+', 50, Associativity::Left);
        $operators->register('-', 50, Associativity::Left);

        // Multiplicative
        $operators->register('*', 60, Associativity::Left);
        $operators->register('/', 60, Associativity::Left);

        // Unary (prefix)
        $operators->register('!', 70, Associativity::Right, OperatorPosition::Prefix);
        $operators->register('not', 70, Associativity::Right, OperatorPosition::Prefix, isKeyword: true);

        // Pipe
        $operators->register('|>', 5, Associativity::Left);
    }

    public function types(TypeRegistry $types): void
    {
        $types->register('number', fn() => new NumberType());
        $types->register('string', fn() => new StringType());
        $types->register('bool', fn() => new BooleanType());
        $types->register('list', fn(mixed ...$args) => new ListType(isset($args[0]) && is_string($args[0]) ? $types->resolve($args[0]) : new StringType()));
        $types->register('dict', fn(mixed ...$args) => new DictType(isset($args[0]) && is_string($args[0]) ? $types->resolve($args[0]) : new StringType()));
    }

    public function functions(FunctionRegistry $functions): void {}

    /** @return list<PatternMatcher> */
    public function patterns(): array
    {
        return [
            new WildcardMatcher(),
            new LiteralMatcher(),
        ];
    }

    /** @return list<DslLiteralExtension> */
    public function literals(): array
    {
        return [];
    }

    /** @return list<OperatorOverloader> */
    public function overloaders(): array
    {
        return [
            new DefaultOverloader(),
        ];
    }
}
