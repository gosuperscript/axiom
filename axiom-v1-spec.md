# Axiom v1 - Language Specification

Axiom is a statically typed expression language for declarative business computation.
It is designed for authored logic such as pricing, eligibility, financial calculation,
classification, and product rules.

Axiom is not a general-purpose programming language. It is a small, reviewable
language for computing values from typed inputs and versioned table artifacts.

The core guarantee of Axiom v1 is:

> If a program parses, type-checks, all referenced table artifacts validate, and
> its runtime inputs validate, then evaluating a target expression deterministically
> produces a value of the declared result type without runtime exceptions.

This document is normative unless a section is explicitly marked as informative.
The grammar in Section 13 is normative for syntax.

---

## 1. Design Principles

1. **Expressions are the unit of authorship.** An Axiom program is a collection of
   named, typed expressions. Each expression evaluates to exactly one value.

2. **Reviewability beats cleverness.** The language prefers explicit, local
   constructs over compact or highly abstract ones.

3. **The core is pure.** Expressions, operators, constructors, collection forms,
   and expression calls are deterministic and side-effect free.

4. **Tables are core.** Versioned table artifacts are part of the language model.
   Arbitrary external IO is not.

5. **Static proof first, runtime execution second.** Parsing, type checking, table
   validation, and input validation establish the preconditions for safe execution.

6. **The language stays narrow.** Axiom v1 intentionally excludes mutation, loops,
   recursion as a control structure, exceptions, implicit IO, dynamic maps, and
   syntax-extending plugins.

7. **Named types are nominal.** Named variant and record types are identified by
   name, not by accidental structural equivalence.

8. **Meaningful failure is modeled in values.** Domain outcomes such as decline,
   referral, or non-availability are represented in variants, not in an out-of-band
   error channel.

9. **No null.** There is no `null` type in Axiom v1.

10. **Defaults must be explicit.** Axiom does not silently substitute domain values
    for invalid or absent data. Fallbacks must be authored in the program.

11. **General-purpose constructs over domain-specific ones.** Core language features
    should be useful beyond a single business domain.

---

## 2. Non-Goals

Axiom v1 does not provide:

- mutation or assignment statements
- loops (`for`, `while`)
- recursion as a user-facing control structure
- exceptions or `try/catch`
- implicit IO or side effects
- dynamic maps or dictionary types
- indexing (`xs[0]`, `record["field"]`)
- type coercions
- `null` or optional/nullable types (`T?`)
- syntax-extending plugins
- string interpolation or free-form text templating
- general-purpose higher-order functions (`map`, `filter`, `reduce`)

---

## 3. Program Structure

An Axiom program is a collection of top-level declarations. There is no `main`
expression. Any named expression may be targeted for evaluation.

Top-level declarations are:

- expression declarations
- type declarations
- namespace declarations
- table declarations

### 3.1 Expression Declarations

A named expression has a name, zero or more typed parameters, a declared return
type, and a body expression.

```axiom
BasePremium(sum_insured: number, rate: number): number {
    sum_insured / 1000 * rate
}
```

Zero-argument expressions omit the parameter list entirely:

```axiom
AdminFee: number {
    35
}
```

At use sites, zero-argument expressions are referenced by name, not called with
empty parentheses:

```axiom
AdminFee
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

Return types are required in v1. This keeps variant construction and expression
interfaces explicit.

### 3.2 Type Declarations

Type declarations bind a name directly to a type body. They do not use `=`.

#### Variant types

A variant type is a closed set of tagged alternatives. Each alternative has a tag
and an optional record payload.

```axiom
type CoverOutcome
    rated {
        key: string,
        name: string,
        premium: number,
    }
  | not_available { reason: string }
```

Payload-less variants omit the payload:

```axiom
type Status active | suspended | cancelled
```

#### Record types

A record type declares a fixed set of named fields:

```axiom
type Exposure {
    industry: string,
    turnover: number,
    employees: number,
}
```

Records are the only object-shaped structured value in Axiom v1.

### 3.3 Namespace Declarations

Namespaces group related types and expressions.

```axiom
namespace Industry {
    BaseRate(industry: string): number {
        match industry {
            "DRI-945" => 0.85,
            _ => 1.00,
        }
    }
}
```

Namespace members are accessed with qualified names:
`Industry.BaseRate("DRI-945")`.

Namespaces may contain:

- expression declarations
- type declarations

### 3.4 Table Declarations

A table declaration defines a named, typed, immutable list of records. The data
comes from a companion artifact bundled with the program version.

```axiom
table industry_config: list({
    code: string,
    base_rate: number,
    min_premium: number,
})
```

Or, using a named record type:

```axiom
type IndustryRow {
    code: string,
    base_rate: number,
    min_premium: number,
}

