import { TypeSig } from './types';

/** Plugin hook for custom token recognition in the lexer. */
export interface LexerPlugin {
  /** Try to recognize a custom token at position `pos` in source.
   *  Return null if this position isn't handled by this plugin. */
  tryTokenize(source: string, pos: number): {
    tag: string;           // e.g., 'money'
    value: string;         // display value, e.g., '£100.50'
    payload: unknown;      // structured data for AST/evaluator
    length: number;        // chars consumed from source
  } | null;
}

/** Plugin hook for type checking. */
export interface CheckerPlugin {
  /** Infer the type of a plugin literal. */
  inferLiteralType?(tag: string, payload: unknown): TypeSig | null;
  /** Check a binary operator with the given operand types.
   *  Return the result type, { error: string } for a type error, or null to defer to defaults. */
  checkBinaryOp?(op: string, left: TypeSig, right: TypeSig): TypeSig | { error: string } | null;
  /** Check an intrinsic/function call with the given arg types.
   *  Return the result type, or null to defer to default checking. */
  checkCall?(name: string, argTypes: TypeSig[]): TypeSig | null;
}

/** Plugin hook for evaluation. */
export interface EvaluatorPlugin {
  /** Return true if this plugin handles the given binary operation. */
  supportsOp?(left: unknown, right: unknown, op: string): boolean;
  /** Evaluate a binary operation. Only called if supportsOp returned true. */
  evaluateOp?(left: unknown, right: unknown, op: string): unknown;
  /** Plugin-provided intrinsic overrides. Return undefined to fall through to built-in. */
  intrinsics?: Record<string, ((...args: unknown[]) => unknown) | undefined>;
}

/** An Axiom plugin bundles lexer, checker, and evaluator extensions. */
export interface AxiomPlugin {
  name: string;
  lexer?: LexerPlugin;
  checker?: CheckerPlugin;
  evaluator?: EvaluatorPlugin;
}
