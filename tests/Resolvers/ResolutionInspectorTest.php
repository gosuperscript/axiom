<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\ComparisonOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\MemberAccessResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Resolvers\UnaryResolver;
use Superscript\Axiom\Resolvers\CoerceResolver;
use Superscript\Axiom\Sources\CoerceSource;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Tests\Resolvers\Fixtures\SpyInspector;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(StaticResolver::class)]
#[CoversClass(InfixResolver::class)]
#[CoversClass(UnaryResolver::class)]
#[CoversClass(SymbolResolver::class)]
#[CoversClass(MemberAccessResolver::class)]
#[CoversClass(CoerceResolver::class)]
#[CoversClass(DelegatingResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(MemberAccessSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(UnaryExpression::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(CoerceSource::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(ComparisonOverloader::class)]
#[UsesClass(NullOverloader::class)]
#[UsesClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
class ResolutionInspectorTest extends TestCase
{
    // -- StaticResolver --

    #[Test]
    public function static_resolver_annotates_label_with_value_type(): void
    {
        $inspector = new SpyInspector();
        $resolver = new StaticResolver();

        $resolver->resolve(new StaticSource(42), new Context(inspector: $inspector));

        $this->assertSame('static(int)', $inspector->annotations['label']);
    }

    #[Test]
    public function static_resolver_annotates_label_for_string_value(): void
    {
        $inspector = new SpyInspector();
        $resolver = new StaticResolver();

        $resolver->resolve(new StaticSource('hello'), new Context(inspector: $inspector));

        $this->assertSame('static(string)', $inspector->annotations['label']);
    }

    #[Test]
    public function static_resolver_annotates_label_for_null_value(): void
    {
        $inspector = new SpyInspector();
        $resolver = new StaticResolver();

        $resolver->resolve(new StaticSource(null), new Context(inspector: $inspector));

        $this->assertSame('static(null)', $inspector->annotations['label']);
    }

    // -- InfixResolver --

    #[Test]
    public function infix_resolver_annotates_label_with_operator(): void
    {
        $inspector = new SpyInspector();
        $resolver = new InfixResolver(new StaticResolver(), new DefaultOverloader());

        $resolver->resolve(new InfixExpression(
            left: new StaticSource(1),
            operator: '+',
            right: new StaticSource(2),
        ), new Context(inspector: $inspector));

        $this->assertSame('+', $inspector->annotations['label']);
    }

    #[Test]
    public function infix_resolver_annotates_result_with_computed_value(): void
    {
        $inspector = new SpyInspector();
        $resolver = new InfixResolver(new StaticResolver(), new DefaultOverloader());

        $resolver->resolve(new InfixExpression(
            left: new StaticSource(3),
            operator: '*',
            right: new StaticSource(4),
        ), new Context(inspector: $inspector));

        $this->assertSame(12, $inspector->annotations['result']);
    }

    #[Test]
    public function infix_resolver_annotates_left_and_right_with_resolved_operand_values(): void
    {
        $inspector = new SpyInspector();
        $resolver = new InfixResolver(new StaticResolver(), new DefaultOverloader());

        $resolver->resolve(new InfixExpression(
            left: new StaticSource(3),
            operator: '+',
            right: new StaticSource(4),
        ), new Context(inspector: $inspector));

        $this->assertSame(3, $inspector->annotations['left']);
        $this->assertSame(4, $inspector->annotations['right']);
    }

    #[Test]
    public function infix_resolver_annotates_left_and_right_with_null_for_none_values(): void
    {
        $inspector = new SpyInspector();
        $resolver = new InfixResolver(new StaticResolver(), new DefaultOverloader());

        $resolver->resolve(new InfixExpression(
            left: new StaticSource(null),
            operator: '==',
            right: new StaticSource(null),
        ), new Context(inspector: $inspector));

        $this->assertNull($inspector->annotations['left']);
        $this->assertNull($inspector->annotations['right']);
    }

    // -- UnaryResolver --

    #[Test]
    public function unary_resolver_annotates_label_with_operator(): void
    {
        $inspector = new SpyInspector();
        $resolver = new UnaryResolver(new StaticResolver());

        $resolver->resolve(new UnaryExpression(
            operator: '!',
            operand: new StaticSource(true),
        ), new Context(inspector: $inspector));

        $this->assertSame('!', $inspector->annotations['label']);
    }

    #[Test]
    public function unary_resolver_annotates_result_with_computed_value(): void
    {
        $inspector = new SpyInspector();
        $resolver = new UnaryResolver(new StaticResolver());

        $resolver->resolve(new UnaryExpression(
            operator: '-',
            operand: new StaticSource(7),
        ), new Context(inspector: $inspector));

        $this->assertSame(-7, $inspector->annotations['result']);
    }

    // -- SymbolResolver --

