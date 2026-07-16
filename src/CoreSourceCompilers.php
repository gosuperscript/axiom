<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use Superscript\Axiom\SourceCompilers\AscriptionSourceCompiler;
use Superscript\Axiom\SourceCompilers\CoerceSourceCompiler;
use Superscript\Axiom\SourceCompilers\InfixExpressionCompiler;
use Superscript\Axiom\SourceCompilers\MatchExpressionCompiler;
use Superscript\Axiom\SourceCompilers\MemberAccessSourceCompiler;
use Superscript\Axiom\SourceCompilers\StaticSourceCompiler;
use Superscript\Axiom\SourceCompilers\SymbolSourceCompiler;
use Superscript\Axiom\SourceCompilers\UnaryExpressionCompiler;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * The language's source compiler registry. Each compiler owns one exact
 * core Source class and consumes only the same {@see SourceCompilation}
 * capability available to host extensions.
 */
final readonly class CoreSourceCompilers
{
    /**
     * @return array<class-string<Source>, Closure(Source, SourceCompilation): Result<CompiledNode, TypeMismatch>>
     */
    public static function compilers(): array
    {
        /** @var array<class-string<Source>, Closure(Source, SourceCompilation): Result<CompiledNode, TypeMismatch>> */
        return [
            StaticSource::class => StaticSourceCompiler::compile(...),
            SymbolSource::class => SymbolSourceCompiler::compile(...),
            Coerce::class => CoerceSourceCompiler::compile(...),
            Ascription::class => AscriptionSourceCompiler::compile(...),
            UnaryExpression::class => UnaryExpressionCompiler::compile(...),
            InfixExpression::class => InfixExpressionCompiler::compile(...),
            MatchExpression::class => MatchExpressionCompiler::compile(...),
            MemberAccessSource::class => MemberAccessSourceCompiler::compile(...),
        ];
    }
}
