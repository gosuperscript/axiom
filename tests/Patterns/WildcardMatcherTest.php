<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Patterns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Patterns\WildcardMatcher;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\WildcardPattern;

#[CoversClass(WildcardMatcher::class)]
#[UsesClass(WildcardPattern::class)]
#[UsesClass(LiteralPattern::class)]
class WildcardMatcherTest extends TestCase
{
    #[Test]
    public function it_supports_wildcard_pattern(): void
    {
        $matcher = new WildcardMatcher();

        $this->assertTrue($matcher->supports(new WildcardPattern()));
    }

    #[Test]
    public function it_does_not_support_other_patterns(): void
    {
        $matcher = new WildcardMatcher();

        $this->assertFalse($matcher->supports(new LiteralPattern('foo')));
    }

    #[Test]
    public function it_always_matches(): void
    {
        $matcher = new WildcardMatcher();

        $this->assertTrue($matcher->matches(new WildcardPattern(), 'anything')->unwrap());
        $this->assertTrue($matcher->matches(new WildcardPattern(), null)->unwrap());
        $this->assertTrue($matcher->matches(new WildcardPattern(), 42)->unwrap());
    }
}
