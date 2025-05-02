<?php

namespace Superscript\Abacus\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Abacus\Operators\DefaultOverloader;
use Superscript\Abacus\Operators\OverloaderManager;
use Superscript\Abacus\Sources\InfixExpression;
use Superscript\Abacus\Sources\StaticSource;

#[CoversClass(InfixExpression::class)]
#[CoversClass(InfixResolver::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(OverloaderManager::class)]
class InfixResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_an_infix_expression()
    {
        $resolver = new InfixResolver(new StaticResolver());
        $result = $resolver->resolve(new InfixExpression(new StaticSource(1), '+', new StaticSource(2)));
        $this->assertEquals(3, $result->unwrap()->unwrap());
    }
}
