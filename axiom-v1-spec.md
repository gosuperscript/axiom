# Axiom v1 — Language Specification

Axiom is a statically-typed expression language for declarative, type-safe computation. It is designed for authored business logic: rating, pricing, eligibility, financial calculations, and domain rules.

Axiom is not a general-purpose programming language. It is a small, reviewable language for computing values from typed inputs.

The core guarantee of Axiom v1 is:

> If a program parses, type-checks, and its runtime inputs validate, then evaluating it cannot fail.

---

## 1. Design Principles

1. **Expressions are the unit of authorship.** An Axiom program is a collection of named, typed expressions. Each expression has typed parameters and evaluates to exactly one value.

2. **Reviewability beats cleverness.** The language prefers explicit, local constructs over compact or highly abstract ones. A business stakeholder should be able to read an Axiom program and follow the logic.

3. **The core is pure.** Expressions, operators, coercions, constructors, collection forms, and expression calls are deterministic and side-effect free.

4. **External data access is a boundary concern.** Host-provided functions for external data (CSV lookups, APIs, databases) are provided through the extension system, not as core language constructs. See §14.

5. **Static proof first, runtime execution second.** Parsing, type checking, and runtime input validation establish the preconditions for safe execution.

6. **The language stays narrow.** Axiom v1 intentionally excludes mutation, loops, user-defined higher-order functions, recursion as a control structure, exceptions, implicit IO, and arbitrary control abstractions.

7. **Types are nominal.** Named variant types are identified by name, not structure. Accidental structural equivalence between unrelated types does not make them assignable.

8. **Expressions return values.** Every named expression evaluates to a value. Meaningful domain outcomes (decline, referral, availability) are represented in that value, not in an out-of-band channel.

9. **No null.** There is no `null` type. Meaningful absence is modeled with variants.

10. **General-purpose constructs over domain-specific ones.** Language features should be useful beyond any single domain, even though the primary use case is insurance and financial computation.

---

## 2. Non-Goals

Axiom v1 does not provide:

- mutation or assignment statements
- loops (`for`, `while`)
- recursion as a user-facing control structure
- exceptions or `try/catch`
- implicit IO or side effects
- hidden type coercions
- unrestricted anonymous unions
- `null` or optional/nullable types (`T?`) — use variants for meaningful absence
- string interpolation or concatenation — strings are codes and identifiers, not prose
- general-purpose higher-order functions (`map`, `filter`, `reduce`) — use collection forms instead

---

## 3. Program Structure

An Axiom program is a collection of top-level declarations. There is no "main" expression — any named expression may be targeted for execution.

### 3.1 Expression Declarations

A named expression has a name, typed parameters, an optional return type annotation, and a body.

```axiom
BasePremium(sum_insured: number, rate: number): number {
    sum_insured / 1000 * rate
}
```

Parameters may use inline record shapes:

```axiom
Rating(quote: { sum_insured: number, trade: string }): number {
    quote.sum_insured / 1000 * 1.5
}
```

Or named record types:

```axiom
Rating(exposure: Exposure, limit: number): number {
    exposure.turnover / 1000 * limit
}
```

Return type annotations are optional. When omitted, the type checker infers the return type from the body expression. Annotations are required when:

- The body produces a variant type that must be nominally bound to a declared type name
- The inferred type would be ambiguous

### 3.2 Type Declarations

#### Variant types

A variant type is a closed set of tagged alternatives. Each alternative has a tag and an optional typed payload.

```axiom
type CoverOutcome =
    rated {
        key: string,
        name: string,
        base_premium: number,
        limit: number,
        excess: number,
    }
  | not_available { reason: string }
```

#### Payload-less variants

Variant alternatives may omit the payload when no data is needed:

```axiom
type Status = active | suspended | cancelled

type Decision =
    approved { premium: number }
  | declined
  | referred { reason: string }
```

Payload-less tags are valid in both construction and pattern matching positions. At runtime they are represented as `{ _tag: "tagname" }` with no additional fields.

#### Record types

A record type declares a named shape without variant tags:

```axiom
type Exposure = {
    industry: string,
    turnover: number,
    number_of_employees: number,
}
```

Record types are used to structure input parameters and intermediate data. At runtime, record values are plain associative structures (dicts with known shapes). Member access on record-typed values is validated against the declared shape.

### 3.3 Namespace Declarations

Namespaces group related types, expressions, and constant symbols:

```axiom
namespace Industry {
    BuildingsFireClass(industry: string): string {
        match industry {
            "DRI-945" | "DRI-946" => "B",
            _ => "Y",
        }
    }

    BaseExcess(industry: string): number {
        match industry {
            "DRI-945" => 500,
            "DRI-946" => 250,
            _ => 100,
        }
    }
}
```

Namespace members are accessed with qualified names: `Industry.BuildingsFireClass("DRI-945")`.

Namespaces may contain:

- **Expression declarations** — callable via `Namespace.Name(args)`
- **Type declarations** — referenced via `Namespace.TypeName`
- **Symbol declarations** — constant values with type annotations: `pi: number = 3.14159`

### 3.4 Table Declarations

A table declaration defines a named, typed, immutable list of records. The data comes from a companion artifact (e.g., a CSV file) that is bundled with the program version.

```axiom
table industry_config: list({
    code: string,
    buildings_fire_class: string,
    pl_class: string,
    pl_severity: number,
    deep_frying_rate: number,
    min_premium_default: number,
})
```

Table declarations serve two purposes:

1. **Schema declaration** — the type checker validates all access to the table's fields at compile time
2. **Artifact binding** — the runtime loads the companion data file, validates it against the declared schema, and provides the rows as an immutable list

#### Design properties

- **Declarative**: the language declares WHAT data exists and its shape; the runtime decides HOW to store and retrieve it (CSV scan, SQLite index, hash lookup)
- **Immutable**: table data cannot be modified by the program — it is fixed per program version
- **Pure**: tables are just typed lists — all existing list operations (`match ... in`, `collect ... in`, `any ... in`, `all ... in`) work on tables
- **Validated**: the runtime validates the artifact against the declared schema at load time, before any expression is evaluated

#### Artifacts

A program bundle consists of source code + artifacts. Artifacts are companion data files that are:

- Version-locked to the program (changing the CSV means a new version)
- Validated against table schemas at deploy/load time
- Storage-format-agnostic from the language's perspective (CSV in development, SQLite in production — same program, same results)

---

## 4. Type System

### 4.1 Primitive Types

| Type | Description | Literals |
|------|-------------|----------|
| `number` | Numeric values | `42`, `3.14`, `-1` |
| `string` | Text values | `"hello"`, `"DRI-945"` |
| `bool` | Boolean values | `true`, `false` |

### 4.2 Compound Types

| Type | Description | Literals |
|------|-------------|----------|
| `list(T)` | Ordered collection of elements | `[1, 2, 3]`, `["a", "b"]` |
| `dict(T)` | Key-value mapping | `{ key: "value", other: 42 }` |

### 4.3 Named Types

Named types are declared with `type` and are nominal — two types with different names are distinct even if structurally identical.

**Variant types** model values that can be one of several tagged shapes:

