<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\LookupResolver\Support\Filters\InfixExpression;
use Superscript\LookupResolver\Support\Filters\StaticSource;
use Superscript\Lookups\InfixResolver;
use Superscript\Lookups\StaticResolver;
use Superscript\Schema\Operators\BinaryOverloader;
use Superscript\Schema\Operators\DefaultOverloader;
use Superscript\Schema\Operators\OverloaderManager;

#[CoversClass(InfixExpression::class)]
#[CoversClass(InfixResolver::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(OverloaderManager::class)]
#[UsesClass(BinaryOverloader::class)]
class InfixResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_an_infix_expression()
    {
        $resolver = new InfixResolver(new StaticResolver());
        $source = new InfixExpression(
            left: new StaticSource(1),
            operator: '+',
            right: new StaticSource(2),
        );
        $this->assertEquals(3, $resolver->resolve($source)->unwrap()->unwrap());
    }
}
