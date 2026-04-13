# Axiom PHP Implementation Plan

## Purpose

This document describes a concrete plan for implementing the rewritten Axiom v1
specification in PHP as the canonical runtime.

The goal is not to keep the current playground and PHP library loosely aligned.
The goal is to make the PHP implementation the reference engine for:

- parsing
- type checking
- artifact validation
- deterministic evaluation
- extension loading
- conformance testing

## Why PHP

PHP is a good fit for the real Axiom implementation because:

- all current applications are already in PHP
- Axiom evaluation is primarily a server-side concern
- integration with product apps, persistence, deployment, and artifacts will be simpler
- `brick/math` is already present and gives a solid base for exact decimal semantics
- `brick/money` can be added later for the standardized money extension

The browser playground can remain as:

- a non-canonical prototype
- a thin client that calls the PHP engine
- or a disposable implementation once PHP becomes authoritative

## Current Starting Point

The repository now has:

- a fresh root PHP scaffold under `src/` and `tests/`
- an archived pre-v1 runtime under `legacy/`
- a TypeScript playground under `playground/`

Relevant archived legacy areas:

- `legacy/src/Resolvers/*`
- `legacy/src/Patterns/*`
- `legacy/src/Operators/*`
- `legacy/src/Types/*`
- `legacy/src/Sources/*`

Important mismatch between the archived runtime and the rewritten spec:

- the legacy runtime includes `DictType`
- the legacy runtime includes `NullOverloader`
- the legacy runtime is expression-centric, not program/AST/typechecker-centric
- the legacy runtime does not model tables as ordered validated artifacts
- the legacy runtime does not enforce v1 semantics like no indexing and static division safety

Because of that, the new PHP implementation should not be developed as an
incremental patch to the old mental model. The fresh root scaffold should stay
conceptually separate, with selective reuse from `legacy/` only where it still
fits the v1 design.

## Recommended Strategy

Build the Axiom v1 engine under a fresh root package structure in `src/`, then
migrate reusable pieces from `legacy/` deliberately if they still make sense.

Recommended namespace:

```php
Superscript\Axiom\
```

The old runtime is already archived under `legacy/`, so the fresh root package
can use the direct namespace without inheriting conflicting concepts.

## Guiding Constraints From The v1 Spec

The PHP implementation must preserve these rules from the current spec:

- exact decimal `number`, not float semantics
- `non_zero` refinement and static division safety
- no `dict(T)` core type
- no indexing
- records as the only object-shaped structured core value
- tables as ordered immutable artifact-backed lists
- no silent fallback semantics
- lazy evaluation with memoization
- narrow extension model: literals, types, operators, intrinsics, total coercions
- extension composition must not depend on registration order

## Target Architecture

The canonical PHP implementation should have these subsystems:

1. `Lexer`
2. `Parser`
3. `AST`
4. `NameResolver`
5. `TypeSystem`
6. `TypeChecker`
7. `ArtifactValidator`
8. `InputValidator`
9. `Evaluator`
10. `Extensions`
11. `Diagnostics`
12. `Conformance`

Recommended package layout:

```text
src/Ast/
src/Lexing/
src/Parsing/
src/Names/
src/Types/
src/Typing/
src/Artifacts/
src/Input/
src/Eval/
src/Extensions/
src/Diagnostics/
src/Runtime/
src/Conformance/
src/Values/
```

## Core Data Model

Do not use raw PHP arrays as the semantic model for Axiom values.

Use explicit value objects:

- `DecimalValue`
- `StringValue`
- `BooleanValue`
- `ListValue`
- `RecordValue`
- `VariantValue`
- extension values such as `MoneyValue`

Use explicit type objects:

- `NumberType`
- `NonZeroType`
- `StringType`
- `BooleanType`
- `ListType`
- `InlineRecordType`
- `NamedRecordType`
- `VariantType`
- `NamedTypeReference`
- extension types such as `MoneyType`

Reason:

- PHP arrays blur list, record, and map semantics
- the v1 spec explicitly does not
- explicit value and type objects will prevent semantic drift

## Runtime Program Model

The main production unit should be an analyzed program, not raw source text.

Recommended objects:

- `ProgramBundle`
  - source text
  - table artifacts
  - enabled extensions
- `ParsedProgram`
  - AST
  - parse diagnostics
- `AnalyzedProgram`
  - AST
  - symbol tables
  - resolved declarations
  - typed nodes
  - validated table schemas
  - recursion analysis
  - extension validation
- `Runtime`
  - `evaluate(string $expressionName, array $input): Value`

The engine should parse and analyze once, then evaluate many times.

## Parser Approach

