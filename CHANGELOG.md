# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- RFC 0001: Typesafe Axiom (`docs/rfc/0001-typesafe-axiom.md`) — the accepted design for the static type system, shipping as one breaking release; `docs/extending-axiom.md` is the extension guide.
- The sealed shape algebra (`Types\Shapes`): `Boolean`/`Number`/`String`, `Literal`, `Option` (value-set semantics, nesting collapses), `Union` (canonicalized), `Record` (exact named fields), `Dict`, `List` (validated length bounds), `Unknown`, `Never`, `Opaque` (nominal head with structural parameters). Shapes are **census-verified truth claims** about runtime structure: fictional record projections for object-valued types (the TypeScript branded-types trick) are outlawed — object domain types project `Opaque`.
- Type relations (`TypeRelations`): assignability (⊆ over value sets), equivalence, symmetric overlap (complete: a `dead` verdict is a constant-`false` claim, so one shared value — `[] == []` across list/dict/record — keeps a comparison live), and pessimistic operand admissibility, with `TypeMismatch` cause chains rendered through `TypeDescriber`. `TypeOrder` answers orderability (Number-only in core).
- New language-level types: `OptionType` (null coerces to a present `Some(null)`), `LiteralType`, `UnionType`, `RecordType` (coercion canonicalizes missing optional keys to null), `UnknownType`, `NeverType`; `ListType` gains length bounds; every core type projects via `Type extends Shaped`, and hosts declare object-valued inputs with their own `Shaped` domain types (a real membership check + an `OpaqueShape` projection).
- Typed operators: `handles()`/`typeOf()` on `OperatorOverloader` (the certification contract — a verdict is **total**: `Ok(T)` claims every value of the operand types), the `UnaryOverloader` contract with `NotOverloader`/`NegateOverloader` and `UnaryOverloaderManager`, typing-verdict composition on `OverloaderManager`, and `ShapeDomain::all` — the one traversal rules use to gate verdicts on their runtime claims.
- The signature builder — the front door for extension operators: `Operator::infix('-')->signature(new DateType(), new PeriodType())->returns(new DateType())->evaluate(fn (Date $d, Period $p) => $d->minus($p))` declares operand ownership once and derives both faces (runtime claim via `assert`, static verdict via `admits`), so the honesty contract holds by construction. Closures auto-wrap plain values in `Ok`, pass a returned `Result` through, and let throws propagate; `Operator::prefix` rejects `Option` operand types loudly. The raw overloader contracts remain the documented escape hatch.
- `Dialect` + `Extension`: the operator rules and literal registry live in one value object consumed by both the evaluator and the checker; packages contribute via `Extension` (prepend semantics, loud literal collisions).
- Type inference (`TypeInference`): literal-first inference, union element unification with exact list bounds, mandatory match exhaustiveness (`Never` vacuously covered), shape-driven member access with reification (`TypeReifier`), optionality propagation, and `check` = infer + assignability. `TypeEnvironment` types symbols from declarations and definitions with memoization; `LiteralTypeRegistry` types object literals; `TypedSource` is the host-source seam.
- Typed bindings: `Expression` accepts `declarations` and a `Boundary` mode (`Coerce`/`Assert`); declared inputs are validated/converted at invoke time with aggregated, named `BoundaryViolation`s, and every undeclared binding key is stripped — the declaration list is the expression's complete public signature. `Expression::infer()`/`check()` let the expression type itself.
- Static cycle detection: `DefinitionGraph` walks the definition reference graph before typing — self- and mutual definition cycles are compile diagnostics; the runtime backstops with a named error on re-entrant symbol resolution.
- The agreement harness: generative soundness (L1, claiming asserted over specimen matrices), anti-shadowing (L2), and dead-verdict (L3) laws for every core rule and the composed default dialect; a shape-soundness census with specimen (C1) and shape-truth (C2) laws.
- Initial open source release
- MIT License
- Contributing guidelines
- Security policy
- Comprehensive documentation

