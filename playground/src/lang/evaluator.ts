import {
  ProgramNode, ExpressionDeclaration, SourceDeclaration, TableDeclaration, Expr, Pattern, MatchArm,
} from './ast';

type Value = number | string | boolean | ValueArray | ValueRecord | null;
interface ValueArray extends Array<Value> {}
interface ValueRecord { [key: string]: Value }

interface Scope {
  vars: Map<string, Value>;
  parent?: Scope;
}

function scopeGet(scope: Scope, name: string): Value | undefined {
  const val = scope.vars.get(name);
  if (val !== undefined) return val;
  if (scope.parent) return scopeGet(scope.parent, name);
  return undefined;
}

function childScope(parent: Scope): Scope {
  return { vars: new Map(), parent };
}

/** Extract numeric values from a list or dict. */
function numericValues(v: Value): number[] {
  if (Array.isArray(v)) return v as number[];
  if (v && typeof v === 'object') return Object.values(v) as number[];
  return [];
}

// Built-in intrinsic functions
const INTRINSICS: Record<string, (...args: Value[]) => Value> = {
  round: (n, decimals) => {
    const d = typeof decimals === 'number' ? decimals : 0;
    const factor = Math.pow(10, d);
    return Math.round((n as number) * factor) / factor;
  },
  len: (list) => {
    if (Array.isArray(list)) return list.length;
    if (list && typeof list === 'object') return Object.keys(list).length;
    return 0;
  },
  flatten: (list) => {
    if (!Array.isArray(list)) return [];
    return list.flat();
  },
  product: (list) => {
    const vals = numericValues(list);
    return vals.reduce((a, b) => a * b, 1);
  },
  sum: (list) => {
    const vals = numericValues(list);
    return vals.reduce((a, b) => a + b, 0);
  },
  sum_money: (list) => {
    const vals = numericValues(list);
    return vals.reduce((a, b) => a + b, 0);
  },
  max: (...args) => {
    if (args.length === 1 && Array.isArray(args[0])) {
      return (args[0] as number[]).length === 0 ? 0 : Math.max(...(args[0] as number[]));
    }
    return Math.max(...(args as number[]));
  },
  min: (...args) => {
    if (args.length === 1 && Array.isArray(args[0])) {
      return (args[0] as number[]).length === 0 ? 0 : Math.min(...(args[0] as number[]));
    }
    return Math.min(...(args as number[]));
  },
};

export interface EvalResult {
  value: Value;
  error?: string;
}