```axiom
type ProductOutcome =
    offered { total: number, covers: dict(CoverOutcome) }
  | declined { reasons: list(string) }
  | referred { reasons: dict(string) }
```

**Record types** model values with a single known shape:

```axiom
type Exposure = { industry: string, turnover: number }
```

### 4.4 Parameterized Types

Types may accept parameters. The core provides `list(T)` and `dict(T)`. Extensions may define additional parameterized types such as `money(GBP)`.

### 4.5 Type Assignability

Axiom uses nominal typing for named types and structural compatibility for dicts and inline shapes.

Rules:

1. **Primitives**: same name = assignable. `number` to `number`, `string` to `string`, etc.
2. **Named variant types**: same name = same type. Structural equivalence is not sufficient.
3. **Lists**: `list(T)` is assignable to `list(U)` when `T` is assignable to `U`.
4. **Dicts/records**: structural compatibility — all required fields must exist with compatible types.
5. **`mixed`**: a special internal type assignable to any target. Used for intrinsic function parameters that accept any type.

### 4.6 Type Coercion

Explicit coercion with `as` is required — Axiom never performs hidden coercions.

Valid coercions:

| From | To |
|------|-----|
| `string` | `number` |
| `number` | `string` |

Extensions may register additional coercion paths (e.g., `string` ↔ `money`, `number` ↔ `money`).

### 4.7 Member Access

Member access (`expr.property`) is valid when the type has a known shape declaring that property:

- Record types: access validated against declared fields
- Dict literals with known shape: access validated against inferred fields
- Expression parameters with inline shapes: access validated against declared shape
- Variant types: access is legal **only** when the field exists on **every** alternative with the same type. Otherwise, narrow with `match` first.

### 4.8 Index Access

Index access (`expr[index]`) is valid on:

- `list(T)[number]` → `T`
- `dict(shape)["key"]` → type of the keyed field, when the key is statically known

---

## 5. Expressions

All computation in Axiom is expressed through expressions. There are no statements.

### 5.1 Literals

```axiom
42              // number
3.14            // number
"hello"         // string
true            // bool
false           // bool
[1, 2, 3]       // list(number)
{ key: "value" } // dict
```

### 5.2 Identifiers and Member Access

```axiom
turnover                    // identifier (resolved from scope)
exposure.industry           // member access
exposure.address.postcode   // chained member access
items[0]                    // index access
```

### 5.3 Arithmetic and Comparison

```axiom
base_rate * (sum_insured / 1000)
total ** 2
premium % 100
turnover >= 50000 && not is_cancelled
trade not in ["asbestos", "demolition"]
```

See §6 for the full operator table.

### 5.4 If / Then / Else

`if/then/else` is an expression — both branches must produce a value.

```axiom
if claims_count == 0
    then 0.95
    else 1.0
```

Chained conditions with `else if`:

```axiom
if claims == 0
    then 0.9
    else if claims <= 2
    then 1.0
    else if claims <= 5
    then 1.25
    else 1.50
```

The condition must be `bool`. The `then` and `else` branches must produce compatible types.

### 5.5 Match Expressions

`match` dispatches on a subject value against a series of pattern arms:

```axiom
match industry {
    "DRI-945" | "DRI-946" => "B",
    _ => "Y",
}
```

Subjectless match (condition-based):

```axiom
match {
    claims_count == 0 => 0.95,
    claims_count <= 2 => 1.00,
    claims_count == 3 => 1.20,
    _ => 1.50,
}
```

Tuple match (multiple subjects):

```axiom
match (region, tier) {
    ("north", "premium") => 1.2,
    ("south", _) => 1.0,
    _ => 0.9,
}
```

Variant match with destructuring:

```axiom
match cover {
    rated { base_premium: p, limit: l } => p * l / 1000,
    not_available { reason: r } => 0,
}
```

#### Match over lists (`match ... in`)

`match binding in list` iterates over a list, binding each element to a variable, and returns the first arm that matches. The wildcard arm serves as a "no element matched" fallback.

```axiom
match row in industry_config {
    row.code == industry => row.pl_class,
    _ => "",
}
```

This is the primary mechanism for querying table data. The arms are expression patterns evaluated with the binding in scope — any boolean condition that references the bound element.

Semantics:
1. For each element in the list, bind it to the variable and try each non-wildcard arm in order
2. The first element+arm combination that matches returns the arm's expression value
3. If no element matches any arm, the wildcard arm (if present) provides the default

```axiom
// Multi-condition lookup
match row in premium_bands {
    turnover >= row.min && turnover < row.max => row.rate,
    _ => 0,
}

// Combining conditions: find matching row within a subset
match row in industry_config {
    row.code in industries && row.pl_severity == worst => row.pl_class,
    _ => "",
}
```

See §7 for all pattern forms.

### 5.6 Expression Calls

Named expressions are called by name with named arguments:

```axiom
BuildingsCover(exposure: exposure, limit: 500000)
```

Qualified calls into namespaces:

```axiom
Industry.BuildingsFireClass(industry: exposure.industry)
```

#### Named argument shorthand

When a variable name matches the parameter name, the `: value` can be omitted:

```axiom
// These are equivalent:
Rate(exposure: exposure, limit: limit)
Rate(exposure, limit)
```

#### Spread operator

The spread operator `...` fills remaining unbound parameters by matching variable names in the caller's scope to parameter names in the callee:

```axiom
Product(exposure: Exposure, claims: ClaimsHistory, pl_limit: number): ProductOutcome {
    total_claims_loading = Claims.TotalLoading(...)
        where total_claims_loading = Claims.TotalLoading(
            number_of_claims: claims.number_of_claims,
            total_claims_value: claims.total_claims_value,
        ),
    ...
}
```

Spread can be combined with explicit arguments — explicit arguments take precedence:

```axiom
Rate(..., total_claims_loading: custom_value)
```

The type checker validates that every spread-resolved binding has a compatible type with the target parameter. Missing parameters after spread resolution produce a type error.

### 5.7 Variant Construction

Variant values are constructed with their tag name:

```axiom
rated {
    key: "PL",
    name: "Public Liability",
    base_premium: 500,
    limit: 1000000,
    excess: 250,
}
```

Payload-less variant construction:

```axiom
active          // payload-less tag as identifier
declined        // payload-less tag as identifier
```

Qualified construction for disambiguation:

```axiom
CoverOutcome.not_available { reason: "zone_blocked" }
```

#### Field shorthand

When a variable name matches the field name:

```axiom
// These are equivalent:
offered { covers: covers, subtotal: subtotal, total: total }
offered { covers, subtotal, total }
```

Shorthand and explicit fields can be mixed:

```axiom
offered { covers, subtotal, total: round(raw_total, 2) }
```

### 5.8 Dict Literals

```axiom
{
    pl: PublicLiability.Rate(exposure, limit: pl_limit),
    bc: BuildingsContents.Rate(exposure, bc, risks),
    bi: BusinessInterruption.Rate(exposure, bi),
}
```

Dict literals support the same field shorthand as variant construction.

### 5.9 List Literals

```axiom
[1, 2, 3]
["DRI-945", "DRI-946", "DRI-947"]
[rule1, rule2, rule3]
```

### 5.10 Where Clauses

`where` introduces local bindings for intermediate values. Bindings are evaluated left-to-right and each binding can reference previous ones.

