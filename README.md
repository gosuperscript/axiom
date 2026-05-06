# Axiom Library

A powerful PHP library for data transformation, type validation, and expression evaluation. This library provides a flexible framework for defining data schemas, transforming values, and evaluating complex expressions with type safety.

## Features

- **Type System**: Robust type validation and transformation for numbers, strings, booleans, lists, and dictionaries
- **Expression Evaluation**: Support for infix expressions with custom operators
- **Match Expressions**: Unified conditional logic — if/then/else, dispatch tables, and cond-style matching
- **Compiled Expressions**: Turn a source tree into a callable you invoke with inputs
- **Resolver Pattern**: Pluggable resolver system for different data sources
- **Operator Overloading**: Extensible operator system for custom evaluation logic
- **Monadic Error Handling**: Built on functional programming principles using Result and Option types

## Requirements

- PHP 8.4 or higher
- ext-intl extension

## Installation

```bash
composer require gosuperscript/axiom
```

## Quick Start

### Expressions as callables

The top-level API is `Expression`: wrap a `Source` tree in a versioned `Schema` envelope, build the expression with `Expression::fromSchema()`, and invoke it with inputs like a function:

```php
<?php

use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Schema;
use Superscript\Axiom\SchemaVersion;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;

// area = PI * radius * radius
$source = new InfixExpression(
    left: new SymbolSource('PI'),
    operator: '*',
    right: new InfixExpression(
        left: new SymbolSource('radius'),
        operator: '*',
        right: new SymbolSource('radius'),
    ),
);

$area = Expression::fromSchema(
    schema: new Schema(SchemaVersion::V1, $source),
    definitions: new Definitions(['PI' => new StaticSource(3.14159)]),
);

$area->parameters(); // ['radius']

$area(['radius' => 5])->unwrap()->unwrap();  // ~78.54
$area(['radius' => 10])->unwrap()->unwrap(); // ~314.16
```

Two ideas to internalize:

1. **The expression's inputs are its parameters**, passed at the call site.
2. **The `Schema` envelope pins a version to the source tree.** When you persist a source and replay it later, the runtime uses the resolver semantics it was authored against — later versions of the library can ship behavior changes without changing the meaning of existing schemas.

### Basic Type Transformation

```php
<?php

use Superscript\Axiom\Expression;
use Superscript\Axiom\Schema;
use Superscript\Axiom\SchemaVersion;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Types\NumberType;

$source = new TypeDefinition(
    type: new NumberType(),
    source: new StaticSource('42'),
);

$expression = Expression::fromSchema(new Schema(SchemaVersion::V1, $source));

$expression()->unwrap()->unwrap(); // 42 (as integer)
```

### Inputs, Definitions, and Namespaces

Inputs are **bindings** — passed at the call site. Stable named expressions (constants, named sub-expressions) are **definitions** — bound once when the `Expression` is constructed. Both support flat names and dotted namespaces.

```php
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Schema;
use Superscript\Axiom\SchemaVersion;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;

$expression = Expression::fromSchema(
    schema: new Schema(SchemaVersion::V1, /* ... */),
    definitions: new Definitions([
        // Global scope
        'version' => new StaticSource('1.0.0'),
        // Namespaced scope
        'math' => [
            'pi' => new StaticSource(3.14159),
            'e'  => new StaticSource(2.71828),
        ],
    ]),
);

// Flat and namespaced inputs
$expression([
    'tier' => 'small',
    'quote' => [
        'claims'   => 3,
        'turnover' => 600000,
    ],
]);
```

`SymbolSource` looks up by name + optional namespace:

```php
new SymbolSource('pi', 'math');      // -> math.pi
new SymbolSource('claims', 'quote'); // -> quote.claims
new SymbolSource('version');         // -> version (global)
```

**Bindings shadow definitions.** A binding with a `null` value is still a real binding — it intentionally shadows any definition of the same name.

### Match Expressions

`MatchExpression` provides a unified way to express conditionals, dispatch tables, and cond-style matching. A match expression has a **subject** and an ordered list of **arms**. Each arm pairs a pattern with a result expression. The first matching arm wins.

**Patterns:**

- **LiteralPattern**: Matches via strict equality (`===`)
- **WildcardPattern**: Always matches (the default/catch-all arm)
- **ExpressionPattern**: Wraps a `Source` — resolves it and compares to the subject

**If/then/else:**

```php
// if quote.claims > 2 then 100 * 0.25 else 0
new MatchExpression(
    subject: new StaticSource(true),
    arms: [
        new MatchArm(
            new ExpressionPattern(
                new InfixExpression(new SymbolSource('claims', 'quote'), '>', new StaticSource(2)),
            ),
            new InfixExpression(new StaticSource(100), '*', new StaticSource(0.25)),
        ),
        new MatchArm(new WildcardPattern(), new StaticSource(0)),
    ],
);
```

