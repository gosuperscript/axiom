<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\TypeReifier;

/** @internal Compiler for the core unary-expression source. */
final readonly class UnaryExpressionCompiler
{
    /**
     * Optionality propagates through unary operators: resolution sees the
     * present operand type, and evaluation short-circuits absence.
     *
     */
    public static function compile(UnaryExpression $source, SourceCompilation $compilation): CompiledSource
    {
        $operand = $compilation->child($source->operand);
        $shape = $operand->returns->shape();
        $present = $shape instanceof OptionShape ? TypeReifier::reify($shape->inner) : $operand->returns;
        $operation = $compilation->prefix($source->operator, $present);
        $returns = $shape instanceof OptionShape ? new OptionType($operation->returns) : $operation->returns;

        return $compilation->custom($returns, static function (SourceEvaluation $evaluation) use ($operand, $operation, $source) {
            try {
                $value = $evaluation->value($operand);

                if ($value === null) {
                    return null;
                }

                $result = $operation($value);
                $evaluation->annotate('result', $result);

                return $result;
            } finally {
                $evaluation->annotate('label', $source->operator);
            }
        });
    }
}
