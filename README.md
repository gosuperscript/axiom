# Axiom Library

A powerful PHP library for data transformation, type validation, and expression evaluation. This library provides a flexible framework for defining data schemas, compiling typed expressions, and evaluating them with certainty.

## Features

- **Type System**: Robust type validation and transformation for numbers, strings, booleans, lists, dictionaries, records, options, literals, and unions
- **Compile, Then Trust**: `Expression::compile()` type-checks a program once and returns a certified, callable `Program` — dead comparisons, non-exhaustive matches, and type errors surface as compile diagnostics, and a compiled program performs no runtime type dispatch ([RFC 0001](docs/rfc/0001-typesafe-axiom.md))
- **Expression Evaluation**: Support for infix expressions with custom operators
- **Match Expressions**: Unified conditional logic — if/then/else, dispatch tables, and cond-style matching
- **Operator Overloading**: Extensible operator system where every rule states its typing and its evaluation in one verdict, so they can never drift
- **Monadic Error Handling**: Built on functional programming principles using Result and Option types

## Requirements

- PHP 8.4 or higher
- ext-intl extension

## Installation

```bash
composer require gosuperscript/axiom
```

## Quick Start

### Expressions compile to programs

The top-level API is `Expression`: a complete *description* of a program — its `Source` tree, definitions, and declared input types. It is deliberately not runnable; `compile()` is the one way from description to execution:

```php
<?php

use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\NumberType;

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
    definitions: new Definitions(['PI' => new StaticSource(3.14159)]),
    declarations: ['radius' => new NumberType()],
);

$area->parameters(); // ['radius']

$program = $area->compile()->unwrap();  // every node resolved and certified
$program->returns;                      // NumberType — a property, not a query

$program(['radius' => 5])->unwrap()->unwrap();  // ~78.54
$program(['radius' => 10])->unwrap()->unwrap(); // ~314.16
```

Compile once — at authoring or deploy time — and invoke per request: the compiled program carries no dialect and dispatches nothing; every operator was resolved against the operand *types* at compile time, exactly as overload resolution works in natively typed languages. `compile()` refuses, with names, everything that would make evaluation dishonest: definition cycles, unbound symbols, operators no rule resolves (or two rules claim), type errors. Running an unchecked program is not discouraged — it is unrepresentable, because only `Program` is callable.

The expression's inputs are its **parameters**, passed at the call site — and the declaration list is the program's complete public signature (undeclared binding keys never enter; a parameter you cannot type yet is declared `Unknown` explicitly).

### Basic Type Transformation

```php
<?php

use Superscript\Axiom\Expression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Types\NumberType;

$source = new Coerce(
    type: new NumberType(),
    source: new StaticSource('42'),
);

$program = (new Expression($source))->compile()->unwrap();

$program()->unwrap()->unwrap(); // 42 (as integer)
```

### Inputs, Definitions, and Namespaces

Inputs are **bindings** — passed at the call site. Stable named expressions (constants, named sub-expressions) are **definitions** — bound once when the `Expression` is constructed and compiled exactly once into the program (at runtime a definition evaluates lazily and memoizes per invocation). Both support flat names and dotted namespaces.

```php
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;

$expression = new Expression(
    source: /* ... */,
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

// Flat and namespaced inputs — bound by their exact dotted keys
$program = $expression->compile()->unwrap();
$program([
    'tier' => 'small',
    'quote.claims' => 3,
    'quote.turnover' => 600000,
]);
```

`SymbolSource` looks up by name + optional namespace:

```php
new SymbolSource('pi', 'math');      // -> math.pi
new SymbolSource('claims', 'quote'); // -> quote.claims
new SymbolSource('version');         // -> version (global)
```

**Symbols are names; member access is structure.** A namespaced symbol is the flat dotted key and nothing else: `SymbolSource('claims', 'quote')` is answered by a binding or definition named exactly `quote.claims` — never by digging into the value of a `quote` binding. Bind keys exactly as declared (`['quote.claims' => 3]`), or declare `quote` as a record, bind it whole, and reach its fields with `MemberAccessSource` — one value, one reading, chosen at declaration time. This is what makes caller data structurally unable to answer for (and so shadow) a definition.

