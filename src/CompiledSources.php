<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;

/**
 * Named compiled children that can be evaluated as one computation.
 *
 * Both doors here claim a type over every child at once, so one child that
 * did not compile is enough to make the claim unfounded. Neither decides
 * that for itself: both are built from {@see CompiledSource::claiming()},
 * the one place absorption is decided.
 */
final readonly class CompiledSources
{
    /** @param array<array-key, CompiledSource> $sources */
    public function __construct(private array $sources) {}

    /**
     * Evaluate left-to-right and invoke the callback only when every child is
     * present. The first absence short-circuits the remaining children and
     * any optional child makes the result type optional.
     */
    public function mapPresent(Type $returns, callable $evaluate): CompiledSource
    {
        return CompiledSource::claiming($this->sources, function () use ($returns, $evaluate): CompiledSource {
            $sources = $this->sources;
            $evaluate = $evaluate(...);

            if (
                array_any($sources, fn(CompiledSource $source) => $source->returns->shape() instanceof OptionShape)
                && !$returns->shape() instanceof OptionShape
            ) {
                $returns = new OptionType($returns);
            }

            return CompiledSource::custom($returns, function (SourceEvaluation $runtime) use ($sources, $evaluate) {
                $values = [];

                foreach ($sources as $name => $source) {
                    $value = $runtime->value($source);

                    if ($value === null) {
                        return null;
                    }

                    $values[$name] = $value;
                }

                return $evaluate(...$values);
            });
        });
    }

    /** Evaluate every child left-to-right and pass absence as null. */
    public function mapIncludingAbsent(Type $returns, callable $evaluate): CompiledSource
    {
        return CompiledSource::claiming($this->sources, function () use ($returns, $evaluate): CompiledSource {
            $sources = $this->sources;
            $evaluate = $evaluate(...);

            return CompiledSource::custom($returns, function (SourceEvaluation $runtime) use ($sources, $evaluate) {
                $values = [];

                foreach ($sources as $name => $source) {
                    $values[$name] = $runtime->value($source);
                }

                return $evaluate(...$values);
            });
        });
    }

    /** Apply a bound operation after evaluating every operand, including absence. */
    public function applyIncludingAbsent(BoundOperation $operation): CompiledSource
    {
        return $this->mapIncludingAbsent(
            $operation->returns,
            fn(mixed ...$operands) => $operation(...$operands),
        );
    }
}