table industry_config: list(IndustryRow)
```

Tables serve two purposes:

1. **Schema declaration.** The type checker validates field access against the
   declared row shape.
2. **Artifact binding.** The runtime loads the companion artifact, validates every
   row against the declared schema, and exposes the rows as an immutable list.

#### Table semantics

- A table is semantically an ordered immutable list of records.
- Artifact row order is part of program semantics.
- `match row in table { ... }` returns the first matching row in artifact order.
- `collect row in table { ... }` preserves artifact order.
- Implementations may optimize physical storage or access strategy, but they must
  produce the same result as evaluating against the table as an in-memory immutable
  list in artifact order.

#### Artifacts

A program bundle consists of source code plus table artifacts. For a program to
load successfully:

- every referenced artifact must be present
- every row must conform to the declared row schema
- the artifact must preserve row order

Artifact validation failures are load-time failures, not evaluation-time failures.

---

## 4. Type System

### 4.1 Primitive Types

| Type | Description |
|------|-------------|
| `number` | Arbitrary-precision decimal number |
| `non_zero` | Numeric value proven not equal to `0` |
| `string` | Text value |
| `bool` | Boolean value |

`number` is exact decimal, not IEEE 754 float. Conforming implementations must use
arbitrary-precision decimal semantics.

### 4.2 Compound Types

| Type | Description |
|------|-------------|
| `list(T)` | Ordered collection of elements of type `T` |

There is no core map or dictionary type in v1.

### 4.3 Named Types

Named record types and named variant types are nominal.

```axiom
type Exposure { industry: string, turnover: number }

type Decision
    approved { premium: number }
  | declined
```

### 4.4 Extension Types

Extensions may define additional parameterized types such as `money(GBP)`.
Extension types are not part of the core type hierarchy unless explicitly imported
through the extension mechanism described in Section 15.

### 4.5 Type Assignability

Rules:

1. **Primitives.** A type is assignable to itself.
2. **Numeric refinement.** `non_zero` is assignable to `number`.
3. **Lists.** `list(T)` is assignable to `list(U)` when `T` is assignable to `U`.
4. **Named variants.** Same declared name means same type.
5. **Named records.** Two differently named record types are not assignable to each
   other, even if their fields are identical.
6. **Inline record shapes.** Inline record values are assignable to inline record
   shapes when all required fields exist with assignable types.
7. **Named record to inline shape.** A value of named record type `R` is assignable
   to an inline record shape when `R` contains at least the required fields with
   assignable types.
8. **Inline record literal to named record.** An inline record literal is assignable
   to a named record type when used in a context expecting that named type and it
   provides exactly the fields of that type with assignable values. This is
   contextual construction, not structural equivalence between named records.

### 4.6 Member Access

Member access (`expr.field`) is valid when the type has a known field set:

- named record types
- inline record shapes
- variant types only when the field exists on every alternative with the same type

If a field exists only on some variant alternatives, the value must be narrowed
with `match` first.

### 4.7 Numeric Refinement and Division Safety

Division is total in Axiom v1 because it is restricted statically.

Rule:

- `left / right` is valid only when `right` is assignable to `non_zero`

Sources of `non_zero`:

- a parameter, field, or expression explicitly annotated as `non_zero`
- a numeric literal other than `0`
- recognized narrowing forms such as:
  - `if x != 0 then ... else ...`
  - `if x > 0 then ... else ...`
  - `if x < 0 then ... else ...`
  - match arms whose range pattern excludes `0`

If the type checker cannot prove that the divisor is `non_zero`, the program is
rejected.

Arithmetic on `non_zero` values usually produces plain `number` unless another rule
proves a refined result.

---

## 5. Expressions

All computation in Axiom is expressed through expressions. There are no statements.

### 5.1 Literals

```axiom
42
3.14
"hello"
true
false
[1, 2, 3]
```

Numeric literals other than `0` are assignable to `non_zero`.

### 5.2 Identifiers and Member Access

```axiom
turnover
AdminFee
exposure.industry
row.base_rate
```

### 5.3 Arithmetic and Comparison

```axiom
base_rate * (sum_insured / 1000)
turnover >= 50000
industry not in ["asbestos", "demolition"]
```

See Section 6 for the operator table.

### 5.4 If / Then / Else

`if/then/else` is an expression.

```axiom
if claims_count == 0
    then 0.95
    else 1.00