**Declarations and definitions are disjoint namespaces.** A symbol is a *parameter* (declared, supplied by bindings) or a *derived value* (defined), never both — a collision is a constructor error. The boundary strips undeclared binding keys before evaluation. Together with exact-key lookup, shadowing a definition is unrepresentable. To let callers override a derived value, model the override in-language: an `Option`-typed parameter the definition consults.

### Match Expressions

`MatchExpression` provides a unified way to express conditionals, dispatch tables, and cond-style matching. A match expression has a **subject** and an ordered list of **arms**. Each arm pairs a pattern with a result expression. The first matching arm wins — and a match where **no** arm matches is a runtime error, so add a wildcard arm for a deliberate default (the compiler enforces this: unprovable exhaustiveness is a compile diagnostic).

**Patterns:**

- **LiteralPattern**: Matches via **value equality** — the same one definition the comparison operators and the exhaustiveness analysis use (`5` matches `5.0`; never PHP juggling across bases)
- **WildcardPattern**: Always matches (the default/catch-all arm)
- **ExpressionPattern**: Wraps a `Source` — it is a program like any other, compiled with the rest of the match and compared to the subject at runtime

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

### Compile, Then Trust

Declare your input types once on the `Expression`, and `compile()` certifies the whole program — through the same `Dialect` (operator rules) and `Definitions` the program embeds, so there is nothing at runtime left to compose differently:

```php
use Superscript\Axiom\Expression;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;

$gate = new Expression(
    source: $condition,                                   // quote.turnover * 1.2 > 500000
    definitions: $definitions,
    declarations: ['quote.turnover' => new NumberType()], // one map, both faces
);

$gate->infer();                    // Ok(BooleanType) — what does this return?
$gate->check(new BooleanType());   // certified

$program = $gate->compile()->unwrap();

$program(['quote.turnover' => '600000']);
// the BOUNDARY coerces '600000' → 600000 through the declared type
// before evaluation — certified programs never see raw garbage

$program(['quote.turnover' => 'lots']);
// Err(BoundaryViolation): "binding [quote.turnover]: …" — aggregated,
// named by input, before any evaluation
```

Certification is a conditional guarantee ("*if* inputs inhabit their declared types…") and the boundary establishes the condition on every call: declared bindings pass through their declared types (`coerce` by default, `Boundary::Assert` for strict hosts), required inputs must be present, and every undeclared binding key is stripped — the declaration list is the program's complete public signature. The boundary is the one runtime type check that survives compilation, by design: `compile()` proves the program, not future inputs.

The compiler refuses, with a nested cause chain (`TypeMismatch::describe()`):

- **Type errors** — `"abc" * 2`, `!5`, arithmetic on a possibly-absent value
- **Dead code** — comparisons and membership tests that are statically constant (`kind == "warehouse"` when `kind` is `'shop' | 'office'`), flagged via `TypeMismatch::$dead`
- **Non-exhaustive matches** — a `match` without a wildcard arm over a subject it cannot prove covered (an unmatched subject is a runtime error)
- **False ascriptions** — an `Ascription` whose claimed type is disjoint from the value's
- **Unbound and cyclic symbols** — definition cycles are a standalone graph pass (declarations answer typing, never termination); a cyclic program cannot be compiled, and only compiled programs run
- **Inert `Unknown`** — an `Unknown`-typed value at an operator, comparison, or member access is refused with the fix in the message: bridge it with `Coerce` or `Ascription`
- **Ambiguity** — two rules resolving the same operator over jointly admissible operand types (some operand type would resolve both) is an error naming both rules, never a precedence question

Inference is **literal-first**: `'shop'` types as the literal `'shop'` (assignable to `String` wherever needed), `['shop', 'office']` as `List<'shop' | 'office', 2>` — which is what makes enum-style checking precise. (The lower-level `TypeInference`/`TypeEnvironment` API remains available for corpus sweeps over stored programs.)

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
For collections and associative arrays with nested type validation. `ListType` optionally carries length bounds (`min`/`max`), enforced by `assert` and `coerce` and visible to the compiler.

#### OptionType
A possibly-absent value. `null` is a legal, *present* value of the option — coercing `null` yields `Some(null)`, not a failed coercion. That is what lets an optional field live inside a record whose required fields treat absence as "missing".

