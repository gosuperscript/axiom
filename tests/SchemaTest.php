<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Schema;
use Superscript\Axiom\SchemaVersion;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;

#[CoversClass(Schema::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(InfixExpression::class)]
final class SchemaTest extends TestCase
{
    #[Test]
    public function it_pairs_a_version_with_a_source(): void
    {
        $source = new StaticSource(42);
        $schema = new Schema(SchemaVersion::V1, $source);

        $this->assertSame(SchemaVersion::V1, $schema->version);
        $this->assertSame($source, $schema->source);
    }

    #[Test]
    public function it_accepts_any_source_type(): void
    {
        $source = new InfixExpression(
            left: new SymbolSource('a'),
            operator: '+',
            right: new SymbolSource('b'),
        );

        $schema = new Schema(SchemaVersion::V1, $source);

        $this->assertSame($source, $schema->source);
    }
}
