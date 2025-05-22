<?php

declare(strict_types=1);

namespace Superscript\Abacus\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Abacus\Resolvers\StaticResolver;
use Superscript\Abacus\Source;
use Superscript\Abacus\Sources\StaticSource;

#[CoversClass(StaticResolver::class)]
#[CoversClass(StaticSource::class)]
class StaticResolverTest extends TestCase
{
    #[Test]
    public function it_resolves(): void
    {
        $resolver = new StaticResolver();
        $source = new StaticSource('Hello world!');
        $this->assertTrue($resolver::supports($source));
        $this->assertEquals('Hello world!', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_supports_only_static_sources(): void
    {
        $this->assertTrue(StaticResolver::supports(new StaticSource(42)));
        ;
        $this->assertFalse(StaticResolver::supports(new class implements Source {}));
    }
}