```

Chained conditions are written with `else if`:

```axiom
if claims == 0
    then 0.9
    else if claims <= 2
    then 1.0
    else 1.25
```

The condition must be `bool`. Both branches must produce assignable types.

### 5.5 Match Expressions

#### Subject match

```axiom
match industry {
    "DRI-945" | "DRI-946" => "A",
    _ => "B",
}
```

#### Subjectless condition match

```axiom
match {
    claims_count == 0 => 0.95,
    claims_count <= 2 => 1.00,
    _ => 1.25,
}
```

#### Tuple match

```axiom
match (industry, employees) {
    ("DRI-945", 1) => 0.85,
    ("DRI-945", _) => 0.95,
    _ => 1.00,
}
```

#### Variant match

```axiom
match cover {
    rated { premium: p } => p,
    not_available { reason: _ } => 0,
}
```

#### Match over lists and tables (`match ... in`)

`match binding in iterable` searches a list or table in iteration order and returns
the first matching result.

```axiom
match row in industry_config {
    row.code == industry => row.base_rate,
    _ => 1.00,
}
```

Semantics:

1. Iterate the list or table in order.
2. For each element, bind it to the name.
3. Evaluate the non-wildcard arms top to bottom as boolean conditions.
4. Return the first arm body whose condition is `true`.
5. If no condition matches, evaluate the wildcard arm.

The wildcard arm is required.

### 5.6 Expression Calls

Parameterized expressions are called with one of two forms:

- positional arguments
- named arguments

Calls may also mix the forms, with one restriction: positional arguments must come
before named arguments.

Zero-argument expressions are not called. They are referenced directly by their
name or qualified name.

Positional example:

```axiom
BasePremium(exposure, 500000)
```

Named example:

```axiom
BasePremium(exposure: exposure, limit: 500000)
```

Mixed example:

```axiom
Rate(industry, limit: 500000, employees: 3)
```

Qualified calls into namespaces:

```axiom
Industry.BaseRate(industry)
```

Zero-argument namespace members are also referenced directly:

```axiom
Pricing.AdminFee
```

### 5.7 Variant Construction

Variant values are constructed with their tag name:

```axiom
rated {
    key: "PL",
    name: "Public Liability",
    premium: 500,
}
```

Payload-less alternatives are constructed with the tag alone:

```axiom
declined
```

Qualified construction may be used for disambiguation:

```axiom
CoverOutcome.not_available { reason: "industry_blocked" }
```

Unqualified constructors resolve from expected type when available. Otherwise the
tag must be unique in scope or be qualified explicitly.

### 5.8 Record Literals

```axiom
{
    key: "PL",
    name: "Public Liability",
    premium: 500,
}
```

Field shorthand is allowed when a variable name matches the field name:

```axiom
{
    covers,
    total,
}
```

### 5.9 List Literals

```axiom
[1, 2, 3]
["A", "B"]
[cover_one, cover_two]
```

### 5.10 Where Clauses

`where` introduces local bindings for intermediate values.

```axiom
round(total, 2)
    where base = exposure.turnover / 1000 * rate,
          total = base * loading
