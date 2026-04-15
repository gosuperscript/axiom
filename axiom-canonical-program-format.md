# Axiom Canonical Program Format

## Purpose

This document defines a UI-facing canonical program format for Axiom.

It is intended to be the shared interchange layer between:

- builder UIs
- imported legacy normalized JSON
- hand-authored DSL text
- the internal PHP implementation

The canonical format is not the parser's raw AST and it is not a PHP class dump.
It is a versioned, stable, program-level JSON model that can be persisted,
rendered, migrated, and validated independently of the engine internals.

## Role In The System

The intended flow is:

1. builder UI -> canonical program JSON
2. legacy normalized JSON -> canonical program JSON
3. DSL text -> parser -> canonical program JSON
4. canonical program JSON -> internal PHP AST/analyzed program
5. analyzed program -> evaluation
6. canonical program JSON -> optional DSL printer

This gives Axiom one semantic center without forcing structured authoring tools
to emit DSL text directly.

## Design Constraints

The canonical format should:

- be versioned explicitly
- represent complete programs, not just isolated expression trees
- separate stable machine identity from human-readable names
- represent tables as first-class program artifacts
- make references explicit by target kind
- avoid leaking parser-only or PHP-only implementation details
- be easy to construct from UI builders
- be easy to migrate from the legacy normalized JSON format

## Non-Goals

The canonical format should not:

- mirror the internal PHP class layout one-to-one
- expose lazy evaluation or memoization internals
- encode formatter trivia or source-text round-tripping details
- preserve legacy node names when they no longer match the Axiom v1 semantics

## Program Shape

```json
{
  "format": "axiom.program/v1alpha1",
  "meta": {
    "source": "builder-ui"
  },
  "inputs": [],
  "tables": [],
  "types": [],
  "expressions": []
}
```

### Top-Level Fields

- `format`: required version tag
- `meta`: optional non-semantic metadata
- `inputs`: declared runtime inputs
- `tables`: declared external table artifacts
- `types`: optional named types used by the program
- `expressions`: named expression declarations

## Identity Model

Every user-authored top-level item should have:

- `id`: stable machine identity used by persistence and migration
- `name`: printable identifier used for DSL export and diagnostics
- `label`: optional UI display text

Example:

```json
{
  "id": "01KHBRRYYCRA5KPZRP8650AH5J",
  "name": "isMainIndustryOnlineRetailer",
  "label": "Is Main Industry Online Retailer"
}
```

The engine should not rely on `label` for semantics.

## Inputs

Inputs replace the old implicit `answers` symbol namespace.

```json
{
  "id": "01KHBRRYYDY6X95BQC50EASGHZ",
  "name": "mainIndustry",
  "label": "Main Industry",
  "type": {
    "kind": "list",
    "element": {
      "kind": "named",
      "name": "IndustryRef"
    }
  }
}
```

### Input Invariants

- inputs are declared once at the program level
- expression bodies refer to inputs by `input_ref`
- the old generic `SymbolSource(namespace="answers")` should not survive in the
  canonical format

## Tables

Tables are first-class program declarations.

```json
{
  "id": "01KHBRRY0V6Y3H4WAKKT8X3Q9C",
  "name": "industryLookup",
  "label": "Industry Lookup",
  "artifact": {
    "kind": "csv",
    "path": "lookups/01KHBRRY0V6Y3H4WAKKT8X3Q9B/01KHBRRY0V6Y3H4WAKKT8X3Q9C.csv"
  }
}
```

### Table Invariants

- the table declaration identifies the artifact
- expressions refer to tables by `tableId`
- repeated legacy lookup nodes that reference the same table should collapse to
  one top-level table declaration

## Expressions

Expressions are the main authored units.

```json
{
  "id": "01KHBRRYYCRA5KPZRP8650AH5J",
  "name": "isMainIndustryOnlineRetailer",
  "label": "Is Main Industry Online Retailer",
  "returnType": {
    "kind": "primitive",
    "name": "bool"
  },
  "body": {}
}
```

### Expression Invariants

- every expression has exactly one body node
- expression composition should use `call`, not generic symbol lookup
- the canonical format is declaration-oriented, not just a bag of anonymous
  nodes

## Type Nodes

The UI-facing type layer should stay small and explicit.

### Primitive Type

```json
{ "kind": "primitive", "name": "bool" }
```

Supported primitive names should reflect the Axiom v1 spec, for example:

- `number`
- `string`
- `bool`

### Named Type

```json
{ "kind": "named", "name": "IndustryRef" }
```

### List Type

```json
{
  "kind": "list",
  "element": { "kind": "named", "name": "IndustryRef" }
}
```

### Record Type

