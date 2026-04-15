# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and the project aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
for released artifacts.

## [Unreleased]

### Added

- Axiom v1 language specification
- PHP implementation plan for a future reference runtime

### Changed

- repository documentation now reflects the current spec-first project state
- playground examples were realigned with the rewritten Axiom v1 direction
- the pre-v1 PHP runtime was archived under `legacy/`
- the root `src/` and `tests/` surfaces were reset for a fresh PHP
  implementation

## [1.0.0] - Initial Release

### Added

- type system for data validation and transformation
- expression evaluation system
- source system
- resolver-based architecture
- symbol registry support
- comprehensive PHP test suite

[Unreleased]: https://github.com/gosuperscript/axiom/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/gosuperscript/axiom/releases/tag/v1.0.0