```

Bindings are independent definitions inside the current scope. Their evaluation
order is determined by demand and data dependency, not by textual order alone.

### 5.11 Parenthesized Expressions

Parentheses override precedence:

```axiom
(base + adjustment) * factor
```

---

## 6. Operators

### 6.1 Precedence Table

From lowest to highest precedence:

| Precedence | Operators | Associativity |
|------------|-----------|---------------|
| 1 | `\|\|` | left |
| 2 | `&&` | left |
| 3 | `==`, `!=` | left |
| 4 | `<`, `>`, `<=`, `>=`, `in`, `not in` | left |
| 5 | `+`, `-` | left |
| 6 | `*`, `/` | left |

### 6.2 Unary Operators

| Operator | Operand | Result |
|----------|---------|--------|
| `-` | `number` | `number` |
| `not` | `bool` | `bool` |
| `!` | `bool` | `bool` |

`not` is the preferred spelling. `!` is an alias.

### 6.3 Arithmetic Operators

| Operator | Left | Right | Result |
|----------|------|-------|--------|
| `+` | `number` | `number` | `number` |
| `-` | `number` | `number` | `number` |
| `*` | `number` | `number` | `number` |
| `/` | `number` | `non_zero` | `number` |

Extensions may define additional operator rules for extension-defined types.

### 6.4 Comparison Operators

| Operator | Operands | Result |
|----------|----------|--------|
| `==` | same comparable type | `bool` |
| `!=` | same comparable type | `bool` |
| `<` | same ordered type | `bool` |
| `>` | same ordered type | `bool` |
| `<=` | same ordered type | `bool` |
| `>=` | same ordered type | `bool` |

### 6.5 Logical Operators

| Operator | Left | Right | Result |
|----------|------|-------|--------|
| `&&` | `bool` | `bool` | `bool` |
| `\|\|` | `bool` | `bool` | `bool` |

### 6.6 Membership Operators

| Operator | Left | Right | Result |
|----------|------|-------|--------|
| `in` | `T` | `list(T)` | `bool` |
| `not in` | `T` | `list(T)` | `bool` |

`not in` is a first-class operator, not macro syntax.

---

## 7. Patterns

Patterns are used in subject `match`, collection forms, and aggregate collection.

### 7.1 Wildcard Pattern

```axiom
_ => default_value
```

### 7.2 Literal Patterns

```axiom
42 => "exact"
"brick" => 1.0
true => "yes"
```

### 7.3 Alternative Patterns

```axiom
"DRI-945" | "DRI-946" => "A"
1 | 2 | 3 => "low"
```

### 7.4 Range Patterns

Range patterns are part of the core language.

```axiom
[0..100]
(0..100)
[0..100)
(0..100]
[5..]
[..10]
```

Range patterns operate over numeric subjects.

### 7.5 Variant Patterns

```axiom
rated { premium: p } => p
not_available { reason: r } => r
declined => 0
```

Field shorthand is allowed:

```axiom
rated { premium } => premium
```

Qualified variant patterns may be used for disambiguation:

```axiom
CoverOutcome.rated { premium: p } => p
```

### 7.6 Tuple Patterns

```axiom
match (industry, employees) {
    ("DRI-945", 1) => 0.85,
    (_, _) => 1.00,
}
```

### 7.7 Exhaustiveness

For variant subjects, match arms must be exhaustive. A wildcard arm satisfies
exhaustiveness for all remaining alternatives.

```axiom
match outcome {
    offered { total: t } => t,
    declined => 0,
    referred { reasons: _ } => 0,
}
```

For non-variant subjects, a wildcard arm is recommended but not required.

---

## 8. Collection Forms

Collection forms operate on `list(T)` values and on tables, which are lists of
records.

### 8.1 `any` - Existential Predicate

Returns `true` if at least one element matches the pattern.

```axiom
any referred in covers
any rated { premium: _ } in covers
```

Type: `bool`

### 8.2 `all` - Universal Predicate

Returns `true` if every element matches the pattern.

```axiom
all rated in covers
```

Type: `bool`

### 8.3 `collect` - Pattern Map

Evaluates the body for each matching element and returns a list of results.

```axiom
collect referred { reason: r } in covers => r
collect rated { premium: p } in covers => p
```

Type: `list(T)` where `T` is the body type.

### 8.4 `collect` - Binding Map

The binding form transforms every element:

```axiom
collect cover in covers => CoverAmount(cover)
```

### 8.5 `collect` - Binding Filter Map

The binding-arm form filters and transforms:

```axiom
collect row in industry_config {
    row.min_premium > 0 => row.code,
}
```

Non-matching elements are skipped. There is no implicit fallback value.

### 8.6 Aggregate Collect

Aggregate collect applies a core aggregator to a collected list.

Core aggregators in v1:

- `sum`
- `product`

Examples:

```axiom
sum collect rated { premium: p } in covers => p

