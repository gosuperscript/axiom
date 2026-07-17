# Axiom

Axiom is a PHP library for programs you keep as data. A pricing formula, an eligibility gate, a rating rule — described as a tree of sources, stored wherever you store data, and compiled into a certified, callable `Program` when you need it to run.

The design principle is **compile, then trust**: `Expression::compile()` type-checks the whole program once — dead comparisons, non-exhaustive matches, unbound symbols, and type errors all surface as compile diagnostics — and the program it returns performs no runtime type dispatch. Every operator was resolved against the operand *types* at compile time, exactly as overload resolution works in natively typed languages. The full design, including the sealed shape algebra and relation laws, is in [RFC 0001: Typesafe Axiom](docs/rfc/0001-typesafe-axiom.md).

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Core Concepts](#core-concepts)
    - [Expressions Compile to Programs](#expressions-compile-to-programs)
    - [Compile, Then Trust](#compile-then-trust)
    - [Types](#types)
    - [Assert vs Coerce](#assert-vs-coerce)
    - [Sources](#sources)
    - [Inputs, Definitions, and Namespaces](#inputs-definitions-and-namespaces)
    - [Match Expressions](#match-expressions)
    - [Operators](#operators)
    - [Execution Observation](#execution-observation)
- [Extending Axiom](#extending-axiom)
- [Development](#development)
- [License](#license)

## Installation

Axiom requires PHP 8.4 or higher with the `intl` extension.

```bash
composer require gosuperscript/axiom
```

## Quick Start

The top-level API is `Expression`: a complete *description* of a program — its `Source` tree, definitions, and declared input types. It is deliberately not runnable; `compile()` is the one way from description to execution.

The smallest program coerces a static value:

```php
use Superscript\Axiom\Expression;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\NumberType;

$source = new Coerce(
    type: new NumberType(),
    source: new StaticSource('42'),
);

$program = (new Expression($source))->compile()->unwrap();

$program()->unwrap()->unwrap(); // 42 (as integer)
```

A real program has parameters and definitions:

```php
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

Compile once — at authoring or deploy time — and invoke per request. `compile()` refuses, with names, everything that would make evaluation dishonest: definition cycles, unbound symbols, operators no rule resolves (or two rules claim), type errors. Running an unchecked program is not discouraged — it is unrepresentable, because only `Program` is callable.

The expression's inputs are its **parameters**, passed at the call site, and the declaration list is the program's complete public signature: undeclared binding keys never enter, and a parameter you cannot type yet is declared `Unknown` explicitly. [Compile, Then Trust](#compile-then-trust) covers how inputs are admitted.

## Core Concepts

### Expressions Compile to Programs

`TypeInference` is the compiler: one syntax-directed rule per node computes the node's type *and* emits its evaluation, as one `CompiledNode`. `Expression::compile()` runs the definition-graph well-foundedness pass, compiles the tree, and wraps the result in a `Program` — the only callable thing in the library:

```php
$program = $expression->compile()->unwrap();

$program->returns;   // the inferred return type
$program($bindings); // boundary + evaluation; Result<Option<mixed>, Throwable>
```

What remains at runtime is semantics, not dispatch: absence short-circuits, match arms try in order, division by zero errs, the admission bridges check what they exist to check. The per-invocation state (`Runtime`) carries the admitted bindings, lazily-memoized definition slots, and an optional execution observer — no dialect, no resolver.

You can also ask questions without compiling:

```php
$gate->infer();                  // Ok(BooleanType) — what does this return?
$gate->check(new BooleanType()); // certified
```

Inference is **literal-first**: `'shop'` types as the literal `'shop'` (assignable to `String` wherever needed), and `['shop', 'office']` as `List<'shop' | 'office', 2>` — which is what makes enum-style checking precise. The lower-level `TypeInference`/`TypeEnvironment` API remains available for corpus sweeps over stored programs.

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

$program = $gate->compile()->unwrap();

$program(['quote.turnover' => '600000']);
// the BOUNDARY coerces '600000' → 600000 through the declared type
// before evaluation — certified programs never see raw garbage

$program(['quote.turnover' => 'lots']);
// Err(BoundaryViolation): "binding [quote.turnover]: …" — aggregated,
// named by input, before any evaluation
```

Certification is a conditional guarantee — "*if* inputs inhabit their declared types…" — and the boundary establishes the condition on every call. Declared bindings pass through their declared types (`coerce` by default, `Boundary::Assert` for strict hosts), required inputs must be present, and every undeclared binding key is stripped.

> [!NOTE]
> The boundary is the one runtime type check that survives compilation, by design: `compile()` proves the program, not future inputs.

The compiler refuses, with a nested cause chain (`TypeMismatch::describe()`):

- **Type errors** — `"abc" * 2`, `!5`, arithmetic on a possibly-absent value
- **Dead code** — comparisons and membership tests that are statically constant (`kind == "warehouse"` when `kind` is `'shop' | 'office'`), flagged via `TypeMismatch::$dead`
- **Non-exhaustive matches** — a `match` without a wildcard arm over a subject it cannot prove covered (an unmatched subject is a runtime error)
- **False ascriptions** — an `Ascription` whose claimed type is disjoint from the value's
- **Unbound and cyclic symbols** — definition cycles are a standalone graph pass (declarations answer typing, never termination); a cyclic program cannot be compiled, and only compiled programs run
- **Inert `Unknown`** — an `Unknown`-typed value at an operator, comparison, or member access is refused with the fix in the message: bridge it with `Coerce` or `Ascription`
- **Ambiguity** — two rules resolving the same operator over jointly admissible operand types (some operand type would resolve both) is an error naming both rules, never a precedence question

### Types

The built-in types, and what their coercions read:

- **`NumberType`** — int/float. Coercion reads numeric strings (`"42"` → `42`) and percentage strings (`"50%"` → `0.5`).
- **`StringType`** — strings. Coercion converts numbers and `Stringable` objects, and reads `''` and `'null'` as absence; under `assert` those are ordinary strings.
- **`BooleanType`** — booleans. Coercion reads `"true"`/`"false"`, `"yes"`/`"no"`, `"on"`/`"off"`, `"1"`/`"0"` — and coercing `null` yields absence, never a silent `false`.
- **`ListType` / `DictType`** — collections and associative arrays with nested type validation. `ListType` optionally carries length bounds (`min`/`max`), enforced by `assert` and `coerce` and visible to the compiler.
- **`LiteralType` / `UnionType`** — a singleton of a scalar and a set of alternatives. An enum is a union of literals:

  ```php
  $tier = new UnionType(new LiteralType('micro'), new LiteralType('small'));
  $tier->assert('micro'); // Ok(Some('micro'))
  $tier->assert('large'); // Err — not a member
  ```

Three types carry laws worth knowing:

- **`OptionType`** — a possibly-absent value. `null` is a legal, *present* value of the option: coercing `null` yields `Some(null)`, not a failed coercion. That is what lets an optional field live inside a record whose required fields treat absence as "missing".
- **`RecordType`** — named, individually typed fields, exact: a record's value set is fully described by its fields (data with unenumerable keys is a `Dict`). An optional field is a field whose type is `OptionType`; coercion canonicalizes a missing optional key to a present `null`. The two admission faces diverge on undeclared keys by design: `assert` rejects them (strict membership), while `coerce` takes the declared slice of wide input — pass a whole context row and only the declared fields enter.
- **`UnknownType` and `NeverType`** — the statically-unnameable type and the bottom type (no value inhabits it). Both are produced by inference, never declared by authors — except that a host may declare an input `Unknown` explicitly when its scope genuinely cannot type it. `Unknown` is **inert**: no operator, comparison, or member access accepts it. The ways out are the two explicit bridges — `Coerce` (convert it into a type) and `Ascription` (claim its type, runtime-verified) — so every escape from untyped data is a visible node in the program.

### Assert vs Coerce

Every type has two admission faces, following the [php-standard-library](https://github.com/php-standard-library/php-standard-library) pattern:

- **`assert(T $value): Result<Option<T>>`** — strict membership. Is this value *already* of the type?
- **`coerce(mixed $value): Result<Option<T>>`** — the lenient input boundary. Convert anything that can reasonably be read as the type.

```php
$numberType = new NumberType();

$numberType->assert(42);     // Ok(Some(42))
$numberType->assert('42');   // Err(TransformValueException)

$numberType->coerce(42);     // Ok(Some(42))
$numberType->coerce('42');   // Ok(Some(42))
$numberType->coerce('45%');  // Ok(Some(0.45))
```

Both return `Result<Option<T>, Throwable>` — no exceptions for normal control flow:

- `Ok(Some(value))` — success with a value
- `Ok(None())` — success with no value (absence readings live in `coerce`, the lenient input boundary; `assert` is strict membership)
- `Err(exception)` — the value cannot be read as the type

> [!IMPORTANT]
> **The admission-honesty law**: whatever `coerce` emits must pass the same type's `assert`. Compile-then-trust rests on this — a value that crosses a boundary *is* its declared type from then on, and nothing downstream re-checks it. The law is enforced generatively for every built-in type in the shape census, and extension types should run under the same law (see [Extending Axiom](docs/extending-axiom.md)).

### Sources

Sources are the nodes a program is described with:

- **`StaticSource`** — direct values
- **`SymbolSource`** — named references: a declared parameter (read from bindings) or a defined derived value (compiled once, memoized per invocation)
- **`Coerce`** — the conversion bridge: converts a resolved value into the declared type via coercion (statically opaque by design)
- **`Ascription`** — the author's checked type claim: verified by `assert()` at runtime, checked for overlap at compile time
- **`InfixExpression`** / **`UnaryExpression`** — operator applications
- **`MatchExpression`** — conditional matching with ordered arms
- **`MemberAccessSource`** — chained property/array-key access

When do you reach for `Coerce` vs `Ascription` in practice?

**`Coerce` is for input you don't control.** Wrap the exact spot where a messy external value enters the program — a CSV cell, a form field, a JSON fragment — and evaluation converts it (`'42' → 42`, `'' → absence`) before anything downstream sees it. Most programs never write an explicit `Coerce` node at all: the typed-bindings boundary (`declarations` on the `Expression`) is the same conversion applied to every declared input at once, which is where conversion usually belongs. Reach for the node itself when a single value needs converting *mid-expression* — for example, a host source that returns a stringly lookup cell.

```php
new Coerce(new NumberType(), $rawLookupCell)   // '42 ' → 42; the compiler takes Number on faith
```

**`Ascription` is for narrowing what you already have.** The value exists and should *already* inhabit the type — you are recording a claim the engine cannot infer, most commonly refining a host source that honestly returns `Unknown`. The compiler verifies the claim is *possible* (the types overlap; claiming `Number` on something inferred `String` is a compile error), and the runtime verifies it actually *holds* (`assert`), so a false claim is a loud error at the exact node that lied — never silent corruption downstream.

```php
new Ascription(new NumberType(), $unknownHostSource)  // "trust me, this is a number" — and it's checked twice
```

> [!TIP]
> Converting? `Coerce`, preferably via declarations at the boundary. Claiming? `Ascription`. If you find yourself ascribing to paper over a conversion, the value wanted a boundary declaration instead. These two nodes plus the binding boundary are the **only** places a compiled program ever inspects a value's type — everything else was proven at compile time.

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

Two rules keep symbol lookup honest:

- **Symbols are names; member access is structure.** A namespaced symbol is the flat dotted key and nothing else: `SymbolSource('claims', 'quote')` is answered by a binding or definition named exactly `quote.claims` — never by digging into the value of a `quote` binding. Bind keys exactly as declared (`['quote.claims' => 3]`), or declare `quote` as a record, bind it whole, and reach its fields with `MemberAccessSource` — one value, one reading, chosen at declaration time. This is what makes caller data structurally unable to answer for (and so shadow) a definition.
- **Declarations and definitions are disjoint namespaces.** A symbol is a *parameter* (declared, supplied by bindings) or a *derived value* (defined), never both — a collision is a constructor error. The boundary strips undeclared binding keys before evaluation. Together with exact-key lookup, shadowing a definition is unrepresentable. To let callers override a derived value, model the override in-language: an `Option`-typed parameter the definition consults.

### Match Expressions

`MatchExpression` provides a unified way to express conditionals, dispatch tables, and cond-style matching. A match expression has a **subject** and an ordered list of **arms**; each arm pairs a pattern with a result expression, and the first matching arm wins.

A match where **no** arm matches is a runtime error, so add a wildcard arm for a deliberate default — and the compiler enforces this: unprovable exhaustiveness is a compile diagnostic.

The patterns:

- **`LiteralPattern`** — matches via **value equality**, the same one definition the comparison operators and the exhaustiveness analysis use (`5` matches `5.0`; never PHP juggling across bases)
- **`WildcardPattern`** — always matches (the default/catch-all arm)
- **`ExpressionPattern`** — wraps a `Source`: it is a program like any other, compiled with the rest of the match and compared to the subject at runtime

If/then/else:

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

Dispatch table:

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

### Operators

The core dialect ships these rules:

- **Binary arithmetic**: `+`, `-`, `*`, `/` — rows over two present numbers
- **Equality**: `=`/`==`, `===`, `!=`, `!==` over domains supported by built-in value equality — numeric within `Number` (`1 == 1.0`), strict otherwise, `false` across bases (`===`/`!==` are aliases). Support is established before overlap is used for a *dead* compile diagnostic; opaque values get equality from their owning package.
- **Ordering**: `<`, `<=`, `>`, `>=` — rows over **numbers only**; PHP's willingness to rank strings is not a defined order (a dialect that wants lexicographic ranking ships its own row)
- **Logical**: `&&`, `||`, `xor` — rows over two present booleans
- **Set**: `has`, `in`, `intersects` — list membership and intersection by the same value equality (never `array_intersect` string juggling)
- **Unary**: `!`/`not` (booleans only), `-` (numbers only)

Every rule owns one symbol (`operator()`) and answers one question — `resolve(operand types)` — with a `ResolvedOperation`, `UnsupportedOperation`, or `DeadOperation`. A success carries the return type *and* the evaluation, so rule selection and evaluation cannot drift apart; that the evaluation honors its stated type is your certified obligation, tested by the totality harness. Resolvers index rules by symbol, so unrelated rules are never invoked. Most rules are declarative rows built with the operator rule builder; equality and the set operators are hand-written type functions. Ambiguity is refused at composition time (jointly admissible rows) or compile time (multiple resolutions), never absorbed.

See [Extending Axiom](docs/extending-axiom.md) for writing your own.

### Execution Observation

Pass an `Execution\Observer` to one `Program` invocation to observe the compiled evaluation as an ordered event stream. The observer is invocation-scoped: it is not stored on the serializable `Source` tree, the `Expression`, or the compiled `Program`, so it cannot leak state into a later run.

Every compiled source node emits `Entered`, zero or more `Annotated`, then `Exited`; a host exception emits `Threw` instead. Each event carries a `Node` descriptor with the source class and certified return type. The nesting in the event order is enough for tracing packages to build trees and timings without teaching core about a particular trace representation.

```php
use Superscript\Axiom\Execution\Annotated;
use Superscript\Axiom\Execution\Event;
use Superscript\Axiom\Execution\Observer;

final class AnnotationLog implements Observer
{
    public array $annotations = [];

    public function observe(Event $event): void
    {
        if ($event instanceof Annotated) {
            $this->annotations[] = [
                'source' => $event->node->sourceType,
                'key' => $event->key,
                'value' => $event->value,
            ];
        }
    }
}

$observer = new AnnotationLog();
$program = $expression->compile()->unwrap();
$result = $program->call(['radius' => 5], observer: $observer);
```

When no observer is passed, the same program follows the direct evaluation path and annotations are no-ops.

**Built-in annotations:**

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

## Extending Axiom

Axiom is designed to be extended from the outside — domain types, operator rules, host sources, and literal registrations all plug in through dedicated seams, without touching core. A fixed operator rule is one declarative row carrying its operand types, return type, and evaluation:

```php
use Superscript\Axiom\Operators\Operator;

Operator::infix('-')
    ->takes(new DateType(), new PeriodType())
    ->returns(new DateType())
    ->evaluatesWith(fn (Date $d, Period $p) => $d->minus($p));
```

The full guide is **[docs/extending-axiom.md](docs/extending-axiom.md)**; the short version:

| You want to… | Implement / use | Guide section |
| --- | --- | --- |
| Add a domain type (money, dates, IDs) | `Type` (which includes `Shaped::shape()`) | Custom types |
| Give operators new semantics | fixed rows, typed computed rules, or `BinaryOperatorRule` / `UnaryOperatorRule` for fully custom judgments | Custom operators |
| Type your own literal values | `LiteralTypeRegistry` | Literal registration |
| Add a data source | `Extension::sourceCompilers()` plus composable `CompiledSource` values; sources stay data-only | Host sources |
| Add match pattern kinds | (reserved: an `Extension::matchers()` hook can be added without breaking implementors) | — |
| Prove your rules honest | the totality harness + admission-honesty law patterns | Testing your extension |

## Development

1. Clone the repository
2. Install dependencies: `composer install`
3. Run tests: `composer test`

```bash
# Individual test suites
composer test:unit      # Unit tests
composer test:types     # Static analysis (PHPStan)
composer test:infection # Mutation testing
```

Quality bars for contributions:

- **PHPStan**: Level max static analysis
- **Infection**: Mutation testing for test quality
- **Laravel Pint**: Code formatting
- **100% Code Coverage**: Required for all new code

## License

This library is open-sourced software licensed under the [MIT license](LICENSE).

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on how to contribute to this project.

## Security

If you discover any security-related issues, please review our [Security Policy](SECURITY.md) for information on how to responsibly report vulnerabilities.
