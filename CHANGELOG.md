# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed (Breaking — Phase 0 runtime honesty, RFC 0001)
- `StringType::assert` no longer reads `''` or `'null'` as absence — they are ordinary strings. The absence readings remain in `coerce`, the lenient input boundary.
- `DictType::assert` no longer reads the empty array as absence — `[]` is a dict. The absence reading remains in `coerce`.
- `BooleanType::coerce(null)` now yields absence (`None`) instead of silently producing `false`, so required-but-missing boolean bindings are detectable.
- `ComparisonOverloader` claims only values it owns: equality for scalars, `null`, and (nested) arrays of them — never objects; ordering (`<`, `<=`, `>`, `>=`) for real numbers only. String ordering and object comparison are no longer silently evaluated with PHP semantics; they now fall through to the dialect's own rules or fail.
- `BinaryOverloader` requires real numbers (`int`/`float`); numeric strings are no longer accepted for arithmetic.
- `HasOverloader`/`InOverloader`/`IntersectsOverloader` refuse object operands; element sides must be scalar, `null`, or lists.
- `UnaryResolver`: `!`/`not` are boolean-only — PHP truthiness on non-booleans is no longer evaluated.
- `MatchResolver`: a match where no arm matches is now a runtime error instead of silently evaluating to absence — add a wildcard arm for a deliberate default.

### Changed (Breaking — adversarial-review round, RFC 0001 second revision)
- Equality is **value equality**, never PHP juggling: numeric within `Number` (`1 == 1.0`), strict identity otherwise, element-wise for lists, `false` across bases (`5 == '5'` is now `false`); `===`/`!==` become aliases of `==`/`!=`. The set operators (`has`/`in`/`intersects`) use the same value equality instead of `array_intersect`'s string comparison (`true in [1]` is now `false`).
- One representation of null in the resolution channel: a bound `null` normalizes to absence at symbol lookup (it still shadows — shadowing lives in `has()`).
- `TypeDefinition` is split into `Coerce` (runtime `coerce`, statically verbatim — the opaque boundary node) and `Ascription` (runtime `assert`, statically checked: `Unknown`-or-overlaps); `ValueResolver` becomes `CoerceResolver`, and `AscriptionResolver` is new. The dead-coercion overlap check is removed from `Coerce` — it was the right rule on the wrong node.
- `OpaqueShape` gains structural parameters (`Opaque('money', ['currency' => …])`) — nominal head, parameter-wise relations. The **shape-truth law** lands: projections are census-verified truth claims about runtime structure; fictional record projections (the TypeScript branded-types trick) are outlawed for object-valued types.
- Member access is **shape-driven**: any type whose (verified) projection is record-like gets certified field access; field shapes reify to types (`TypeReifier`, `OpaqueType`).
- `Bindings` uses **descent, not flattening**: an array binding binds its key whole and namespaced lookups descend into it; explicit dotted keys win. `TypeEnvironment` mirrors the descent for record declarations.
- **Shadowing a definition requires a declaration** — an undeclared binding colliding with a definition is a boundary error. *(Superseded below: shadowing is now unrepresentable.)*

### Changed (Breaking — second-opinion review round, RFC 0001 fourth revision)
- **Declarations and definitions are disjoint namespaces**: a symbol is a parameter or a derived value, never both. A collision — including through the record view (declaring `customer` as a record with a `turnover` field declares `customer.turnover`) — is now a constructor error, and the boundary **strips undeclared binding keys** before evaluation, so shadowing a definition (top-level or by nested descent) is unrepresentable. The declared∧defined agreement check is removed with the feature. Callers that overrode derived values via bindings model the override in-language: an `Option`-typed parameter the definition consults. Callers that relied on undeclared bindings feeding free symbols declare them (worst case `Unknown` — the explicit gradual path).
- **The dialect rides the `Context`**: resolvers hold no operator state. `InfixResolver`/`UnaryResolver` lose their operator-stack constructor parameters and read the per-call context; the `Expression` constructor no longer wires stacks into the resolver, and an overloader bound on a resolver container is inert. One resolver graph now serves any number of expressions with different dialects, order-independently.
- **`ListType::assert` is strict membership**: associative arrays are rejected instead of silently reindexed (reindexing remains in `coerce`); `DictType::assert` rejects non-empty lists. The empty array inhabits both `List` and `Dict`, so `overlaps(List, Dict)` now holds at `[]` — a list/dict comparison is live (true exactly when both are empty), no longer a false `dead` verdict.
- **`LiteralMatcher` uses value equality** — the same definition as the comparison operators and the exhaustiveness analysis, so `match 5 { 5.0 => … }` matches at runtime exactly as coverage predicts.
- **Raw `typeOf` verdicts are total**: `ComparisonOverloader` refuses equality over types with object inhabitants (universally over union members — `ShapeDomain::all` is the traversal, shipped once); the set operators judge union needles universally and refuse opaque needles. The agreement harness's soundness law asserts claiming instead of filtering on it, with opaque and mixed-union specimens added.
- **`OpaqueType` is `@internal` and fail-closed**: it exists only so opaque field shapes can reify, and its `assert`/`coerce` reject every value, naming the host as the owner of membership. Hosts declare inputs with their own `Shaped` domain types (a real membership check + an `OpaqueShape` projection).
- **Cycle detection is a graph pass** (`DefinitionGraph`), independent of declarations, run by `infer()`/`check()` before typing — self- and mutual definition cycles are compile diagnostics even when every participant is declared. The runtime backstops it: re-entrant symbol resolution errs by name (`Context` in-progress marker) instead of recursing unboundedly.
- Match exhaustiveness: `Never` is vacuously covered, so `match null { null => … }` is exhaustive. `Expression::admit()` and unary inference dispatch on the `OptionShape` projection instead of the concrete `OptionType` class, so canonicalized option-shaped unions (`Union(Option<Number>, String)` ≡ `(Number | String)?`) admit absence and propagate optionality correctly.