product collect factor in loadings => factor
```

Aggregate collect preserves the iteration order of the source collection, though
the current core aggregators are order-insensitive.

### 8.7 Collection Form Typing

- The iterable operand must be `list(T)` or a table.
- Patterns are checked against `T`.
- `any` and `all` return `bool`.
- `collect` returns `list(U)`.
- Aggregate collect returns the aggregator result type.

---

## 9. Intrinsic Functions

Axiom v1 includes a small set of built-in functions.

| Function | Signature | Description |
|----------|-----------|-------------|
| `round(value, places)` | `(number, number) -> number` | Round to `places` decimal places |
| `len(values)` | `(list(T)) -> number` | Number of elements |
| `flatten(nested)` | `(list(list(T))) -> list(T)` | Flatten one nesting level |
| `sum(values)` | `(list(number)) -> number` | Sum of all values |
| `product(values)` | `(list(number)) -> number` | Product of all values |

Semantics:

- `round(value, places)` truncates `places` toward zero before rounding.
- `sum([])` is `0`.
- `product([])` is `1`.

Additional intrinsics may be provided by extensions, but they must obey the same
determinism and totality requirements as the core language.

---

## 10. Type Checking

### 10.1 Inference Rules

The type checker infers types bottom-up.

| Expression | Inferred Type |
|------------|---------------|
| `42` | `non_zero` |
| `0` | `number` |
| `"hello"` | `string` |
| `true` | `bool` |
| `[1, 2, 3]` | `list(non_zero)` |
| `{ a: 1, b: "x" }` | inline record shape `{ a: non_zero, b: string }` |
| `identifier` | declared type from scope |
| `QualifiedName` | declared return type of a zero-argument expression |
| `object.field` | field type from record shape |
| `left OP right` | operator result type |
| `if/then/else` | common branch type |
| `match` | common arm type |
| `Name(args)` | declared return type of named expression |
| `tag { fields }` | resolved variant type |
| `any P in xs` | `bool` |
| `collect P in xs => body` | `list(T)` |
| `agg collect ...` | aggregator return type |
| `expr where bindings` | type of `expr` |

### 10.2 Variant Resolution

Variant tags are resolved in this order:

1. Expected type from context
2. Explicit qualification
3. Unique visible tag

If a tag is ambiguous, qualification is required.

### 10.3 Call Checking

For each call, the type checker validates:

- the callee exists
- the referenced expression declares one or more parameters
- the argument ordering is valid
- positional arity or named parameter completeness
- argument types are assignable to parameter types
- the body type is assignable to the declared return type

### 10.4 Table Checking

For each table declaration, the checker validates:

- the row type is well formed
- all table field references are valid against the row schema
- `match row in table` and `collect row in table` bind rows with the declared row type

### 10.5 Division Safety

The checker rejects division when the divisor is not statically proven to be
`non_zero`.

```axiom
Ratio(a: number, b: number): number {
    a / b
}
```

The expression above is a type error because `b` is only `number`.

```axiom
Ratio(a: number, b: non_zero): number {
    a / b
}
```

This is valid.

### 10.6 Recursion Detection

The checker builds the call graph of named expressions and rejects cyclic
dependencies. Mutual recursion and self-recursion are type errors in v1.

### 10.7 Soundness Checks

The checker enforces:

- operator type validity
- member access validity
- match exhaustiveness for variant subjects
- match arm type consistency
- collection-form typing
- table row access validity
- division safety
- recursion absence
- unresolved symbol detection
- duplicate declaration detection

---

## 11. Evaluation

### 11.1 Execution Model

Axiom v1 uses lazy evaluation with memoization.

- Expression arguments are evaluated on first use.
- `where` bindings are evaluated on first use.
- Each value is computed at most once per scope.
- Each expression call creates a fresh child scope and memo table.

Because Axiom is pure, lazy evaluation changes performance only, not meaning.

### 11.2 Runtime Representation

Records are plain associative structures:

```json
{ "industry": "DRI-945", "turnover": "500000" }
```

Variants use a reserved `_tag` field:

```json
{
  "_tag": "rated",
  "key": "PL",
  "premium": "500"
}
```

Payload-less variants are represented as:

```json
{ "_tag": "declined" }
```

Authors may not declare a payload field named `_tag`.

### 11.3 Evaluation Order

- `if/then/else`: evaluate the condition, then the taken branch only
- subject `match`: evaluate the subject, then arms top to bottom
- subjectless `match`: evaluate arms top to bottom until a condition succeeds
- `match binding in iterable`: iterate in collection order, then arm order
- `where`: evaluate bindings on demand
- `&&` and `||`: short-circuit
- collection forms: iterate in collection order

### 11.4 Table Evaluation

Tables are evaluated as immutable ordered lists of rows.

- Table artifacts are loaded and validated before any expression is evaluated.
- Row order is preserved exactly as declared by the artifact.
- Evaluation never mutates a table or derives side effects from reading it.

---

## 12. Diagnostics

All pipeline stages produce diagnostics with a uniform structure:

- `severity`: `error`, `warning`, or `info`
- `code`: stable dotted identifier
- `message`: human-readable description
- `location`: line, column, offset, length

### 12.1 Diagnostic Categories

| Prefix | Stage |
|--------|-------|
| `parse.*` | Parser |
| `type.*` | Type checker |
| `validation.*` | Input and artifact validation |
| `extension.*` | Extension loading and overlap validation |

### 12.2 Error Quality

Diagnostics should:

- name expected and actual types concretely
- identify the precise parameter, field, or arm involved
- list missing variant alternatives for non-exhaustive matches
- identify the specific divisor expression that is not proven `non_zero`
- identify the specific table and row field involved in artifact validation failures

---

## 13. Grammar

```ebnf
program            = { declaration } ;
declaration        = type_decl | namespace_decl | table_decl | expr_decl ;