Use a handwritten recursive-descent parser in PHP.

Why:

- the grammar is small and controlled
- diagnostics matter
- grammar changes are still likely during implementation
- a handwritten parser is easier to evolve than a generated one here

Deliverables:

- token definitions
- lexer with source locations
- AST node classes
- parser with declaration-level recovery
- parser diagnostics

## Type System And Static Semantics

The type checker is the heart of the implementation. It should be a real phase, not
runtime checking disguised as evaluation.

Responsibilities:

- declaration collection
- namespace resolution
- named type registration
- inline record shape checking
- named-vs-inline assignability rules
- variant constructor resolution
- variant pattern validation
- match exhaustiveness
- collection form typing
- table row typing
- recursion detection
- static division safety
- extension hook type participation

The output should include resolved type information for every expression node.

## Numeric Semantics

Use `brick/math` and never evaluate Axiom `number` using PHP `float`.

Rules:

- literals parse to `BigDecimal`
- arithmetic uses exact decimal operations
- serialization at boundaries uses strings
- `non_zero` is a static type property, not a runtime convention

Do not defer this. If the engine starts with native PHP floats, the implementation
will drift from the spec immediately.

## Tables And Artifacts

Tables are a first-class language feature and should be modeled as such.

Recommended components:

- `ArtifactRepository`
- `TableSchema`
- `ValidatedTable`
- `ArtifactValidator`
- `TableLoader`

Rules to enforce:

- artifacts are required for declared tables
- rows must conform to declared record shape
- artifact row order is preserved
- evaluation sees tables as immutable ordered lists

The runtime may add internal indexes later, but only as an optimization.

## Input Validation

Separate boundary validation from evaluation.

Recommended components:

- `InputValidator`
- `InputCoercer` only if coercions are explicitly total and spec-approved

Rules:

- validate target expression parameters before evaluation
- reject invalid input before evaluation begins
- do not silently manufacture domain values
- keep parsing/normalization concerns at the boundary, not in business logic

## Evaluation

Start with direct AST evaluation.

Recommended components:

- `EvaluationContext`
- `Scope`
- `Thunk`
- `MemoizedBinding`
- `Evaluator`

Required behavior:

- lazy arguments
- lazy `where` bindings
- one computation per binding per scope
- no mutation
- no side effects
- deterministic list/table iteration order

Do not compile to PHP code in the first implementation.

## Extensions

The extension system should match the narrowed v1 spec.

Allowed extension capabilities:

- literal recognition
- custom type families
- operator typing/runtime rules
- intrinsic overloads
- total coercions

Disallowed in v1:

- new control-flow forms
- new keywords
- new pattern syntax
- external data access
- registration-order-dependent behavior

Recommended extension interfaces:

- `LiteralExtension`
- `TypeExtension`
- `OperatorExtension`
- `IntrinsicExtension`
- `CoercionExtension`

Recommended registry behavior:

- validate overlaps at program load time
- fail fast on extension conflicts
- make extension composition deterministic

## Standardized Money Extension

The money extension should be implemented after the core engine is stable.

Required additions:

- add `brick/money` dependency
- `MoneyType`
- `MoneyValue`
- money literal parsing
- money arithmetic rules
- money comparison rules
- intrinsic overloads for `round`, `sum`, and other approved intrinsics

Money should remain an extension, not a core type.

## Conformance Testing

This is critical. The spec, PHP engine, and playground will drift unless there is
one shared test suite.

Build a conformance suite with:

- lexer tests
- parser tests
- AST snapshot tests
- type-check tests
- negative diagnostics tests
- table artifact validation tests
- evaluation tests
- extension tests

Recommended structure:

```text
tests/Conformance/Lexing/
tests/Conformance/Parsing/
tests/Conformance/Typing/
tests/Conformance/Artifacts/
tests/Conformance/Eval/
tests/Conformance/Extensions/
```

The examples in `axiom-v1-spec.md` should become executable conformance tests.

## Suggested Delivery Phases

### Phase 0 - Project Setup

Goals:

- create `Superscript\Axiom\V1\` structure
- decide coexistence strategy with current runtime
- add fixtures and baseline test harness

Deliverables:

- directory structure
- base diagnostics types
- source location model
- test conventions

Acceptance:

- empty v1 package compiles
- PHPUnit and PHPStan include the new namespace cleanly

### Phase 1 - AST, Lexer, Parser

Goals:

- parse the rewritten core grammar

Deliverables:

- token model
- lexer
- AST classes
- parser
- parse diagnostics

Acceptance:

- all spec examples parse
- parser recovery works at declaration boundaries

### Phase 2 - Declaration And Name Resolution

Goals:

- resolve types, namespaces, expressions, and tables

Deliverables:

- declaration registry
- namespace-aware symbol resolution
- duplicate-name diagnostics

Acceptance:

- unresolved symbols are detected cleanly
- duplicate declarations are rejected

### Phase 3 - Type System And Core Type Checking

Goals:

- implement the core v1 type system

Deliverables:

- type objects
- assignability rules
- record and variant checking
- constructor resolution
- match exhaustiveness
- collection-form typing

Acceptance:

- spec examples type-check or fail with correct diagnostics

### Phase 4 - Numeric Refinement And Division Safety

Goals:

- implement `non_zero` and static division safety

Deliverables:

- refined numeric typing
- narrowing for approved forms
- division diagnostics

Acceptance:

- unsafe division is rejected statically
- safe division cases from conformance tests pass

### Phase 5 - Artifacts And Input Validation

Goals:

- make tables and validated inputs real runtime boundaries

Deliverables:

- table schemas
- artifact loading and validation
- input validator

Acceptance:

- invalid artifacts fail before evaluation
- invalid inputs fail before evaluation
- table iteration preserves artifact order

### Phase 6 - Evaluator

Goals:

- evaluate analyzed programs deterministically

Deliverables:

- lazy evaluator
- memoization model
- runtime values
- variant and record runtime representation

Acceptance:

- core spec example evaluates correctly
- evaluator uses no float arithmetic for `number`

### Phase 7 - Extensions

Goals:

- add the narrow v1 extension model

Deliverables:

- extension registry
- overlap validation
- literal/type/operator/intrinsic extension hooks

Acceptance:

- extension conflicts fail at load time
- core language behavior does not depend on extension order

### Phase 8 - Standardized Money Extension

Goals:

- implement the money extension against the stabilized extension API

Deliverables:

- money literal parser
- money type/value
- money arithmetic and comparisons
- intrinsic overloads

Acceptance:

- money example passes as conformance tests
- cross-currency violations fail statically

### Phase 9 - Tooling Integration

Goals:

- connect the PHP engine to the rest of the developer workflow

Deliverables:

- API for evaluating programs from applications
- optional HTTP endpoint for playground/editor integration
- fixture export for cross-runtime comparison

Acceptance:

- playground can be backed by PHP if desired
- applications can evaluate analyzed programs without reparsing every request

## Recommended Near-Term Decisions

Before implementation starts, lock these in explicitly:

1. The PHP engine is the canonical implementation.
2. The current TS playground is non-canonical.
3. v1 lives under a new namespace/package boundary.
4. Existing conflicting concepts such as `DictType` and `NullOverloader` are not
   part of the new engine.
5. The spec examples become conformance fixtures.

## Main Risks

### 1. Reusing Too Much Of The Old Runtime

Risk:

- carrying over `dict`/`null`/resolver assumptions that now conflict with the spec

Mitigation:

- implement v1 in a new namespace with explicit imports from old code only when justified

### 2. Cheating On Decimal Semantics

Risk:

- accidental use of PHP floats

Mitigation:

- wrap `BigDecimal` in a dedicated numeric value model from day one

### 3. Type Checker Creep

Risk:

- pushing semantics into the evaluator because static analysis feels slower to build

Mitigation:

- require that every major semantic feature ships with type-check tests first

### 4. Playground Drift

Risk:

- TypeScript and PHP becoming two dialects again

Mitigation:

- make the PHP engine canonical and test the playground against PHP outputs where needed

## Recommended First Implementation Slice

If the aim is to get a serious vertical slice quickly, build this first:

- expression declarations
- record and variant types
- `if`
- subject `match`
- `where`
- `list(T)`
- `sum`, `product`, `round`, `len`, `flatten`
- tables
- exact decimal numbers
- `non_zero`
- direct evaluation

Defer until the slice is stable:

- namespaces beyond basics
- mixed call style ergonomics
- extension system
- money
- rich editor integration

## Definition Of Done For v1 PHP Engine

The PHP implementation is ready to be called the Axiom v1 reference runtime when:

- it parses the normative grammar
- it enforces the core type system from the spec
- it validates inputs and table artifacts before evaluation
- it evaluates deterministically using exact decimals
- it rejects unsafe division statically
- it loads non-overlapping extensions deterministically
- it passes a conformance suite derived from the spec and examples

## Next Step

The root package skeleton now exists. The next concrete step should be:

1. populate `tests/Conformance/` with the first fixture-driven cases
2. implement lexer tokenization and source locations
3. implement AST construction and parser diagnostics

That keeps the implementation on a stable path without prematurely coupling it
to the older expression resolver model.
