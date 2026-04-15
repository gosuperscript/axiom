# Copilot Agent Instructions for Axiom

## Repository Overview

This repository is in transition toward a spec-driven Axiom v1 DSL project.

There are three distinct surfaces:

- `axiom-v1-spec.md`: the primary language source of truth
- `src/` and `tests/`: the fresh root PHP implementation surface
- `playground/`: the experimental TypeScript playground

The old PHP library has been archived under `legacy/`. Do not treat `legacy/`
as the active implementation unless a task explicitly targets it.

## Working Rules

- Prefer the current Axiom v1 specification over legacy implementation behavior.
- Keep the root PHP implementation small and deliberate.
- Treat the playground as a validation tool, not as the language definition.
- Avoid reintroducing old library abstractions just because they exist in
  `legacy/`.

## Validation

For root PHP changes:

```bash
composer test
```

For playground changes:

```bash
cd playground
npm run build
```

Run only the relevant validations for the surfaces you change, and say what you
did not run.
