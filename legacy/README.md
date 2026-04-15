# Legacy Archive

This directory contains the archived PHP library that predates the rewritten
Axiom v1 specification.

It is preserved for reference while the new PHP implementation starts cleanly
from the root [`src/`](../src) and [`tests/`](../tests) directories.

## Contents

- `src/`: archived PHP source
- `tests/`: archived PHP tests
- `composer.json`: archived package manifest for the legacy runtime
- `phpunit.xml.dist`, `phpstan.neon.dist`, `infection.json5`, `pint.json`:
  archived quality/config files

## Status

- not the active implementation
- not the root package surface
- useful as reference material during the rewrite

If you need to inspect or run the legacy code, do so from this directory
explicitly rather than treating it as the active root runtime.
