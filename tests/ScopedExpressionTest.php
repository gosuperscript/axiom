<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\ScopedExpression;

#[CoversClass(ScopedExpression::class)]
#[UsesClass(StaticSource::class)]
final class ScopedExpressionTest extends TestCase
{
    #[Test]
    public function it_keeps_its_parameters_and_body(): void
    {
        $body = new StaticSource(true);
        $expression = new ScopedExpression(['item'], $body);

        $this->assertSame(['item'], $expression->parameters);
        $this->assertSame($body, $expression->body);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidParameters(): iterable
    {
        yield 'duplicate' => [['item', 'item']];
        yield 'empty' => [['']];
        yield 'structural path' => [['item.value']];
        yield 'not a string' => [[1]];
    }

    #[Test]
    #[DataProvider('invalidParameters')]
    public function parameters_are_distinct_root_symbol_names(array $parameters): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ScopedExpression($parameters, new StaticSource(true));
    }
}