```axiom
round(total, 2)
    where base = exposure.turnover / 1000 * rate,
          adjusted = base * claims_loading * experience_factor,
          total = adjusted * group_relativity
```

Where clauses keep expression bodies readable by naming intermediate steps without requiring separate named expressions for every calculation:

```axiom
Product(exposure: Exposure, claims: ClaimsHistory): ProductOutcome {
    offered {
        covers,
        total_gross_premium: round(subtotal, 2),
        total_net_premium: round(subtotal * (1 - commission_rate), 2),
        commission_rate: 0.35,
        currency: "GBP",
    }
        where total_claims_loading = Claims.TotalLoading(...),
              covers = {
                  pl: PublicLiability.Rate(exposure, limit: pl_limit, total_claims_loading),
                  bc: BuildingsContents.Rate(exposure, bc, risks, total_claims_loading),
              },
              subtotal = max(
                  sum collect rated { base_premium: p } in covers => p,
                  MinimumPremium(exposure.industry),
              )
}
```

### 5.11 Coercion

```axiom
"42" as number        // string to number
42 as string          // number to string
```

### 5.12 Parenthesized Expressions

Parentheses override operator precedence:

```axiom
(base + adjustment) * factor
```

---

## 6. Operators

### 6.1 Precedence Table

From lowest to highest precedence:

| Precedence | Operators | Associativity | Description |
|------------|-----------|---------------|-------------|
| 1 | `\|\|` | left | Logical OR |
| 2 | `&&` | left | Logical AND |
| 3 | `==`, `!=` | left | Equality |
| 4 | `<`, `>`, `<=`, `>=`, `in`, `not in` | left | Comparison and membership |
| 5 | `+`, `-` | left | Addition, subtraction |
| 6 | `*`, `/`, `%` | left | Multiplication, division, modulo |
| 7 | `**` | **right** | Exponentiation |

### 6.2 Unary Operators

| Operator | Operand | Result | Description |
|----------|---------|--------|-------------|
| `-` | `number` | `number` | Numeric negation |
| `not` | `bool` | `bool` | Logical negation |
| `!` | `bool` | `bool` | Logical negation (alias for `not`) |

`not` is the preferred form for readability. `!` is accepted as an alias.

### 6.3 Arithmetic Operators

| Operator | Left | Right | Result |
|----------|------|-------|--------|
| `+` | `number` | `number` | `number` |
| `-` | `number` | `number` | `number` |
| `*` | `number` | `number` | `number` |
| `/` | `number` | `number` | `number` |
| `%` | `number` | `number` | `number` |
| `**` | `number` | `number` | `number` |

Extensions may add operator rules for additional types (e.g., `money * number → money`).

### 6.4 Comparison Operators

| Operator | Operands | Result |
|----------|----------|--------|
| `==` | any, any | `bool` |
| `!=` | any, any | `bool` |
| `<` | any, any | `bool` |
| `>` | any, any | `bool` |
| `<=` | any, any | `bool` |
| `>=` | any, any | `bool` |

### 6.5 Logical Operators

| Operator | Left | Right | Result |
|----------|------|-------|--------|
| `&&` | `bool` | `bool` | `bool` |
| `\|\|` | `bool` | `bool` | `bool` |

### 6.6 Membership Operators

| Operator | Left | Right | Result | Description |
|----------|------|-------|--------|-------------|
| `in` | `T` | `list(T)` | `bool` | Element is in list |
| `not in` | `T` | `list(T)` | `bool` | Element is not in list |

`not in` is a first-class operator, not sugar for `not (x in xs)`. It is parsed as a single two-token operator.

### 6.7 Arrow Operator

`=>` is used in match arms and collect bodies to separate patterns/conditions from result expressions. It is not a general-purpose operator.

---

## 7. Patterns

Patterns are used in `match` arms, `any`/`all` predicates, and `collect` forms.

### 7.1 Wildcard Pattern

Matches any value without binding:

```axiom
_ => default_value
```

### 7.2 Literal Patterns

Match by value equality:

```axiom
42 => "exact number",
"brick" => 1.0,
true => "yes",
```

### 7.3 Alternative Patterns

Match if any alternative matches:

```axiom
"DRI-945" | "DRI-946" => "B",
1 | 2 | 3 => "low",
```

### 7.4 Range Patterns

Match numeric ranges with inclusive `[]` and exclusive `()` bounds:

```axiom
[0..100]     // 0 ≤ x ≤ 100  (inclusive both)
(0..100)     // 0 < x < 100  (exclusive both)
[0..100)     // 0 ≤ x < 100  (inclusive left, exclusive right)
(0..100]     // 0 < x ≤ 100  (exclusive left, inclusive right)
```

Open-ended ranges:

```axiom
[5..]        // x ≥ 5
[..10]       // x ≤ 10
(0..)        // x > 0
```

### 7.5 Variant Patterns

Match on variant tag and optionally destructure payload fields:

```axiom
rated { base_premium: p, limit: l } => p + l,
not_available { reason: r } => r,
```

Field shorthand (bind to same name as field):

```axiom
rated { base_premium, limit } => base_premium + limit,
```

