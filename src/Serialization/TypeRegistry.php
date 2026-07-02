<?php

declare(strict_types=1);

namespace Superscript\Axiom\Serialization;

use Closure;
use InvalidArgumentException;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\Option;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;

/**
 * The tag → factory map used for hydration only (serialization reads the tag
 * straight off the Type). Doubles as the allowlist of hydratable types.
 */
final class TypeRegistry
{
    /** @var array<string, Closure(list<Type|int|float|bool>): Type> */
    private array $factories = [];

    /**
     * @param Closure(list<Type|int|float|bool>): Type $factory
     */
    public function register(string $tag, Closure $factory): void
    {
        if (isset($this->factories[$tag])) {
            throw new InvalidArgumentException("A type factory is already registered for tag [$tag]");
        }

        $this->factories[$tag] = $factory;
    }

    /**
     * @return Option<Closure(list<Type|int|float|bool>): Type>
     */
    public function get(string $tag): Option
    {
        return isset($this->factories[$tag]) ? Some($this->factories[$tag]) : None();
    }

    public static function default(): self
    {
        $registry = new self();
        $registry->register(NumberType::tag(), fn(array $args) => self::noArgs(NumberType::tag(), $args, new NumberType()));
        $registry->register(StringType::tag(), fn(array $args) => self::noArgs(StringType::tag(), $args, new StringType()));
        $registry->register(BooleanType::tag(), fn(array $args) => self::noArgs(BooleanType::tag(), $args, new BooleanType()));
        $registry->register(ListType::tag(), fn(array $args) => new ListType(self::typeArg(ListType::tag(), $args)));
        $registry->register(DictType::tag(), fn(array $args) => new DictType(self::typeArg(DictType::tag(), $args)));

        return $registry;
    }

    /**
     * @param list<Type|int|float|bool> $args
     */
    private static function noArgs(string $tag, array $args, Type $type): Type
    {
        if ($args !== []) {
            throw new InvalidArgumentException("Type [$tag] does not accept type arguments");
        }

        return $type;
    }

    /**
     * @param list<Type|int|float|bool> $args
     */
    private static function typeArg(string $tag, array $args): Type
    {
        if (count($args) !== 1 || !$args[0] instanceof Type) {
            throw new InvalidArgumentException("Type [$tag] expects exactly one type argument");
        }

        return $args[0];
    }
}