(* --- Declarations --- *)

type_decl          = "type" UPPER_IDENT type_decl_body ;
type_decl_body     = record_shape | variant_alts | extension_type ;
record_shape       = "{" field_decl { "," field_decl } [ "," ] "}" ;
variant_alts       = variant_alt { "|" variant_alt } ;
variant_alt        = LOWER_IDENT [ record_shape ] ;
field_decl         = LOWER_IDENT ":" type_expr ;

namespace_decl     = "namespace" UPPER_IDENT "{" { namespace_member } "}" ;
namespace_member   = type_decl | expr_decl ;

table_decl         = "table" LOWER_IDENT ":" "list" "(" table_row_type ")" ;
table_row_type     = record_shape | qualified_upper ;

expr_decl          = zero_arg_expr_decl | param_expr_decl ;
zero_arg_expr_decl = UPPER_IDENT ":" type_expr "{" expression "}" ;
param_expr_decl    = UPPER_IDENT "(" param_list ")" ":" type_expr
                     "{" expression "}" ;
param_list         = param { "," param } ;
param              = LOWER_IDENT ":" type_expr ;

(* --- Type expressions --- *)

type_expr          = primitive_type
                   | list_type
                   | record_shape
                   | qualified_upper
                   | extension_type ;

primitive_type     = "number" | "non_zero" | "string" | "bool" ;
list_type          = "list" "(" type_expr ")" ;
extension_type     = LOWER_IDENT "(" type_arg { "," type_arg } ")" ;
type_arg           = qualified_upper | LOWER_IDENT | type_expr ;

qualified_upper    = UPPER_IDENT { "." UPPER_IDENT } ;

(* --- Expressions --- *)

expression         = where_expr ;
where_expr         = or_expr [ "where" binding { "," binding } ] ;
binding            = LOWER_IDENT "=" expression ;

or_expr            = and_expr { "||" and_expr } ;
and_expr           = equality_expr { "&&" equality_expr } ;
equality_expr      = comparison_expr { ( "==" | "!=" ) comparison_expr } ;
comparison_expr    = additive_expr
                     { ( "<" | ">" | "<=" | ">=" | "in" | "not" "in" )
                       additive_expr } ;
additive_expr      = multiplicative_expr { ( "+" | "-" ) multiplicative_expr } ;
multiplicative_expr = unary_expr { ( "*" | "/" ) unary_expr } ;
unary_expr         = ( "not" | "!" | "-" ) unary_expr | postfix_expr ;
postfix_expr       = primary { "." LOWER_IDENT } ;

