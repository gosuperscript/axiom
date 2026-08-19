<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Sources\InfixExpression;

/** @internal Compiler for the core infix-expression source. */
final readonly class InfixExpressionCompiler
{
    public static function compile(InfixExpression $source, SourceCompilation $compilation): CompiledSource
    {
        $left = $compilation->child($source->left, 'left');
        $right = $compilation->child($source->right, 'right');
        $operation = $compilation->infix(
            $compilation->typeOf($left),
            $source->operator,
            $compilation->typeOf($right),
        );

        return $compilation->custom($operation->returns, static function (SourceEvaluation $evaluation) use ($left, $right, $operation, $source) {
            try {
                $leftValue = $evaluation->layeredValue($left);
                $rightValue = $evaluation->layeredValue($right);

                $evaluation->annotate('left', $leftValue);
                $evaluation->annotate('right', $rightValue);

                $result = $operation($leftValue, $rightValue);
                $evaluation->annotate('result', $result);

                return $result;
            } finally {
                $evaluation->annotate('label', $source->operator);
            }
        });
    }
}
