# Axiom Library

A powerful PHP library for data transformation, type validation, and expression evaluation. This library provides a flexible framework for defining data schemas, transforming values, and evaluating complex expressions with type safety.

## Features

- **Type System**: Robust type validation and transformation for numbers, strings, booleans, lists, dictionaries, records, options, literals, and unions
- **Static Type Checking**: Infer and check the type of any expression *before* evaluating it — dead comparisons, non-exhaustive matches, and type errors surface as diagnostics, not runtime surprises ([RFC 0001](docs/rfc/0001-typesafe-axiom.md))
- **Expression Evaluation**: Support for infix expressions with custom operators
- **Match Expressions**: Unified conditional logic — if/then/else, dispatch tables, and cond-style matching
- **Compiled Expressions**: Turn a source tree into a callable you invoke with inputs
- **Resolver Pattern**: Pluggable resolver system for different data sources
- **Operator Overloading**: Extensible operator system where every rule owns its runtime *and* static semantics in one class, so they can never drift
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

The top-level API is `Expression`: wrap a `Source` tree with the resolver stack you want, then invoke it with inputs like a function:

```php
<?php

use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\NumberType;

$resolver = new DelegatingResolver([
    StaticSource::class   => StaticResolver::class,
    SymbolSource::class   => SymbolResolver::class,
    InfixExpression::class => InfixResolver::class,
]);
// Resolvers hold no operator state: the operator rules (the Dialect)
// travel with each evaluation, so one resolver graph serves any number
// of expressions with different dialects.

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

$area = new Expression(
    source: $source,
    resolver: $resolver,
    definitions: new Definitions(['PI' => new StaticSource(3.14159)]),
    declarations: ['radius' => new NumberType()],
);

$area->parameters(); // ['radius']

$area(['radius' => 5])->unwrap()->unwrap();  // ~78.54
$area(['radius' => 10])->unwrap()->unwrap(); // ~314.16
```

The key idea: the expression's inputs are its **parameters**, passed at the call site — and the declaration list is the expression's complete public signature (undeclared binding keys never enter; a parameter you cannot type yet is declared `Unknown` explicitly).

### Basic Type Transformation

```php
<?php

use Superscript\Axiom\Expression;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\CoerceResolver;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Types\NumberType;

$resolver = new DelegatingResolver([
    StaticSource::class   => StaticResolver::class,
    Coerce::class => CoerceResolver::class,
]);

$source = new Coerce(
    type: new NumberType(),
    source: new StaticSource('42'),
);

$expression = new Expression($source, $resolver);

$expression()->unwrap()->unwrap(); // 42 (as integer)
```

### Inputs, Definitions, and Namespaces

Inputs are **bindings** — passed at the call site. Stable named expressions (constants, named sub-expressions) are **definitions** — bound once when the `Expression` is constructed. Both support flat names and dotted namespaces.

```php
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;

$expression = new Expression(
    source: /* ... */,
    resolver: $resolver,
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

**An array binding is both a record and a namespace.** `['quote' => ['claims' => 3]]` binds `quote` whole (a record value — coercible at the boundary, member-accessible) *and* answers the namespaced lookup `quote.claims` by descent. An explicit dotted key (`'quote.claims' => 3`) wins over descent.

**Declarations and definitions are disjoint namespaces.** A symbol is a *parameter* (declared, supplied by bindings) or a *derived value* (defined), never both — a collision, including through the record view (declaring `customer` as a record with a `turnover` field declares `customer.turnover`), is a constructor error. The boundary strips undeclared binding keys before evaluation, so shadowing a definition is unrepresentable. To let callers override a derived value, model the override in-language: an `Option`-typed parameter the definition consults.

### Match Expressions

`MatchExpression` provides a unified way to express conditionals, dispatch tables, and cond-style matching. A match expression has a **subject** and an ordered list of **arms**. Each arm pairs a pattern with a result expression. The first matching arm wins — and a match where **no** arm matches is a runtime error, so add a wildcard arm for a deliberate default (the checker enforces this: unprovable exhaustiveness is a compile diagnostic).

**Patterns:**

- **LiteralPattern**: Matches via **value equality** — the same one definition the comparison operators and the exhaustiveness analysis use (`5` matches `5.0`; never PHP juggling across bases)
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

**Extensible pattern matching:** The `MatchResolver` delegates pattern evaluation to a registry of `PatternMatcher` implementations. Extension packages can register their own pattern types (e.g. `IntervalPattern` from `axiom-interval`) without modifying core axiom:

```php
$matchers = [
    new WildcardMatcher(),
    new LiteralMatcher(),
    new ExpressionMatcher($resolver),
    // Add custom matchers from extension packages here
];

$resolver->instance(MatchResolver::class, new MatchResolver($resolver, $matchers));
```

### Static Type Checking and Typed Bindings

Declare your input types once on the `Expression`, and it can **type itself before evaluating anything** — through the same `Dialect` (operator rules) and `Definitions` the evaluator runs, so static and runtime semantics cannot be composed differently:

```php
use Superscript\Axiom\Expression;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;

