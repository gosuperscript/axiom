<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers\Fixtures;

use Superscript\Axiom\Context;
use Superscript\Axiom\Patterns\PatternMatcher;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

final readonly class CustomMatcher implements PatternMatcher
{
    public function supports(MatchPattern $pattern): bool
    {
        return $pattern instanceof CustomMatchPattern;
    }

    public function matches(MatchPattern $pattern, mixed $subjectValue, Context $context): Result
    {
        return Ok($pattern instanceof CustomMatchPattern && $pattern->needle === $subjectValue);
    }
}
