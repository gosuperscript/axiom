<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Sources\UnaryExpression;

/** @internal Compiler for the core unary-expression source. */
final readonly class UnaryExpressionCompiler
{
    /**
     * Optionality needs no handling here: the resolver lifts a rule matched
     * on the operand's present type, and the lifted operation answers
     * absence itself ({@see \Superscript\Axiom\Operators\ResolvedOperation::liftedOverAbsence()}).
     */
    public static function compile(UnaryExpression $source, SourceCompilation $compilation): CompiledSource
    {
        $operand = $compilation->child($source->operand, 'operand');
        $operation = $compilation->prefix($source->operator, $compilation->typeOf($operand));

        return $compilation->custom($operation->returns, static function (SourceEvaluation $evaluation) use ($operand, $operation, $source) {
            try {
                $value = $evaluation->value($operand);
                $result = $operation($value);
                $evaluation->annotate('result', $result);

                return $result;
            } finally {
                $evaluation->annotate('label', $source->operator);
            }
        });
    }
}