$gate = new Expression(
    source: $condition,                                  // quote.turnover * 1.2 > 500000
    resolver: $resolver,
    definitions: $definitions,
    declarations: ['quote.turnover' => new NumberType()], // one map, both faces
);

$gate->infer();                    // Ok(BooleanType) — what does this return?
$gate->check(new BooleanType());   // certified

$gate(['quote' => ['turnover' => '600000']]);
// the BOUNDARY coerces '600000' → 600000 through the declared type
// before evaluation — certified expressions never see raw garbage

$gate(['quote' => ['turnover' => 'lots']]);
// Err(BoundaryViolation): "binding [quote.turnover]: …" — aggregated,
// named by input, before any evaluation
```

Certification is a conditional guarantee ("*if* inputs inhabit their declared types…") and the boundary establishes the condition: declared bindings pass through their declared types (`coerce` by default, `Boundary::Assert` for strict hosts), required inputs must be present, and every undeclared binding key is stripped — the declaration list is the expression's complete public signature. A parameter this scope cannot type is declared `Unknown` explicitly; that is the gradual path.

The checker reports, with a nested cause chain (`TypeMismatch::describe()`):

- **Type errors** — `"abc" * 2`, `!5`, arithmetic on a possibly-absent value
- **Dead code** — comparisons and membership tests that are statically constant (`kind == "warehouse"` when `kind` is `'shop' | 'office'`), flagged via `TypeMismatch::$dead`
- **Non-exhaustive matches** — a `match` without a wildcard arm over a subject it cannot prove covered (an unmatched subject is a runtime error)
- **False ascriptions** — an `Ascription` whose claimed type is disjoint from the value's
- **Unbound and cyclic symbols** — definition cycles are a standalone graph pass (declarations answer typing, never termination), and the runtime backstops it: re-entrant resolution errs by name instead of recursing unboundedly

Inference is **literal-first**: `'shop'` types as the literal `'shop'` (assignable to `String` wherever needed), `['shop', 'office']` as `List<'shop' | 'office', 2>` — which is what makes enum-style checking precise. Gradual typing is available through `UnknownType`: it is admitted at every operand position and certifies nothing. (The lower-level `TypeInference`/`TypeEnvironment` API remains available for corpus sweeps over stored programs.)

See [RFC 0001: Typesafe Axiom](docs/rfc/0001-typesafe-axiom.md) for the full design, including the sealed shape algebra and relation laws.

## Core Concepts

### Types

The library provides several built-in types for data validation and coercion:

#### NumberType
Validates and coerces values to numeric types (int/float):
- Numeric strings: `"42"` → `42` (coercion only)
- Percentage strings: `"50%"` → `0.5` (coercion only)
- Numbers: `42.5` → `42.5`

#### StringType
Validates and coerces values to strings:
- Numbers: `42` → `"42"` (coercion only)
- Stringable objects: converted to string representation (coercion only)
- Coercion reads `''` and `'null'` as absence; under `assert` they are ordinary strings

#### BooleanType
Validates and coerces values to boolean:
- String representations: `"true"`/`"false"`, `"yes"`/`"no"`, `"on"`/`"off"`, `"1"`/`"0"` (coercion only)
- Coercing `null` yields absence, never a silent `false`

#### ListType and DictType
For collections and associative arrays with nested type validation. `ListType` optionally carries length bounds (`min`/`max`), enforced by `assert` and `coerce` and visible to the checker.

#### OptionType
A possibly-absent value. `null` is a legal, *present* value of the option — coercing `null` yields `Some(null)`, not a failed coercion. That is what lets an optional field live inside a record whose required fields treat absence as "missing".

#### RecordType
Named, individually typed fields, open or closed. An optional field is a field whose type is `OptionType`; coercion canonicalizes a missing optional key to a present `null`. Closed records reject undeclared keys; open records pass them through.

#### LiteralType and UnionType
A singleton of a scalar (`new LiteralType('shop')`) and a set of alternatives. An enum is a union of literals:

```php
$tier = new UnionType(new LiteralType('micro'), new LiteralType('small'));
$tier->assert('micro'); // Ok(Some('micro'))
$tier->assert('large'); // Err — not a member
```

#### UnknownType and NeverType
The gradual-typing escape hatch (admits everything, certifies nothing) and the bottom type (no value inhabits it). Both are produced by inference, never declared by authors.

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
- `Ok(None())` - successful but no value (absence readings live in `coerce`, the lenient input boundary — `assert` is strict membership)
- `Err(exception)` - failed validation/coercion

### Sources

Sources represent different ways to provide data:

- **StaticSource**: Direct values
- **SymbolSource**: Named references resolved from the context's bindings or definitions
- **Coerce**: The boundary node — converts a resolved value into the declared type via coercion (statically opaque by design)
- **Ascription**: The author's checked type claim — verified by assert() at runtime, checked for overlap statically
- **InfixExpression**: Mathematical/logical expressions
- **UnaryExpression**: Single-operand expressions
- **MatchExpression**: Conditional matching with ordered arms
- **MemberAccessSource**: Chained property/array-key access

**When do you reach for `Coerce` vs `Ascription` in practice?**

- **`Coerce` is for input you don't control.** Wrap the exact spot where a messy external value enters the program — a CSV cell, a form field, a JSON fragment — and evaluation converts it (`'42' → 42`, `'' → absence`) before anything downstream sees it. Most programs never write an explicit `Coerce` node at all: the typed-bindings boundary (`declarations` on the `Expression`) is the same conversion applied to every declared input at once, which is where conversion usually belongs. Reach for the node itself when a single value needs converting *mid-expression* — e.g. a host source that returns a stringly lookup cell.

  ```php
  new Coerce(new NumberType(), $rawLookupCell)   // '42 ' → 42; the checker takes Number on faith
  ```

- **`Ascription` is for narrowing what you already have.** The value exists and should *already* inhabit the type — you are recording a claim the engine cannot infer, most commonly refining a host source that honestly returns `Unknown`. The checker verifies the claim is *possible* (the types overlap; claiming `Number` on something inferred `String` is a compile error), and the runtime verifies it actually *holds* (`assert`), so a false claim is a loud error at the exact node that lied — never silent corruption downstream.

  ```php
  new Ascription(new NumberType(), $unknownHostSource)  // "trust me, this is a number" — and it's checked twice
  ```

Rule of thumb: converting? `Coerce`, preferably via declarations at the boundary. Claiming? `Ascription`. If you find yourself ascribing to paper over a conversion, the value wanted a boundary declaration instead.

### Resolvers

Resolvers handle the evaluation of sources. They are **stateless** — all per-call state (bindings, definitions, the inspector, and the symbol memo) lives on a `Context` threaded through `resolve(Source, Context)`:

- **StaticResolver**: Resolves static values
- **CoerceResolver**: Evaluates Coerce nodes via the `coerce()` method
- **AscriptionResolver**: Evaluates Ascription nodes via the `assert()` method
- **InfixResolver**: Evaluates binary expressions
- **UnaryResolver**: Evaluates unary expressions
- **SymbolResolver**: Looks up symbols from bindings (first) then definitions (with per-context memoization)
- **MemberAccessResolver**: Evaluates property/array-key access
- **MatchResolver**: Evaluates match expressions with extensible pattern matching
- **DelegatingResolver**: Chains multiple resolvers together

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
    dialect: $dialect,     // optional — Dialect::core() by default; the
                           // operator rules travel with the call
);

$resolver->resolve($source, $context);
```