primary            = if_expr
                   | match_expr
                   | aggregate_collect_expr
                   | collect_expr
                   | any_expr
                   | all_expr
                   | call_expr
                   | variant_ctor
                   | list_literal
                   | record_literal
                   | NUMBER
                   | STRING
                   | BOOL
                   | LOWER_IDENT
                   | qualified_upper
                   | "(" expression ")" ;

(* --- Control flow --- *)

if_expr            = "if" expression "then" expression
                     { "else" "if" expression "then" expression }
                     "else" expression ;

match_expr         = subject_match | condition_match | binding_match ;
subject_match      = "match" match_subject "{" pattern_arm { "," pattern_arm } [ "," ] "}" ;
condition_match    = "match" "{" condition_arm { "," condition_arm } [ "," ] "}" ;
binding_match      = "match" LOWER_IDENT "in" expression
                     "{" condition_arm { "," condition_arm } [ "," ] "}" ;

match_subject      = expression | "(" expression { "," expression } ")" ;
pattern_arm        = pattern "=>" expression ;
condition_arm      = ( expression | "_" ) "=>" expression ;

(* --- Collection forms --- *)

any_expr           = "any" pattern "in" expression ;
all_expr           = "all" pattern "in" expression ;

collect_expr       = pattern_collect
                   | binding_collect
                   | binding_arm_collect ;

pattern_collect    = "collect" pattern "in" expression "=>" expression ;
binding_collect    = "collect" LOWER_IDENT "in" expression "=>" expression ;
binding_arm_collect = "collect" LOWER_IDENT "in" expression
                      "{" condition_arm { "," condition_arm } [ "," ] "}" ;

aggregate_collect_expr =
                     aggregator "collect" pattern "in" expression "=>" expression
                   | aggregator "collect" LOWER_IDENT "in" expression "=>" expression
                   | aggregator "collect" LOWER_IDENT "in" expression
                     "{" condition_arm { "," condition_arm } [ "," ] "}" ;

aggregator         = "sum" | "product" ;

(* --- Calls and construction --- *)

call_expr          = qualified_call "(" [ arg_list ] ")" ;
qualified_call     = UPPER_IDENT { "." UPPER_IDENT } ;
arg_list           = positional_then_named | named_args ;
positional_then_named =
                     positional_args [ "," named_args ] ;
positional_args    = expression { "," expression } ;
named_args         = named_arg { "," named_arg } ;
named_arg          = LOWER_IDENT ":" expression ;

variant_ctor       = [ qualified_upper "." ] LOWER_IDENT [ record_shape_expr ] ;
record_shape_expr  = "{" [ record_entry { "," record_entry } [ "," ] ] "}" ;
record_entry       = LOWER_IDENT ":" expression | LOWER_IDENT ;

list_literal       = "[" [ expression { "," expression } ] [ "," ] "]" ;
record_literal     = "{" [ record_entry { "," record_entry } [ "," ] ] "}" ;

(* --- Patterns --- *)

pattern            = alt_pattern ;
alt_pattern        = single_pattern { "|" single_pattern } ;
single_pattern     = wildcard_pat
                   | range_pat
                   | variant_pat
                   | tuple_pat
                   | literal_pat ;

wildcard_pat       = "_" ;
literal_pat        = NUMBER | STRING | BOOL ;
range_pat          = ( "[" | "(" ) [ NUMBER ] ".." [ NUMBER ] ( "]" | ")" ) ;
variant_pat        = [ qualified_upper "." ] LOWER_IDENT [ pattern_record ] ;
pattern_record     = "{" [ pattern_field { "," pattern_field } [ "," ] ] "}" ;
pattern_field      = LOWER_IDENT [ ":" ( LOWER_IDENT | "_" ) ] ;
tuple_pat          = "(" pattern "," pattern { "," pattern } ")" ;

(* --- Lexical --- *)

