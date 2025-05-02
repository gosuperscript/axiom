<?php

namespace Superscript\Abacus\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
        $result = $resolver->resolve($source);

        $this->assertEquals('Hello world!', $result->unwrap()->unwrap());
    }
}
