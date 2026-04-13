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
use Superscript\Axiom\Patterns\LiteralMatcher;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\WildcardPattern;

#[CoversClass(LiteralMatcher::class)]
#[UsesClass(LiteralPattern::class)]
#[UsesClass(WildcardPattern::class)]
#[UsesClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
class LiteralMatcherTest extends TestCase
{
    #[Test]
    public function it_supports_literal_pattern(): void
    {
        $matcher = new LiteralMatcher();

        $this->assertTrue($matcher->supports(new LiteralPattern('foo')));
    }

    #[Test]
    public function it_does_not_support_other_patterns(): void
    {
        $matcher = new LiteralMatcher();

        $this->assertFalse($matcher->supports(new WildcardPattern()));
    }

    #[Test]
    public function it_matches_on_strict_equality(): void
    {
        $matcher = new LiteralMatcher();

        $this->assertTrue($matcher->matches(new LiteralPattern('micro'), 'micro', new Context())->unwrap());
    }

    #[Test]
    public function it_does_not_match_on_mismatch(): void
    {
        $matcher = new LiteralMatcher();

        $this->assertFalse($matcher->matches(new LiteralPattern('micro'), 'small', new Context())->unwrap());
    }

    #[Test]
    public function it_is_type_strict_int_vs_string(): void
    {
        $matcher = new LiteralMatcher();

        $this->assertFalse($matcher->matches(new LiteralPattern(42), '42', new Context())->unwrap());
    }

    #[Test]
    public function it_is_type_strict_bool_vs_int(): void
    {
        $matcher = new LiteralMatcher();

        $this->assertFalse($matcher->matches(new LiteralPattern(true), 1, new Context())->unwrap());
    }
}
