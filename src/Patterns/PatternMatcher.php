<?php

declare(strict_types=1);

namespace Superscript\Axiom\Patterns;

use Superscript\Axiom\Context;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Monads\Result\Result;

/**
 * Core axiom ships matchers for WildcardPattern, LiteralPattern, and ExpressionPattern.
 * Extension packages (e.g. axiom-interval) register additional matchers for their
 * own pattern types (e.g. IntervalPattern).
 */
interface PatternMatcher
{
    public function supports(MatchPattern $pattern): bool;

    /**
     * @return Result<bool, \Throwable>
     */
    public function matches(MatchPattern $pattern, mixed $subjectValue, Context $context): Result;
}
