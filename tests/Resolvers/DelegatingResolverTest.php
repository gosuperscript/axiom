<?php

declare(strict_types=1);

namespace Superscript\Abacus\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Abacus\Resolvers\DelegatingResolver;
use Superscript\Abacus\Resolvers\StaticResolver;
use Superscript\Abacus\Sources\StaticSource;

#[CoversClass(DelegatingResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(StaticResolver::class)]
class DelegatingResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_by_delegating_to_another_resolver(): void
    {
        $resolver = new DelegatingResolver([
            StaticResolver::class,
        ]);
        $result = $resolver->resolve(new StaticSource('Hello world!'));
        $this->assertEquals('Hello world!', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_throws_an_exception_if_no_resolver_can_handle_the_source(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No resolver found for source of type ' . StaticSource::class);

        $resolver = new DelegatingResolver([]);
        $resolver->resolve(new StaticSource('Hello world!'));
    }
}
