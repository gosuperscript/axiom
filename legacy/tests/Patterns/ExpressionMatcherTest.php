<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Patterns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Patterns\ExpressionMatcher;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\WildcardPattern;

#[CoversClass(ExpressionMatcher::class)]
#[UsesClass(ExpressionPattern::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(WildcardPattern::class)]
#[UsesClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
class ExpressionMatcherTest extends TestCase
{
    #[Test]
    public function it_supports_expression_pattern(): void
    {
        $matcher = new ExpressionMatcher(new StaticResolver());

        $this->assertTrue($matcher->supports(new ExpressionPattern(new StaticSource(true))));
    }

    #[Test]
    public function it_does_not_support_other_patterns(): void
    {
        $matcher = new ExpressionMatcher(new StaticResolver());

        $this->assertFalse($matcher->supports(new WildcardPattern()));
    }

    #[Test]
    public function it_matches_when_expression_resolves_to_subject(): void
    {
        $matcher = new ExpressionMatcher(new StaticResolver());

        $this->assertTrue($matcher->matches(new ExpressionPattern(new StaticSource(true)), true, new Context())->unwrap());
    }

    #[Test]
    public function it_does_not_match_when_expression_resolves_to_different_value(): void
    {
        $matcher = new ExpressionMatcher(new StaticResolver());

        $this->assertFalse($matcher->matches(new ExpressionPattern(new StaticSource(false)), true, new Context())->unwrap());
    }
}
