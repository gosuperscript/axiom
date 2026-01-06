<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Sources\StaticSource;

#[CoversClass(StaticSource::class)]
class StaticResolverTest extends TestCase
{
    #[Test]
    public function it_resolves(): void
    {
        $source = new StaticSource('Hello world!');
        $resolver = $source->resolver();
        $this->assertEquals('Hello world!', $resolver()->unwrap()->unwrap());
    }
}
