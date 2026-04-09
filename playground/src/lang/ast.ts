import { Location } from './diagnostics';

// --- Top-level declarations ---

export interface ProgramNode {
  kind: 'Program';
  body: Declaration[];
}

export type Declaration = TypeDeclaration | ExpressionDeclaration | NamespaceDeclaration | SourceDeclaration | TableDeclaration;

export interface TypeDeclaration {
  kind: 'TypeDeclaration';
  name: string;
  alternatives: VariantAlternative[];
  shape?: Record<string, TypeAnnotation>;  // Record type (no variants, just fields)
  location?: Location;
}

export interface VariantAlternative {
  tag: string;
  shape: Record<string, TypeAnnotation>;
}

export interface SourceDeclaration {
  kind: 'SourceDeclaration';
  name: string;
  params: Parameter[];
  returnType: TypeAnnotation;
  location?: Location;
}

export interface TableDeclaration {
  kind: 'TableDeclaration';
  name: string;
  elementType: TypeAnnotation;
  location?: Location;
}

export interface NamespaceDeclaration {
  kind: 'NamespaceDeclaration';
  name: string;
  symbols: SymbolDeclaration[];
  types: TypeDeclaration[];
  expressions: ExpressionDeclaration[];
  sources: SourceDeclaration[];
  location?: Location;
}

export interface SymbolDeclaration {
  name: string;
  type: TypeAnnotation;
  value: Expr;
}

export interface ExpressionDeclaration {
  kind: 'ExpressionDeclaration';
  name: string;
  params: Parameter[];
  returnType?: TypeAnnotation;
  body: Expr;
  location?: Location;
}

export interface Parameter {
  name: string;
  type: TypeAnnotation;
}

// --- Type annotations ---

export interface TypeAnnotation {
  keyword: string;
  args: Expr[];
  shape?: Record<string, TypeAnnotation>;
}

// --- Expressions ---

export type Expr =
  | LiteralExpr
  | PluginLiteralExpr
  | IdentifierExpr
  | MemberExpr
  | IndexExpr
  | InfixExpr
  | UnaryExpr
  | CoercionExpr
  | IfExpr
  | MatchExpr
  | CallExpr
  | ListLiteralExpr
  | DictLiteralExpr
  | VariantConstructionExpr
  | AnyExpr
  | AllExpr
  | CollectExpr
  | AggregateCollectExpr
  | ParenExpr
  | WhereExpr;

export interface LiteralExpr {
  kind: 'Literal';
  value: number | string | boolean;
  raw: string;
  location?: Location;
}

export interface PluginLiteralExpr {
  kind: 'PluginLiteral';
  tag: string;
  value: unknown;
  displayValue: string;
  location?: Location;
}

export interface IdentifierExpr {
  kind: 'Identifier';
  name: string;
  location?: Location;
}

export interface MemberExpr {
  kind: 'MemberExpression';
  object: Expr;
  property: string;
  location?: Location;
}

export interface IndexExpr {
  kind: 'IndexExpression';
  object: Expr;
  index: Expr;
  location?: Location;
}

export interface InfixExpr {
  kind: 'InfixExpression';
  left: Expr;
  operator: string;
  right: Expr;
  location?: Location;
}

export interface UnaryExpr {
  kind: 'UnaryExpression';
  operator: string;
  operand: Expr;
  location?: Location;
}

export interface CoercionExpr {
  kind: 'CoercionExpression';
  expression: Expr;
  targetType: TypeAnnotation;
  location?: Location;
}

export interface IfExpr {
  kind: 'IfExpression';
  condition: Expr;
  then: Expr;
  elseIfs: { condition: Expr; then: Expr }[];
  else: Expr;
  location?: Location;
}

export interface MatchExpr {
  kind: 'MatchExpression';
  subject?: Expr;
  binding?: string;    // match binding in iterable { ... }
  iterable?: Expr;     // the list to iterate over
  arms: MatchArm[];
  location?: Location;
}

export interface MatchArm {
  pattern: Pattern;
  expression: Expr;
}

export interface CallExpr {
  kind: 'CallExpression';
  callee: string;
  args: Expr[];
  namedArgs: Record<string, Expr>;
  allArgs: Expr[];  // All arguments in original source order (for intrinsic calls)
  spread?: boolean; // ... — fill remaining params from scope by matching name
  location?: Location;
}

export interface ListLiteralExpr {
  kind: 'ListLiteral';
  elements: Expr[];
  location?: Location;
}

export interface DictLiteralExpr {
  kind: 'DictLiteral';
  entries: { key: string; value: Expr }[];
  location?: Location;
}

export interface VariantConstructionExpr {
  kind: 'VariantConstruction';
  typeName?: string;
  tag: string;
  entries: { key: string; value: Expr }[];
  location?: Location;
}

export interface AnyExpr {
  kind: 'AnyExpression';
  pattern: Pattern;
  list: Expr;
  location?: Location;
}

export interface AllExpr {
  kind: 'AllExpression';
  pattern: Pattern;
  list: Expr;
  location?: Location;
}

export interface CollectExpr {
  kind: 'CollectExpression';
  pattern?: Pattern;    // standard form: collect pattern in list => body
  list: Expr;
  body?: Expr;
  binding?: string;     // binding form: collect row in list { arms }
  arms?: { pattern: Pattern; body: Expr }[];
  location?: Location;
}

export interface AggregateCollectExpr {
  kind: 'AggregateCollectExpression';
  aggregator: string;
  list: Expr;
  arms: { pattern: Pattern; body: Expr }[];
  binding?: string;     // binding form: agg collect row in list { arms }
  location?: Location;
}

export interface ParenExpr {
  kind: 'ParenExpression';
  expression: Expr;
  location?: Location;
}

export interface WhereExpr {
  kind: 'WhereExpression';
  body: Expr;
  bindings: { name: string; value: Expr }[];
  location?: Location;
}

// --- Patterns ---

export type Pattern =
  | WildcardPattern
  | LiteralPattern
  | ExpressionPattern
  | VariantPattern
  | RangePattern
  | AlternativePattern
  | TuplePattern;

export interface WildcardPattern {
  kind: 'WildcardPattern';
  location?: Location;
}

export interface LiteralPattern {
  kind: 'LiteralPattern';
  value: number | string | boolean;
  raw: string;
  location?: Location;
}

export interface ExpressionPattern {
  kind: 'ExpressionPattern';
  expression: Expr;
  location?: Location;
}

export interface VariantPattern {
  kind: 'VariantPattern';
  typeName?: string;
  tag: string;
  bindings: Record<string, string | null>; // field -> alias (null = wildcard binding)
  location?: Location;
}

export interface RangePattern {
  kind: 'RangePattern';
  openLeft: boolean;   // ( = exclusive
  openRight: boolean;  // ) = exclusive
  left?: number;
  right?: number;
  location?: Location;
}

export interface AlternativePattern {
  kind: 'AlternativePattern';
  patterns: Pattern[];
  location?: Location;
}

export interface TuplePattern {
  kind: 'TuplePattern';
  elements: Pattern[];
  location?: Location;
}