```json
{
  "kind": "record",
  "fields": [
    { "name": "industry", "type": { "kind": "named", "name": "IndustryRef" } },
    { "name": "turnover", "type": { "kind": "primitive", "name": "number" } }
  ]
}
```

### Variant Type

```json
{
  "kind": "variant",
  "cases": [
    { "tag": "accept" },
    {
      "tag": "refer",
      "payload": {
        "kind": "record",
        "fields": [
          { "name": "reason", "type": { "kind": "primitive", "name": "string" } }
        ]
      }
    }
  ]
}
```

## Core Expression Nodes

The initial canonical node set should cover the most common builder operations.

### `literal`

```json
{ "kind": "literal", "value": "DRI-749" }
```

### `list`

```json
{
  "kind": "list",
  "elements": [
    { "kind": "literal", "value": "DRI-749" },
    { "kind": "literal", "value": "DRI-1793" }
  ]
}
```

### `record`

```json
{
  "kind": "record",
  "fields": [
    { "name": "industry", "value": { "kind": "literal", "value": "DRI-749" } },
    { "name": "turnover", "value": { "kind": "literal", "value": "500000" } }
  ]
}
```

### `input_ref`

```json
{
  "kind": "input_ref",
  "inputId": "01KHBRRYYDY6X95BQC50EASGHZ"
}
```

Use `input_ref` for declared runtime inputs only.

### `local_ref`

```json
{
  "kind": "local_ref",
  "name": "row"
}
```

Use `local_ref` for bound names introduced by `match` or table queries.

### `call`

```json
{
  "kind": "call",
  "expressionId": "01KHBRRYYCRA5KPZRP8650AH5J",
  "arguments": []
}
```

Use `call` when one named expression depends on another named expression.

### `field`

```json
{
  "kind": "field",
  "object": { "kind": "local_ref", "name": "row" },
  "name": "Product Group"
}
```

### `unary`

```json
{
  "kind": "unary",
  "operator": "not",
  "operand": { "kind": "input_ref", "inputId": "01..." }
}
```

### `binary`

```json
{
  "kind": "binary",
  "operator": "intersects",
  "left": { "kind": "input_ref", "inputId": "01..." },
  "right": { "kind": "list", "elements": [] }
}
```

Typical UI-facing binary operators include:

- `==`
- `!=`
- `>`
- `>=`
- `<`
- `<=`
- `and`
- `or`
- `+`
- `-`
- `*`
- `/`
- `in`
- `intersects`

### `match`

```json
{
  "kind": "match",
  "subject": { "kind": "input_ref", "inputId": "01..." },
  "arms": [
    {
      "pattern": { "kind": "literal_pattern", "value": "micro" },
      "value": { "kind": "literal", "value": "1.3" }
    },
    {
      "pattern": { "kind": "wildcard_pattern" },
      "value": { "kind": "literal", "value": "1.0" }
    }
  ]
}
```

### `annotate`

```json
{
  "kind": "annotate",
  "type": { "kind": "list", "element": { "kind": "named", "name": "IndustryRef" } },
  "expression": { "kind": "list", "elements": [] }
}
```

`annotate` exists for cases where the authoring surface needs to pin a type
explicitly. It should not be used as a generic wrapper around every expression.

### `table_query`

```json
{
  "kind": "table_query",
  "mode": "first",
  "tableId": "01KHBRRY0V6Y3H4WAKKT8X3Q9C",
  "binding": "row",
  "where": {
    "kind": "binary",
    "operator": "==",
    "left": {
      "kind": "field",
      "object": { "kind": "local_ref", "name": "row" },
      "name": "ID"
    },
    "right": {
      "kind": "input_ref",
      "inputId": "01KHBRRYYDY6X95BQC50EASGHZ"
    }
  },
  "select": {
    "kind": "field",
    "object": { "kind": "local_ref", "name": "row" },
    "name": "Product Group"
  }
}
```

`mode` should begin with:

- `first`
- `all`

This keeps the canonical format close to the current legacy lookup behavior
while still modeling tables as first-class declarations.

## Pattern Nodes

The initial pattern set should remain small:

- `literal_pattern`
- `wildcard_pattern`
- `tag_pattern`

If expression-based guard patterns are needed in the canonical format, define
them explicitly rather than carrying legacy pattern node names forward.

## Canonical Example

The following shows a simplified rewrite of two legacy variables into the
canonical format.

