import { Diagnostic, Location, error } from './diagnostics';

export enum TokenType {
  // Literals
  Number = 'Number',
  String = 'String',
  Bool = 'Bool',

  // Identifiers & keywords
  Identifier = 'Identifier',
  Type = 'Type',
  Namespace = 'Namespace',
  If = 'If',
  Then = 'Then',
  Else = 'Else',
  Match = 'Match',
  Not = 'Not',
  In = 'In',
  As = 'As',
  Any = 'Any',
  All = 'All',
  Collect = 'Collect',
  Where = 'Where',
  Source = 'Source',
  Table = 'Table',
  True = 'True',
  False = 'False',

  // Operators
  Plus = 'Plus',
  Minus = 'Minus',
  Star = 'Star',
  Slash = 'Slash',
  Percent = 'Percent',
  StarStar = 'StarStar',
  Eq = 'Eq',
  NotEq = 'NotEq',
  Lt = 'Lt',
  Gt = 'Gt',
  LtEq = 'LtEq',
  GtEq = 'GtEq',
  And = 'And',
  Or = 'Or',
  Bang = 'Bang',
  Arrow = 'Arrow',       // =>
  Assign = 'Assign',     // =
  Pipe = 'Pipe',         // |

  // Punctuation
  LParen = 'LParen',
  RParen = 'RParen',
  LBracket = 'LBracket',
  RBracket = 'RBracket',
  LBrace = 'LBrace',
  RBrace = 'RBrace',
  Comma = 'Comma',
  Colon = 'Colon',
  Dot = 'Dot',
  DotDot = 'DotDot',     // ..
  Spread = 'Spread',     // ...
  Underscore = 'Underscore',

  // Plugin
  PluginLiteral = 'PluginLiteral',

  // Special
  EOF = 'EOF',
}

export interface Token {
  type: TokenType;
  value: string;
  tag?: string;      // For PluginLiteral: plugin-defined tag (e.g., 'money')
  payload?: unknown;  // For PluginLiteral: structured data for AST/evaluator
  location: Location;
}

const KEYWORDS: Record<string, TokenType> = {
  type: TokenType.Type,
  namespace: TokenType.Namespace,
  if: TokenType.If,
  then: TokenType.Then,
  else: TokenType.Else,
  match: TokenType.Match,
  not: TokenType.Not,
  in: TokenType.In,
  as: TokenType.As,
  any: TokenType.Any,
  all: TokenType.All,
  collect: TokenType.Collect,
  where: TokenType.Where,
  source: TokenType.Source,
  table: TokenType.Table,
  true: TokenType.True,
  false: TokenType.False,
};

