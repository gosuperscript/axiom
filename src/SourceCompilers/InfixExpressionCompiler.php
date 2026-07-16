<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

/** @internal Compiler for the core infix-expression source. */
final readonly class InfixExpressionCompiler
{
    /** @return Result<CompiledNode, TypeMismatch> */
    public static function compile(InfixExpression $source, SourceCompilation $compilation): Result
    {
        return $compilation->compile($source->left)->andThen(
            fn(CompiledNode $left) => $compilation->compile($source->right)->andThen(
                fn(CompiledNode $right) => $compilation->infix($left->returns, $source->operator, $right->returns)
                    ->map(fn(ResolvedOperation $operation) => new CompiledNode(
                        $operation->returns,
                        static function (Runtime $runtime) use ($left, $right, $operation, $source) {
                            $result = $left->evaluate($runtime)
                                ->andThen(fn(Option $l) => $right->evaluate($runtime)->map(fn(Option $r) => [$l, $r]))
                                ->andThen(/** @param array{Option<mixed>, Option<mixed>} $operands */ function (array $operands) use ($operation, $runtime) {
                                    [$leftValue, $rightValue] = $operands;

                                    $runtime->annotate('left', $leftValue->unwrapOr(null));
                                    $runtime->annotate('right', $rightValue->unwrapOr(null));

                                    return $operation->evaluate($leftValue->unwrapOr(null), $rightValue->unwrapOr(null))
                                        ->inspect(fn(mixed $value) => $runtime->annotate('result', $value))
                                        ->map(fn(mixed $value) => Option::from($value));
                                });

                            $runtime->annotate('label', $source->operator);

                            return $result;
                        },
                    )),
            ),
        );
    }
}
