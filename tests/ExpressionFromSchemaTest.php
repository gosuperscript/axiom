<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Patterns\ExpressionMatcher;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\MatchResolver;
use Superscript\Axiom\Resolvers\ResolverPreset;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Resolvers\ValueResolver;
use Superscript\Axiom\Schema;
use Superscript\Axiom\SchemaVersion;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Tests\Resolvers\Fixtures\CustomSource;
use Superscript\Axiom\Tests\Resolvers\Fixtures\CustomSourceResolver;
use Superscript\Axiom\Tests\Resolvers\Fixtures\SpyInspector;
use Superscript\Axiom\UnboundSymbols;

#[CoversClass(Expression::class)]
#[UsesClass(Schema::class)]
#[UsesClass(ResolverPreset::class)]
#[UsesClass(DelegatingResolver::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(SymbolResolver::class)]
#[UsesClass(InfixResolver::class)]
#[UsesClass(ValueResolver::class)]
#[UsesClass(MatchResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(NullOverloader::class)]
#[UsesClass(ExpressionMatcher::class)]
#[UsesClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(UnboundSymbols::class)]
final class ExpressionFromSchemaTest extends TestCase
{
    #[Test]
    public function from_schema_resolves_identically_to_the_manual_constructor(): void
    {
        $source = new InfixExpression(
            left: new SymbolSource('PI'),
            operator: '*',
            right: new InfixExpression(
                left: new SymbolSource('radius'),
                operator: '*',
                right: new SymbolSource('radius'),
            ),
        );
        $definitions = new Definitions(['PI' => new StaticSource(3.14)]);

        $manual = new Expression(
            source: $source,
            resolver: ResolverPreset::for(SchemaVersion::V1)->build(),
            definitions: $definitions,
        );

        $fromSchema = Expression::fromSchema(
            schema: new Schema(SchemaVersion::V1, $source),
            definitions: $definitions,
        );

        $this->assertSame(
            $manual(['radius' => 5])->unwrap()->unwrap(),
            $fromSchema(['radius' => 5])->unwrap()->unwrap(),
        );
        $this->assertSame(
            $manual(['radius' => 10])->unwrap()->unwrap(),
            $fromSchema(['radius' => 10])->unwrap()->unwrap(),
        );
    }

    #[Test]
    public function from_schema_records_the_version(): void
    {
        $expression = Expression::fromSchema(
            new Schema(SchemaVersion::V1, new StaticSource(42)),
        );

        $this->assertSame(SchemaVersion::V1, $expression->version);
    }

    #[Test]
    public function customize_closure_can_extend_the_preset(): void
    {
        $expression = Expression::fromSchema(
            schema: new Schema(SchemaVersion::V1, new CustomSource('hi')),
            customize: fn(ResolverPreset $preset) => $preset
                ->withResolver(CustomSource::class, CustomSourceResolver::class),
        );

        $this->assertSame('custom:hi', $expression()->unwrap()->unwrap());
    }

    #[Test]
    public function customize_closure_must_return_a_resolver_preset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The customize closure must return a ResolverPreset.');

        Expression::fromSchema(
            schema: new Schema(SchemaVersion::V1, new StaticSource(42)),
            customize: fn(ResolverPreset $preset) => 'not a preset',
        );
    }

    #[Test]
    public function from_schema_passes_definitions_through(): void
    {
        $expression = Expression::fromSchema(
            schema: new Schema(SchemaVersion::V1, new SymbolSource('greeting')),
            definitions: new Definitions(['greeting' => new StaticSource('hello')]),
        );

        $this->assertSame('hello', $expression()->unwrap()->unwrap());
    }

    #[Test]
    public function from_schema_passes_inspector_through(): void
    {
        $inspector = new SpyInspector();

        $expression = Expression::fromSchema(
            schema: new Schema(SchemaVersion::V1, new StaticSource(42)),
            inspector: $inspector,
        );

        $expression();

        $this->assertSame('static(int)', $inspector->annotations['label']);
    }

    #[Test]
    public function manual_constructor_defaults_to_v1(): void
    {
        $resolver = ResolverPreset::for(SchemaVersion::V1)->build();

        $expression = new Expression(
            source: new StaticSource(1),
            resolver: $resolver,
        );

        $this->assertSame(SchemaVersion::V1, $expression->version);
    }

    #[Test]
    public function with_definitions_preserves_version(): void
    {
        $expression = Expression::fromSchema(
            new Schema(SchemaVersion::V1, new StaticSource(1)),
        );

        $swapped = $expression->withDefinitions(new Definitions());

        $this->assertSame(SchemaVersion::V1, $swapped->version);
    }

    #[Test]
    public function with_inspector_preserves_version(): void
    {
        $expression = Expression::fromSchema(
            new Schema(SchemaVersion::V1, new StaticSource(1)),
        );

        $swapped = $expression->withInspector(new SpyInspector());

        $this->assertSame(SchemaVersion::V1, $swapped->version);
    }
}
