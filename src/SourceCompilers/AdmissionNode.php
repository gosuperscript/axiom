<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Closure;
use RuntimeException;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/** @internal Shared runtime construction for the two admission bridges. */
final readonly class AdmissionNode
{
    /**
     * Evaluate the inner node, convert present values through the bridge,
     * guard absence, and normalize the admitted value.
     *
     * @param Closure(mixed, Runtime): Result<Option<mixed>, Throwable> $convert
     */
    public static function from(CompiledNode $inner, Type $type, Closure $convert, string $missing, string $label): CompiledNode
    {
        $optional = $type->shape() instanceof OptionShape;
        $missing = sprintf($missing, TypeDescriber::describe($type), TypeDescriber::describe(new OptionType($type)));

        return new CompiledNode($type, static function (Runtime $runtime) use ($inner, $convert, $optional, $missing, $label) {
            $result = $inner->evaluate($runtime)
                ->andThen(fn(Option $option) => $option
                    ->andThen(fn(mixed $value) => $convert($value, $runtime)->transpose())
                    ->transpose())
                ->andThen(static fn(Option $option) => $option->isNone() && !$optional
                    ? Err(new RuntimeException($missing))
                    : Ok($option))
                // One representation of null in the resolution channel: an
                // Option-typed admission emits Some(null), the boundary
                // protocol; downstream it travels as None.
                ->map(fn(Option $option) => $option->andThen(fn(mixed $value) => Option::from($value)));

            $runtime->annotate('label', $label);

            return $result;
        });
    }
}