#### RecordType
Named, individually typed fields, exact: a record's value set is fully described by its fields (data with unenumerable keys is a `Dict`). An optional field is a field whose type is `OptionType`; coercion canonicalizes a missing optional key to a present `null`. The two admission faces diverge on undeclared keys by design: `assert` rejects them (strict membership), while `coerce` takes the declared slice of wide input — pass a whole context row and only the declared fields enter.

#### LiteralType and UnionType
A singleton of a scalar (`new LiteralType('shop')`) and a set of alternatives. An enum is a union of literals:

```php
$tier = new UnionType(new LiteralType('micro'), new LiteralType('small'));
$tier->assert('micro'); // Ok(Some('micro'))
$tier->assert('large'); // Err — not a member
```

#### UnknownType and NeverType
The statically-unnameable type and the bottom type (no value inhabits it). Both are produced by inference, never declared by authors — except that a host may declare an input `Unknown` explicitly when its scope genuinely cannot type it. `Unknown` is **inert**: no operator, comparison, or member access accepts it. The ways out are the two explicit bridges — `Coerce` (convert it into a type) and `Ascription` (claim its type, runtime-verified) — so every escape from untyped data is a visible node in the program.

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

**The admission-honesty law**: whatever `coerce` emits must pass the same type's `assert`. Compile-then-trust rests on this — a value that crosses a boundary *is* its declared type from then on, and nothing downstream re-checks it. The law is enforced generatively for every type in the shape census, and extension types should run under the same law (see [Extending Axiom](docs/extending-axiom.md)).

### Sources

Sources represent different ways to provide data:

- **StaticSource**: Direct values
- **SymbolSource**: Named references — a declared parameter (read from bindings) or a defined derived value (compiled once, memoized per invocation)
- **Coerce**: The conversion bridge — converts a resolved value into the declared type via coercion (statically opaque by design)
- **Ascription**: The author's checked type claim — verified by assert() at runtime, checked for overlap at compile time
- **InfixExpression**: Mathematical/logical expressions
- **UnaryExpression**: Single-operand expressions
- **MatchExpression**: Conditional matching with ordered arms
- **MemberAccessSource**: Chained property/array-key access

**When do you reach for `Coerce` vs `Ascription` in practice?**

- **`Coerce` is for input you don't control.** Wrap the exact spot where a messy external value enters the program — a CSV cell, a form field, a JSON fragment — and evaluation converts it (`'42' → 42`, `'' → absence`) before anything downstream sees it. Most programs never write an explicit `Coerce` node at all: the typed-bindings boundary (`declarations` on the `Expression`) is the same conversion applied to every declared input at once, which is where conversion usually belongs. Reach for the node itself when a single value needs converting *mid-expression* — e.g. a host source that returns a stringly lookup cell.

  ```php
  new Coerce(new NumberType(), $rawLookupCell)   // '42 ' → 42; the compiler takes Number on faith
  ```

- **`Ascription` is for narrowing what you already have.** The value exists and should *already* inhabit the type — you are recording a claim the engine cannot infer, most commonly refining a host source that honestly returns `Unknown`. The compiler verifies the claim is *possible* (the types overlap; claiming `Number` on something inferred `String` is a compile error), and the runtime verifies it actually *holds* (`assert`), so a false claim is a loud error at the exact node that lied — never silent corruption downstream.

  ```php
  new Ascription(new NumberType(), $unknownHostSource)  // "trust me, this is a number" — and it's checked twice
  ```

Rule of thumb: converting? `Coerce`, preferably via declarations at the boundary. Claiming? `Ascription`. If you find yourself ascribing to paper over a conversion, the value wanted a boundary declaration instead. These two nodes plus the binding boundary are the **only** places a compiled program ever inspects a value's type — everything else was proven at compile time.

### Compilation

`TypeInference` is the compiler: one syntax-directed rule per node computes the node's type *and* emits its evaluation, as one `CompiledNode`. `Expression::compile()` runs the definition-graph well-foundedness pass, compiles the tree, and wraps the result in a `Program` — the only callable thing in the library:

```php
$program = $expression->compile()->unwrap();

$program->returns;          // the inferred return type
$program($bindings);        // boundary + evaluation; Result<Option<mixed>, Throwable>
```

What remains at runtime is semantics, not dispatch: absence short-circuits, match arms try in order, division by zero errs, the admission bridges check what they exist to check. The per-invocation state (`Runtime`) carries the admitted bindings, lazily-memoized definition slots, and the optional inspector — no dialect, no resolver.

