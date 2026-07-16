<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeReifier;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

/** @internal Compiler for the core unary-expression source. */
final readonly class UnaryExpressionCompiler
{
    /**
     * Optionality propagates through unary operators: resolution sees the
     * present operand type, and evaluation short-circuits absence.
     *
     * @return Result<CompiledNode, TypeMismatch>
     */
    public static function compile(UnaryExpression $source, SourceCompilation $compilation): Result
    {
        return $compilation->compile($source->operand)->andThen(function (CompiledNode $operand) use ($source, $compilation) {
            $shape = $operand->returns->shape();
            $present = $shape instanceof OptionShape ? TypeReifier::reify($shape->inner) : $operand->returns;

            return $compilation->prefix($source->operator, $present)
                ->map(function (ResolvedOperation $operation) use ($operand, $source, $shape) {
                    $returns = $shape instanceof OptionShape ? new OptionType($operation->returns) : $operation->returns;

                    return new CompiledNode($returns, static function (Runtime $runtime) use ($operand, $operation, $source) {
                        $result = $operand->evaluate($runtime)
                            ->andThen(fn(Option $option) => $option
                                ->map(fn(mixed $value) => $operation->evaluate($value))
                                ->transpose())
                            ->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $runtime->annotate('result', $value)));

                        $runtime->annotate('label', $source->operator);

                        return $result;
                    });
                });
        });
    }
}