    #[Test]
    public function symbol_resolver_annotates_label_with_symbol_name(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            definitions: new Definitions(['A' => new StaticSource(2)]),
            inspector: $inspector,
        );

        $resolver->resolve(new SymbolSource('A'), $context);

        $this->assertSame('A', $inspector->annotations['label']);
    }

    #[Test]
    public function symbol_resolver_annotates_label_with_namespace_and_name(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            definitions: new Definitions(['math' => ['pi' => new StaticSource(3.14)]]),
            inspector: $inspector,
        );

        $resolver->resolve(new SymbolSource('pi', 'math'), $context);

        $this->assertSame('math.pi', $inspector->annotations['label']);
    }

    #[Test]
    public function symbol_resolver_annotates_result_with_resolved_value(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            definitions: new Definitions(['A' => new StaticSource(2)]),
            inspector: $inspector,
        );

        $resolver->resolve(new SymbolSource('A'), $context);

        $this->assertSame(2, $inspector->annotations['result']);
    }

    // -- CoerceResolver --

    #[Test]
    public function coerce_resolver_annotates_label_with_type_name(): void
    {
        $inspector = new SpyInspector();
        $resolver = new CoerceResolver(new StaticResolver());

        $resolver->resolve(new CoerceSource(new StaticSource('42'), new NumberType()), new Context(inspector: $inspector));

        $this->assertSame('NumberType', $inspector->annotations['label']);
    }

    #[Test]
    public function coerce_resolver_annotates_coercion_when_value_type_changes(): void
    {
        $inspector = new SpyInspector();
        $resolver = new CoerceResolver(new StaticResolver());

        $resolver->resolve(new CoerceSource(new StaticSource('42'), new NumberType()), new Context(inspector: $inspector));

        $this->assertSame('string -> int', $inspector->annotations['coercion']);
    }

    #[Test]
    public function coerce_resolver_does_not_annotate_coercion_when_value_unchanged(): void
    {
        $inspector = new SpyInspector();
        $resolver = new CoerceResolver(new StaticResolver());

        $resolver->resolve(new CoerceSource(new StaticSource(42), new NumberType()), new Context(inspector: $inspector));

        $this->assertArrayNotHasKey('coercion', $inspector->annotations);
    }

    #[Test]
    public function coerce_resolver_annotates_coercion_for_string_type(): void
    {
        $inspector = new SpyInspector();
        $resolver = new CoerceResolver(new StaticResolver());

        $resolver->resolve(new CoerceSource(new StaticSource(42), new StringType()), new Context(inspector: $inspector));

        $this->assertSame('StringType', $inspector->annotations['label']);
        $this->assertSame('int -> string', $inspector->annotations['coercion']);
    }

    #[Test]
    public function coerce_resolver_coercion_path_works_without_inspector(): void
    {
        $resolver = new CoerceResolver(new StaticResolver());

        $result = $resolver->resolve(new CoerceSource(new StaticSource('42'), new NumberType()), new Context());

        $this->assertSame(42, $result->unwrap()->unwrap());
    }

    // -- MemberAccessResolver --

    #[Test]
    public function member_access_resolver_annotates_label_with_dot_prefixed_property(): void
    {
        $inspector = new SpyInspector();
        $resolver = new MemberAccessResolver(new StaticResolver());

        $resolver->resolve(new MemberAccessSource(
            object: new StaticSource(['name' => 'John']),
            property: 'name',
        ), new Context(inspector: $inspector));

        $this->assertSame('.name', $inspector->annotations['label']);
    }

    #[Test]
    public function member_access_resolver_annotates_result_with_accessed_value(): void
    {
        $inspector = new SpyInspector();
        $resolver = new MemberAccessResolver(new StaticResolver());

        $resolver->resolve(new MemberAccessSource(
            object: new StaticSource(['age' => 30]),
            property: 'age',
        ), new Context(inspector: $inspector));

        $this->assertSame(30, $inspector->annotations['result']);
    }

    // -- DelegatingResolver integration --

    #[Test]
    public function delegating_resolver_passes_context_inspector_to_child_resolvers(): void
    {
        $inspector = new SpyInspector();

        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
        ]);

        $resolver->resolve(new StaticSource(42), new Context(inspector: $inspector));

        $this->assertSame('static(int)', $inspector->annotations['label']);
    }

    #[Test]
    public function delegating_resolver_works_without_inspector(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
        ]);

        $result = $resolver->resolve(new StaticSource(42), new Context());

        $this->assertSame(42, $result->unwrap()->unwrap());
    }

    #[Test]
    public function delegating_resolver_passes_inspector_through_recursive_resolution(): void
    {
        $inspector = new SpyInspector();

        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            CoerceSource::class => CoerceResolver::class,
        ]);

        $resolver->resolve(new CoerceSource(new StaticSource('5'), new NumberType()), new Context(inspector: $inspector));

        $this->assertArrayHasKey('label', $inspector->annotations);
    }
}