UPPER_IDENT        = [A-Z] [a-zA-Z0-9_]* ;
LOWER_IDENT        = [a-z_] [a-zA-Z0-9_]* ;
NUMBER             = [0-9]+ [ "." [0-9]+ ] ;
STRING             = '"' ( [^"\\] | '\\' . )* '"' ;
BOOL               = "true" | "false" ;
COMMENT            = "//" [^\n]* ;
```

### 13.1 Keywords

```text
type  namespace  table
if  then  else
match  in
any  all  collect
where
not  true  false
sum  product
```

### 13.2 Reserved

```text
_tag
_
```

---

## 14. Example

The following example uses only core v1 features.

```axiom
type Exposure {
    industry: string,
    turnover: number,
}

type CoverOutcome
    rated {
        key: string,
        name: string,
        premium: number,
    }
  | not_available { reason: string }

type ProductOutcome
    offered {
        covers: list(CoverOutcome),
        total: number,
    }
  | referred { reasons: list(string) }

table industry_config: list({
    code: string,
    base_rate: number,
    minimum_premium: number,
})

BaseRate(industry: string): number {
    match row in industry_config {
        row.code == industry => row.base_rate,
        _ => 1.00,
    }
}

MinimumPremium(industry: string): number {
    match row in industry_config {
        row.code == industry => row.minimum_premium,
        _ => 0,
    }
}

LiabilityCover(exposure: Exposure): CoverOutcome {
    if exposure.turnover == 0
        then not_available { reason: "turnover_zero" }
        else rated {
            key: "PL",
            name: "Public Liability",
            premium: exposure.turnover / 1000 * BaseRate(exposure.industry),
        }
}

Product(exposure: Exposure): ProductOutcome {
    if any not_available in covers
        then referred {
            reasons: collect not_available { reason } in covers => reason,
        }
        else offered {
            covers,
            total: sum collect rated { premium } in covers => premium,
        }
    where covers = [
        LiabilityCover(exposure),
    ]
}
```

---

## 15. Extensions

Extensions are part of Axiom v1, but their scope is intentionally narrow.

### 15.1 What Extensions May Add

An extension may add:

- custom literal forms
- custom types
- operator rules for those types
- intrinsic overloads for those types

An extension may not add:

- new keywords
- new control-flow forms
- new pattern syntax
- mutable state
- side effects
- external data access

### 15.2 Extension Contract

An extension participates in three stages:

- literal recognition
- type checking
- evaluation

Conceptually:

```text
Extension
  name: string
  lexer?: literal hooks
  checker?: type hooks
  evaluator?: runtime hooks
```

The precise host-language API is implementation-defined, but all conforming
implementations must preserve the same source-level semantics.

### 15.3 Non-Overlap Rule

Extension meaning may not depend on registration order.

At program load time:

- if two extensions claim the same literal family, load fails
- if two extensions claim the same type constructor, load fails
- if two extensions define overlapping operator or intrinsic behavior for the same
  operand types, load fails

This keeps extension composition deterministic.

---

## 16. Standardized Money Extension

This section is normative for implementations that ship the standard money
extension. It is not part of the core language.

### 16.1 Type

`money(CURRENCY)` is a parameterized extension type. The currency is part of the
type.

```axiom
type Premium money(GBP)
```

### 16.2 Literals

Money literals use a currency prefix followed by a decimal amount:

```axiom
£100
GBP100
USD1500.00
```

### 16.3 Arithmetic Rules

| Expression | Result |
|------------|--------|
| `money(C) + money(C)` | `money(C)` |
| `money(C) - money(C)` | `money(C)` |
| `money(C) * number` | `money(C)` |
| `number * money(C)` | `money(C)` |
| `money(C) / non_zero` | `money(C)` |
| `money(C) / money(C)` | `number` |

Cross-currency arithmetic is a type error.

### 16.4 Comparisons

Comparison operators are valid only between values of the same `money(C)` type.

### 16.5 Rounding and Aggregation

The standard money extension overloads:

- `round`
- `sum`
- `product` when explicitly defined by the implementation

---

## 17. Summary

Axiom v1 is a small, typed, deterministic DSL for authored business logic.

Its core consists of:

- named expressions with declared interfaces
- nominal record and variant types
- ordered immutable tables backed by validated artifacts
- `if`, `match`, and `where`
- list-oriented collection forms
- exact decimal numbers with static division safety
- a narrow extension model for value and type families

Axiom v1 does not include:

- mutation
- loops
- indexing
- dynamic maps
- implicit IO
- syntax-extending plugins
- silent fallback semantics

The intent of v1 is to be small enough to specify precisely, implement consistently,
and review with confidence.
