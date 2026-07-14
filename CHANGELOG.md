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
