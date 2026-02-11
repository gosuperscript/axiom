<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Operators\OverloaderManager;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;

#[CoversClass(InfixExpression::class)]
#[CoversClass(InfixResolver::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(OverloaderManager::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(NullOverloader::class)]
class InfixResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_an_infix_expression()
    {
        $resolver = new InfixResolver(new StaticResolver(), new OverloaderManager([new DefaultOverloader()]));
        $source = new InfixExpression(
            left: new StaticSource(1),
            operator: '+',
            right: new StaticSource(2),
        );
        $this->assertEquals(3, $resolver->resolve($source)->unwrap()->unwrap());
    }
}
