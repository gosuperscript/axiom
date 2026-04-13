<?php

declare(strict_types=1);

namespace Superscript\Axiom\Patterns;

use Superscript\Axiom\Context;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

final readonly class WildcardMatcher implements PatternMatcher
{
    public function supports(MatchPattern $pattern): bool
    {
        return $pattern instanceof WildcardPattern;
    }

    public function matches(MatchPattern $pattern, mixed $subjectValue, Context $context): Result
    {
        return Ok(true);
    }
}