### Added (extension-DX round)
- The signature builder — the front door for extension operators: `Operator::infix('-')->signature(new DateType(), new PeriodType())->returns(new DateType())->evaluate(fn (Date $d, Period $p) => $d->minus($p))` declares operand ownership once and derives both faces (runtime claim via `assert`, static verdict via `admits`), so the honesty contract and the agreement-harness laws hold by construction. Staged and immutable (the final `evaluate()` call is the compiled `InfixSignature`/`PrefixSignature` rule — no `build()`); closures auto-wrap plain values in `Ok`, pass a returned `Result` through, and let throws propagate; `Operator::prefix` rejects `Option` operand types loudly. Parameterized families (money) enumerate their host-finite parameter space, one row per parameter. The raw `OperatorOverloader`/`UnaryOverloader` contract remains the documented escape hatch for rules that aren't rows (overlap-based verdicts, dead findings, computed return types).

### Added (adversarial-review round)
- `Dialect` + `Extension`: the operator rules and literal registry live in one value object consumed by both the evaluator and the checker; packages contribute via `Extension` (prepend semantics, loud literal collisions).
- Typed bindings: `Expression` accepts `declarations` and a `Boundary` mode (`Coerce`/`Assert`); declared inputs are validated/converted at invoke time with aggregated, named `BoundaryViolation`s; `Expression::infer()`/`check()` let the expression type itself; declared∧defined symbols get an agreement check.
- Harness law L3 (dead refusals verified statically-constant over specimens) and census law C2 (record projections verified against specimens).

### Added
- RFC 0001: Typesafe Axiom (`docs/rfc/0001-typesafe-axiom.md`) — the accepted design for the static type system: sealed shape algebra, type relations, typed operators (`typeOf` beside `evaluate`), and graph-walking inference over the runtime AST, shipping as one breaking release.
- The sealed shape algebra (`Types\Shapes`): `Boolean`/`Number`/`String`, `Literal`, `Option` (value-set semantics, nesting collapses), `Union` (canonicalized), `Record` (open/closed), `Dict`, `List` (bounded), `Unknown`, `Never`, `Opaque`.
- Type relations (`TypeRelations`): assignability (⊆ over value sets), equivalence, symmetric overlap, and pessimistic operand admissibility, with `TypeMismatch` cause chains (including a `dead` flag for runtime-tolerated but statically-meaningless operations) rendered through `TypeDescriber`. `TypeOrder` answers orderability (Number-only in core).
- New language-level types: `OptionType` (null coerces to a present `Some(null)`), `LiteralType`, `UnionType`, `RecordType` (coercion canonicalizes missing optional keys to null), `UnknownType`, `NeverType`; `ListType` gains length bounds; every core type projects via `Type extends Shaped`.
- Typed operators: `handles()`/`typeOf()` on `OperatorOverloader` (certification contract), the `UnaryOverloader` contract with `NotOverloader`/`NegateOverloader` and `UnaryOverloaderManager`, and typing-verdict composition on `OverloaderManager` (agreement → the type; disagreement → Unknown).
- The agreement harness: generative soundness (R1) and anti-shadowing (R2) laws over specimen matrices for every core rule and the composed default dialect.
- Type inference (`TypeInference`): literal-first inference, union element unification with exact list bounds, mandatory match exhaustiveness, strict member access, optionality propagation, dead-coercion detection on `TypeDefinition`, and `check` = infer + assignability. `TypeEnvironment` walks the symbol graph with memoization and cycle detection; `LiteralTypeRegistry` types object literals; `TypedSource` is the host-source seam.
- Initial open source release
- MIT License
- Contributing guidelines
- Security policy
- Comprehensive documentation

### Changed
- Changed license from proprietary to MIT

## [1.0.0] - Initial Release

### Added
- Type system for data validation and transformation
  - NumberType for numeric coercion
  - StringType for string conversion
  - BooleanType for boolean validation
  - ListType for array/list validation
  - DictType for dictionary/map validation
- Expression evaluation system
  - InfixExpression for binary operations
  - UnaryExpression for unary operations
  - Operator overloading support
- Source system
  - StaticSource for direct values
  - SymbolSource for named references with namespace support
  - TypeDefinition for type-aware transformations
- Resolver pattern implementation
  - DelegatingResolver for chaining resolvers
  - StaticResolver for static value resolution
  - ValueResolver for type coercion
  - InfixResolver for expression evaluation
  - SymbolResolver for symbol lookup
- SymbolRegistry for managing named values with namespace support
- Functional programming approach
  - Result monad for error handling
  - Option monad for null handling
- Comprehensive test suite
  - 100% code coverage requirement
  - PHPStan level max static analysis
  - Mutation testing with Infection

### Architecture
- Strategy pattern for resolvers
- Chain of responsibility for delegating resolvers
- Factory pattern for type creation
- Functional programming with monadic error handling

[Unreleased]: https://github.com/gosuperscript/axiom/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/gosuperscript/axiom/releases/tag/v1.0.0