**Dispatch table:**

```php
// match tier { "micro" => 1.3, "small" => 1.1, _ => 1.0 }
new MatchExpression(
    subject: new SymbolSource('tier'),
    arms: [
        new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
        new MatchArm(new LiteralPattern('small'), new StaticSource(1.1)),
        new MatchArm(new WildcardPattern(), new StaticSource(1.0)),
    ],
);
```

**Extensible pattern matching:** The default preset wires the built-in matchers (`WildcardPattern`, `LiteralPattern`, `ExpressionPattern`). Extension packages can register their own pattern types (e.g. `IntervalPattern` from `axiom-interval`) via the `customize` closure on `Expression::fromSchema()` — see [Extending the resolver preset](#extending-the-resolver-preset).

### Extending the resolver preset

Most schemas only need the built-in resolvers and the default preset is enough. When you have your own source types, pattern matchers, or want to replace the operator overloader, pass a `customize` closure to `Expression::fromSchema()`. The closure receives a `ResolverPreset` for the schema's version and returns an extended preset:

```php
use Superscript\Axiom\Expression;
use Superscript\Axiom\Resolvers\ResolverPreset;
use Superscript\Axiom\Schema;
use Superscript\Axiom\SchemaVersion;

$expression = Expression::fromSchema(
    schema: new Schema(SchemaVersion::V1, $source),
    customize: fn (ResolverPreset $preset) => $preset
        ->withResolver(IntervalSource::class, IntervalResolver::class)
        ->withMatcher(new IntervalMatcher())
        ->withOverloader(new MyOverloader()),
    definitions: $definitions,
);
```

The version owns all version-sensitive bindings — the resolver class for `TypeDefinition`, the default operator overloader, and the default matcher set. Attempting to override a version-sensitive binding (e.g. `->withResolver(TypeDefinition::class, ...)`) throws — the version contract is enforced structurally so a persisted schema can't accidentally run with the semantics of a different version.

## Core Concepts

### Schema and SchemaVersion

A `Schema` is the canonical persistence shape: a `SchemaVersion` paired with a `Source` tree. When the runtime replays a persisted schema, the version determines which resolver semantics apply — protecting persisted data from behavior drift.

```php
$schema = new Schema(SchemaVersion::V1, $source);
$expression = Expression::fromSchema($schema, definitions: $definitions);
$expression->version; // SchemaVersion::V1
```

Each `SchemaVersion` case pins a coherent set of resolver semantics. New versions are added as new enum cases when a behavior change would otherwise alter the meaning of existing schemas; older cases keep their original semantics so persisted schemas continue to evaluate the same way they always did.

### Persisting and replaying schemas

Axiom doesn't ship a serializer — `Schema` is an envelope, and how you encode it for storage is up to you. The contract to preserve across a round trip:

- `SchemaVersion` is a string-backed enum (`'v1'`), so it survives any encoder.
- Every built-in `Source` class is `final readonly` with public constructor-promoted properties, so they encode straightforwardly. Custom source types should follow the same shape.
- The runtime entry point is `Expression::fromSchema($schema, customize: ..., definitions: ...)` — your codec only needs to reconstitute the `Schema` (version + source tree). Definitions, custom resolvers, and inspectors are wired at call time, not persisted.

For short-lived caches, PHP's native serializer is enough:

```php
$encoded = serialize($schema);

// Restrict allowed classes to limit unsafe deserialization.
$schema = unserialize($encoded, ['allowed_classes' => [
    Schema::class,
    StaticSource::class,
    SymbolSource::class,
    InfixExpression::class,
    /* ...other Source classes you actually use */
]]);

$expression = Expression::fromSchema($schema, definitions: $definitions);
```

For durable storage (database rows, API payloads), encode each `Source` as a tagged structure and decode by dispatching on the tag. Put `version` at the top level so a reader can bail out early on an unknown version:

```json
{
    "version": "v1",
    "source": {
        "kind": "infix",
        "operator": "*",
        "left":  { "kind": "symbol", "name": "PI" },
        "right": { "kind": "symbol", "name": "radius" }
    }
}
```

**Forward-compatibility tip.** A consumer running an older version of axiom may encounter a schema tagged with a `SchemaVersion` case that doesn't exist in their build. Use `SchemaVersion::tryFrom($value)` (which returns `null` for unknown values) rather than `from()` (which throws), and turn the `null` into a clear error for the caller — your decoder shouldn't crash inside the read path.

### Types

The library provides several built-in types for data validation and coercion:

#### NumberType
Validates and coerces values to numeric types (int/float):
- Numeric strings: `"42"` → `42`
- Percentage strings: `"50%"` → `0.5`
- Numbers: `42.5` → `42.5`

#### StringType
Validates and coerces values to strings:
- Numbers: `42` → `"42"`
- Stringable objects: converted to string representation
- Special handling for null and empty values

#### BooleanType
Validates and coerces values to boolean:
- Truthy/falsy evaluation
- String representations: `"true"`, `"false"`

#### ListType and DictType
For collections and associative arrays with nested type validation.

### Type API: Assert vs Coerce

The `Type` interface provides two methods for value processing, following the [@php-standard-library/php-standard-library](https://github.com/php-standard-library/php-standard-library) pattern:

- **`assert(T $value): Result<Option<T>>`** - Validates that a value is already of the correct type
- **`coerce(mixed $value): Result<Option<T>>`** - Attempts to convert a value from any type to the target type

**When to use:**
- Use `assert()` when you expect a value to already be the correct type and want strict validation
- Use `coerce()` when you want to transform values from various input types (permissive conversion)

**Example:**
```php
$numberType = new NumberType();

$numberType->assert(42);     // Ok(Some(42))
$numberType->assert('42');   // Err(TransformValueException)

$numberType->coerce(42);     // Ok(Some(42))
$numberType->coerce('42');   // Ok(Some(42))
$numberType->coerce('45%');  // Ok(Some(0.45))
```

Both methods return `Result<Option<T>, Throwable>` where:
- `Ok(Some(value))` - successful validation/coercion with a value
- `Ok(None())` - successful but no value (e.g., empty strings)
- `Err(exception)` - failed validation/coercion

### Sources

Sources represent different ways to provide data:

- **StaticSource**: Direct values
- **SymbolSource**: Named references resolved from the context's bindings or definitions
- **TypeDefinition**: Combines a type with a source for validation and coercion
- **InfixExpression**: Mathematical/logical expressions
- **UnaryExpression**: Single-operand expressions
- **MatchExpression**: Conditional matching with ordered arms
- **MemberAccessSource**: Chained property/array-key access

### Resolvers

Resolvers handle the evaluation of sources. They are **stateless** — all per-call state (bindings, definitions, the inspector, and the symbol memo) lives on a `Context` threaded through `resolve(Source, Context)`.

You don't normally construct resolvers directly: `Expression::fromSchema()` builds the right stack via a `ResolverPreset` for the schema's version, and the [`customize` closure](#extending-the-resolver-preset) lets you plug in your own. The built-in resolvers are:

- **StaticResolver**: Resolves static values
- **ValueResolver**: Applies type coercion using the `coerce()` method (V1 default for `TypeDefinition`)
- **InfixResolver**: Evaluates binary expressions
- **UnaryResolver**: Evaluates unary expressions
- **SymbolResolver**: Looks up symbols from bindings (first) then definitions (with per-context memoization)
- **MemberAccessResolver**: Evaluates property/array-key access
- **MatchResolver**: Evaluates match expressions with extensible pattern matching
- **DelegatingResolver**: The chain-of-responsibility primitive that dispatches each `Source` class to its registered resolver — produced by `ResolverPreset::build()`

### Context

`Context` carries everything a single call needs:

```php
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;

$context = new Context(
    bindings: new Bindings(['radius' => 5]),
    definitions: new Definitions(['PI' => new StaticSource(3.14159)]),
    inspector: $inspector, // optional
);

$resolver->resolve($source, $context);
```

`Expression::call()` / `Expression::__invoke()` build the `Context` for you from the bindings you pass.

### Operators

The library supports various operators through the overloader system:

- **Binary**: `+`, `-`, `*`, `/`, `%`, `**`
- **Comparison**: `==`, `!=`, `<`, `<=`, `>`, `>=`
- **Logical**: `&&`, `||`
- **Special**: `has`, `in`, `intersects`

### Resolution Inspector

The `ResolutionInspector` interface provides a zero-overhead observability primitive for resolution. Resolvers accept the inspector via the `Context` and annotate metadata about their work. When no inspector is present on the context, resolvers skip annotation entirely via null-safe calls.

**Interface:**

```php
interface ResolutionInspector
{
    public function annotate(string $key, mixed $value): void;
}
```

**Built-in annotations from first-party resolvers:**

| Resolver | Annotations |
|----------|-------------|
| `StaticResolver` | `label`: `"static(int)"`, `"static(string)"`, etc. |
| `ValueResolver` | `label`: type class name (e.g. `"NumberType"`); `coercion`: type change (e.g. `"string -> int"`) |
| `InfixResolver` | `label`: operator (e.g. `"+"`, `"&&"`); `left`, `right`, `result` |
| `UnaryResolver` | `label`: operator (e.g. `"!"`, `"-"`); `result` |
| `SymbolResolver` | `label`: symbol name (e.g. `"A"`, `"math.pi"`); `memo`: `"hit"`/`"miss"`; `result` |
| `MatchResolver` | `label`: `"match"`; `subject`: resolved subject value; `matched_arm`: index of matched arm; `result`: final value |

**Usage:**

```php
use Superscript\Axiom\ResolutionInspector;

final class ResolutionContext implements ResolutionInspector
{
    private array $annotations = [];

    public function annotate(string $key, mixed $value): void
    {
        $this->annotations[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->annotations[$key] ?? null;
    }
}

$inspector = new ResolutionContext();
$expression->withInspector($inspector)(['radius' => 5]);

// Annotations are available via $inspector->get('label'), etc.
```

## Advanced Usage

### Custom Types

Implement the `Type` interface to create custom data validations and coercions:

```php
<?php

use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;
use Superscript\Monads\Result\Err;
use Superscript\Axiom\Exceptions\TransformValueException;
use function Superscript\Monads\Result\Ok;
use function Superscript\Monads\Option\Some;

class EmailType implements Type
{
    public function assert(mixed $value): Result
    {
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return Ok(Some($value));
        }

        return new Err(new TransformValueException(type: 'email', value: $value));
    }

    public function coerce(mixed $value): Result
    {
        $stringValue = is_string($value) ? $value : strval($value);
        $trimmed = trim($stringValue);

        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return Ok(Some($trimmed));
        }

        return new Err(new TransformValueException(type: 'email', value: $value));
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }

    public function format(mixed $value): string
    {
        return (string) $value;
    }
}
```

### Custom Resolvers

Create specialized resolvers for specific data sources. Resolvers must be stateless and read everything they need from the `Context`:

```php
<?php

use Superscript\Axiom\Context;
use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Source;
use Superscript\Monads\Result\Result;

class DatabaseResolver implements Resolver
{
    public function resolve(Source $source, Context $context): Result
    {
        // Custom resolution logic — connect to database, fetch data, etc.
    }
}
```

Plug it in via the `customize` closure on `Expression::fromSchema()`:

```php
$expression = Expression::fromSchema(
    schema: new Schema(SchemaVersion::V1, $source),
    customize: fn (ResolverPreset $preset) => $preset
        ->withResolver(DatabaseSource::class, DatabaseResolver::class),
);
```

### Manual resolver wiring (escape hatch)

The lower-level `Expression` constructor accepts a manually wired `DelegatingResolver`. This bypasses `ResolverPreset` and **does not provide version guarantees** — the expression is tagged `SchemaVersion::V1` regardless of how the resolver is wired. Prefer `Expression::fromSchema()` for any persisted schema. Use this only for ad-hoc scripts or interactive exploration.

```php
<?php

use Superscript\Axiom\Expression;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;

$resolver = new DelegatingResolver([
    StaticSource::class    => StaticResolver::class,
    SymbolSource::class    => SymbolResolver::class,
    InfixExpression::class => InfixResolver::class,
]);
$resolver->instance(OperatorOverloader::class, new DefaultOverloader());

$expression = new Expression($source, $resolver, $definitions);
```

## Development

### Setup

1. Clone the repository
2. Install dependencies: `composer install`
3. Run tests: `composer test`

### Testing

```bash
# Run all tests
composer test

# Individual test suites
composer test:unit      # Unit tests
composer test:types     # Static analysis (PHPStan)
composer test:infection # Mutation testing
```

### Code Quality

- **PHPStan**: Level max static analysis
- **Infection**: Mutation testing for test quality
- **Laravel Pint**: Code formatting
- **100% Code Coverage**: Required for all new code

## Architecture

The library follows several design patterns:

- **Strategy Pattern**: Different resolvers for different source types
- **Chain of Responsibility**: DelegatingResolver chains multiple resolvers
- **Factory Pattern**: Type system for creating appropriate transformations
- **Functional Programming**: Extensive use of Result and Option monads
- **Explicit Per-Call State**: Resolvers are stateless; `Context` carries inputs, definitions, inspector, and memo

## Error Handling

All type validation and coercion operations return `Result<Option<T>, Throwable>` types:

- `Result::Ok(Some(value))`: Successful validation/coercion with value
- `Result::Ok(None())`: Successful validation/coercion with no value (null/empty)
- `Result::Err(exception)`: Validation/coercion failed with error

This approach ensures:
- No exceptions for normal control flow
- Explicit handling of success/failure cases
- Type-safe null handling

## License

This library is open-sourced software licensed under the [MIT license](LICENSE).

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on how to contribute to this project.

## Security

If you discover any security-related issues, please review our [Security Policy](SECURITY.md) for information on how to responsibly report vulnerabilities.
