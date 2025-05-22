<?php

declare(strict_types=1);

namespace Superscript\Abacus\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Abacus\Operators\BinaryOverloader;
use Superscript\Abacus\Operators\DefaultOverloader;
use Superscript\Abacus\Operators\OverloaderManager;
use Superscript\Abacus\Resolvers\InfixResolver;
use Superscript\Abacus\Resolvers\StaticResolver;
use Superscript\Abacus\Source;
use Superscript\Abacus\Sources\InfixExpression;
use Superscript\Abacus\Sources\StaticSource;

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
        $this->assertTrue($resolver::supports($source));
        $this->assertEquals(3, $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_supports_only_infix_expressions(): void
    {
        $this->assertTrue(InfixResolver::supports(new InfixExpression(new class implements Source {}, '+', new class implements Source {})));
        $this->assertFalse(InfixResolver::supports(new class implements Source {}));
    }
}