Wildcard field binding (match field but don't bind):

```axiom
rated { base_premium: _, limit: l } => l,
```

Payload-less variant patterns:

```axiom
active => "active",
suspended => "on hold",
cancelled => "terminated",
```

Qualified variant patterns:

```axiom
CoverOutcome.rated { base_premium: p } => p,
```

### 7.6 Tuple Patterns

Match against tuple subjects:

```axiom
match (region, tier) {
    ("north", "premium") => 1.2,
    ("south", _) => 1.0,
    (_, _) => 0.9,
}
```

### 7.7 Expression Patterns

In subjectless match, arms use boolean expression patterns:

```axiom
match {
    claims == 0 => 0.95,
    claims <= 2 => 1.00,
    _ => 1.50,
}
```

### 7.8 Exhaustiveness

When matching on a variant type, the type checker verifies that all tags are covered. A wildcard `_` arm satisfies exhaustiveness for all remaining tags.

```axiom
// Type error: non-exhaustive match — missing "referred"
match outcome {
    offered { total: t } => t,
    declined => 0,
}
```

---

## 8. Collection Forms

Axiom provides pattern-aware collection operations over lists. These are narrower than general higher-order functions and are designed for working with lists of variants.

### 8.1 `any` — Existential Predicate

Returns `true` if at least one element matches the pattern:

```axiom
any referred in covers
any rated { base_premium: _ } in covers
```

Type: `bool`

### 8.2 `all` — Universal Predicate

Returns `true` if every element matches the pattern:

```axiom
all rated in covers
all ok { loading: _ } in rules
```

Type: `bool`

### 8.3 `collect` — Pattern Map

Evaluates the body for each matching element and returns a list of results:

```axiom
collect referred { reason: r } in covers => r
collect rated { base_premium: p } in covers => p * 1.1
```

Type: `list(T)` where `T` is the body type.

### 8.4 Aggregate Collect

Applies an aggregator function to the collected results. The aggregator is a registered intrinsic (e.g., `sum`, `product`, `max`, `min`).

```axiom
product collect in rules {
    ok { factor: f } => f,
    _ => 1.0,
}

sum collect in covers {
    rated { base_premium: p } => p,
    not_available => 0,
}
```

The arms must be exhaustive over the element type. Each arm body must produce a type compatible with the aggregator's input.

### 8.5 Collect Over Lists (`collect ... in`)

The binding form of `collect` binds each element to a name and transforms or filters it.

**Map form** — transform every element:

```axiom
collect prop in exposure.properties => PropertyRating.Total(prop)
// → [45.27, 101.95, 163.82]
```

**Filter form** — collect only matching elements using arms:

```axiom
collect row in industry_config {
    row.code in industries => row.pl_class,
}
// → ["A", "B", "C"]
```

Unlike `match ... in` (which returns the first match), `collect ... in` gathers all matches into a list. In the filter form, non-matching elements are skipped; a wildcard arm, if present, serves as a fallback for non-matching elements.

Type: `list(T)` where `T` is the body/arm body type.

### 8.6 Aggregate Collect Over Lists

The aggregate form applies an aggregator to the collected results.

**Map form** — aggregate every element:

```axiom
sum collect prop in exposure.properties => PropertyRating.Total(prop)
// → 311.04
```

**Filter form** — aggregate only matching elements:

```axiom
max collect row in industry_config {
    row.code in industries => row.deep_frying_rate,
}
// → 0.35
```

This is the primary mechanism for multi-row aggregation — finding the worst-case, total, or average across matching rows.

### 8.7 Collection Form Typing

- The list operand must be `list(T)` for some `T`.
- Patterns are checked against `T`.
- `any`/`all` return `bool`.
- `collect` returns `list(U)` where `U` is the body type.
- Aggregate collect returns the aggregator's return type (typically `number`).
- In binding forms (`collect row in list => ...` and `collect row in list { ... }`), the binding variable has the element type `T` and is available in the body or all arm expressions.

---

## 9. Intrinsic Functions

Axiom v1 includes a small set of built-in functions.

### 9.1 Core Intrinsics

| Function | Signature | Description |
|----------|-----------|-------------|
| `round(value, decimals)` | `(number, number) → number` | Round to `decimals` decimal places |
| `len(collection)` | `(list \| dict) → number` | Number of elements/entries |
| `flatten(nested)` | `(list(list(T))) → list(T)` | Flatten one nesting level |
| `sum(collection)` | `(list(number)) → number` | Sum all elements |
| `product(collection)` | `(list(number)) → number` | Multiply all elements |
| `max(a, b, ...)` | `(number, number, ...) → number` | Maximum value (variadic or single list) |
| `min(a, b, ...)` | `(number, number, ...) → number` | Minimum value (variadic or single list) |

### 9.2 Aggregators

`sum`, `product`, `max`, and `min` are also usable as aggregator names in aggregate collect expressions:

```axiom
sum collect in items { rated { premium: p } => p, _ => 0 }
product collect in rules { ok { factor: f } => f, _ => 1 }
max collect in options { available { rate: r } => r, _ => 0 }
```

---

## 10. Type Checking

### 10.1 Inference Rules

The type checker infers types bottom-up:

| Expression | Inferred Type |
|------------|---------------|
| `42`, `3.14` | `number` |
| `"hello"` | `string` |
| `true`, `false` | `bool` |
| `[1, 2, 3]` | `list(number)` |
| `{ a: 1, b: "x" }` | `dict` with shape `{ a: number, b: string }` |
| `identifier` | declared type from scope |
| `object.property` | property type from object shape |
| `object[index]` | element/value type |
| `expr as type` | target type |
| `left OP right` | operator return type |
| `not expr` / `!expr` | `bool` |
| `-expr` | `number` |
| `if/then/else` | common branch type |
| `match` | common arm type |
| `Name(args)` | return type of named expression |
| `tag { fields }` | resolved variant type |
| `any P in xs` | `bool` |
| `all P in xs` | `bool` |
| `collect P in xs => body` | `list(T)` where `T` is body type |
| `agg collect in xs { ... }` | aggregator return type |
| `expr where bindings` | type of `expr` |

### 10.2 Variant Resolution

Variant tags are resolved in this order:

1. **Contextual**: from the expected type (return type annotation, list element type, etc.)
2. **Qualified**: explicit `TypeName.tag` or `Namespace.tag`
3. **First-match**: search all declared types for a unique match

If a tag appears in multiple types and is not qualified, the type checker reports an ambiguity error.

### 10.3 Conditional Typing

Both branches of `if/then/else` must produce compatible types. When the return type is annotated as a variant type, branches may produce different tags of that variant.

### 10.4 Match Exhaustiveness

For variant subjects, the type checker verifies all tags are covered. A wildcard `_` arm satisfies any uncovered tags. Missing coverage produces a diagnostic listing the uncovered tags.

For non-variant subjects (numbers, strings), no exhaustiveness check is performed — a wildcard arm is recommended.

### 10.5 Expression Call Checking

For each expression call, the type checker validates:

- All required parameters are provided (by name, shorthand, or spread)
- No unknown parameter names
- Argument types are assignable to parameter types
- Spread-resolved bindings have compatible types
- Return type (if annotated) is satisfied by the body

### 10.6 Soundness Guarantees

The type checker enforces:

- Operator type validity
- Coercion validity
- Member access validity (field exists on type)
- Index access validity
- Match exhaustiveness (for variant subjects)
- Match arm type consistency
- Expression call argument validation (arity, names, types)
- Variant constructor validity (tag exists, fields correct, types match)
- No unresolved symbols
- No duplicate expression or type names

---

## 11. Evaluation

### 11.1 Direct AST Evaluation

The evaluator walks AST nodes directly. There is no separate compiled intermediate representation in v1.

### 11.2 Runtime Representation

**Records and dicts** are plain associative structures:

```json
{ "industry": "DRI-945", "turnover": 500000 }
```

**Variants** use a reserved `_tag` field:

```json
{
    "_tag": "rated",
    "key": "PL",
    "name": "Public Liability",
    "base_premium": 500,
    "limit": 1000000,
    "excess": 250
}
```

**Payload-less variants**:

```json
{ "_tag": "active" }
```

The `_tag` field is reserved. Authors may not declare payload fields named `_tag`.

### 11.3 Scope

Each expression call creates a new scope with parameters bound from arguments. `where` bindings extend the current scope for the duration of the `where` expression. Namespace symbols are available via qualified access.

### 11.4 Evaluation Order

- `if/then/else`: condition first, then only the taken branch
- `match`: subject first, then arms top-to-bottom until a match
- `where`: bindings left-to-right, then the body
- Operators: left-to-right (except `**` which is right-to-left)
- Expression calls: arguments evaluated, then body in new scope
- `&&` / `||`: short-circuit evaluation

### 11.5 Match Dispatch

- **Literal arms**: match by value equality
- **Range arms**: match by numeric range inclusion
- **Variant arms**: dispatch on the runtime `_tag` field; matched bindings introduced into arm scope
- **Wildcard arms**: match any value
- **Expression arms** (subjectless match): evaluate the expression as boolean; first truthy arm wins
- **Alternative arms**: match if any sub-pattern matches

### 11.6 Collection Form Evaluation

- `any P in xs` — iterate, return `true` on first match
- `all P in xs` — iterate, return `false` on first non-match
- `collect P in xs => body` — iterate, evaluate body for each match, collect into list
- `agg collect in xs { arms }` — iterate, evaluate matching arm body for each element, apply aggregator to resulting list

---

## 12. Grammar

```ebnf
program          = { declaration } ;
declaration      = type_decl | namespace_decl | expr_decl ;

(* --- Type declarations --- *)

type_decl        = "type" UPPER_IDENT "=" ( record_shape | variant_alts ) ;
record_shape     = "{" field_decl { "," field_decl } [ "," ] "}" ;
variant_alts     = variant_alt { "|" variant_alt } ;
variant_alt      = LOWER_IDENT [ "{" field_decl { "," field_decl } [ "," ] "}" ] ;
field_decl       = LOWER_IDENT ":" type_expr ;

(* --- Namespace declarations --- *)

namespace_decl   = "namespace" UPPER_IDENT "{" { ns_member } "}" ;
ns_member        = type_decl | expr_decl | symbol_decl ;
symbol_decl      = LOWER_IDENT ":" type_expr "=" expression ;

(* --- Expression declarations --- *)

expr_decl        = UPPER_IDENT "(" [ param_list ] ")" [ ":" type_expr ]
                   "{" expression "}" ;
param_list       = param { "," param } ;
param            = LOWER_IDENT ":" ( type_expr | record_shape ) ;

(* --- Type expressions --- *)

type_expr        = TYPE_KEYWORD [ "(" type_args ")" ] | UPPER_IDENT ;
type_args        = expression { "," expression } ;
TYPE_KEYWORD     = "number" | "string" | "bool" | "list" | "dict" ;

(* --- Expressions --- *)

expression       = where_expr ;

where_expr       = or_expr [ "where" binding { "," binding } ] ;
binding          = LOWER_IDENT "=" expression ;

or_expr          = and_expr { "||" and_expr } ;
and_expr         = equality_expr { "&&" equality_expr } ;
equality_expr    = comparison_expr { ( "==" | "!=" ) comparison_expr } ;
comparison_expr  = additive_expr { ( "<" | ">" | "<=" | ">=" | "in"
                   | "not" "in" ) additive_expr } ;
additive_expr    = multiplicative_expr { ( "+" | "-" ) multiplicative_expr } ;
multiplicative_expr = power_expr { ( "*" | "/" | "%" ) power_expr } ;
power_expr       = unary_expr [ "**" power_expr ] ;
unary_expr       = ( "not" | "!" | "-" ) unary_expr | postfix_expr ;
postfix_expr     = primary { "." LOWER_IDENT
                           | "[" expression "]"
                           | "as" type_expr } ;

primary          = if_expr
                 | match_expr
                 | aggregate_collect
                 | collect_expr
                 | any_expr
                 | all_expr
                 | call_or_variant_ctor
                 | list_literal
                 | dict_literal
                 | NUMBER | STRING | BOOL
                 | LOWER_IDENT | UPPER_IDENT
                 | "(" expression ")" ;

(* --- Control flow --- *)

if_expr          = "if" expression "then" expression
                   { "else" "if" expression "then" expression }
                   "else" expression ;

match_expr       = "match" [ match_subject ] "{" match_arm { "," match_arm }
                   [ "," ] "}" ;
match_subject    = "(" expression { "," expression } ")" | expression ;
match_arm        = pattern "=>" expression ;

(* --- Collection forms --- *)

any_expr         = "any" pattern "in" expression ;
all_expr         = "all" pattern "in" expression ;
collect_expr     = "collect" pattern "in" expression "=>" expression ;
aggregate_collect = LOWER_IDENT "collect" "in" expression
                    "{" collect_arm { "," collect_arm } [ "," ] "}" ;
collect_arm      = pattern "=>" expression ;

(* --- Calls and construction --- *)

call_or_variant_ctor = qualified_upper "(" [ arg_list ] [ "..." ] ")"
                     | LOWER_IDENT "{" [ entry_list ] "}"
                     | qualified_upper "." LOWER_IDENT "{" [ entry_list ] "}" ;
qualified_upper  = UPPER_IDENT { "." UPPER_IDENT } ;
arg_list         = arg { "," arg } ;
arg              = LOWER_IDENT ":" expression | expression ;
entry_list       = entry { "," entry } ;
entry            = LOWER_IDENT ":" expression | LOWER_IDENT ;

list_literal     = "[" [ expression { "," expression } ] [ "," ] "]" ;
dict_literal     = "{" [ entry_list ] [ "," ] "}" ;

(* --- Patterns --- *)

pattern          = alt_pattern ;
alt_pattern      = single_pattern { "|" single_pattern } ;
single_pattern   = wildcard_pat | range_pat | variant_pat | tuple_pat
                 | literal_pat | expr_pat ;
wildcard_pat     = "_" ;
literal_pat      = NUMBER | STRING | BOOL ;
range_pat        = ( "[" | "(" ) [ NUMBER ] ".." [ NUMBER ] ( "]" | ")" ) ;
variant_pat      = [ qualified_upper "." ] LOWER_IDENT
                   [ "{" pat_binding { "," pat_binding } [ "," ] "}" ] ;
pat_binding      = LOWER_IDENT [ ":" ( LOWER_IDENT | "_" ) ] ;
tuple_pat        = "(" pattern "," pattern { "," pattern } ")" ;
expr_pat         = expression ;

(* --- Lexical --- *)

UPPER_IDENT      = [A-Z] [a-zA-Z0-9_]* ;
LOWER_IDENT      = [a-z_] [a-zA-Z0-9_]* ;
NUMBER           = [0-9]+ [ "." [0-9]+ ] ;
STRING           = '"' ( [^"\\] | '\\' . )* '"' ;
BOOL             = "true" | "false" ;
COMMENT          = "//" [^\n]* ;
```

### 12.1 Keywords

```
type  namespace  if  then  else  match  not  in  as
any  all  collect  where  true  false
```

### 12.2 Reserved

```
_tag    // reserved field name (variant tag marker)
_       // wildcard pattern
```

---

## 13. Diagnostics

All pipeline stages (parse, type check, lint, validate) produce diagnostics with a uniform structure:

- **severity**: `error`, `warning`, or `info`
- **code**: stable dotted identifier (e.g., `type.unknown_tag`, `parse.unexpected_token`)
- **message**: human-readable description
- **location**: line, column, offset, length

### 13.1 Diagnostic Code Categories

| Prefix | Stage | Examples |
|--------|-------|----------|
| `parse.*` | Parser | `parse.unexpected_token`, `parse.unterminated_string` |
| `type.*` | Type checker | `type.unknown_tag`, `type.missing_field`, `type.argument_mismatch` |
| `lint.*` | Linter | `lint.unused_expression`, `lint.unreachable_arm` |
| `validation.*` | Input validation | `validation.missing_field`, `validation.type_mismatch` |

### 13.2 Error Quality

Diagnostics should:

- Name expected and actual types concretely: `expected number, got string`
- For unknown variant tags, suggest the nearest valid tag
- For unknown fields, suggest the nearest valid field name
- For expression call errors, show the expected parameter signature
- For type mismatches in arguments, identify which parameter is wrong

### 13.3 Parser Recovery

The parser should recover at declaration boundaries and produce partial ASTs where possible, so the type checker can report additional errors on valid portions.

---

## 14. Open Questions

The following areas are acknowledged as important but not yet fully designed. Each will be addressed as a follow-up to this specification.

### 14.1 ~~Boundary Functions and~~ External Data — RESOLVED

**Resolution**: External data integrates via **table declarations** (§3.4) and **match/collect over lists** (§5.5, §8.5–8.6), not via boundary functions or plugins.

**Key design decisions**:

1. **Tables are core language constructs**, not an escape hatch to external systems. A `table` declaration defines a typed, immutable list of records. The data comes from a companion artifact (CSV, etc.) bundled with the program version.

2. **The language is declarative about data access**. `match row in table { condition => value }` says WHAT to find; the runtime decides HOW (CSV scan, SQLite index, hash lookup). Same program, same results regardless of backing store.

3. **No boundary functions needed for data lookup**. The original thinking assumed external data required a plugin system or callable escape hatch. Instead, tables make external data a first-class, pure, type-checked part of the language.

4. **Failure model**: Tables are validated at load time against the declared schema. If the artifact doesn't match the schema, the program fails to load — not at evaluation time. The wildcard arm in `match ... in` handles "no row matched" as a value, not an error.

5. **Multi-row queries** use `collect row in table { ... }` to gather all matching rows, composable with existing aggregators (`max`, `sum`, etc.).

**Prototyped and validated** in the playground with a hospitality insurance product: single table replaces 12 source declarations, CSV artifact loading, single-industry and multi-industry lookups all verified.

### 14.2 Money Type — RESOLVED

Money is an **extension type** that plugs into the language via custom types, operator overloading, and coercion rules (see §14.6). It is not part of the core language, but v1 defines the syntax and semantics that money extensions must conform to.

#### Literal Syntax

Money literals use a currency prefix (symbol or ISO 4217 code) followed directly by a decimal number:

```axiom
£100            // money(GBP)
£1234.56        // money(GBP)
€50.25          // money(EUR)
$200            // money(USD)
GBP100          // money(GBP) — 3-letter ISO code form
USD1500.00      // money(USD)
JPY10000        // money(JPY)
```

**Predefined symbol mapping:**

| Symbol | Currency |
|--------|----------|
| `£`    | GBP      |
| `€`    | EUR      |
| `$`    | USD      |
| `¥`    | JPY      |

All other currencies use the 3-letter ISO 4217 code prefix (e.g., `AUD250`, `CHF100.50`).

#### Type

`money(CURRENCY)` is a parameterized type. The currency is part of the type — `money(GBP)` and `money(USD)` are distinct types.

```axiom
BasePremium(turnover: number, rate: number): money(GBP) {
    £100 + turnover * rate     // type error: number * number → number, not money
}
```

#### Arithmetic Rules

| Expression | Result | Notes |
|------------|--------|-------|
| `money(C) + money(C)` | `money(C)` | Same currency required |
| `money(C) - money(C)` | `money(C)` | Same currency required |
| `money(C) * number` | `money(C)` | Scaling |
| `number * money(C)` | `money(C)` | Scaling (commutative) |
| `money(C) / number` | `money(C)` | Division by scalar |
| `money(C) / money(C)` | `number` | Ratio |
| `money(C1) + money(C2)` | **type error** | Cross-currency |
| `money(C) + number` | **type error** | Cannot mix money and number |

Comparison operators (`==`, `!=`, `<`, `>`, `<=`, `>=`) work between values of the same `money(C)` type and return `bool`.

#### Coercion

```axiom
"100.50" as money(GBP)    // string → money
150 as money(GBP)          // number → money
```

#### Precision

Money operations use arbitrary-precision decimal arithmetic (e.g., Brick\Money in PHP). Intermediate calculations preserve full precision. Rounding is explicit:

```axiom
round(£100 / 3, 2)    // → £33.33
```

Currency-specific precision (e.g., 2 decimal places for GBP, 0 for JPY) is enforced by the runtime, not the language.

#### Extension Mechanism

A money extension registers:
1. **Type constructor** — `money(CURRENCY)` with currency validation
2. **Operator overloader** — arithmetic and comparison rules for money operands
3. **Coercion rules** — `string → money`, `number → money` conversion
4. **Literal tokenizer** — currency symbol/code recognition in the lexer

The playground treats money as `number` for simplicity. A production implementation uses the money extension with Brick\Money (or equivalent) for precision and currency safety.

### 14.3 Numeric Precision — RESOLVED

**Resolution**: `number` is arbitrary-precision decimal. IEEE 754 floats are not conformant. See §19 for full specification.

**Key decisions**:

1. **Exact decimal representation**. All `number` values are stored and computed as arbitrary-precision decimals. Literals like `0.1` are parsed as exact decimal values, not float approximations. `0.1 + 0.2 == 0.3` must hold.

2. **Arithmetic precision**. All operators (`+`, `-`, `*`, `/`, `%`, `**`) operate on exact decimals. Division produces exact results up to implementation-defined precision (recommended minimum: 20 significant digits). Explicit `round()` is required when a specific precision is needed.

3. **Comparison is exact**. No epsilon tolerance — decimal comparison is bitwise on the decimal representation. This eliminates an entire class of subtle bugs in financial computation.

4. **Coercion preserves precision**. `"1.005" as number` produces exact `1.005`, not a float approximation. JSON serialization uses string-encoded decimals to avoid JSON float precision loss.

5. **Implementation requirements**. Conformant implementations must use an arbitrary-precision decimal library: `bcmath` or `brick/math` in PHP, `BigDecimal` in Java, `decimal.js` in JavaScript, `decimal` module in Python.

6. **Playground exception**. The playground uses JavaScript floats as a pragmatic simplification for prototyping. It is explicitly non-conformant on precision — this is acceptable for syntax and type-system validation, but not for production use.

### 14.4 Evaluation Model — Lazy vs Eager — RESOLVED

**Resolution**: Lazy evaluation with memoization. Parameters and `where` bindings are evaluated on first reference, not at definition time. Each value is computed at most once and cached.

**Key decisions**:

1. **Expression arguments are lazy**. Parameters are evaluated on first reference, not at call time. If a branch never touches a parameter, it's never computed. A product that declines early skips all cover rating computations.

2. **`where` bindings are lazy**. In `body where a = ..., b = ..., c = ...`, each binding is only evaluated when first demanded by `body` or by another binding. Source order does not determine evaluation order — only data dependencies do.

3. **Memoized**. Each parameter and `where` binding is evaluated at most once per scope. First access computes and caches; subsequent accesses return the cached value.

4. **Fresh memo table per call**. Each named expression call creates a child scope with a fresh memo table. There is no sharing of memoized values across expression calls.

5. **Safety guarantee**. Lazy evaluation is safe because Axiom is pure — no side effects, no mutation, no I/O. The result of evaluating an expression is the same regardless of evaluation order. The only observable difference is performance.

6. **Type checker validates all paths**. The type checker validates ALL branches and ALL expressions statically, regardless of whether they would be reached at runtime. Errors in unreached code are caught at check time, not hidden by lazy evaluation.

**Playground exception**: The playground uses eager evaluation for implementation simplicity. This produces identical results (purity guarantees this) but may compute unnecessary values.

### 14.5 Error Model — RESOLVED

**Resolution**: If the type checker passes and input validation passes, evaluation cannot fail. The error model is **statically total** — every potential runtime error is either prevented by the type system or made safe by design.

**Key decisions**:

1. **Division by zero — prevented by refined number types**. The type system includes refined subtypes of `number`: `positive` (> 0), `non_negative` (>= 0), and `non_zero` (!= 0). Division requires the divisor to be `non_zero` or `positive`. If the divisor is typed as plain `number`, the type checker rejects it. See §6.1 in the revised spec for the full refinement type system.

2. **Non-exhaustive match — type error**. The type checker requires match arms to be exhaustive: all variant tags covered, or a wildcard `_` present. Non-exhaustive match is a type error, not a runtime error.

3. **Coercion failure — total by design**. `"abc" as number` returns `0`. Coercion is explicitly requested by the author and always succeeds. Input data is validated at load time, so runtime coercions typically operate on known-good data.

4. **Index out of bounds — total by design**. `list[n]` where `n` is out of range returns `null`. The type checker warns on unguarded index access. In practice, Axiom programs rarely use direct indexing — `collect`, `match ... in`, `any`, and `all` iterate safely.

5. **Missing input fields — rejected at input validation**. Input data is validated against declared parameter shapes before evaluation begins.

6. **Table schema mismatch — rejected at load time**. Table data is validated against the declared schema when the program is loaded.

**Refined number types** are the key design addition. They allow the type system to prove division safety, and they align naturally with the insurance domain where divisors are almost always inherently positive (counts, rates, sums insured). See §6.1 for the subtype hierarchy and §12.2 for inference and narrowing rules.

### 14.6 Extension/Plugin System — RESOLVED

**Resolution**: A plugin is a bundle of hooks across three pipeline stages: **lexer**, **checker**, and **evaluator**. The hooks are defined as abstract contracts (not tied to a specific language) and follow a "first plugin wins, or fall through to defaults" dispatch model. Prototyped and validated in the playground with the `axiom-money` plugin.

#### Plugin Structure

A plugin provides a name and optional hooks for each pipeline stage:

```
Plugin
  name: string
  lexer?: LexerHooks
  checker?: CheckerHooks
  evaluator?: EvaluatorHooks
```

All hooks are optional. A plugin may provide hooks for any combination of stages — e.g., a money plugin provides all three, while a plugin that only adds intrinsic functions might only provide evaluator hooks.

#### Lexer Hooks

The lexer hook allows a plugin to recognise custom literal syntax in the source text.

```
LexerHooks
  tryTokenize(source: string, position: int) -> PluginToken | null
  
PluginToken
  tag: string           // token identifier, e.g. "money"
  value: string         // display text, e.g. "£100.50"
  payload: any          // structured data carried to AST and evaluator
  length: int           // characters consumed from source
```

The lexer tries each plugin's `tryTokenize` at each position **before** the core tokenizer. If a plugin returns a token, it's emitted as a `PluginLiteral` and the lexer advances by `length`. If no plugin matches, the core tokenizer handles the position.

**Example**: The money plugin's lexer hook recognises `£100.50` (symbol prefix) and `GBP100.50` (ISO code prefix), returning a token with tag `"money"` and a structured payload containing the amount and currency.

#### Checker Hooks

The checker hooks allow a plugin to participate in type inference and type checking.

```
CheckerHooks
  inferLiteralType?(tag: string, payload: any) -> TypeSig | null
  checkBinaryOp?(op: string, left: TypeSig, right: TypeSig) -> TypeSig | TypeError | null
  checkCall?(name: string, argTypes: TypeSig[]) -> TypeSig | null
```

**`inferLiteralType`** — Given a plugin literal's tag and payload, return its type. The type checker calls this for every `PluginLiteral` node. Returns `null` to indicate the plugin doesn't handle this tag.

**`checkBinaryOp`** — Given an operator and the inferred types of both operands, return:
- A `TypeSig` (the result type) if the plugin handles this combination
- A `TypeError` with a diagnostic message if the combination is a type error (e.g., cross-currency money addition)
- `null` to defer to the core type rules

The checker tries each plugin **before** the core operator rules. This allows plugins to both extend (new type combinations) and restrict (type errors for invalid combinations) operator behaviour.

**`checkCall`** — Given a function/intrinsic name and the inferred argument types, return the result type if the plugin overrides the built-in type checking for this call. Returns `null` to defer. This is used when a plugin-defined type changes the return type of a core intrinsic (e.g., `round(money, n)` → `money` instead of `number`).

**Example**: The money plugin's checker infers `£100` as `money(GBP)`, defines `money(GBP) + money(GBP)` → `money(GBP)`, rejects `money(GBP) + number` with a descriptive error, and overrides `round(money, n)` to return `money`.

#### Evaluator Hooks

The evaluator hooks allow a plugin to handle operator evaluation and provide intrinsic function overrides.

```
EvaluatorHooks
  supportsOp?(left: any, right: any, op: string) -> bool
  evaluateOp?(left: any, right: any, op: string) -> any
  intrinsics?: map(string -> function(...args) -> any | undefined)
```

**`supportsOp` / `evaluateOp`** — The evaluator checks each plugin before the core operator implementation. If `supportsOp` returns true, `evaluateOp` is called to produce the result. This allows plugins to define runtime behaviour for their types (e.g., money arithmetic that preserves currency metadata).

**`intrinsics`** — A map of function names to implementations. When the evaluator encounters a call to a registered intrinsic, the plugin's implementation is tried first. If it returns `undefined`, the evaluator falls through to the built-in implementation. This allows plugins to override built-in intrinsics for specific argument types (e.g., `sum` over a list of money values).

**Example**: The money plugin's evaluator handles `money + money`, `money * number`, etc. at runtime, preserving the `{ amount, currency }` structure. It overrides `round`, `max`, `min`, and `sum` to work with money values.

#### Dispatch Order

Plugins are registered in a defined order. At each hook point:

1. Plugins are tried in registration order
2. The first plugin that returns a non-null result wins
3. If no plugin handles the hook, the core implementation runs

This means a plugin registered earlier takes priority. In practice, plugins operate on disjoint types (money, interval, etc.) so ordering rarely matters.

#### Boot Sequence

```
for each plugin in registered_plugins:
    register plugin.lexer hooks with lexer
    register plugin.checker hooks with checker
    register plugin.evaluator hooks with evaluator
```

Plugin registration happens once at program load time, before parsing. The set of active plugins is immutable for the lifetime of a program evaluation.

#### Scope of Extensions

With table declarations resolving external data (§14.1), the scope of plugins is focused:

| Extension point | What it provides | Example |
|---|---|---|
| Custom literal syntax | New token forms in source text | `£100`, `(0..1000]` |
| Parameterized types | New types with parameters | `money(GBP)`, `interval(number)` |
| Operator overloading | Type rules + runtime behaviour for new types | `money + money`, `money * number` |
| Intrinsic overrides | Type-specific behaviour for core functions | `round(money)`, `sum(list(money))` |
| Named types | Shared domain vocabulary under a namespace | `insurance.CoverOutcome` |
| Pattern matchers | Custom match pattern forms | interval patterns |

Plugins do **not** provide:
- New syntax forms beyond literals (no new operators, no new keywords)
- Mutable state or side effects
- External data access (handled by tables, §14.1)

#### Validated with Prototype

The `axiom-money` plugin was implemented in the playground, demonstrating all extension points working end-to-end: custom lexer tokens (`£500`, `EUR1000`), type inference and operator checking (`money(GBP) + money(GBP)` → `money(GBP)`, `money + number` → type error), intrinsic overrides (`round`, `max`, `min`, `sum`), and runtime evaluation. The healthcare and tradespeople examples use 116 and 88 money tokens respectively with zero type errors.

### 14.7 Collection Predicate Narrowing — RESOLVED

**Resolution**: Yes, `any`/`all` predicates narrow variant types in conditional branches. This is a v1 feature with a full design specified in §12.5 of the revised spec.

**Rules**:

For `if any P in xs then A else B` where `P` is a variant pattern and `xs` has variant element type `T`:
- `A` is checked under the original type of `xs`
- `B` is checked with `xs` narrowed to `list(T minus matched alternatives)`

For `if all P in xs then A else B`:
- `A` is checked with `xs` narrowed to `list(matched alternatives only)`
- `B` is checked under the original type of `xs`

**Primary use case**: In the `else` branch of `if any referred in covers`, `covers` is narrowed to exclude `referred`. An aggregated collect over `rated`/`not_available` in that branch is exhaustive without a wildcard arm:

```axiom
if any referred in covers
    then referred { reasons: collect referred { reason: r } in covers => r }
    else offered {
        total: sum collect in covers {
            rated { premium: p } => p,
            not_available => 0,
            // no "referred" arm needed — type system knows it's excluded
        },
    }
```

This narrowing applies only to the direct recognised forms. Logically equivalent derived expressions do not trigger narrowing in v1. The playground does not yet implement this, but the spec design is complete.

### 14.8 Pretty Printer and Round-Trip — DEFERRED

**Status**: Desirable but not blocking v1. The revised spec defines the round-trip property `parse(prettyPrint(ast))` yields an equivalent AST (§15), but implementation and testing are deferred.

### 14.9 Namespace `use` / Imports — DEFERRED

**Status**: Not in scope for v1. v1 uses single-file programs with fully qualified namespace references. Multi-file programs and imports are a v2 concern.

### 14.10 Recursion Detection — RESOLVED

**Resolution**: Yes, the type checker detects and rejects circular call dependencies between named expressions. Mutual recursion is a type error — Axiom does not support recursion as a control structure. This is specified as a soundness check in §12.8 of the revised spec.

---

## 15. Full Example

The following demonstrates most language features in a realistic insurance rating scenario:

```axiom
// --- Input record types ---

type Exposure = {
    industry: string,
    number_of_employees: number,
    turnover: number,
    is_sole_trader: bool,
    years_experience: number,
}

type ClaimsHistory = {
    number_of_claims: number,
    total_claims_value: number,
}

type RiskScores = {
    flood_risk: number,
    theft_risk: number,
    terrorism_risk: number,
}

// --- Outcome types ---

type CoverOutcome =
    rated {
        key: string,
        name: string,
        base_premium: number,
        limit: number,
        excess: number,
    }
  | not_available { reason: string }

type ProductOutcome =
    offered {
        covers: dict(CoverOutcome),
        subtotal: number,
        minimum_premium: number,
        total_gross_premium: number,
        total_net_premium: number,
        commission_rate: number,
        currency: string,
    }
  | declined { reasons: list(string) }
  | referred { reasons: dict(string) }

// --- Industry configuration ---

namespace Industry {
    BaseRate(industry: string): number {
        match industry {
            "DRI-945" => 0.85,
            "DRI-946" => 1.10,
            "DRI-947" => 0.65,
            _ => 1.00,
        }
    }

    BaseExcess(industry: string): number {
        match industry {
            "DRI-945" => 500,
            "DRI-946" => 250,
            _ => 100,
        }
    }
}

// --- Claims loading ---

namespace Claims {
    TotalLoading(number_of_claims: number, total_claims_value: number): number {
        FrequencyLoading(number_of_claims) * SeverityLoading(total_claims_value)
    }

    FrequencyLoading(number_of_claims: number): number {
        match number_of_claims {
            0 => 1,
            1 => 1.1,
            2 => 1.25,
            [3..5] => 1.5,
            _ => 2.0,
        }
    }

    SeverityLoading(total_claims_value: number): number {
        match total_claims_value {
            [0..10000] => 1,
            (10000..50000] => 1.15,
            (50000..100000] => 1.35,
            _ => 1.6,
        }
    }
}

// --- Cover rating ---

namespace PublicLiability {
    Rate(exposure: Exposure, limit: number, total_claims_loading: number): CoverOutcome {
        rated {
            key: "PL",
            name: "Public Liability",
            base_premium: round(base * total_claims_loading, 2),
            limit,
            excess: Industry.BaseExcess(industry: exposure.industry),
        }
            where base = exposure.turnover / 1000
                       * Industry.BaseRate(industry: exposure.industry)
                       * limit / 1000000
    }
}

// --- Product entry point ---

Product(exposure: Exposure, claims: ClaimsHistory, pl_limit: number): ProductOutcome {
    offered {
        covers,
        subtotal,
        minimum_premium: 500,
        total_gross_premium: round(max(subtotal, 500), 2),
        total_net_premium: round(max(subtotal, 500) * (1 - 0.35), 2),
        commission_rate: 0.35,
        currency: "GBP",
    }
        where total_claims_loading = Claims.TotalLoading(
                  number_of_claims: claims.number_of_claims,
                  total_claims_value: claims.total_claims_value,
              ),
              covers = {
                  pl: PublicLiability.Rate(exposure, limit: pl_limit, total_claims_loading),
              },
              subtotal = sum collect rated { base_premium: p } in covers => p
}
```

---

## 16. Summary

Axiom v1 is a typed expression language for authored business computation. Its core is:

- **Named expressions** with typed parameters as the unit of authorship
- **Composition by calling** — expressions call other expressions
- **Where clauses** for naming intermediate values within an expression body
- **Spread** and **shorthand notation** for reducing argument-passing boilerplate
- **Record types** for structuring input data with named shapes
- **Variant types** — closed tagged unions with optional payloads, including payload-less tags
- **Nominal typing** for named types, structural compatibility for dicts
- **`if/then/else`** for boolean conditions, **`match`** for multi-arm dispatch and variant narrowing
- **Pattern matching** with literals, wildcards, ranges, alternatives, variants, and tuples
- **Collection forms** (`any`, `all`, `collect`, aggregate `collect`) for working with lists of variants
- **Explicit coercion** with `as` — no hidden type conversions
- **Namespaces** for organising related types and expressions
- **A small set of intrinsics** (`round`, `len`, `flatten`, `sum`, `product`, `max`, `min`)
- **No null**, no mutation, no loops, no side effects
- **A strong execution guarantee**: if it parses, type-checks, and validates, it cannot fail
