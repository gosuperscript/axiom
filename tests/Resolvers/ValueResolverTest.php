<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\ValueDefinition;
use Superscript\Schema\Types\StringType;

#[CoversClass(ValueDefinition::class)]
#[UsesClass(DelegatingResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(StringType::class)]
class ValueResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_a_value()
    {
        $source = new ValueDefinition(new StringType(), new StaticSource('Hello, World!'));

        $resolver = $source->resolver();

        $result = $resolver(new DelegatingResolver());
        $this->assertEquals('Hello, World!', $result->unwrap()->unwrap());
    }
}
