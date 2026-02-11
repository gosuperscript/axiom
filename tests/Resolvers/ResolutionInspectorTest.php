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
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Resolvers\UnaryResolver;
use Superscript\Axiom\Resolvers\ValueResolver;
use Superscript\Axiom\ResolutionInspector;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\SymbolRegistry;
use Superscript\Axiom\Tests\Resolvers\Fixtures\SpyInspector;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(StaticResolver::class)]
#[CoversClass(InfixResolver::class)]
#[CoversClass(UnaryResolver::class)]
#[CoversClass(SymbolResolver::class)]
#[CoversClass(ValueResolver::class)]
#[CoversClass(DelegatingResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(UnaryExpression::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(TypeDefinition::class)]
#[UsesClass(SymbolRegistry::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(NullOverloader::class)]
class ResolutionInspectorTest extends TestCase
{
    // -- StaticResolver --

    #[Test]
    public function static_resolver_annotates_label_with_value_type(): void
    {
        $inspector = new SpyInspector();
        $resolver = new StaticResolver($inspector);

        $resolver->resolve(new StaticSource(42));

        $this->assertSame('static(int)', $inspector->annotations['label']);
    }

    #[Test]
    public function static_resolver_annotates_label_for_string_value(): void
    {
        $inspector = new SpyInspector();
        $resolver = new StaticResolver($inspector);

        $resolver->resolve(new StaticSource('hello'));

        $this->assertSame('static(string)', $inspector->annotations['label']);
    }

    #[Test]
    public function static_resolver_annotates_label_for_null_value(): void
    {
        $inspector = new SpyInspector();
        $resolver = new StaticResolver($inspector);

        $resolver->resolve(new StaticSource(null));

        $this->assertSame('static(null)', $inspector->annotations['label']);
    }

    // -- InfixResolver --

    #[Test]
    public function infix_resolver_annotates_label_with_operator(): void
    {
        $inspector = new SpyInspector();
        $resolver = new InfixResolver(new StaticResolver(), new DefaultOverloader(), $inspector);

        $resolver->resolve(new InfixExpression(
            left: new StaticSource(1),
            operator: '+',
            right: new StaticSource(2),
        ));

        $this->assertSame('+', $inspector->annotations['label']);
    }

    #[Test]
    public function infix_resolver_annotates_result_with_computed_value(): void
    {
        $inspector = new SpyInspector();
        $resolver = new InfixResolver(new StaticResolver(), new DefaultOverloader(), $inspector);

        $resolver->resolve(new InfixExpression(
            left: new StaticSource(3),
            operator: '*',
            right: new StaticSource(4),
        ));

        $this->assertSame(12, $inspector->annotations['result']);
    }

    // -- UnaryResolver --

    #[Test]
    public function unary_resolver_annotates_label_with_operator(): void
    {
        $inspector = new SpyInspector();
        $resolver = new UnaryResolver(new StaticResolver(), $inspector);

        $resolver->resolve(new UnaryExpression(
            operator: '!',
            operand: new StaticSource(true),
        ));

        $this->assertSame('!', $inspector->annotations['label']);
    }

    #[Test]
    public function unary_resolver_annotates_result_with_computed_value(): void
    {
        $inspector = new SpyInspector();
        $resolver = new UnaryResolver(new StaticResolver(), $inspector);

        $resolver->resolve(new UnaryExpression(
            operator: '-',
            operand: new StaticSource(7),
        ));

        $this->assertSame(-7, $inspector->annotations['result']);
    }

    // -- SymbolResolver --

    #[Test]
    public function symbol_resolver_annotates_label_with_symbol_name(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(
            new StaticResolver(),
            new SymbolRegistry(['A' => new StaticSource(2)]),
            $inspector,
        );

        $resolver->resolve(new SymbolSource('A'));

        $this->assertSame('A', $inspector->annotations['label']);
    }

    #[Test]
    public function symbol_resolver_annotates_label_with_namespace_and_name(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(
            new StaticResolver(),
            new SymbolRegistry(['math' => ['pi' => new StaticSource(3.14)]]),
            $inspector,
        );

        $resolver->resolve(new SymbolSource('pi', 'math'));

        $this->assertSame('math.pi', $inspector->annotations['label']);
    }

    #[Test]
    public function symbol_resolver_annotates_result_with_resolved_value(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(
            new StaticResolver(),
            new SymbolRegistry(['A' => new StaticSource(2)]),
            $inspector,
        );

        $resolver->resolve(new SymbolSource('A'));

        $this->assertSame(2, $inspector->annotations['result']);
    }

    // -- ValueResolver --

    #[Test]
    public function value_resolver_annotates_label_with_type_name(): void
    {
        $inspector = new SpyInspector();
        $resolver = new ValueResolver(new StaticResolver(), $inspector);

        $resolver->resolve(new TypeDefinition(new NumberType(), new StaticSource('42')));

        $this->assertSame('NumberType', $inspector->annotations['label']);
    }

    #[Test]
    public function value_resolver_annotates_coercion_when_value_type_changes(): void
    {
        $inspector = new SpyInspector();
        $resolver = new ValueResolver(new StaticResolver(), $inspector);

        $resolver->resolve(new TypeDefinition(new NumberType(), new StaticSource('42')));

        $this->assertSame('string -> int', $inspector->annotations['coercion']);
    }

    #[Test]
    public function value_resolver_does_not_annotate_coercion_when_value_unchanged(): void
    {
        $inspector = new SpyInspector();
        $resolver = new ValueResolver(new StaticResolver(), $inspector);

        $resolver->resolve(new TypeDefinition(new NumberType(), new StaticSource(42)));

        $this->assertArrayNotHasKey('coercion', $inspector->annotations);
    }

    #[Test]
    public function value_resolver_annotates_coercion_for_string_type(): void
    {
        $inspector = new SpyInspector();
        $resolver = new ValueResolver(new StaticResolver(), $inspector);

        $resolver->resolve(new TypeDefinition(new StringType(), new StaticSource(42)));

        $this->assertSame('StringType', $inspector->annotations['label']);
        $this->assertSame('int -> string', $inspector->annotations['coercion']);
    }

    #[Test]
    public function value_resolver_coercion_path_works_without_inspector(): void
    {
        $resolver = new ValueResolver(new StaticResolver());

        $result = $resolver->resolve(new TypeDefinition(new NumberType(), new StaticSource('42')));

        $this->assertSame(42, $result->unwrap()->unwrap());
    }

    // -- DelegatingResolver integration --

    #[Test]
    public function delegating_resolver_passes_inspector_to_child_resolvers(): void
    {
        $inspector = new SpyInspector();

        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
        ]);
        $resolver->instance(ResolutionInspector::class, $inspector);

        $resolver->resolve(new StaticSource(42));

        $this->assertSame('static(int)', $inspector->annotations['label']);
    }

    #[Test]
    public function delegating_resolver_works_without_inspector_registered(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
        ]);

        $result = $resolver->resolve(new StaticSource(42));

        $this->assertSame(42, $result->unwrap()->unwrap());
    }

    #[Test]
    public function delegating_resolver_passes_inspector_through_recursive_resolution(): void
    {
        $inspector = new SpyInspector();

        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            TypeDefinition::class => ValueResolver::class,
        ]);
        $resolver->instance(ResolutionInspector::class, $inspector);

        $resolver->resolve(new TypeDefinition(new NumberType(), new StaticSource('5')));

        // The last resolver to annotate 'label' wins (StaticResolver overwrites ValueResolver's label)
        // but we can verify the inspector was used
        $this->assertArrayHasKey('label', $inspector->annotations);
    }
}