export function evaluate(
  ast: ProgramNode,
  expressionName: string,
  inputData: Record<string, Value>,
  sourceData?: Record<string, Record<string, Value>>,
  tableData?: Record<string, Value[]>,
  plugins?: import('./plugin').AxiomPlugin[],
): EvalResult {
  try {
    // Collect expression declarations (top-level and namespaced)
    const exprDecls = new Map<string, ExpressionDeclaration>();
    const srcDecls = new Map<string, SourceDeclaration>();
    const tableDeclNames = new Set<string>();
    for (const decl of ast.body) {
      if (decl.kind === 'ExpressionDeclaration') {
        exprDecls.set(decl.name, decl);
      }
      if (decl.kind === 'SourceDeclaration') {
        srcDecls.set(decl.name, decl);
      }
      if (decl.kind === 'TableDeclaration') {
        tableDeclNames.add(decl.name);
      }
      if (decl.kind === 'NamespaceDeclaration') {
        for (const expr of decl.expressions) {
          exprDecls.set(`${decl.name}.${expr.name}`, expr);
        }
        for (const src of decl.sources) {
          srcDecls.set(`${decl.name}.${src.name}`, src);
        }
      }
    }

    // Register payload-less variant tags as constants
    const rootScope: Scope = { vars: new Map() };
    for (const decl of ast.body) {
      const typeDecls = decl.kind === 'TypeDeclaration' ? [decl]
        : decl.kind === 'NamespaceDeclaration' ? decl.types : [];
      for (const td of typeDecls) {
        for (const alt of td.alternatives) {
          if (Object.keys(alt.shape).length === 0) {
            rootScope.vars.set(alt.tag, { _tag: alt.tag });
          }
        }
      }
    }

    // Collect namespace constants
    const namespaceValues = new Map<string, Value>();
    for (const decl of ast.body) {
      if (decl.kind === 'NamespaceDeclaration') {
        for (const sym of decl.symbols) {
          const val = evalExpr(sym.value, rootScope);
          namespaceValues.set(`${decl.name}.${sym.name}`, val);
          rootScope.vars.set(`${decl.name}.${sym.name}`, val);
        }
      }
    }

    // Register table data into root scope
    if (tableData) {
      for (const name of tableDeclNames) {
        const data = tableData[name];
        if (data) {
          rootScope.vars.set(name, data as unknown as Value);
        }
      }
    }

    // Track current namespace for unqualified sibling resolution
    let currentNamespace: string | undefined;

    const targetDecl = exprDecls.get(expressionName);
    if (!targetDecl) {
      return { value: null, error: `Expression '${expressionName}' not found` };
    }

    // Build scope from input data
    const scope: Scope = { vars: new Map(rootScope.vars), parent: undefined };
    for (const param of targetDecl.params) {
      const val = inputData[param.name];
      if (val !== undefined) {
        scope.vars.set(param.name, val);
      } else {
        return { value: null, error: `Missing input parameter '${param.name}'` };
      }
    }

    const value = evalExpr(targetDecl.body, scope);
    return { value };

    function evalExpr(expr: Expr, scope: Scope): Value {
      switch (expr.kind) {
        case 'Literal':
          return expr.value;

        case 'PluginLiteral':
          return expr.value as Value;

        case 'Identifier': {
          const val = scopeGet(scope, expr.name);
          if (val !== undefined) return val;
          // Check namespace constants
          const nsVal = namespaceValues.get(expr.name);
          if (nsVal !== undefined) return nsVal;
          // Try qualifying with current namespace
          if (currentNamespace) {
            const qualVal = namespaceValues.get(`${currentNamespace}.${expr.name}`);
            if (qualVal !== undefined) return qualVal;
          }
          // Auto-evaluate parameterless expressions referenced by name
          const paramlessDecl = exprDecls.get(expr.name)
            ?? (currentNamespace ? exprDecls.get(`${currentNamespace}.${expr.name}`) : undefined);
          if (paramlessDecl && paramlessDecl.params.length === 0) {
            const prevNs = currentNamespace;
            const dotIdx = expr.name.lastIndexOf('.');
            currentNamespace = dotIdx >= 0 ? expr.name.substring(0, dotIdx) : currentNamespace;
            const result = evalExpr(paramlessDecl.body, childScope(scope));
            currentNamespace = prevNs;
            return result;
          }
          throw new Error(`Undefined variable '${expr.name}'`);
        }

        case 'MemberExpression': {
          const obj = evalExpr(expr.object, scope);
          if (obj && typeof obj === 'object' && !Array.isArray(obj)) {
            return (obj as Record<string, Value>)[expr.property] ?? null;
          }
          throw new Error(`Cannot access property '${expr.property}' on ${typeof obj}`);
        }

        case 'IndexExpression': {
          const obj = evalExpr(expr.object, scope);
          const idx = evalExpr(expr.index, scope);
          if (Array.isArray(obj) && typeof idx === 'number') {
            return obj[idx] ?? null;
          }
          if (obj && typeof obj === 'object' && typeof idx === 'string') {
            return (obj as Record<string, Value>)[idx] ?? null;
          }
          return null;
        }

        case 'InfixExpression': {
          // Short-circuit for && and ||
          if (expr.operator === '&&') {
            const left = evalExpr(expr.left, scope);
            if (!left) return false;
            return !!evalExpr(expr.right, scope);
          }
          if (expr.operator === '||') {
            const left = evalExpr(expr.left, scope);
            if (left) return true;
            return !!evalExpr(expr.right, scope);
          }

          const left = evalExpr(expr.left, scope);
          const right = evalExpr(expr.right, scope);

          // Let plugins handle operator evaluation first
          if (plugins) {
            for (const plugin of plugins) {
              if (plugin.evaluator?.supportsOp?.(left, right, expr.operator)) {
                return plugin.evaluator.evaluateOp!(left, right, expr.operator) as Value;
              }
            }
          }

          switch (expr.operator) {
            case '+': return (left as number) + (right as number);
            case '-': return (left as number) - (right as number);
            case '*': return (left as number) * (right as number);
            case '/': return (right as number) === 0 ? 0 : (left as number) / (right as number);
            case '%': return (left as number) % (right as number);
            case '**': return Math.pow(left as number, right as number);
            case '==': return left === right;
            case '!=': return left !== right;
            case '<': return (left as number) < (right as number);
            case '>': return (left as number) > (right as number);
            case '<=': return (left as number) <= (right as number);
            case '>=': return (left as number) >= (right as number);
            case 'in': {
              if (Array.isArray(right)) return right.includes(left);
              return false;
            }
            case 'not in': {
              if (Array.isArray(right)) return !right.includes(left);
              return true;
            }
            default:
              throw new Error(`Unknown operator '${expr.operator}'`);
          }
        }

        case 'UnaryExpression': {
          const operand = evalExpr(expr.operand, scope);
          switch (expr.operator) {
            case '-': return -(operand as number);
            case 'not':
            case '!': return !operand;
            default: return operand;
          }
        }

        case 'CoercionExpression': {
          const val = evalExpr(expr.expression, scope);
          const target = expr.targetType.keyword;
          if (target === 'number' || target === 'money') {
            if (typeof val === 'string') {
              // Strip currency suffixes, percentage signs
              const cleaned = val.replace(/[^0-9.\-]/g, '');
              return parseFloat(cleaned) || 0;
            }
            return typeof val === 'number' ? val : 0;
          }
          if (target === 'string') {
            return String(val);
          }
          return val;
        }

        case 'IfExpression': {
          const cond = evalExpr(expr.condition, scope);
          if (cond) return evalExpr(expr.then, scope);
          for (const ei of expr.elseIfs) {
            const eiCond = evalExpr(ei.condition, scope);
            if (eiCond) return evalExpr(ei.then, scope);
          }
          return evalExpr(expr.else, scope);
        }

        case 'MatchExpression': {
          // match binding in iterable { ... } — iteration form
          if (expr.binding && expr.iterable) {
            const list = evalExpr(expr.iterable, scope);
            if (!Array.isArray(list)) throw new Error('match-in requires a list');
            let fallbackArm: MatchArm | undefined;
            for (const arm of expr.arms) {
              if (arm.pattern.kind === 'WildcardPattern') {
                fallbackArm = arm;
                continue;
              }
            }
            // Iterate over list, try non-wildcard arms for each element
            for (const elem of list) {
              const elemScope = childScope(scope);
              elemScope.vars.set(expr.binding, elem);
              for (const arm of expr.arms) {
                if (arm.pattern.kind === 'WildcardPattern') continue;
                const bindings = matchPattern(arm.pattern, null, elemScope);
                if (bindings !== null) {
                  for (const [k, v] of Object.entries(bindings)) {
                    elemScope.vars.set(k, v);
                  }
                  return evalExpr(arm.expression, elemScope);
                }
              }
            }
            // No element matched — use wildcard fallback
            if (fallbackArm) {
              return evalExpr(fallbackArm.expression, scope);
            }
            throw new Error('No matching element in match-in expression');
          }

          // Standard match
          const subject = expr.subject ? evalExpr(expr.subject, scope) : null;
          for (const arm of expr.arms) {
            const bindings = matchPattern(arm.pattern, subject, scope);
            if (bindings !== null) {
              const armScope = childScope(scope);
              for (const [k, v] of Object.entries(bindings)) {
                armScope.vars.set(k, v);
              }
              return evalExpr(arm.expression, armScope);
            }
          }
          throw new Error('No matching arm in match expression');
        }

        case 'CallExpression': {
          // Resolve callee: try direct, then qualify with current namespace
          let qualifiedCallee = expr.callee;
          let decl = exprDecls.get(qualifiedCallee);
          if (!decl && currentNamespace) {
            qualifiedCallee = `${currentNamespace}.${expr.callee}`;
            decl = exprDecls.get(qualifiedCallee);
          }
          if (decl) {
            const callScope = childScope(scope);
            // Bind positional args
            const positionallyBound = new Set<string>();
            for (let i = 0; i < expr.args.length && i < decl.params.length; i++) {
              callScope.vars.set(decl.params[i].name, evalExpr(expr.args[i], scope));
              positionallyBound.add(decl.params[i].name);
            }
            // Bind named args
            const namedArgNames = new Set(Object.keys(expr.namedArgs));
            for (const [name, argExpr] of Object.entries(expr.namedArgs)) {
              callScope.vars.set(name, evalExpr(argExpr, scope));
            }
            // Spread: fill remaining params from caller scope by matching name
            if (expr.spread) {
              for (const param of decl.params) {
                if (!positionallyBound.has(param.name) && !namedArgNames.has(param.name)) {
                  const val = scopeGet(scope, param.name);
                  if (val !== undefined) {
                    callScope.vars.set(param.name, val);
                  }
                }
              }
            }
            // Copy namespace constants
            for (const [k, v] of namespaceValues) {
              callScope.vars.set(k, v);
            }
            // Set namespace context from the resolved callee
            const prevNamespace = currentNamespace;
            const dotIdx = qualifiedCallee.lastIndexOf('.');
            currentNamespace = dotIdx >= 0 ? qualifiedCallee.substring(0, dotIdx) : undefined;
            const result = evalExpr(decl.body, callScope);
            currentNamespace = prevNamespace;
            return result;
          }

          // Source declarations — lookup in provided source data
          {
            let srcCallee = expr.callee;
            let srcDecl = srcDecls.get(srcCallee);
            if (!srcDecl && currentNamespace) {
              srcCallee = `${currentNamespace}.${expr.callee}`;
              srcDecl = srcDecls.get(srcCallee);
            }
            if (srcDecl && sourceData) {
              const data = sourceData[srcCallee];
              if (!data) throw new Error(`No source data provided for '${srcCallee}'`);
              // Build lookup key from evaluated args
              const keyParts: string[] = [];
              // Bind positional args
              for (let i = 0; i < expr.args.length && i < srcDecl.params.length; i++) {
                keyParts.push(String(evalExpr(expr.args[i], scope)));
              }
              // Bind named args
              const positionalCount = Math.min(expr.args.length, srcDecl.params.length);
              for (const param of srcDecl.params.slice(positionalCount)) {
                const namedVal = expr.namedArgs[param.name];
                if (namedVal) {
                  keyParts.push(String(evalExpr(namedVal, scope)));
                } else if (expr.spread) {
                  const val = scopeGet(scope, param.name);
                  if (val !== undefined) keyParts.push(String(val));
                }
              }
              const key = keyParts.join('|');
              const result = data[key];
              if (result === undefined) throw new Error(`Source '${srcCallee}' has no entry for key '${key}'`);
              return result;
            }
          }

          // Plugin intrinsics (checked before built-ins, can override)
          if (plugins) {
            for (const plugin of plugins) {
              const pluginFn = plugin.evaluator?.intrinsics?.[expr.callee];
              if (pluginFn) {
                const args = (expr.allArgs || expr.args).map(a => evalExpr(a, scope));
                const result = pluginFn(...args);
                if (result !== undefined) return result as Value;
              }
            }
          }

          // Intrinsic functions
          const intrinsic = INTRINSICS[expr.callee];
          if (intrinsic) {
            // Use allArgs to preserve original source order
            const args = (expr.allArgs || expr.args).map(a => evalExpr(a, scope));
            return intrinsic(...args);
          }

          throw new Error(`Unknown function '${expr.callee}'`);
        }

        case 'ListLiteral':
          return expr.elements.map(e => evalExpr(e, scope));

        case 'DictLiteral': {
          const result: Record<string, Value> = {};
          for (const entry of expr.entries) {
            result[entry.key] = evalExpr(entry.value, scope);
          }
          return result;
        }

        case 'VariantConstruction': {
          const result: Record<string, Value> = { _tag: expr.tag };
          for (const entry of expr.entries) {
            result[entry.key] = evalExpr(entry.value, scope);
          }
          return result;
        }

        case 'AnyExpression': {
          const coll = evalExpr(expr.list, scope);
          const items = iterableValues(coll);
          return items.some(item => matchPattern(expr.pattern, item, scope) !== null);
        }

        case 'AllExpression': {
          const coll = evalExpr(expr.list, scope);
          const items = iterableValues(coll);
          return items.every(item => matchPattern(expr.pattern, item, scope) !== null);
        }

        case 'CollectExpression': {
          const coll = evalExpr(expr.list, scope);

          // Binding form: collect row in list { condition => body, ... }
          if (expr.binding && expr.arms) {
            const items = iterableValues(coll);
            const result: Value[] = [];
            const wildcardArm = expr.arms.find(a => a.pattern.kind === 'WildcardPattern');
            for (const item of items) {
              const elemScope = childScope(scope);
              elemScope.vars.set(expr.binding, item);
              let matched = false;
              for (const arm of expr.arms) {
                if (arm.pattern.kind === 'WildcardPattern') continue;
                const bindings = matchPattern(arm.pattern, null, elemScope);
                if (bindings !== null) {
                  for (const [k, v] of Object.entries(bindings)) {
                    elemScope.vars.set(k, v);
                  }
                  result.push(evalExpr(arm.body, elemScope));
                  matched = true;
                  break;
                }
              }
              // Wildcard fallback: include element with default value
              if (!matched && wildcardArm) {
                result.push(evalExpr(wildcardArm.body, elemScope));
              }
            }
            return result;
          }

          // Standard form: collect pattern in list => body
          const isDict = coll && typeof coll === 'object' && !Array.isArray(coll);

          if (isDict) {
            // Collect from dict → dict, preserving keys
            const result: Record<string, Value> = {};
            for (const [key, item] of Object.entries(coll as Record<string, Value>)) {
              const bindings = matchPattern(expr.pattern!, item, scope);
              if (bindings !== null) {
                const itemScope = childScope(scope);
                for (const [k, v] of Object.entries(bindings)) {
                  itemScope.vars.set(k, v);
                }
                result[key] = evalExpr(expr.body!, itemScope);
              }
            }
            return result;
          }

          // Collect from list → list
          const items = iterableValues(coll);
          const result: Value[] = [];
          for (const item of items) {
            const bindings = matchPattern(expr.pattern!, item, scope);
            if (bindings !== null) {
              const itemScope = childScope(scope);
              for (const [k, v] of Object.entries(bindings)) {
                itemScope.vars.set(k, v);
              }
              result.push(evalExpr(expr.body!, itemScope));
            }
          }
          return result;
        }

        case 'AggregateCollectExpression': {
          const coll = evalExpr(expr.list, scope);
          const items = iterableValues(coll);
          const collected: Value[] = [];

          if (expr.binding) {
            // Binding form: agg collect row in list { condition => body, ... }
            const wildcardArm = expr.arms.find(a => a.pattern.kind === 'WildcardPattern');
            for (const item of items) {
              const elemScope = childScope(scope);
              elemScope.vars.set(expr.binding, item);
              let matched = false;
              for (const arm of expr.arms) {
                if (arm.pattern.kind === 'WildcardPattern') continue;
                const bindings = matchPattern(arm.pattern, null, elemScope);
                if (bindings !== null) {
                  for (const [k, v] of Object.entries(bindings)) {
                    elemScope.vars.set(k, v);
                  }
                  collected.push(evalExpr(arm.body, elemScope));
                  matched = true;
                  break;
                }
              }
              if (!matched && wildcardArm) {
                collected.push(evalExpr(wildcardArm.body, elemScope));
              }
            }
          } else {
            // Standard form: agg collect in list { pattern => body, ... }
            for (const item of items) {
              for (const arm of expr.arms) {
                const bindings = matchPattern(arm.pattern, item, scope);
                if (bindings !== null) {
                  const armScope = childScope(scope);
                  for (const [k, v] of Object.entries(bindings)) {
                    armScope.vars.set(k, v);
                  }
                  collected.push(evalExpr(arm.body, armScope));
                  break;
                }
              }
            }
          }

          // Apply aggregator
          const agg = INTRINSICS[expr.aggregator];
          if (agg) return agg(collected);
          throw new Error(`Unknown aggregator '${expr.aggregator}'`);
        }

        case 'ParenExpression':
          return evalExpr(expr.expression, scope);

        case 'WhereExpression': {
          const whereScope = childScope(scope);
          for (const binding of expr.bindings) {
            whereScope.vars.set(binding.name, evalExpr(binding.value, whereScope));
          }
          return evalExpr(expr.body, whereScope);
        }
      }
    }

    /** Extract iterable values from a list (array) or dict (object values). */
    function iterableValues(val: Value): Value[] {
      if (Array.isArray(val)) return val;
      if (val && typeof val === 'object') return Object.values(val);
      return [];
    }

    function matchPattern(
      pattern: Pattern,
      value: Value,
      scope: Scope,
    ): Record<string, Value> | null {
      switch (pattern.kind) {
        case 'WildcardPattern':
          return {};

        case 'LiteralPattern':
          return value === pattern.value ? {} : null;

        case 'VariantPattern': {
          if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
          const obj = value as Record<string, Value>;
          if (obj._tag !== pattern.tag) return null;
          const bindings: Record<string, Value> = {};
          for (const [field, alias] of Object.entries(pattern.bindings)) {
            if (!(field in obj)) return null;
            if (alias !== null) {
              bindings[alias] = obj[field];
            }
          }
          return bindings;
        }

        case 'ExpressionPattern': {
          // Evaluate the expression pattern as a condition
          // For subject-less match, the expression IS the condition
          if (value === null) {
            const condValue = evalExpr(pattern.expression, scope);
            return condValue ? {} : null;
          }
          const patternValue = evalExpr(pattern.expression, scope);
          return value === patternValue ? {} : null;
        }

        case 'RangePattern': {
          if (typeof value !== 'number') return null;
          const leftOk = pattern.left === undefined || (pattern.openLeft ? value > pattern.left : value >= pattern.left);
          const rightOk = pattern.right === undefined || (pattern.openRight ? value < pattern.right : value <= pattern.right);
          return leftOk && rightOk ? {} : null;
        }

        case 'AlternativePattern': {
          for (const alt of pattern.patterns) {
            const result = matchPattern(alt, value, scope);
            if (result !== null) return result;
          }
          return null;
        }

        case 'TuplePattern': {
          if (!Array.isArray(value)) return null;
          if (value.length !== pattern.elements.length) return null;
          const bindings: Record<string, Value> = {};
          for (let i = 0; i < pattern.elements.length; i++) {
            const result = matchPattern(pattern.elements[i], value[i], scope);
            if (result === null) return null;
            Object.assign(bindings, result);
          }
          return bindings;
        }
      }

      return null;
    }
  } catch (e) {
    return { value: null, error: e instanceof Error ? e.message : String(e) };
  }
}
