import { Token, TokenType } from './lexer';
import { Diagnostic, Location, error } from './diagnostics';
import {
  ProgramNode, Declaration, TypeDeclaration, ExpressionDeclaration,
  NamespaceDeclaration, SourceDeclaration, TableDeclaration, SymbolDeclaration,
  VariantAlternative, Parameter, TypeAnnotation, Expr, MatchArm, Pattern, MatchExpr,
} from './ast';

export function parse(tokens: Token[]): { ast: ProgramNode; diagnostics: Diagnostic[] } {
  const diagnostics: Diagnostic[] = [];
  let pos = 0;

  function current(): Token { return tokens[pos] ?? tokens[tokens.length - 1]; }
  function peek(offset = 0): Token { return tokens[pos + offset] ?? tokens[tokens.length - 1]; }
  function at(type: TokenType): boolean { return current().type === type; }
  function atValue(value: string): boolean { return current().value === value; }

  function advance(): Token {
    const tok = current();
    if (pos < tokens.length - 1) pos++;
    return tok;
  }

  function expect(type: TokenType, msg?: string): Token {
    if (at(type)) return advance();
    const tok = current();
    diagnostics.push(error('parse.expected', msg ?? `Expected ${type}, got '${tok.value}'`, tok.location));
    return tok;
  }

  function expectValue(value: string, msg?: string): Token {
    if (current().value === value) return advance();
    const tok = current();
    diagnostics.push(error('parse.expected', msg ?? `Expected '${value}', got '${tok.value}'`, tok.location));
    return tok;
  }

  function loc(start: Token, end?: Token): Location {
    const e = end ?? tokens[pos - 1] ?? start;
    return {
      line: start.location.line,
      column: start.location.column,
      offset: start.location.offset,
      length: (e.location.offset + e.location.length) - start.location.offset,
    };
  }

  // Peek ahead to check if a '{' starts a variant/dict (has key:value pairs)
  // versus something else (match body, etc.)
  function looksLikeVariantOrDict(): boolean {
    const saved = pos;
    pos++; // skip '{'
    const result = at(TokenType.RBrace) || (at(TokenType.Identifier) && (
      peek(1).type === TokenType.Colon ||   // key: value
      peek(1).type === TokenType.Comma ||   // shorthand: key,
      peek(1).type === TokenType.RBrace     // shorthand: key }
    ));
    pos = saved;
    return result;
  }

  // --- Top Level ---

  /** Break out of a loop if no progress was made since last check. */
  function guardProgress(lastPos: number): boolean {
    if (pos === lastPos) { advance(); return false; }
    return true;
  }

  function parseProgram(): ProgramNode {
    const body: Declaration[] = [];
    while (!at(TokenType.EOF)) {
      const before = pos;
      try {
        body.push(parseDeclaration());
      } catch {
        // Recovery: skip to next declaration boundary
        while (!at(TokenType.EOF) && !at(TokenType.Type) && !at(TokenType.Namespace) && !at(TokenType.Source) && !at(TokenType.Table) && !isExprDeclStart()) {
          advance();
        }
      }
      if (!guardProgress(before)) continue;
    }
    return { kind: 'Program', body };
  }

  function isExprDeclStart(): boolean {
    // An expression declaration starts with IDENT (
    return at(TokenType.Identifier) && peek(1).type === TokenType.LParen;
  }

  function parseDeclaration(): Declaration {
    if (at(TokenType.Type)) return parseTypeDeclaration();
    if (at(TokenType.Namespace)) return parseNamespaceDeclaration();
    if (at(TokenType.Source)) return parseSourceDeclaration();
    if (at(TokenType.Table)) return parseTableDeclaration();
    return parseExpressionDeclaration();
  }

  function parseTypeDeclaration(): TypeDeclaration {
    const start = advance(); // 'type'
    const name = expect(TokenType.Identifier).value;
    expect(TokenType.Assign);
    // Record type: type Name = { field: Type, ... }
    if (at(TokenType.LBrace)) {
      const shape = parseShapeFields();
      return { kind: 'TypeDeclaration', name, alternatives: [], shape, location: loc(start) };
    }
    // Variant type: type Name = tag { ... } | tag { ... }
    const alternatives: VariantAlternative[] = [];
    const first = tryParseVariantAlternative();
    if (first) alternatives.push(first);
    while (at(TokenType.Pipe)) {
      advance();
      const alt = tryParseVariantAlternative();
      if (alt) alternatives.push(alt);
      else break; // incomplete alternative (e.g. typing `| ` with no tag yet)
    }
    return { kind: 'TypeDeclaration', name, alternatives, location: loc(start) };
  }

  function tryParseVariantAlternative(): VariantAlternative | null {
    if (!at(TokenType.Identifier)) {
      // No tag yet — incomplete variant alternative, bail gracefully
      if (!at(TokenType.EOF) && !at(TokenType.Pipe)) {
        diagnostics.push(error('parse.expected', `Expected variant tag name, got '${current().value}'`, current().location));
      }
      return null;
    }
    const tag = advance().value;
    // Payload-less variant: tag without {} (next is | or newline/EOF/different statement)
    if (!at(TokenType.LBrace)) {
      return { tag, shape: {} };
    }
    const shape = parseShapeFields();
    return { tag, shape };
  }

  function parseShapeFields(): Record<string, TypeAnnotation> {
    expect(TokenType.LBrace);
    const shape: Record<string, TypeAnnotation> = {};
    while (!at(TokenType.RBrace) && !at(TokenType.EOF)) {
      if (!at(TokenType.Identifier)) {
        // Unexpected token inside shape — skip it to avoid infinite loop
        diagnostics.push(error('parse.expected', `Expected field name, got '${current().value}'`, current().location));
        advance();
        continue;
      }
      const fname = advance().value;
      expect(TokenType.Colon);
      shape[fname] = parseTypeAnnotation();
      if (!at(TokenType.RBrace)) expect(TokenType.Comma);
    }
    expect(TokenType.RBrace);
    return shape;
  }

  function parseTypeAnnotation(): TypeAnnotation {
    // Handle qualified names: foo.bar.Baz
    let keyword = expect(TokenType.Identifier).value;
    while (at(TokenType.Dot) && peek(1).type === TokenType.Identifier) {
      advance(); // '.'
      keyword += '.' + advance().value;
    }
    const args: Expr[] = [];

    if (at(TokenType.LParen)) {
      advance();
      while (!at(TokenType.RParen) && !at(TokenType.EOF)) {
        const before = pos;
        args.push(parseExpression());
        if (!at(TokenType.RParen)) expect(TokenType.Comma);
        if (!guardProgress(before)) break;
      }
      expect(TokenType.RParen);
    }

    return { keyword, args };
  }

  function parseNamespaceDeclaration(): NamespaceDeclaration {
    const start = advance(); // 'namespace'
    const name = expect(TokenType.Identifier).value;
    expect(TokenType.LBrace);
    const symbols: SymbolDeclaration[] = [];
    const types: TypeDeclaration[] = [];
    const expressions: ExpressionDeclaration[] = [];
    const sources: SourceDeclaration[] = [];
    while (!at(TokenType.RBrace) && !at(TokenType.EOF)) {
      const before = pos;
      // Type declaration inside namespace
      if (at(TokenType.Type)) {
        types.push(parseTypeDeclaration());
        if (!guardProgress(before)) break;
        continue;
      }
      // Source declaration inside namespace
      if (at(TokenType.Source)) {
        sources.push(parseSourceDeclaration());
        if (!guardProgress(before)) break;
        continue;
      }
      // Expression declaration: Name(...)
      if (isExprDeclStart()) {
        expressions.push(parseExpressionDeclaration());
        if (!guardProgress(before)) break;
        continue;
      }
      // Symbol declaration: Name: Type = Expression
      const sname = expect(TokenType.Identifier).value;
      expect(TokenType.Colon);
      const stype = parseTypeAnnotation();
      expect(TokenType.Assign);
      const value = parseExpression();
      symbols.push({ name: sname, type: stype, value });
      if (!guardProgress(before)) break;
    }
    expect(TokenType.RBrace);
    return { kind: 'NamespaceDeclaration', name, symbols, types, expressions, sources, location: loc(start) };
  }

  function parseExpressionDeclaration(): ExpressionDeclaration {
    const start = current();
    const name = expect(TokenType.Identifier).value;
    const params: Parameter[] = [];

    // Parameters are optional — parse only if ( follows
    if (at(TokenType.LParen)) {
      advance();
      while (!at(TokenType.RParen) && !at(TokenType.EOF)) {
        const before = pos;
        const pname = expect(TokenType.Identifier).value;
        expect(TokenType.Colon);
        let ptype: TypeAnnotation;
        if (at(TokenType.LBrace)) {
          // Inline shape parameter
          ptype = { keyword: 'dict', args: [], shape: parseShapeFields() };
        } else {
          ptype = parseTypeAnnotation();
        }
        params.push({ name: pname, type: ptype });
        if (!at(TokenType.RParen)) expect(TokenType.Comma);
        if (!guardProgress(before)) break;
      }
      expect(TokenType.RParen);
    }

    let returnType: TypeAnnotation | undefined;
    if (at(TokenType.Colon)) {
      advance();
      returnType = parseTypeAnnotation();
    }

    expect(TokenType.LBrace);
    const body = parseExpression();
    expect(TokenType.RBrace);
    return { kind: 'ExpressionDeclaration', name, params, returnType, body, location: loc(start) };
  }

  function parseSourceDeclaration(): SourceDeclaration {
    const start = advance(); // 'source'
    const name = expect(TokenType.Identifier).value;
    expect(TokenType.LParen);
    const params: Parameter[] = [];
    while (!at(TokenType.RParen) && !at(TokenType.EOF)) {
      const before = pos;
      const pname = expect(TokenType.Identifier).value;
      expect(TokenType.Colon);
      let ptype: TypeAnnotation;
      if (at(TokenType.LBrace)) {
        ptype = { keyword: 'dict', args: [], shape: parseShapeFields() };
      } else {
        ptype = parseTypeAnnotation();
      }
      params.push({ name: pname, type: ptype });
      if (!at(TokenType.RParen)) expect(TokenType.Comma);
      if (!guardProgress(before)) break;
    }
    expect(TokenType.RParen);
    expect(TokenType.Colon);
    let returnType: TypeAnnotation;
    if (at(TokenType.LBrace)) {
      returnType = { keyword: 'dict', args: [], shape: parseShapeFields() };
    } else {
      returnType = parseTypeAnnotation();
    }
    return { kind: 'SourceDeclaration', name, params, returnType, location: loc(start) };
  }

  function parseTableDeclaration(): TableDeclaration {
    const start = advance(); // 'table'
    const name = expect(TokenType.Identifier).value;
    expect(TokenType.Colon);
    // Expect list({field: type, ...}) or list(TypeName)
    const keyword = expect(TokenType.Identifier).value;
    let elementType: TypeAnnotation;
    if (keyword === 'list' && at(TokenType.LParen)) {
      advance(); // '('
      if (at(TokenType.LBrace)) {
        // list({field: type, ...}) — inline record shape
        const shape = parseShapeFields();
        elementType = { keyword: 'dict', args: [], shape };
      } else {
        // list(TypeName) — named element type
        elementType = parseTypeAnnotation();
      }
      expect(TokenType.RParen);
    } else {
      // Bare type name (shouldn't normally happen for tables)
      elementType = { keyword, args: [] };
    }
    return { kind: 'TableDeclaration', name, elementType, location: loc(start) };
  }

  // --- Expressions ---

  function parseExpression(): Expr {
    let expr = parseExpressionInner();

    // where clause: expr where name = expr, name2 = expr2
    if (at(TokenType.Where)) {
      const start = advance(); // 'where'
      const bindings: { name: string; value: Expr }[] = [];
      do {
        const bname = expect(TokenType.Identifier).value;
        expect(TokenType.Assign);
        bindings.push({ name: bname, value: parseExpressionInner() });
      } while (at(TokenType.Comma) && advance());
      expr = { kind: 'WhereExpression', body: expr, bindings, location: loc(start) };
    }

    return expr;
  }

  /** Parse an expression without consuming a trailing `where` clause. */
  function parseExpressionInner(): Expr {
    if (at(TokenType.If)) return parseIfExpression();
    if (at(TokenType.Match)) return parseMatchExpression();
    return parseInfixExpression(0);
  }

  function parseIfExpression(): Expr {
    const start = advance(); // 'if'
    const condition = parseExpressionInner();
    expectValue('then');
    const thenExpr = parseExpressionInner();
    const elseIfs: { condition: Expr; then: Expr }[] = [];
    while (at(TokenType.Else) && peek(1).type === TokenType.If) {
      advance(); // 'else'
      advance(); // 'if'
      const eiCond = parseExpressionInner();
      expectValue('then');
      const eiThen = parseExpressionInner();
      elseIfs.push({ condition: eiCond, then: eiThen });
    }
    expect(TokenType.Else);
    const elseExpr = parseExpressionInner();
    return { kind: 'IfExpression', condition, then: thenExpr, elseIfs, else: elseExpr, location: loc(start) };
  }

  function parseMatchExpression(): MatchExpr {
    const start = advance(); // 'match'

    // match binding in iterable { ... } — iteration form
    if (at(TokenType.Identifier) && peek(1).type === TokenType.In) {
      const binding = advance().value; // binding name
      advance(); // 'in'
      const iterable = parseInfixExpression(0);
      expect(TokenType.LBrace);
      const arms: MatchArm[] = [];
      while (!at(TokenType.RBrace) && !at(TokenType.EOF)) {
        const before = pos;
        const pattern = parsePattern();
        expect(TokenType.Arrow);
        const expression = parseExpression();
        arms.push({ pattern, expression });
        if (at(TokenType.Comma)) advance();
        if (!guardProgress(before)) break;
      }
      expect(TokenType.RBrace);
      return { kind: 'MatchExpression', binding, iterable, arms, location: loc(start) };
    }

    // Standard match: subject or subjectless
    let subject: Expr | undefined;
    if (!at(TokenType.LBrace)) {
      // Tuple subject: match (a, b, c) { ... }
      if (at(TokenType.LParen)) {
        const tupleStart = current();
        advance(); // '('
        const first = parseExpression();
        if (at(TokenType.Comma)) {
          // It's a tuple
          const elements: Expr[] = [first];
          while (at(TokenType.Comma)) {
            advance();
            elements.push(parseExpression());
          }
          expect(TokenType.RParen);
          subject = { kind: 'ListLiteral', elements, location: loc(tupleStart) };
        } else {
          // Single parenthesized expression
          expect(TokenType.RParen);
          subject = { kind: 'ParenExpression', expression: first, location: loc(tupleStart) };
        }
      } else {
        subject = parseInfixExpression(0);
      }
    }
    expect(TokenType.LBrace);
    const arms: MatchArm[] = [];
    while (!at(TokenType.RBrace) && !at(TokenType.EOF)) {
      const before = pos;
      const pattern = parsePattern();
      expect(TokenType.Arrow);
      const expression = parseExpression();
      arms.push({ pattern, expression });
      if (at(TokenType.Comma)) advance();
      if (!guardProgress(before)) break;
    }
    expect(TokenType.RBrace);
    return { kind: 'MatchExpression', subject, arms, location: loc(start) };
  }

  // --- Pratt parser for infix expressions ---

  const PRECEDENCE: Record<string, number> = {
    '||': 1,
    '&&': 2,
    '==': 3, '!=': 3,
    '<': 4, '>': 4, '<=': 4, '>=': 4,
    'in': 4, 'not in': 4,
    '+': 5, '-': 5,
    '*': 6, '/': 6, '%': 6,
    '**': 7,
  };

  function getInfixOp(): { op: string; prec: number } | null {
    // 'not in' as two-token operator
    if (at(TokenType.Not) && peek(1).type === TokenType.In) {
      return { op: 'not in', prec: PRECEDENCE['not in'] };
    }
    if (at(TokenType.In)) return { op: 'in', prec: PRECEDENCE['in'] };

    const opMap: Partial<Record<TokenType, string>> = {
      [TokenType.Plus]: '+', [TokenType.Minus]: '-',
      [TokenType.Star]: '*', [TokenType.Slash]: '/',
      [TokenType.Percent]: '%', [TokenType.StarStar]: '**',
      [TokenType.Eq]: '==', [TokenType.NotEq]: '!=',
      [TokenType.Lt]: '<', [TokenType.Gt]: '>',
      [TokenType.LtEq]: '<=', [TokenType.GtEq]: '>=',
      [TokenType.And]: '&&', [TokenType.Or]: '||',
    };
    const op = opMap[current().type];
    if (op) return { op, prec: PRECEDENCE[op] };
    return null;
  }

  function parseInfixExpression(minPrec: number): Expr {
    let left = parseUnaryExpression();

    while (true) {
      const inf = getInfixOp();
      if (!inf || inf.prec < minPrec) break;

      const start = current();
      if (inf.op === 'not in') { advance(); advance(); }
      else advance();

      // Right-associative for **
      const nextPrec = inf.op === '**' ? inf.prec : inf.prec + 1;
      const right = parseInfixExpression(nextPrec);
      left = { kind: 'InfixExpression', left, operator: inf.op, right, location: loc(start) };
    }

    return left;
  }

  function parseUnaryExpression(): Expr {
    if (at(TokenType.Not) && peek(1).type !== TokenType.In) {
      const start = advance();
      const operand = parseUnaryExpression();
      return { kind: 'UnaryExpression', operator: 'not', operand, location: loc(start) };
    }
    if (at(TokenType.Bang)) {
      const start = advance();
      const operand = parseUnaryExpression();
      return { kind: 'UnaryExpression', operator: '!', operand, location: loc(start) };
    }
    if (at(TokenType.Minus)) {
      const start = advance();
      const operand = parseUnaryExpression();
      return { kind: 'UnaryExpression', operator: '-', operand, location: loc(start) };
    }
    return parsePostfixExpression();
  }

  function parsePostfixExpression(): Expr {
    let expr = parsePrimary();

    while (true) {
      if (at(TokenType.Dot)) {
        advance();
        const prop = expect(TokenType.Identifier).value;
        expr = { kind: 'MemberExpression', object: expr, property: prop, location: expr.location };
      } else if (at(TokenType.LBracket)) {
        advance();
        const index = parseExpression();
        expect(TokenType.RBracket);
        expr = { kind: 'IndexExpression', object: expr, index, location: expr.location };
      } else if (at(TokenType.As)) {
        advance();
        const targetType = parseTypeAnnotation();
        expr = { kind: 'CoercionExpression', expression: expr, targetType, location: expr.location };
      } else {
        break;
      }
    }

    return expr;
  }

  function parsePrimary(): Expr {
    const start = current();

    // any PATTERN in EXPR
    if (at(TokenType.Any)) {
      advance();
      const pattern = parsePattern(true);
      expectValue('in');
      const list = parseInfixExpression(0);
      return { kind: 'AnyExpression', pattern, list, location: loc(start) };
    }

    // all PATTERN in EXPR
    if (at(TokenType.All)) {
      advance();
      const pattern = parsePattern(true);
      expectValue('in');
      const list = parseInfixExpression(0);
      return { kind: 'AllExpression', pattern, list, location: loc(start) };
    }

    // collect PATTERN in EXPR => BODY   (pattern form — destructure/filter)
    // collect BINDING in EXPR => BODY   (map form — bind each element, transform all)
    // collect BINDING in EXPR { ARMS }  (binding form — filter + transform with arms)
    if (at(TokenType.Collect)) {
      advance();

      // Detect binding/map form: collect identifier in expr ...
      if (at(TokenType.Identifier) && peek(1).type === TokenType.In) {
        const identTok = advance();
        advance(); // 'in'
        const list = parseInfixExpression(0);
        if (at(TokenType.LBrace)) {
          // Binding form with arms: collect row in list { condition => body, ... }
          expect(TokenType.LBrace);
          const arms: { pattern: Pattern; body: Expr }[] = [];
          while (!at(TokenType.RBrace) && !at(TokenType.EOF)) {
            const before = pos;
            const armPattern = parsePattern();
            expect(TokenType.Arrow);
            const armBody = parseExpression();
            arms.push({ pattern: armPattern, body: armBody });
            if (at(TokenType.Comma)) advance();
            if (!guardProgress(before)) break;
          }
          expect(TokenType.RBrace);
          return { kind: 'CollectExpression', list, binding: identTok.value, arms, location: loc(start) };
        }
        // Map form: collect ident in list => body (bind each element, transform all)
        expect(TokenType.Arrow);
        const body = parseExpression();
        const wildcardArm: { pattern: Pattern; body: Expr } = {
          pattern: { kind: 'WildcardPattern', location: identTok.location },
          body,
        };
        return { kind: 'CollectExpression', list, binding: identTok.value, arms: [wildcardArm], location: loc(start) };
      }

      const pattern = parsePattern(true);
      expectValue('in');
      const list = parseInfixExpression(0);
      expect(TokenType.Arrow);
      const body = parseExpression();
      return { kind: 'CollectExpression', pattern, list, body, location: loc(start) };
    }

    // Nested if/match
    if (at(TokenType.If)) return parseIfExpression();
    if (at(TokenType.Match)) return parseMatchExpression();

    // Plugin literal (e.g., money: £100, GBP50.25)
    if (at(TokenType.PluginLiteral)) {
      const tok = advance();
      return { kind: 'PluginLiteral', tag: tok.tag!, value: tok.payload, displayValue: tok.value, location: tok.location };
    }

    // Number literal
    if (at(TokenType.Number)) {
      const tok = advance();
      return { kind: 'Literal', value: parseFloat(tok.value), raw: tok.value, location: tok.location };
    }

    // String literal
    if (at(TokenType.String)) {
      const tok = advance();
      return { kind: 'Literal', value: tok.value, raw: `"${tok.value}"`, location: tok.location };
    }

    // Bool literal
    if (at(TokenType.Bool)) {
      const tok = advance();
      return { kind: 'Literal', value: tok.value === 'true', raw: tok.value, location: tok.location };
    }

    // List literal
    if (at(TokenType.LBracket)) {
      advance();
      const elements: Expr[] = [];
      while (!at(TokenType.RBracket) && !at(TokenType.EOF)) {
        const before = pos;
        elements.push(parseExpression());
        if (!at(TokenType.RBracket)) expect(TokenType.Comma);
        if (!guardProgress(before)) break;
      }
      expect(TokenType.RBracket);
      return { kind: 'ListLiteral', elements, location: loc(start) };
    }

    // Parenthesized expression or range pattern used as expression
    if (at(TokenType.LParen)) {
      advance();
      const inner = parseExpression();
      expect(TokenType.RParen);
      return { kind: 'ParenExpression', expression: inner, location: loc(start) };
    }

    // Identifier, call, variant construction, aggregate collect, or dict
    if (at(TokenType.Identifier)) {
      // Look ahead for aggregate collect: IDENT collect ...
      if (peek(1).type === TokenType.Collect) {
        const aggName = advance().value;
        advance(); // 'collect'

        // Detect binding form: agg collect identifier in expr { arms }
        let binding: string | undefined;
        if (at(TokenType.Identifier) && peek(1).type === TokenType.In) {
          binding = advance().value;
        }

        expectValue('in');
        const list = parseInfixExpression(0);
        // Map form: agg collect ident in list => body
        if (binding && at(TokenType.Arrow)) {
          advance(); // '=>'
          const mapBody = parseExpression();
          const wildcardArm: { pattern: Pattern; body: Expr } = {
            pattern: { kind: 'WildcardPattern', location: loc(start) },
            body: mapBody,
          };
          return { kind: 'AggregateCollectExpression', aggregator: aggName, list, arms: [wildcardArm], binding, location: loc(start) };
        }
        // Arms form: agg collect ... in list { arms }
        expect(TokenType.LBrace);
        const arms: { pattern: Pattern; body: Expr }[] = [];
        while (!at(TokenType.RBrace) && !at(TokenType.EOF)) {
          const before = pos;
          const pattern = parsePattern();
          expect(TokenType.Arrow);
          const body = parseExpression();
          arms.push({ pattern, body });
          if (at(TokenType.Comma)) advance();
          if (!guardProgress(before)) break;
        }
        expect(TokenType.RBrace);
        return { kind: 'AggregateCollectExpression', aggregator: aggName, list, arms, binding, location: loc(start) };
      }

      // Resolve qualified name: a.b.c
      // Only consume dots when this is clearly a qualified name for a call/variant/type,
      // NOT member access like quote.field
      let name = advance().value;
      while (at(TokenType.Dot) && peek(1).type === TokenType.Identifier) {
        const savedPos = pos;
        advance(); // '.'
        const next = advance().value;
        const tentativeName = name + '.' + next;

        if (at(TokenType.Dot) && peek(1).type === TokenType.Identifier) {
          // More dots coming — keep going, might be a.b.c.Tag
          name = tentativeName;
          continue;
        }
        if (at(TokenType.LParen)) {
          // Qualified call: Name.Sub(...)
          name = tentativeName;
          break;
        }
        if (at(TokenType.LBrace)) {
          // Could be variant construction: tag { ... }
          // or member access followed by something else: expr.field { match body }
          // Peek inside braces: variant/dict has IDENT ':' or is empty '{}'
          if (looksLikeVariantOrDict()) {
            name = tentativeName;
            break;
          }
          // Not a variant — this is member access, roll back
          pos = savedPos;
          break;
        }
        // No call/variant follows — it's member access, roll back
        pos = savedPos;
        break;
      }

      // Call expression: Name(...)
      if (at(TokenType.LParen)) {
        advance();
        const args: Expr[] = [];
        const namedArgs: Record<string, Expr> = {};
        const allArgs: Expr[] = [];
        let spread = false;
        // Look ahead for spread or named args — enables shorthand named args
        let hasNamedOrSpread = false;
        for (let j = pos; j < tokens.length; j++) {
          if (tokens[j].type === TokenType.RParen || tokens[j].type === TokenType.EOF) break;
          if (tokens[j].type === TokenType.Spread) { hasNamedOrSpread = true; break; }
          if (tokens[j].type === TokenType.Identifier && j + 1 < tokens.length && tokens[j + 1].type === TokenType.Colon) { hasNamedOrSpread = true; break; }
        }
        while (!at(TokenType.RParen) && !at(TokenType.EOF)) {
          const before = pos;
          // Spread: ... fills remaining params from scope
          if (at(TokenType.Spread)) {
            advance();
            spread = true;
          }
          // Check for named arg: IDENT ':'
          else if (at(TokenType.Identifier) && peek(1).type === TokenType.Colon) {
            const argName = advance().value;
            advance(); // ':'
            const argExpr = parseExpression();
            namedArgs[argName] = argExpr;
            allArgs.push(argExpr);
          }
          // Shorthand named arg: bare IDENT followed by , or ) — only with named args or spread
          else if (hasNamedOrSpread && at(TokenType.Identifier) && (peek(1).type === TokenType.Comma || peek(1).type === TokenType.RParen)) {
            const tok = advance();
            const argExpr: Expr = { kind: 'Identifier', name: tok.value, location: tok.location };
            namedArgs[tok.value] = argExpr;
            allArgs.push(argExpr);
          }
          else {
            const argExpr = parseExpression();
            args.push(argExpr);
            allArgs.push(argExpr);
          }
          if (!at(TokenType.RParen)) expect(TokenType.Comma);
          if (!guardProgress(before)) break;
        }
        expect(TokenType.RParen);
        const node: any = { kind: 'CallExpression', callee: name, args, namedArgs, allArgs, location: loc(start) };
        if (spread) node.spread = true;
        return node;
      }

      // Variant construction or dict: Name { ... }
      if (at(TokenType.LBrace) && looksLikeVariantOrDict()) {
        advance(); // '{'

        // Empty braces = variant with empty payload
        if (at(TokenType.RBrace)) {
          advance();
          const typeParts = name.split('.');
          const tag = typeParts.pop()!;
          const typeName = typeParts.length > 0 ? typeParts.join('.') : undefined;
          return { kind: 'VariantConstruction', typeName, tag, entries: [], location: loc(start) };
        }

        // It's key:value — variant or dict
        {
          const entries: { key: string; value: Expr }[] = [];
          while (!at(TokenType.RBrace) && !at(TokenType.EOF)) {
            const before = pos;
            const key = expect(TokenType.Identifier).value;
            // Shorthand: bare identifier without ':' means key: key
            if (at(TokenType.Comma) || at(TokenType.RBrace)) {
              entries.push({ key, value: { kind: 'Identifier', name: key, location: tokens[pos - 1].location } });
            } else {
              expect(TokenType.Colon);
              const value = parseExpression();
              entries.push({ key, value });
            }
            if (at(TokenType.Comma)) advance();
            if (!guardProgress(before)) break;
          }
          expect(TokenType.RBrace);

          const typeParts = name.split('.');
          const tag = typeParts.pop()!;
          const typeName = typeParts.length > 0 ? typeParts.join('.') : undefined;
          return { kind: 'VariantConstruction', typeName, tag, entries, location: loc(start) };
        }
      }

      // Plain identifier
      return { kind: 'Identifier', name, location: start.location };
    }

    // Dict literal without a preceding identifier: { key: value, ... }
    if (at(TokenType.LBrace)) {
      advance();
      const entries: { key: string; value: Expr }[] = [];
      while (!at(TokenType.RBrace) && !at(TokenType.EOF)) {
        const before = pos;
        let key: string;
        if (at(TokenType.String)) {
          key = advance().value;
        } else {
          key = expect(TokenType.Identifier).value;
        }
        // Shorthand: bare identifier without ':' means key: key
        if (!key.startsWith('"') && (at(TokenType.Comma) || at(TokenType.RBrace))) {
          entries.push({ key, value: { kind: 'Identifier', name: key, location: tokens[pos - 1].location } });
        } else {
          expect(TokenType.Colon);
          const value = parseExpression();
          entries.push({ key, value });
        }
        if (at(TokenType.Comma)) advance();
        if (!guardProgress(before)) break;
      }
      expect(TokenType.RBrace);
      return { kind: 'DictLiteral', entries, location: loc(start) };
    }

    diagnostics.push(error('parse.unexpected_token', `Unexpected token '${current().value}'`, current().location));
    advance();
    return { kind: 'Literal', value: 0, raw: '0', location: start.location };
  }

  // --- Patterns ---

  function parsePattern(inCollectionForm: boolean = false): Pattern {
    const start = current();
    const first = parseSinglePattern(inCollectionForm);
    if (!at(TokenType.Pipe)) return first;
    const patterns: Pattern[] = [first];
    while (at(TokenType.Pipe)) {
      advance();
      patterns.push(parseSinglePattern(inCollectionForm));
    }
    return { kind: 'AlternativePattern', patterns, location: loc(start) };
  }

  function parseSinglePattern(inCollectionForm: boolean = false): Pattern {
    const start = current();

    // Wildcard
    if (at(TokenType.Underscore)) {
      advance();
      return { kind: 'WildcardPattern', location: start.location };
    }

    // Range pattern: (lo..hi] or [lo..hi) etc.
    if (at(TokenType.LParen) || at(TokenType.LBracket)) {
      const mightBeRange = tryParseRangePattern();
      if (mightBeRange) return mightBeRange;
    }

    // Tuple pattern: (pat1, pat2, ...)
    if (at(TokenType.LParen)) {
      const tupleStart = current();
      advance(); // '('
      const elements: Pattern[] = [parsePattern()];
      while (at(TokenType.Comma)) {
        advance();
        elements.push(parsePattern());
      }
      expect(TokenType.RParen);
      if (elements.length >= 2) {
        return { kind: 'TuplePattern', elements, location: loc(tupleStart) };
      }
      // Single element — treat as the inner pattern
      return elements[0];
    }

    // Variant pattern or literal pattern: IDENT { bindings }
    if (at(TokenType.Identifier)) {
      // Resolve qualified: a.b.Tag
      let name = current().value;
      const savedPos = pos;
      advance();

      while (at(TokenType.Dot) && peek(1).type === TokenType.Identifier) {
        advance();
        name += '.' + advance().value;
      }

      if (at(TokenType.LBrace)) {
        // Variant pattern with field bindings
        advance();
        const bindings: Record<string, string | null> = {};
        while (!at(TokenType.RBrace) && !at(TokenType.EOF)) {
          const before = pos;
          const fieldName = expect(TokenType.Identifier).value;
          if (at(TokenType.Colon)) {
            advance();
            if (at(TokenType.Underscore)) {
              advance();
              bindings[fieldName] = null; // wildcard binding
            } else {
              bindings[fieldName] = expect(TokenType.Identifier).value;
            }
          } else {
            bindings[fieldName] = fieldName; // shorthand: name binds to name
          }
          if (at(TokenType.Comma)) advance();
          if (!guardProgress(before)) break;
        }
        expect(TokenType.RBrace);

        const parts = name.split('.');
        const tag = parts.pop()!;
        const typeName = parts.length > 0 ? parts.join('.') : undefined;
        return { kind: 'VariantPattern', typeName, tag, bindings, location: loc(start) };
      }

      // Bare tag pattern: `referred` without braces, in pattern-terminating context
      // Only treat `in` as a terminator inside collection forms (any/all/collect),
      // not in match arms where `x in [list]` is a valid expression pattern.
      const inTerminates = inCollectionForm && at(TokenType.In);
      if (inTerminates || at(TokenType.Arrow) || at(TokenType.Comma) || at(TokenType.RBrace)) {
        const parts = name.split('.');
        const tag = parts.pop()!;
        const typeName = parts.length > 0 ? parts.join('.') : undefined;
        return { kind: 'VariantPattern', typeName, tag, bindings: {}, location: loc(start) };
      }

      // Not a variant pattern — might be an expression pattern
      pos = savedPos;
    }

    // Number literal pattern
    if (at(TokenType.Number)) {
      const tok = advance();
      return { kind: 'LiteralPattern', value: parseFloat(tok.value), raw: tok.value, location: tok.location };
    }

    // String literal pattern
    if (at(TokenType.String)) {
      const tok = advance();
      return { kind: 'LiteralPattern', value: tok.value, raw: `"${tok.value}"`, location: tok.location };
    }

    // Bool literal pattern
    if (at(TokenType.Bool)) {
      const tok = advance();
      return { kind: 'LiteralPattern', value: tok.value === 'true', raw: tok.value, location: tok.location };
    }

    // Expression pattern (fallback)
    const expr = parseInfixExpression(0);
    return { kind: 'ExpressionPattern', expression: expr, location: expr.location };
  }

  function tryParseRangePattern(): Pattern | null {
    const saved = pos;
    const start = current();
    const openLeft = at(TokenType.LParen); // ( = exclusive
    advance(); // ( or [

    let left: number | undefined;
    let right: number | undefined;

    // Optional left number
    if (at(TokenType.Number)) {
      left = parseFloat(advance().value);
    }

    // Expect ..
    if (!at(TokenType.DotDot)) { pos = saved; return null; }
    advance(); // ..

    // Optional right number
    if (at(TokenType.Number)) {
      right = parseFloat(advance().value);
    }

    // At least one bound must be present
    if (left === undefined && right === undefined) { pos = saved; return null; }

    const openRight = at(TokenType.RParen); // ) = exclusive
    if (!at(TokenType.RBracket) && !at(TokenType.RParen)) { pos = saved; return null; }
    advance(); // ] or )

    return {
      kind: 'RangePattern',
      openLeft,
      openRight,
      left,
      right,
      location: loc(start),
    };
  }

  const ast = parseProgram();
  return { ast, diagnostics };
}