### Operators

The core dialect ships these rules:

- **Binary arithmetic**: `+`, `-`, `*`, `/` — rows over two present numbers
- **Equality**: `=`/`==`, `===`, `!=`, `!==` for scalars, `null`, and lists of them — **value equality, never PHP juggling**: numeric within `Number` (`1 == 1.0`), strict otherwise, `false` across bases (`===`/`!==` are aliases); a comparison whose operand types cannot overlap is a *dead* compile diagnostic
- **Ordering**: `<`, `<=`, `>`, `>=` — rows over **numbers only**; PHP's willingness to rank strings is not a defined order (a dialect that wants lexicographic ranking ships its own row)
- **Logical**: `&&`, `||`, `xor` — rows over two present booleans
- **Set**: `has`, `in`, `intersects` — list membership and intersection by the same value equality (never array_intersect string juggling)
- **Unary**: `!`/`not` (booleans only), `-` (numbers only)

Every rule answers one question — `resolve(operator, operand types)` — whose success carries the return type *and* the evaluation, so rule selection and evaluation cannot drift apart: they are one statement. (That the evaluation honors its stated type is your certified obligation, tested by the totality harness — see the guide.) Most rules are declarative rows built with the signature builder; equality and the set operators are hand-written type functions. Ambiguity is refused at composition time (jointly admissible rows) or compile time (multiple resolutions), never absorbed. See [Extending Axiom](docs/extending-axiom.md) for writing your own.

### Resolution Inspector

The `ResolutionInspector` interface provides a zero-overhead observability primitive for evaluation. Compiled programs accept the inspector at construction (via the `Expression`'s `inspector` parameter or `withInspector()`) and annotate metadata as they evaluate. When no inspector is present, annotation is skipped entirely via null-safe calls.

**Interface:**

```php
interface ResolutionInspector
{
    public function annotate(string $key, mixed $value): void;
}
```

**Built-in annotations from compiled nodes:**

| Node | Annotations |
|------|-------------|
| Static value | `label`: `"static(int)"`, `"static(string)"`, etc. |
| `Coerce` | `label`: the declared type (e.g. `"Number"`); `coercion`: type change (e.g. `"string -> int"`) |
| `Ascription` | `label`: the claim (e.g. `"is Number"`) |
| Infix operator | `label`: operator (e.g. `"+"`, `"&&"`); `left`, `right`, `result` |
| Unary operator | `label`: operator (e.g. `"!"`, `"-"`); `result` |
| Symbol | `label`: symbol name (e.g. `"A"`, `"math.pi"`); `memo`: `"hit"`/`"miss"` for definitions; `result` |
| Match | `label`: `"match"`; `subject`: resolved subject value; `matched_arm`: index of matched arm; `result`: final value |
| Member access | `label`: `".property"`; `result` |

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
$program = $expression->withInspector($inspector)->compile()->unwrap();
$program(['radius' => 5]);

// Annotations are available via $inspector->get('label'), etc.
```

## Extending Axiom

Axiom is designed to be extended from the outside — domain types, operator rules, host sources, and literal registrations all plug in through dedicated seams, without touching core. An operator is one declaration — a **signature** — that resolves to one verdict carrying both the return type and the evaluation, so they cannot drift:

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
| Give operators new semantics | `Operator::infix()` / `Operator::prefix()` signatures — or `OperatorOverloader` / `UnaryOverloader` by hand for verdicts computed from operand types | Custom operators |
| Type your own literal values | `LiteralTypeRegistry` | Literal registration |
| Add a data source | `TypedSource::compile()` — the type claim and the evaluation, one statement | Host sources |
| Add match pattern kinds | (reserved: an `Extension::matchers()` hook can be added without breaking implementors) | — |
| Prove your rules honest | the totality harness + admission-honesty law patterns | Testing your extension |

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

- **Compile, Then Trust**: overload resolution and type checking happen once, at compile time; the compiled program embeds its resolutions and performs no runtime dispatch
- **One Verdict**: an operator rule's typing and evaluation are one return value from one call — drift between the static and runtime faces is unrepresentable
- **Admission at the Edges**: values are type-checked at exactly three visible places — the binding boundary, `Coerce`, and `Ascription`
- **Functional Programming**: extensive use of Result and Option monads

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
