# Contributing to Axiom

This repository is currently transitioning from an older PHP expression library
toward a spec-driven Axiom v1 DSL project. Contributions are welcome, but
changes should be grounded in the current language direction rather than the
archived library surface alone.

## Before You Start

Read the current project anchors first:

- [Axiom v1 Specification](./axiom-v1-spec.md)
- [PHP Implementation Plan](./axiom-php-implementation-plan.md)
- [README](./README.md)

If a proposed change conflicts with the current spec or with the planned PHP
direction, resolve that at the documentation level first.

## Current Contribution Priorities

- improve the Axiom v1 specification
- keep examples aligned with the specification
- tighten playground behavior and diagnostics where it helps validate the spec
- prepare the repository for a clean PHP implementation
- add conformance-style tests and fixtures

## Repository Areas

### Spec and Documentation

Changes to the language should update the relevant documentation in the same
pull request. The specification should not drift away from the examples or from
the implementation plan.

### Playground

The playground is exploratory. It is useful for validating syntax and semantics,
but it is not the canonical implementation target. Keep playground changes
clearly aligned with the spec and avoid using the playground as the de facto
language definition.

### Existing PHP Codebase

The archived code now lives in [`legacy/`](./legacy). Treat it as groundwork
and reference material, not as the active Axiom v1 runtime.

### Fresh Root PHP Implementation

The active PHP implementation surface now starts in [`src/`](./src) and
[`tests/`](./tests). Keep that surface intentionally small and aligned with the
current specification.

## Development Setup

### PHP Codebase

```bash
composer install
composer test
```

### TypeScript Playground

```bash
cd playground
npm install
npm run build
```

## Change Expectations

- keep changes focused
- update docs when semantics or repository structure changes
- prefer explicit, reviewable designs over clever shortcuts
- do not widen the language surface casually
- add or update tests when changing behavior

## Pull Requests

- use a clear title and description
- explain whether the change affects the spec, the playground, the PHP codebase,
  or more than one of them
- call out any intentional divergence from existing behavior
- include follow-up work if the change is deliberately partial

## Testing

For PHP changes, run:

```bash
composer test
```

For playground changes, run:

```bash
cd playground
npm run build
```

If you do not run a relevant validation step, say so in the pull request.