export function tokenize(source: string, plugins?: import('./plugin').AxiomPlugin[]): { tokens: Token[]; diagnostics: Diagnostic[] } {
  const tokens: Token[] = [];
  const diagnostics: Diagnostic[] = [];
  let pos = 0;
  let line = 1;
  let col = 1;

  function loc(start: number, length: number): Location {
    // Compute line/col for start position
    let l = 1, c = 1;
    for (let i = 0; i < start; i++) {
      if (source[i] === '\n') { l++; c = 1; } else { c++; }
    }
    return { line: l, column: c, offset: start, length };
  }

  function peek(offset = 0): string {
    return source[pos + offset] ?? '\0';
  }

  function advance(): string {
    const ch = source[pos++];
    if (ch === '\n') { line++; col = 1; } else { col++; }
    return ch;
  }

  function match(expected: string): boolean {
    if (source[pos] === expected) { advance(); return true; }
    return false;
  }

  while (pos < source.length) {
    // Skip whitespace
    if (/\s/.test(peek())) { advance(); continue; }

    // Skip comments
    if (peek() === '/' && peek(1) === '/') {
      while (pos < source.length && peek() !== '\n') advance();
      continue;
    }

    const start = pos;

    // Try plugin tokenizers first
    if (plugins) {
      let handled = false;
      for (const plugin of plugins) {
        const result = plugin.lexer?.tryTokenize(source, pos);
        if (result) {
          tokens.push({
            type: TokenType.PluginLiteral,
            value: result.value,
            tag: result.tag,
            payload: result.payload,
            location: loc(start, result.length),
          });
          for (let i = 0; i < result.length; i++) advance();
          handled = true;
          break;
        }
      }
      if (handled) continue;
    }

    // Numbers
    if (/[0-9]/.test(peek())) {
      while (/[0-9]/.test(peek())) advance();
      if (peek() === '.' && peek(1) !== '.') {
        advance();
        while (/[0-9]/.test(peek())) advance();
      }
      tokens.push({ type: TokenType.Number, value: source.slice(start, pos), location: loc(start, pos - start) });
      continue;
    }

    // Strings
    if (peek() === '"') {
      advance();
      let value = '';
      while (pos < source.length && peek() !== '"') {
        if (peek() === '\\') { advance(); value += advance(); }
        else { value += advance(); }
      }
      if (peek() === '"') advance();
      else diagnostics.push(error('parse.unterminated_string', 'Unterminated string', loc(start, pos - start)));
      tokens.push({ type: TokenType.String, value, location: loc(start, pos - start) });
      continue;
    }

    // Identifiers / keywords / underscore
    if (/[a-zA-Z_]/.test(peek())) {
      while (/[a-zA-Z0-9_]/.test(peek())) advance();
      const word = source.slice(start, pos);

      if (word === '_') {
        tokens.push({ type: TokenType.Underscore, value: word, location: loc(start, pos - start) });
      } else if (word === 'true' || word === 'false') {
        tokens.push({ type: TokenType.Bool, value: word, location: loc(start, pos - start) });
      } else if (KEYWORDS[word]) {
        tokens.push({ type: KEYWORDS[word], value: word, location: loc(start, pos - start) });
      } else {
        tokens.push({ type: TokenType.Identifier, value: word, location: loc(start, pos - start) });
      }
      continue;
    }

    // Multi-char operators
    const ch = peek();
    switch (ch) {
      case '=':
        advance();
        if (match('=')) { tokens.push({ type: TokenType.Eq, value: '==', location: loc(start, 2) }); }
        else if (match('>')) { tokens.push({ type: TokenType.Arrow, value: '=>', location: loc(start, 2) }); }
        else { tokens.push({ type: TokenType.Assign, value: '=', location: loc(start, 1) }); }
        continue;
      case '!':
        advance();
        if (match('=')) { tokens.push({ type: TokenType.NotEq, value: '!=', location: loc(start, 2) }); }
        else { tokens.push({ type: TokenType.Bang, value: '!', location: loc(start, 1) }); }
        continue;
      case '<':
        advance();
        if (match('=')) { tokens.push({ type: TokenType.LtEq, value: '<=', location: loc(start, 2) }); }
        else { tokens.push({ type: TokenType.Lt, value: '<', location: loc(start, 1) }); }
        continue;
      case '>':
        advance();
        if (match('=')) { tokens.push({ type: TokenType.GtEq, value: '>=', location: loc(start, 2) }); }
        else { tokens.push({ type: TokenType.Gt, value: '>', location: loc(start, 1) }); }
        continue;
      case '&':
        advance();
        if (match('&')) { tokens.push({ type: TokenType.And, value: '&&', location: loc(start, 2) }); }
        else { diagnostics.push(error('parse.unexpected_char', `Unexpected character '&'`, loc(start, 1))); }
        continue;
      case '|':
        advance();
        if (match('|')) { tokens.push({ type: TokenType.Or, value: '||', location: loc(start, 2) }); }
        else { tokens.push({ type: TokenType.Pipe, value: '|', location: loc(start, 1) }); }
        continue;
      case '*':
        advance();
        if (match('*')) { tokens.push({ type: TokenType.StarStar, value: '**', location: loc(start, 2) }); }
        else { tokens.push({ type: TokenType.Star, value: '*', location: loc(start, 1) }); }
        continue;
      case '.':
        advance();
        if (match('.')) {
          if (match('.')) { tokens.push({ type: TokenType.Spread, value: '...', location: loc(start, 3) }); }
          else { tokens.push({ type: TokenType.DotDot, value: '..', location: loc(start, 2) }); }
        }
        else { tokens.push({ type: TokenType.Dot, value: '.', location: loc(start, 1) }); }
        continue;
      case '+': advance(); tokens.push({ type: TokenType.Plus, value: '+', location: loc(start, 1) }); continue;
      case '-': advance(); tokens.push({ type: TokenType.Minus, value: '-', location: loc(start, 1) }); continue;
      case '/': advance(); tokens.push({ type: TokenType.Slash, value: '/', location: loc(start, 1) }); continue;
      case '%': advance(); tokens.push({ type: TokenType.Percent, value: '%', location: loc(start, 1) }); continue;
      case '(': advance(); tokens.push({ type: TokenType.LParen, value: '(', location: loc(start, 1) }); continue;
      case ')': advance(); tokens.push({ type: TokenType.RParen, value: ')', location: loc(start, 1) }); continue;
      case '[': advance(); tokens.push({ type: TokenType.LBracket, value: '[', location: loc(start, 1) }); continue;
      case ']': advance(); tokens.push({ type: TokenType.RBracket, value: ']', location: loc(start, 1) }); continue;
      case '{': advance(); tokens.push({ type: TokenType.LBrace, value: '{', location: loc(start, 1) }); continue;
      case '}': advance(); tokens.push({ type: TokenType.RBrace, value: '}', location: loc(start, 1) }); continue;
      case ',': advance(); tokens.push({ type: TokenType.Comma, value: ',', location: loc(start, 1) }); continue;
      case ':': advance(); tokens.push({ type: TokenType.Colon, value: ':', location: loc(start, 1) }); continue;
      default:
        diagnostics.push(error('parse.unexpected_char', `Unexpected character '${ch}'`, loc(start, 1)));
        advance();
    }
  }

  tokens.push({ type: TokenType.EOF, value: '', location: loc(pos, 0) });
  return { tokens, diagnostics };
}