`Expression::call()` / `Expression::__invoke()` build the `Context` for you from the bindings you pass.

### Operators

The library supports various operators through the overloader system:

- **Binary arithmetic**: `+`, `-`, `*`, `/` — two present numbers
- **Comparison**: `=`/`==`, `===`, `!=`, `!==` for scalars, `null`, and lists of them — **value equality, never PHP juggling**: numeric within `Number` (`1 == 1.0`), strict otherwise, `false` across bases (`5 == '5'` is `false`; `===`/`!==` are aliases); ordering (`<`, `<=`, `>`, `>=`) for **numbers only** — PHP's willingness to rank strings is not a defined order (a dialect that wants lexicographic ranking ships its own overloader)
- **Logical**: `&&`, `||`, `xor` — two present booleans
- **Set**: `has`, `in`, `intersects` — list membership and intersection by the same value equality (never array_intersect string juggling)
- **Unary**: `!`/`not` (booleans only), `-` (numbers only), overloader-driven via `UnaryOverloader`

Every overloader owns **both semantics**: `evaluate()` is the runtime face, `typeOf()` the static face, and `supportsOverloading()` must claim only values the rule genuinely owns — an honesty contract enforced by a generative agreement harness. See [Extending Axiom](docs/extending-axiom.md) for writing your own.

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
| `CoerceResolver` | `label`: type class name (e.g. `"NumberType"`); `coercion`: type change (e.g. `"string -> int"`) |
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

## Extending Axiom

Axiom is designed to be extended from the outside — domain types, operators with their typing rules, host sources, and literal registrations all plug in through dedicated seams, without touching core. An operator is one declaration — a **signature** — from which both the runtime rule and its typing rule are generated, so they cannot drift:

```php
use Superscript\Axiom\Operators\Operator;

Operator::infix('-')
    ->signature(new DateType(), new PeriodType())
    ->returns(new DateType())
    ->evaluate(fn (Date $d, Period $p) => $d->minus($p));
```

The full guide is **[docs/extending-axiom.md](docs/extending-axiom.md)**; the short version:

| You want to… | Implement / use | Guide section |
| --- | --- | --- |
| Add a domain type (money, dates, IDs) | `Type` (which includes `Shaped::shape()`) | Custom types |
| Give operators new semantics | `Operator::infix()` / `Operator::prefix()` signatures — or `OperatorOverloader` / `UnaryOverloader` by hand for relation-based verdicts | Custom operators |
| Type your own literal values | `LiteralTypeRegistry` | Literal registration |
| Add a data source the checker can see | `TypedSource` | Host sources |
| Evaluate a new kind of `Source` | `Resolver` (stateless, reads from `Context`) | Custom resolvers |
| Add match pattern kinds | `PatternMatcher` | Custom patterns |
| Prove your rules honest | the agreement harness pattern | Testing your extension |

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
