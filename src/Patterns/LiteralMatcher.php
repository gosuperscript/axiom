<?php

declare(strict_types=1);

namespace Superscript\Axiom\Patterns;

use Superscript\Axiom\Context;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

/**
 * Matches by the one value-equality definition the exhaustiveness analysis
 * and the comparison operators also consume — a matcher stricter than the
 * coverage rule would certify exhaustiveness the runtime then fails
 * (match 5 { 5.0 => ... }).
 */
final readonly class LiteralMatcher implements PatternMatcher
{
    public function supports(MatchPattern $pattern): bool
    {
        return $pattern instanceof LiteralPattern;
    }

    /**
     * @param LiteralPattern $pattern
     */
    public function matches(MatchPattern $pattern, mixed $subjectValue, Context $context): Result
    {
        return Ok(ValueEquality::equals($pattern->value, $subjectValue));
    }
}
