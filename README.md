# Axiom

Axiom is a small, declarative, deterministic DSL for authored business
computation. It is intended for logic such as pricing, eligibility,
classification, financial calculation, and product rules.

This repository is currently a research and transition workspace. The current
source of truth is the language specification, while the implementation work is
being realigned around a PHP-first reference runtime.

## Current State

- [Axiom v1 Specification](./axiom-v1-spec.md): the current normative language
  spec
- [PHP Implementation Plan](./axiom-php-implementation-plan.md): the proposed
  architecture and delivery plan for a PHP implementation
- [Canonical Program Format](./axiom-canonical-program-format.md): the proposed
  UI-facing program JSON for builder tools, import/export, and engine hydration
- [`playground/`](./playground): an experimental TypeScript playground used to
  explore syntax, examples, parsing, and evaluation behavior
- [`src/`](./src) and [`tests/`](./tests): the fresh root surface for the new
  PHP implementation
- [`legacy/`](./legacy): the archived pre-v1 PHP library and its test/config
  surface

The TypeScript playground is useful for iteration, but it is not the planned
canonical implementation. The next major implementation phase is expected to be
in PHP so it aligns with the surrounding application stack.

## Repository Layout

- [`axiom-v1-spec.md`](./axiom-v1-spec.md): Axiom v1 language specification
- [`axiom-php-implementation-plan.md`](./axiom-php-implementation-plan.md):
  implementation plan for a PHP runtime
- [`axiom-canonical-program-format.md`](./axiom-canonical-program-format.md):
  UI-facing canonical program JSON design
- [`playground/`](./playground): experimental parser/checker/evaluator and
  worked examples
- [`src/`](./src): fresh root namespace for the new PHP runtime
- [`tests/`](./tests): fresh root test surface for the new PHP runtime
- [`legacy/`](./legacy): archived pre-v1 implementation and associated config

## Working Assumptions

- The spec is the primary source of truth.
- Examples should align with the spec, even if the playground lags behind.
- The new PHP implementation starts from the fresh root `src/` tree.
- The archived code in `legacy/` is reference material, not the active runtime.

## Development

For the current root PHP implementation workspace:

```bash
composer install
composer test
```

For the TypeScript playground:

```bash
cd playground
npm install
npm run build
```

## Contributing

Contributions are welcome, but the repository is currently in a reshape phase.
Changes should make the project more internally consistent, especially across:

- the language specification
- the worked examples
- the playground behavior
- the future PHP implementation direction
- the root/legacy split

See [CONTRIBUTING.md](./CONTRIBUTING.md) for contribution guidance.

## Security

See [SECURITY.md](./SECURITY.md) for vulnerability reporting guidance.