```json
{
  "format": "axiom.program/v1alpha1",
  "inputs": [
    {
      "id": "01KHBRRYYDY6X95BQC50EASGHZ",
      "name": "mainIndustry",
      "type": {
        "kind": "list",
        "element": {
          "kind": "named",
          "name": "IndustryRef"
        }
      }
    }
  ],
  "tables": [
    {
      "id": "01KHBRRY0V6Y3H4WAKKT8X3Q9C",
      "name": "industryLookup",
      "artifact": {
        "kind": "csv",
        "path": "lookups/01KHBRRY0V6Y3H4WAKKT8X3Q9B/01KHBRRY0V6Y3H4WAKKT8X3Q9C.csv"
      }
    }
  ],
  "expressions": [
    {
      "id": "01KHBRRYYCRA5KPZRP8650AH5J",
      "name": "isMainIndustryOnlineRetailer",
      "label": "Is Main Industry Online Retailer",
      "returnType": { "kind": "primitive", "name": "bool" },
      "body": {
        "kind": "binary",
        "operator": "intersects",
        "left": {
          "kind": "input_ref",
          "inputId": "01KHBRRYYDY6X95BQC50EASGHZ"
        },
        "right": {
          "kind": "list",
          "elements": [
            { "kind": "literal", "value": "DRI-749" },
            { "kind": "literal", "value": "DRI-1793" },
            { "kind": "literal", "value": "DRI-1794" },
            { "kind": "literal", "value": "DRI-1795" }
          ]
        }
      }
    },
    {
      "id": "01KHBRRYYCRA5KPZRP8650AH5M",
      "name": "industryGroupMainIndustry",
      "label": "Industry Group Lookup - Main Industry",
      "body": {
        "kind": "table_query",
        "mode": "first",
        "tableId": "01KHBRRY0V6Y3H4WAKKT8X3Q9C",
        "binding": "row",
        "where": {
          "kind": "binary",
          "operator": "==",
          "left": {
            "kind": "field",
            "object": { "kind": "local_ref", "name": "row" },
            "name": "ID"
          },
          "right": {
            "kind": "input_ref",
            "inputId": "01KHBRRYYDY6X95BQC50EASGHZ"
          }
        },
        "select": {
          "kind": "field",
          "object": { "kind": "local_ref", "name": "row" },
          "name": "Product Group"
        }
      }
    }
  ]
}
```

## Migration From Legacy Normalized JSON

### Top-Level Legacy Shape

Current legacy payloads appear to use:

```json
{
  "variables": [ ... ]
}
```

Migration should lift this into a full program:

- variable list -> `expressions`
- `answers` references -> `inputs`
- repeated `LookupSource` tables -> deduplicated `tables`

### Legacy Node Mapping

- `StaticValue` -> `literal`
- `ListSource` -> `list`
- `SymbolSource(namespace="answers")` -> `input_ref`
- `SymbolSource` pointing to another variable -> `call`
- `InfixExpression` -> `binary`
- `TypeDefinition` -> remove where redundant, otherwise `annotate`
- `LookupSource` -> top-level `table` + `table_query`

### `SymbolSource` Rule

The old generic symbol node should not survive in the canonical format.

Use:

- `input_ref` for runtime inputs
- `local_ref` for locally bound names
- `call` for dependencies on other named expressions

This makes the reference target explicit and improves static analysis.

### `TypeDefinition` Rule

Legacy `TypeDefinition` often acts as a general wrapper rather than a meaningful
type annotation. The importer should:

- drop it when the type is already implied by the surrounding node
- preserve it as `annotate` only when the type information is semantically
  important

### `LookupSource` Rule

`LookupSource` is not a core expression node in the rewritten v1 language. It
is a legacy packaged lookup abstraction that should be lowered into:

- a table declaration
- a table query expression

Legacy fields:

- `id`, `path` -> table declaration
- `aggregate` -> `table_query.mode`
- `filters` -> `table_query.where`
- `columns` -> `table_query.select`

When `columns` contains exactly one field, the importer can select that field
directly. If multiple columns are requested, the importer should produce a
record-valued selection instead of inventing bespoke lookup semantics.

## UI Authoring Guidance

The builder UI should target the canonical format directly.

Recommended builder primitives:

- choose input
- call expression
- literal value
- list literal
- binary comparison/arithmetic
- boolean combination
- field selection from bound row
- table query with filters
- match with ordered arms
- explicit type annotation only when needed

The UI should not need to know about:

- lexer tokens
- parser recovery
- PHP AST classes
- engine memoization or runtime internals

## Relationship To Internal PHP AST

The PHP engine should hydrate this canonical format into internal classes under
`src/`.

Those internal classes may evolve as implementation details change.

The canonical format should remain the stable contract for:

- UI authoring
- persistence
- import/export
- migration
- test fixtures

## Suggested Next Steps

1. Freeze the legacy format as an import-only compatibility layer.
2. Implement a PHP importer from legacy JSON into the canonical format.
3. Add canonical-format fixtures under `tests/Conformance/`.
4. Implement the internal hydrator from canonical JSON into PHP AST objects.
5. Add a DSL printer after the canonical format and internal AST are stable.
