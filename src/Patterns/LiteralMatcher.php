<?php

declare(strict_types=1);

namespace Superscript\Axiom\Patterns;

use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

final readonly class LiteralMatcher implements PatternMatcher
{
    public function supports(MatchPattern $pattern): bool
    {
        return $pattern instanceof LiteralPattern;
    }

    /**
     * @param LiteralPattern $pattern
     */
    public function matches(MatchPattern $pattern, mixed $subjectValue): Result
    {
        return Ok($pattern->value === $subjectValue);
    }
}