### Changed (Breaking)
- **Runtime honesty** — the evaluator no longer tolerates what the checker cannot certify:
  - `StringType::assert` no longer reads `''` or `'null'` as absence — they are ordinary strings. The absence readings remain in `coerce`, the lenient admission face.
  - `DictType`: `[]` is a dict in **both** faces (`coerce([])` yields `Some([])`, not an absence reading — hosts that want "empty reads as missing" declare `Option<Dict<T>>`), and non-empty lists are rejected in both faces (previously `coerce([1, 2])` returned `[1, 2]`, a value the type's own `assert` refuses).
  - `ListType::assert` is strict membership: associative arrays are rejected instead of silently reindexed (reindexing remains in `coerce`); `ListType`/`ListShape` reject impossible bounds at construction (`min >= 0`, `max >= min`).
  - `BooleanType::coerce(null)` now yields absence (`None`) instead of silently producing `false`, so required-but-missing boolean bindings are detectable.
  - `UnaryResolver`: `!`/`not` are boolean-only — PHP truthiness on non-booleans is no longer evaluated.
  - `MatchResolver`: a match where no arm matches is a runtime error instead of silently evaluating to absence — add a wildcard arm for a deliberate default.
- **Equality is value equality**, never PHP juggling: numeric within `Number` (`1 == 1.0`), strict identity otherwise, element-wise for containers, `false` across bases (`5 == '5'` is now `false`); `===`/`!==` are aliases of `==`/`!=`. The set operators (`has`/`in`/`intersects`) and `LiteralMatcher` use the same one definition, so `match 5 { 5.0 => … }` matches exactly as exhaustiveness coverage predicts (`true in [1]` is now `false`).
- **Operators claim only values they own**: equality for scalars, `null`, and (nested) arrays of them — never objects (object equality belongs to the type's owner); ordering and arithmetic for real numbers only (numeric strings refused); set-operator needles judged universally over unions, opaques refused. String ordering and object comparison are no longer silently evaluated with PHP semantics — they fall through to the dialect's own rules or fail, statically and at runtime alike.
- **Symbols are exact keys; member access is structure.** A namespaced symbol (`SymbolSource('turnover', 'customer')`) is answered by a binding or definition named exactly `customer.turnover` — never by digging into the value of a `customer` binding (`Bindings`' associative-array flattening and its descent successor are both gone; `TypeEnvironment` mirrors the same rule). Reaching into a record value is `MemberAccessSource`, certified against declared record fields. Caller data is therefore structurally unable to answer for — or shadow — a definition. **Migration**: bind keys exactly as declared (`['quote.turnover' => 600000]`, not `['quote' => ['turnover' => 600000]]`), or declare `quote` as a record, bind it whole, and use member access.
- **Records are exact** — no open/closed distinction: a record's value set is fully described by its declared fields (that exactness is what makes whole-record equality certifiable). The admission faces divide the work: `assert` rejects undeclared keys (strict membership), `coerce` takes the declared slice of wide input — hosts still pass whole context rows and only declared fields enter. Data with unenumerable keys is a `Dict`.
- **Declarations and definitions are disjoint namespaces**: a symbol is a parameter or a derived value, never both — a collision is a constructor error. Together with stripping and exact-key lookup, shadowing a definition is unrepresentable. Callers that overrode derived values via bindings model the override in-language: an `Option`-typed parameter the definition consults. Callers that relied on undeclared bindings feeding free symbols declare them (worst case `Unknown` — the explicit gradual path).
- **`TypeDefinition` is split into `Coerce` and `Ascription`** (`ValueResolver` becomes `CoerceResolver`; `AscriptionResolver` is new): `Coerce` converts via `coerce()` and types verbatim (the statically opaque boundary node); `Ascription` verifies via `assert()` and is checked statically (`Unknown`-or-overlaps — a disjoint claim is a compile error). **Absence cannot cross either node non-optionally**: when the declared/claimed type is not Option-shaped and the value reads as missing, resolution is a runtime error naming the requirement instead of a silent `None` — declare/claim `Option<T>` where absence is legal.
- **The dialect rides the `Context`**: resolvers hold no operator state. `InfixResolver`/`UnaryResolver` lose their operator-stack constructor parameters and read the per-call context; an overloader bound on a resolver container is inert. One resolver graph serves any number of expressions with different dialects, order-independently.
- One representation of null in the resolution channel: a bound `null` normalizes to absence at symbol lookup (presence still lives in `has()`).
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
