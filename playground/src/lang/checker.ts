import {
  ProgramNode, Declaration, ExpressionDeclaration, TypeDeclaration,
  SourceDeclaration, TableDeclaration, Expr, Pattern, TypeAnnotation, MatchArm,
} from './ast';
import { Diagnostic, Location, error, warning } from './diagnostics';
import {
  TypeSig, TYPE_NUMBER, TYPE_STRING, TYPE_BOOL, TYPE_MIXED,
  typeList, typeDict, typeVariant, typeMoney, typeToString, isAssignable,
} from './types';

interface ExprDeclInfo {
  decl: ExpressionDeclaration | SourceDeclaration;
  paramTypes: Record<string, TypeSig>;
  returnType?: TypeSig;
}

// Intrinsic function signatures: [paramTypes, returnType]
interface IntrinsicSig {
  params: { name: string; type: TypeSig }[];
  variadic?: boolean;
  returnType: TypeSig | 'from_arg';
}

const INTRINSICS: Record<string, IntrinsicSig> = {
  round:     { params: [{ name: 'value', type: TYPE_NUMBER }, { name: 'decimals', type: TYPE_NUMBER }], returnType: TYPE_NUMBER },
  len:       { params: [{ name: 'list', type: typeList() }], returnType: TYPE_NUMBER },
  flatten:   { params: [{ name: 'list', type: typeList() }], returnType: 'from_arg' },
  product:   { params: [{ name: 'collection', type: TYPE_MIXED }], returnType: TYPE_NUMBER },
  sum:       { params: [{ name: 'collection', type: TYPE_MIXED }], returnType: TYPE_NUMBER },
  sum_money: { params: [{ name: 'collection', type: TYPE_MIXED }], returnType: TYPE_NUMBER },
  max:       { params: [{ name: 'list', type: typeList(TYPE_NUMBER) }], variadic: true, returnType: TYPE_NUMBER },
  min:       { params: [{ name: 'list', type: typeList(TYPE_NUMBER) }], variadic: true, returnType: TYPE_NUMBER },
};

export interface CheckResult {
  diagnostics: Diagnostic[];
  exprTypes: Map<Expr, TypeSig>;
  declTypes: Map<string, TypeSig>;
}

export function check(ast: ProgramNode, plugins?: import('./plugin').AxiomPlugin[]): CheckResult {
  const diagnostics: Diagnostic[] = [];
  const exprTypes = new Map<Expr, TypeSig>();
  const declTypes = new Map<string, TypeSig>();

  // Track current namespace for unqualified name resolution
  let currentCheckerNamespace: string | undefined;

  // Collect type declarations (top-level and namespaced)
  const namedTypes = new Map<string, TypeSig>();
  function registerTypeDecl(td: TypeDeclaration, name: string) {
    // Record type: type Foo = { field: Type, ... }
    if (td.shape) {
      const shape: Record<string, TypeSig> = {};
      for (const [field, ann] of Object.entries(td.shape)) {
        shape[field] = resolveTypeAnnotation(ann);
      }
      namedTypes.set(name, { name, params: [], shape });
      return;
    }
    // Variant type: type Foo = tag { ... } | tag { ... }
    const variants: Record<string, Record<string, TypeSig>> = {};
    for (const alt of td.alternatives) {
      const shape: Record<string, TypeSig> = {};
      for (const [field, ann] of Object.entries(alt.shape)) {
        shape[field] = resolveTypeAnnotation(ann);
      }
      variants[alt.tag] = shape;
    }
    namedTypes.set(name, typeVariant(name, variants));
  }

  for (const decl of ast.body) {
    if (decl.kind === 'TypeDeclaration') {
      registerTypeDecl(decl, decl.name);
    }
    if (decl.kind === 'NamespaceDeclaration') {
      currentCheckerNamespace = decl.name;
      for (const typeDecl of decl.types) {
        registerTypeDecl(typeDecl, `${decl.name}.${typeDecl.name}`);
      }
      currentCheckerNamespace = undefined;
    }
  }

  // Collect table declarations — typed list values in scope
  const tableTypes = new Map<string, TypeSig>();
  for (const decl of ast.body) {
    if (decl.kind === 'TableDeclaration') {
      const elemType = resolveTypeAnnotation(decl.elementType);
      tableTypes.set(decl.name, typeList(elemType));
    }
  }

  // Collect expression declarations (top-level and namespaced)
  const exprDecls = new Map<string, ExprDeclInfo>();
  for (const decl of ast.body) {
    if (decl.kind === 'ExpressionDeclaration') {
      const paramTypes: Record<string, TypeSig> = {};
      for (const p of decl.params) {
        paramTypes[p.name] = resolveTypeAnnotation(p.type);
      }
      const returnType = decl.returnType ? resolveTypeAnnotation(decl.returnType) : undefined;
      exprDecls.set(decl.name, { decl, paramTypes, returnType });
    }
    if (decl.kind === 'SourceDeclaration') {
      const paramTypes: Record<string, TypeSig> = {};
      for (const p of decl.params) {
        paramTypes[p.name] = resolveTypeAnnotation(p.type);
      }
      const returnType = resolveTypeAnnotation(decl.returnType);
      exprDecls.set(decl.name, { decl, paramTypes, returnType });
    }
    if (decl.kind === 'NamespaceDeclaration') {
      currentCheckerNamespace = decl.name;
      for (const exprDecl of decl.expressions) {
        const qualName = `${decl.name}.${exprDecl.name}`;
        const paramTypes: Record<string, TypeSig> = {};
        for (const p of exprDecl.params) {
          paramTypes[p.name] = resolveTypeAnnotation(p.type);
        }
        const returnType = exprDecl.returnType ? resolveTypeAnnotation(exprDecl.returnType) : undefined;
        exprDecls.set(qualName, { decl: exprDecl, paramTypes, returnType });
      }
      for (const srcDecl of decl.sources) {
        const qualName = `${decl.name}.${srcDecl.name}`;
        const paramTypes: Record<string, TypeSig> = {};
        for (const p of srcDecl.params) {
          paramTypes[p.name] = resolveTypeAnnotation(p.type);
        }
        const returnType = resolveTypeAnnotation(srcDecl.returnType);
        exprDecls.set(qualName, { decl: srcDecl, paramTypes, returnType });
      }
      currentCheckerNamespace = undefined;
    }
  }

  // Check for duplicate expression names
  {
    const seen = new Map<string, Location | undefined>();
    for (const decl of ast.body) {
      if (decl.kind === 'ExpressionDeclaration') {
        if (seen.has(decl.name)) {
          diagnostics.push(error('type.duplicate_expression', `Duplicate expression name '${decl.name}'`, decl.location));
        }
        seen.set(decl.name, decl.location);
      }
    }
  }

  // Check for duplicate type names
  {
    const seen = new Map<string, Location | undefined>();
    for (const decl of ast.body) {
      if (decl.kind === 'TypeDeclaration') {
        if (seen.has(decl.name)) {
          diagnostics.push(error('type.duplicate_type', `Duplicate type name '${decl.name}'`, decl.location));
        }
        seen.set(decl.name, decl.location);
      }
    }
  }

  // Register payload-less variant tags as constants
  const variantTagTypes = new Map<string, TypeSig>();
  for (const decl of ast.body) {
    const typeDecls = decl.kind === 'TypeDeclaration' ? [decl]
      : decl.kind === 'NamespaceDeclaration' ? decl.types : [];
    for (const td of typeDecls) {
      const typeSig = namedTypes.get(td.name) ?? namedTypes.get(
        decl.kind === 'NamespaceDeclaration' ? `${decl.name}.${td.name}` : td.name
      );
      for (const alt of td.alternatives) {
        if (Object.keys(alt.shape).length === 0 && typeSig) {
          variantTagTypes.set(alt.tag, typeSig);
        }
      }
    }
  }

  // Collect namespace constants
  const namespaceConstants = new Map<string, TypeSig>();
  for (const decl of ast.body) {
    if (decl.kind === 'NamespaceDeclaration') {
      for (const sym of decl.symbols) {
        namespaceConstants.set(`${decl.name}.${sym.name}`, resolveTypeAnnotation(sym.type));
      }
    }
  }

  // Type-check each expression declaration
  for (const [name, info] of exprDecls) {
    // Source declarations have no body to type-check — just register their return type
    if (info.decl.kind === 'SourceDeclaration') {
      if (info.returnType) declTypes.set(name, info.returnType);
      continue;
    }

    const scope = new Map<string, TypeSig>();
    for (const [pname, ptype] of Object.entries(info.paramTypes)) {
      scope.set(pname, ptype);
    }
    // Set namespace context for unqualified resolution
    const dotIdx = name.lastIndexOf('.');
    currentCheckerNamespace = dotIdx >= 0 ? name.substring(0, dotIdx) : undefined;

    const expected = info.returnType ?? null;
    const bodyType = inferType(info.decl.body, scope, expected);
    if (bodyType) {
      declTypes.set(name, info.returnType ?? bodyType);
      if (info.returnType) {
        if (!isAssignable(bodyType, info.returnType)) {
          diagnostics.push(error(
            'type.return_type_mismatch',
            `Expression '${name}' body has type ${typeToString(bodyType)}, expected ${typeToString(info.returnType)}`,
            info.decl.location,
          ));
        }
      }
    }
  }
  currentCheckerNamespace = undefined;

  return { diagnostics, exprTypes, declTypes };

  // --- Helpers ---

  function resolveTypeAnnotation(ann: TypeAnnotation): TypeSig {
    if (ann.shape) {
      const shape: Record<string, TypeSig> = {};
      for (const [k, v] of Object.entries(ann.shape)) {
        shape[k] = resolveTypeAnnotation(v);
      }
      if (ann.keyword === 'dict') return typeDict(shape);
      return typeDict(shape);
    }

    switch (ann.keyword) {
      case 'number': return TYPE_NUMBER;
      case 'string': return TYPE_STRING;
      case 'bool': return TYPE_BOOL;
      case 'list': {
        if (ann.args.length > 0) {
          const argType = ann.args[0];
          if (argType.kind === 'Identifier') {
            const elemType = resolveTypeKeyword(argType.name) ?? resolveTypeName(argType.name);
            if (elemType) return typeList(elemType);
            diagnostics.push(error('type.unknown_type', `Unknown type '${argType.name}'`, argType.location));
          }
        }
        return typeList();
      }
      case 'dict': {
        if (ann.args.length > 0) {
          const argType = ann.args[0];
          if (argType.kind === 'Identifier') {
            const valueType = resolveTypeKeyword(argType.name) ?? resolveTypeName(argType.name);
            if (valueType) return typeDict(undefined, valueType);
            diagnostics.push(error('type.unknown_type', `Unknown type '${argType.name}'`, argType.location));
          }
        }
        return typeDict();
      }
      case 'money': {
        const currency = ann.args[0];
        if (currency && currency.kind === 'Identifier') {
          return typeMoney(currency.name);
        }
        return typeMoney('?');
      }
      default: {
        const resolved = resolveTypeName(ann.keyword);
        if (resolved) return resolved;
        diagnostics.push(error('type.unknown_type', `Unknown type '${ann.keyword}'`));
        return { name: ann.keyword, params: [] };
      }
    }
  }

  function resolveTypeName(name: string): TypeSig | null {
    const direct = namedTypes.get(name);
    if (direct) return direct;
    // Try qualifying with current namespace
    if (currentCheckerNamespace) {
      return namedTypes.get(`${currentCheckerNamespace}.${name}`) ?? null;
    }
    return null;
  }

  function resolveTypeKeyword(name: string): TypeSig | null {
    switch (name) {
      case 'number': return TYPE_NUMBER;
      case 'string': return TYPE_STRING;
      case 'bool': return TYPE_BOOL;
      case 'list': return typeList();
      case 'dict': return typeDict();
      default: return null;
    }
  }

  /** Resolve which variant type a tag belongs to, considering context. */
  function resolveVariantTag(tag: string, qualifiedTypeName: string | undefined, expected: TypeSig | null): TypeSig | null {
    // 1. Expected type from context (return type annotation)
    if (expected?.variants && expected.variants[tag]) return expected;
    // 2. Qualified type name
    if (qualifiedTypeName) {
      const resolved = namedTypes.get(qualifiedTypeName);
      if (resolved?.variants && resolved.variants[tag]) return resolved;
    }
    // 3. First named type containing this tag
    for (const [, typeSig] of namedTypes) {
      if (typeSig.variants && typeSig.variants[tag]) return typeSig;
    }
    return null;
  }

  /** Extract the element/value type from a list or dict type. */
  function resolveCollectionElemType(collType: TypeSig | null): TypeSig | null {
    if (!collType) return null;
    if (collType.name === 'list' || collType.name === 'dict') {
      // Prefer the full element type (e.g. inline record shape from table declarations)
      if (collType.elementType) return collType.elementType;
      if (collType.params.length > 0) {
        const elemName = collType.params[0];
        return namedTypes.get(elemName) ?? resolveTypeKeyword(elemName) ?? { name: elemName, params: [] };
      }
    }
    return null;
  }

  function inferType(expr: Expr, scope: Map<string, TypeSig>, expected: TypeSig | null = null): TypeSig | null {
    switch (expr.kind) {
      case 'Literal': {
        if (typeof expr.value === 'number') return setType(expr, TYPE_NUMBER);
        if (typeof expr.value === 'string') return setType(expr, TYPE_STRING);
        if (typeof expr.value === 'boolean') return setType(expr, TYPE_BOOL);
        return null;
      }

      case 'PluginLiteral': {
        if (plugins) {
          for (const plugin of plugins) {
            const type = plugin.checker?.inferLiteralType?.(expr.tag, expr.value);
            if (type) return setType(expr, type);
          }
        }
        return setType(expr, TYPE_MIXED);
      }

      case 'Identifier': {
        const t = scope.get(expr.name) ?? namespaceConstants.get(expr.name) ?? variantTagTypes.get(expr.name) ?? tableTypes.get(expr.name);
        if (t) return setType(expr, t);
        // Try qualifying with current namespace
        if (currentCheckerNamespace) {
          const nsT = namespaceConstants.get(`${currentCheckerNamespace}.${expr.name}`);
          if (nsT) return setType(expr, nsT);
        }
        // Auto-resolve parameterless expression declarations
        const paramlessInfo = exprDecls.get(expr.name)
          ?? (currentCheckerNamespace ? exprDecls.get(`${currentCheckerNamespace}.${expr.name}`) : undefined);
        if (
          paramlessInfo
          && paramlessInfo.decl.kind === 'ExpressionDeclaration'
          && paramlessInfo.decl.params.length === 0
        ) {
          const retType = paramlessInfo.returnType ?? inferType(paramlessInfo.decl.body, scope);
          if (retType) return setType(expr, retType);
        }
        diagnostics.push(error('type.unresolved_symbol', `Unknown symbol '${expr.name}'`, expr.location));
        return null;
      }

      case 'MemberExpression': {
        const objType = inferType(expr.object, scope);
        if (!objType) return null;
        if (objType.shape && objType.shape[expr.property]) {
          return setType(expr, objType.shape[expr.property]);
        }
        if (objType.variants) {
          // Check if field exists on ALL alternatives (common field access)
          const allHaveField = Object.values(objType.variants).every(
            shape => expr.property in shape
          );
          if (allHaveField) {
            // All alternatives have this field — check they all have the same type
            const types = Object.values(objType.variants).map(shape => shape[expr.property]);
            if (types.length > 0) return setType(expr, types[0]);
          }
          diagnostics.push(error(
            'type.member_access_on_variant',
            `Cannot access '${expr.property}' on variant type '${objType.name}' — narrow with match first`,
            expr.location,
          ));
          return null;
        }
        diagnostics.push(error(
          'type.unknown_property',
          `Property '${expr.property}' does not exist on ${typeToString(objType)}`,
          expr.location,
        ));
        return null;
      }

      case 'IndexExpression': {
        const objType = inferType(expr.object, scope);
        const idxType = inferType(expr.index, scope);
        if (!objType) return null;
        if (objType.name === 'list') {
          if (idxType && !isAssignable(idxType, TYPE_NUMBER)) {
            diagnostics.push(error('type.index_type', `List index must be number, got ${typeToString(idxType)}`, expr.location));
          }
          return setType(expr, objType.params.length > 0 ? { name: objType.params[0], params: [] } : TYPE_MIXED);
        }
        if (objType.shape && idxType && expr.index.kind === 'Literal' && typeof expr.index.value === 'string') {
          const fieldType = objType.shape[expr.index.value];
          if (fieldType) return setType(expr, fieldType);
          diagnostics.push(error('type.unknown_property', `Key '${expr.index.value}' does not exist on ${typeToString(objType)}`, expr.location));
          return null;
        }
        return setType(expr, TYPE_MIXED);
      }

      case 'InfixExpression': {
        const leftType = inferType(expr.left, scope);
        const rightType = inferType(expr.right, scope);
        if (!leftType || !rightType) return null;

        // Let plugins handle operator type checking first
        if (plugins) {
          for (const plugin of plugins) {
            const result = plugin.checker?.checkBinaryOp?.(expr.operator, leftType, rightType);
            if (result) {
              if ('error' in result) {
                diagnostics.push(error('type.plugin_operator', (result as { error: string }).error, expr.location));
                return null;
              }
              return setType(expr, result as TypeSig);
            }
          }
        }

        if (['+', '-', '*', '/', '%', '**'].includes(expr.operator)) {
          if (isAssignable(leftType, TYPE_NUMBER) && isAssignable(rightType, TYPE_NUMBER)) {
            return setType(expr, TYPE_NUMBER);
          }
          diagnostics.push(error(
            'type.operator_mismatch',
            `Operator '${expr.operator}' requires number operands, got ${typeToString(leftType)} and ${typeToString(rightType)}`,
            expr.location,
          ));
          return null;
        }

        if (['==', '!=', '<', '>', '<=', '>='].includes(expr.operator)) {
          return setType(expr, TYPE_BOOL);
        }
        if (['&&', '||'].includes(expr.operator)) {
          if (!isAssignable(leftType, TYPE_BOOL)) {
            diagnostics.push(error('type.operator_mismatch', `Left operand of '${expr.operator}' must be bool, got ${typeToString(leftType)}`, expr.location));
          }
          if (!isAssignable(rightType, TYPE_BOOL)) {
            diagnostics.push(error('type.operator_mismatch', `Right operand of '${expr.operator}' must be bool, got ${typeToString(rightType)}`, expr.location));
          }
          return setType(expr, TYPE_BOOL);
        }
        if (expr.operator === 'in' || expr.operator === 'not in') {
          if (rightType.name !== 'list') {
            diagnostics.push(error('type.operator_mismatch', `Right operand of '${expr.operator}' must be a list, got ${typeToString(rightType)}`, expr.location));
          }
          return setType(expr, TYPE_BOOL);
        }
        return setType(expr, TYPE_MIXED);
      }

      case 'UnaryExpression': {
        const operandType = inferType(expr.operand, scope);
        if (expr.operator === '-') {
          if (operandType && !isAssignable(operandType, TYPE_NUMBER)) {
            diagnostics.push(error('type.operator_mismatch', `Unary '-' requires number, got ${typeToString(operandType)}`, expr.location));
          }
          return setType(expr, TYPE_NUMBER);
        }
        if (expr.operator === 'not' || expr.operator === '!') {
          if (operandType && !isAssignable(operandType, TYPE_BOOL)) {
            diagnostics.push(error('type.operator_mismatch', `'${expr.operator}' requires bool, got ${typeToString(operandType)}`, expr.location));
          }
          return setType(expr, TYPE_BOOL);
        }
        return operandType ? setType(expr, operandType) : null;
      }

      case 'CoercionExpression': {
        const sourceType = inferType(expr.expression, scope);
        const targetType = resolveTypeAnnotation(expr.targetType);
        if (sourceType) {
          checkCoercionValidity(sourceType, targetType, expr.location);
        }
        return setType(expr, targetType);
      }

      case 'IfExpression': {
        const condType = inferType(expr.condition, scope);
        if (condType && !isAssignable(condType, TYPE_BOOL)) {
          diagnostics.push(error('type.condition_not_bool', `Condition must be bool, got ${typeToString(condType)}`, expr.condition.location));
        }
        const thenType = inferType(expr.then, scope, expected);
        const elseIfTypes: (TypeSig | null)[] = [];
        for (const ei of expr.elseIfs) {
          const eiCondType = inferType(ei.condition, scope);
          if (eiCondType && !isAssignable(eiCondType, TYPE_BOOL)) {
            diagnostics.push(error('type.condition_not_bool', `Condition must be bool, got ${typeToString(eiCondType)}`, ei.condition.location));
          }
          elseIfTypes.push(inferType(ei.then, scope, expected));
        }
        const elseType = inferType(expr.else, scope, expected);

        // If we have an expected variant type and all branches match, use it
        if (expected?.variants) {
          const allBranches = [thenType, elseType, ...elseIfTypes];
          const allMatch = allBranches.every(bt => bt && isAssignable(bt, expected));
          if (allMatch) return setType(expr, expected);
        }

        // Check branch type consistency (non-variant case)
        if (thenType && elseType && !expected?.variants) {
          if (!isAssignable(thenType, elseType) && !isAssignable(elseType, thenType)) {
            diagnostics.push(error(
              'type.branch_mismatch',
              `'then' branch has type ${typeToString(thenType)}, 'else' branch has type ${typeToString(elseType)}`,
              expr.location,
            ));
          }
        }

        return setType(expr, thenType ?? elseType ?? TYPE_MIXED);
      }

      case 'MatchExpression': {
        // match binding in iterable { ... } — iteration form
        if (expr.binding && expr.iterable) {
          const iterableType = inferType(expr.iterable, scope);
          const elemType = resolveCollectionElemType(iterableType) ?? TYPE_MIXED;
          // Type-check arms with binding in scope
          const armTypes: (TypeSig | null)[] = [];
          for (const arm of expr.arms) {
            const armScope = new Map(scope);
            armScope.set(expr.binding, elemType);
            armTypes.push(inferType(arm.expression, armScope, expected));
          }
          const firstType = armTypes.find(t => t != null) ?? null;
          if (firstType) {
            for (let i = 1; i < armTypes.length; i++) {
              const at = armTypes[i];
              if (at && !isAssignable(at, firstType) && !isAssignable(firstType, at)) {
                diagnostics.push(error(
                  'type.branch_mismatch',
                  `Match arm ${i + 1} has type ${typeToString(at)}, expected ${typeToString(firstType)}`,
                  expr.arms[i].expression.location,
                ));
                break;
              }
            }
          }
          return firstType ? setType(expr, firstType) : null;
        }

        let subjectType: TypeSig | null = null;
        if (expr.subject) {
          if (expr.subject.kind === 'ListLiteral') {
            // Tuple match subject — infer element types without consistency check
            for (const el of expr.subject.elements) {
              inferType(el, scope);
            }
            subjectType = typeList(TYPE_MIXED);
            setType(expr.subject, subjectType);
          } else {
            subjectType = inferType(expr.subject, scope);
          }
        }

        // Check exhaustiveness for variant subjects
        if (subjectType?.variants && expr.arms.length > 0) {
          checkMatchExhaustiveness(subjectType, expr.arms, expr.location);
        }

        const armTypes: (TypeSig | null)[] = [];
        for (const arm of expr.arms) {
          const armScope = new Map(scope);
          if (subjectType) {
            checkPatternAgainstType(arm.pattern, subjectType, expr.location);
          }
          bindPatternVars(arm.pattern, subjectType, armScope);
          armTypes.push(inferType(arm.expression, armScope, expected));
        }

        if (expected?.variants) {
          return setType(expr, expected);
        }

        // Build combined variant from all arms and check for tag conflicts
        const combinedVariants: Record<string, Record<string, TypeSig>> = {};
        const tagSeenAtArm: Record<string, number> = {};
        let hasVariants = false;

        for (let i = 0; i < armTypes.length; i++) {
          const armType = armTypes[i];
          if (!armType?.variants) continue;
          hasVariants = true;
          for (const [tag, shape] of Object.entries(armType.variants)) {
            if (tag in combinedVariants) {
              // Same tag seen before — check fields are consistent
              const existing = combinedVariants[tag];
              const existingFields = Object.keys(existing).sort().join(',');
              const newFields = Object.keys(shape).sort().join(',');
              if (existingFields !== newFields) {
                const prevArm = tagSeenAtArm[tag] + 1;
                diagnostics.push(error(
                  'type.branch_mismatch',
                  `Match arm ${i + 1} constructs '${tag}' with fields {${Object.keys(shape).join(', ')}}, but arm ${prevArm} has {${Object.keys(existing).join(', ')}}`,
                  expr.arms[i].expression.location,
                ));
              } else {
                // Same fields — check types are compatible
                for (const [field, fieldType] of Object.entries(shape)) {
                  const existingFieldType = existing[field];
                  if (existingFieldType && !isAssignable(fieldType, existingFieldType)) {
                    diagnostics.push(error(
                      'type.branch_mismatch',
                      `Match arm ${i + 1}: field '${field}' of '${tag}' has type ${typeToString(fieldType)}, but arm ${tagSeenAtArm[tag] + 1} has ${typeToString(existingFieldType)}`,
                      expr.arms[i].expression.location,
                    ));
                  }
                }
              }
            } else {
              combinedVariants[tag] = shape;
              tagSeenAtArm[tag] = i;
            }
          }
        }

        if (hasVariants && Object.keys(combinedVariants).length > 0) {
          // Use first arm's name (tag) since these are ad-hoc
          const firstName = armTypes.find(t => t?.variants)!.name;
          const combined: TypeSig = { name: firstName, params: [], variants: combinedVariants };
          return setType(expr, combined);
        }

        // Non-variant: check branch consistency and return first type
        const firstType = armTypes.find(t => t != null) ?? null;
        if (firstType) {
          for (let i = 0; i < armTypes.length; i++) {
            const at = armTypes[i];
            if (at && at !== firstType && !isAssignable(at, firstType) && !isAssignable(firstType, at)) {
              diagnostics.push(error(
                'type.branch_mismatch',
                `Match arm ${i + 1} has type ${typeToString(at)}, expected ${typeToString(firstType)}`,
                expr.arms[i].expression.location,
              ));
              break;
            }
          }
        }

        return firstType ? setType(expr, firstType) : null;
      }

      case 'CallExpression': {
        // Named expression calls (try direct, then qualify with current namespace)
        let resolvedCallee = expr.callee;
        let info = exprDecls.get(resolvedCallee);
        if (!info && currentCheckerNamespace) {
          resolvedCallee = `${currentCheckerNamespace}.${expr.callee}`;
          info = exprDecls.get(resolvedCallee);
        }
        if (info) {
          checkExpressionCallArgs(expr, info, scope);
          const ret = info.returnType ?? declTypes.get(resolvedCallee) ?? TYPE_MIXED;
          return setType(expr, ret);
        }

        // Let plugins override intrinsic type checking
        if (plugins) {
          const allArgExprs = expr.allArgs || [...expr.args, ...Object.values(expr.namedArgs)];
          const pluginArgTypes: TypeSig[] = [];
          for (const arg of allArgExprs) {
            const t = inferType(arg, scope);
            if (t) pluginArgTypes.push(t);
          }
          for (const plugin of plugins) {
            const result = plugin.checker?.checkCall?.(expr.callee, pluginArgTypes);
            if (result) return setType(expr, result);
          }
        }

        // Intrinsic functions
        const intrinsicSig = INTRINSICS[expr.callee];
        if (intrinsicSig) {
          // Collect all arg types (positional + named, for shorthand support)
          const allArgExprs = [...expr.args, ...Object.values(expr.namedArgs)];
          const argTypes: TypeSig[] = [];
          for (const arg of allArgExprs) {
            const t = inferType(arg, scope);
            if (t) argTypes.push(t);
          }

          const totalArgs = allArgExprs.length;

          // Arity check (skip for variadic intrinsics with 2+ args)
          if (!intrinsicSig.variadic && totalArgs !== intrinsicSig.params.length) {
            diagnostics.push(error(
              'type.argument_count',
              `'${expr.callee}' expects ${intrinsicSig.params.length} argument(s), got ${totalArgs}`,
              expr.location,
            ));
          }
          // Arg type checks — for variadic with multiple args, check each is number
          if (intrinsicSig.variadic && totalArgs > 1) {
            for (let i = 0; i < argTypes.length; i++) {
              if (!isAssignable(argTypes[i], TYPE_NUMBER)) {
                diagnostics.push(error(
                  'type.argument_mismatch',
                  `Argument ${i + 1} of '${expr.callee}' expects number, got ${typeToString(argTypes[i])}`,
                  allArgExprs[i].location,
                ));
              }
            }
          } else {
            for (let i = 0; i < Math.min(argTypes.length, intrinsicSig.params.length); i++) {
              const expected = intrinsicSig.params[i].type;
              if (expected.name !== 'mixed' && !isAssignable(argTypes[i], expected)) {
                diagnostics.push(error(
                  'type.argument_mismatch',
                  `Argument '${intrinsicSig.params[i].name}' of '${expr.callee}' expects ${typeToString(expected)}, got ${typeToString(argTypes[i])}`,
                  allArgExprs[i].location,
                ));
              }
            }
          }

          if (intrinsicSig.returnType === 'from_arg') {
            if (expr.callee === 'flatten' && argTypes.length > 0 && argTypes[0].name === 'list') {
              // flatten(list(list(T))) -> list(T): unwrap one nesting level
              const innerType = argTypes[0].elementType;
              if (innerType && innerType.name === 'list') {
                return setType(expr, innerType);
              }
              return setType(expr, typeList(TYPE_MIXED));
            }
            return setType(expr, argTypes.length > 0 ? argTypes[0] : TYPE_MIXED);
          }
          return setType(expr, intrinsicSig.returnType);
        }

        // Unknown function
        for (const arg of expr.args) inferType(arg, scope);
        for (const argExpr of Object.values(expr.namedArgs)) inferType(argExpr, scope);
        diagnostics.push(error('type.unknown_function', `Unknown function '${expr.callee}'`, expr.location));
        return setType(expr, TYPE_MIXED);
      }

      case 'ListLiteral': {
        // If the expected type is list(T), propagate T as context for elements
        let expectedElemType: TypeSig | null = null;
        if (expected?.name === 'list' && expected.params.length > 0) {
          expectedElemType = namedTypes.get(expected.params[0])
            ?? resolveTypeKeyword(expected.params[0])
            ?? null;
        }

        const elemTypes: TypeSig[] = [];
        for (const el of expr.elements) {
          const t = inferType(el, scope, expectedElemType);
          if (t) elemTypes.push(t);
        }

        // Validate each element against the expected element type
        if (expectedElemType) {
          for (let i = 0; i < elemTypes.length; i++) {
            if (!isAssignable(elemTypes[i], expectedElemType)) {
              diagnostics.push(error(
                'type.list_element_mismatch',
                `List element at index ${i} has type ${typeToString(elemTypes[i])}, expected ${typeToString(expectedElemType)}`,
                expr.elements[i].location,
              ));
            }
          }
        } else if (elemTypes.length > 1) {
          // No expected type — check consistency among elements
          const first = elemTypes[0];
          for (let i = 1; i < elemTypes.length; i++) {
            if (!isAssignable(elemTypes[i], first) && !isAssignable(first, elemTypes[i])) {
              diagnostics.push(error(
                'type.mixed_list',
                `List element at index ${i} has type ${typeToString(elemTypes[i])}, expected ${typeToString(first)}`,
                expr.elements[i].location,
              ));
              break;
            }
          }
        }

        const inferredElem = expectedElemType ?? elemTypes[0] ?? TYPE_MIXED;
        return setType(expr, typeList(inferredElem));
      }

      case 'DictLiteral': {
        // If expected is dict(T), propagate T as context for values
        const expectedValueType = (expected?.name === 'dict' && expected.elementType) ? expected.elementType : null;

        const shape: Record<string, TypeSig> = {};
        const valueTypes: TypeSig[] = [];
        for (const entry of expr.entries) {
          const t = inferType(entry.value, scope, expectedValueType);
          if (t) {
            shape[entry.key] = t;
            valueTypes.push(t);
          }
        }

        // If we have an expected value type, validate and return typed dict
        if (expectedValueType) {
          for (let i = 0; i < valueTypes.length; i++) {
            if (!isAssignable(valueTypes[i], expectedValueType)) {
              diagnostics.push(error(
                'type.dict_value_mismatch',
                `Dict value '${expr.entries[i].key}' has type ${typeToString(valueTypes[i])}, expected ${typeToString(expectedValueType)}`,
                expr.entries[i].value.location,
              ));
            }
          }
          return setType(expr, typeDict(shape, expectedValueType));
        }

        // Infer value type from entries if all values have the same type
        if (valueTypes.length > 0) {
          const first = valueTypes[0];
          const allSame = valueTypes.every(t => isAssignable(t, first));
          if (allSame) return setType(expr, typeDict(shape, first));
        }

        return setType(expr, typeDict(shape));
      }

      case 'VariantConstruction': {
        const tag = expr.tag;
        const resolvedType = resolveVariantTag(tag, expr.typeName, expected);

        // Infer types for all provided entries regardless of resolution
        const providedFields = new Map<string, TypeSig>();
        for (const entry of expr.entries) {
          const t = inferType(entry.value, scope);
          if (t) providedFields.set(entry.key, t);
        }

        // Validate fields when the author explicitly opted into a named type:
        // either via an expected type from context (return annotation, list element type)
        // or via a qualified name (RuleResult.ok { ... })
        const hasExplicitType = expected !== null || expr.typeName !== undefined;

        if (!resolvedType || !hasExplicitType) {
          // No explicit type context — build a structural ad-hoc variant
          const shape: Record<string, TypeSig> = {};
          for (const [k, v] of providedFields) shape[k] = v;
          // Ad-hoc variant — use tag name so it's treated as anonymous, not as the named type
          return setType(expr, { name: tag, params: [], variants: { [tag]: shape } });
        }

        // Validate fields against the resolved named type
        const declaredShape = resolvedType.variants![tag];

        // Check for missing required fields
        for (const field of Object.keys(declaredShape)) {
          if (!providedFields.has(field)) {
            diagnostics.push(error(
              'type.missing_field',
              `Variant '${tag}' is missing required field '${field}' (expected ${typeToString(declaredShape[field])})`,
              expr.location,
            ));
          }
        }

        // Check for extra fields
        for (const [field] of providedFields) {
          if (!(field in declaredShape)) {
            diagnostics.push(error(
              'type.extra_field',
              `Variant '${tag}' has no field '${field}'`,
              expr.location,
            ));
          }
        }

        // Check field types
        for (const [field, actualType] of providedFields) {
          const expectedType = declaredShape[field];
          if (expectedType && !isAssignable(actualType, expectedType)) {
            diagnostics.push(error(
              'type.field_type_mismatch',
              `Field '${field}' of '${tag}' expects ${typeToString(expectedType)}, got ${typeToString(actualType)}`,
              expr.location,
            ));
          }
        }

        return setType(expr, resolvedType);
      }

      case 'AnyExpression':
      case 'AllExpression': {
        const collType = inferType(expr.list, scope);
        if (collType && collType.name !== 'list' && collType.name !== 'dict') {
          diagnostics.push(error('type.not_iterable', `'${expr.kind === 'AnyExpression' ? 'any' : 'all'}' requires a list or dict, got ${typeToString(collType)}`, expr.location));
        }
        const elemType = resolveCollectionElemType(collType);
        if (elemType) checkPatternAgainstType(expr.pattern, elemType, expr.location);
        return setType(expr, TYPE_BOOL);
      }

      case 'CollectExpression': {
        const collType = inferType(expr.list, scope);
        if (collType && collType.name !== 'list' && collType.name !== 'dict') {
          diagnostics.push(error('type.not_iterable', `'collect' requires a list or dict, got ${typeToString(collType)}`, expr.location));
        }

        // Binding form: collect row in list { arms }
        if (expr.binding && expr.arms) {
          const elemType = resolveCollectionElemType(collType) ?? TYPE_MIXED;
          const armTypes: (TypeSig | null)[] = [];
          for (const arm of expr.arms) {
            const armScope = new Map(scope);
            armScope.set(expr.binding, elemType);
            armTypes.push(inferType(arm.body, armScope));
          }
          const bodyType = armTypes.find(t => t != null) ?? null;
          return setType(expr, typeList(bodyType ?? TYPE_MIXED));
        }

        // Standard form: collect pattern in list => body
        const collectScope = new Map(scope);
        const elemType = resolveCollectionElemType(collType);
        if (expr.pattern && elemType) checkPatternAgainstType(expr.pattern, elemType, expr.location);
        if (expr.pattern) bindPatternVars(expr.pattern, elemType, collectScope);
        const bodyType = expr.body ? inferType(expr.body, collectScope) : null;
        // Collect from dict → dict, from list → list
        const isDict = collType && (collType.name === 'dict' || (collType.shape && !Array.isArray(collType.shape)));
        if (isDict) {
          return setType(expr, typeDict(undefined, bodyType ?? TYPE_MIXED));
        }
        return setType(expr, typeList(bodyType ?? TYPE_MIXED));
      }

      case 'AggregateCollectExpression': {
        const collType = inferType(expr.list, scope);
        if (collType && collType.name !== 'list' && collType.name !== 'dict') {
          diagnostics.push(error('type.not_iterable', `Aggregate collect requires a list or dict, got ${typeToString(collType)}`, expr.location));
        }
        const elemType = resolveCollectionElemType(collType);
        for (const arm of expr.arms) {
          const armScope = new Map(scope);
          if (expr.binding) {
            armScope.set(expr.binding, elemType ?? TYPE_MIXED);
          } else {
            if (elemType) checkPatternAgainstType(arm.pattern, elemType, expr.location);
            bindPatternVars(arm.pattern, elemType, armScope);
          }
          inferType(arm.body, armScope);
        }
        // Validate aggregator exists
        if (!INTRINSICS[expr.aggregator]) {
          diagnostics.push(error('type.unknown_function', `Unknown aggregator '${expr.aggregator}'`, expr.location));
        }
        const intrinsic = INTRINSICS[expr.aggregator];
        if (intrinsic) {
          return setType(expr, intrinsic.returnType === 'from_arg' ? TYPE_MIXED : intrinsic.returnType);
        }
        return setType(expr, TYPE_MIXED);
      }

      case 'ParenExpression': {
        const inner = inferType(expr.expression, scope, expected);
        return inner ? setType(expr, inner) : null;
      }

      case 'WhereExpression': {
        const whereScope = new Map(scope);
        for (const binding of expr.bindings) {
          const t = inferType(binding.value, whereScope);
          if (t) whereScope.set(binding.name, t);
        }
        const bodyType = inferType(expr.body, whereScope, expected);
        return bodyType ? setType(expr, bodyType) : null;
      }
    }

    return null;
  }

  function setType(expr: Expr, t: TypeSig): TypeSig {
    exprTypes.set(expr, t);
    return t;
  }

  // --- Expression call argument validation ---

  function checkExpressionCallArgs(
    expr: Expr & { kind: 'CallExpression'; callee: string; args: Expr[]; namedArgs: Record<string, Expr> },
    info: ExprDeclInfo,
    scope: Map<string, TypeSig>,
  ) {
    const paramNames = info.decl.params.map(p => p.name);
    const namedArgNames = new Set(Object.keys(expr.namedArgs));
    const hasSpread = !!(expr as any).spread;
    const hasNamed = namedArgNames.size > 0;
    const hasPositional = expr.args.length > 0;

    if (hasNamed || hasSpread) {
      // Validate named args (includes shorthand)
      for (const name of namedArgNames) {
        if (!info.paramTypes[name]) {
          const suggestion = findClosest(name, paramNames);
          const msg = suggestion
            ? `Unknown argument '${name}' — did you mean '${suggestion}'?`
            : `Unknown argument '${name}' for '${expr.callee}'`;
          diagnostics.push(error('type.unknown_argument', msg, expr.location));
        }
      }

      // Check for missing arguments (account for both named, positional, and spread)
      const positionallyBound = new Set<string>();
      for (let i = 0; i < Math.min(expr.args.length, paramNames.length); i++) {
        positionallyBound.add(paramNames[i]);
      }
      for (const pname of paramNames) {
        if (namedArgNames.has(pname) || positionallyBound.has(pname)) continue;
        // If spread is active, try to resolve from scope
        if (hasSpread) {
          const scopeType = scope.get(pname);
          if (scopeType) {
            // Type-check the spread-resolved variable
            const expectedType = info.paramTypes[pname];
            if (expectedType && expectedType.name !== 'mixed' && !isAssignable(scopeType, expectedType)) {
              diagnostics.push(error(
                'type.argument_mismatch',
                `Spread argument '${pname}' has type ${typeToString(scopeType)}, expected ${typeToString(expectedType)}`,
                expr.location,
              ));
            }
            continue;
          }
        }
        diagnostics.push(error(
          'type.missing_argument',
          `Missing argument '${pname}' in call to '${expr.callee}' (expected ${typeToString(info.paramTypes[pname])})`,
          expr.location,
        ));
      }

      // Type-check named args
      for (const [name, argExpr] of Object.entries(expr.namedArgs)) {
        const expectedType = info.paramTypes[name] ?? null;
        const argType = inferType(argExpr, scope, expectedType);
        if (argType && expectedType) {
          checkArgType(expr.callee, name, argType, expectedType, argExpr.location);
        }
      }

      // Type-check any positional args alongside named
      for (let i = 0; i < Math.min(expr.args.length, paramNames.length); i++) {
        if (namedArgNames.has(paramNames[i])) continue;
        const expectedType = info.paramTypes[paramNames[i]] ?? null;
        const argType = inferType(expr.args[i], scope, expectedType);
        if (argType && expectedType) {
          checkArgType(expr.callee, paramNames[i], argType, expectedType, expr.args[i].location);
        }
      }
    } else if (hasPositional) {
      if (expr.args.length !== paramNames.length) {
        const sig = paramNames.map(n => `${n}: ${typeToString(info.paramTypes[n])}`).join(', ');
        diagnostics.push(error(
          'type.argument_count',
          `'${expr.callee}' expects ${paramNames.length} argument(s) (${sig}), got ${expr.args.length}`,
          expr.location,
        ));
      }

      for (let i = 0; i < Math.min(expr.args.length, paramNames.length); i++) {
        const expectedType = info.paramTypes[paramNames[i]] ?? null;
        const argType = inferType(expr.args[i], scope, expectedType);
        if (argType && expectedType) {
          checkArgType(expr.callee, paramNames[i], argType, expectedType, expr.args[i].location);
        }
      }
    } else if (paramNames.length > 0) {
      const sig = paramNames.map(n => `${n}: ${typeToString(info.paramTypes[n])}`).join(', ');
      diagnostics.push(error(
        'type.argument_count',
        `'${expr.callee}' expects ${paramNames.length} argument(s) (${sig}), got 0`,
        expr.location,
      ));
    }
  }

  function checkArgType(callee: string, paramName: string, actual: TypeSig, expected: TypeSig, loc?: Location) {
    if (expected.name === 'mixed') return;
    // For shaped dict params, check structural compatibility
    if (expected.shape && actual.shape) {
      for (const [field, fieldType] of Object.entries(expected.shape)) {
        if (!actual.shape[field]) {
          diagnostics.push(error(
            'type.argument_mismatch',
            `Argument '${paramName}' of '${callee}' is missing field '${field}' (expected ${typeToString(fieldType)})`,
            loc,
          ));
        } else if (!isAssignable(actual.shape[field], fieldType)) {
          diagnostics.push(error(
            'type.argument_mismatch',
            `Field '${field}' of argument '${paramName}' expects ${typeToString(fieldType)}, got ${typeToString(actual.shape[field])}`,
            loc,
          ));
        }
      }
      return;
    }
    if (!isAssignable(actual, expected)) {
      diagnostics.push(error(
        'type.argument_mismatch',
        `Argument '${paramName}' of '${callee}' expects ${typeToString(expected)}, got ${typeToString(actual)}`,
        loc,
      ));
    }
  }

  // --- Variant constructor / pattern validation ---

  function checkPatternAgainstType(pattern: Pattern, subjectType: TypeSig, loc?: Location) {
    if (pattern.kind === 'AlternativePattern') {
      for (const alt of pattern.patterns) {
        checkPatternAgainstType(alt, subjectType, loc);
      }
      return;
    }
    if (pattern.kind === 'VariantPattern' && subjectType.variants) {
      if (!subjectType.variants[pattern.tag]) {
        const validTags = Object.keys(subjectType.variants);
        const suggestion = findClosest(pattern.tag, validTags);
        const msg = suggestion
          ? `Unknown variant tag '${pattern.tag}' on type '${subjectType.name}' — did you mean '${suggestion}'?`
          : `Unknown variant tag '${pattern.tag}' on type '${subjectType.name}'. Valid tags: ${validTags.join(', ')}`;
        diagnostics.push(error('type.unknown_tag', msg, pattern.location));
      } else {
        // Check that bindings reference valid fields
        const declaredShape = subjectType.variants[pattern.tag];
        for (const field of Object.keys(pattern.bindings)) {
          if (!(field in declaredShape)) {
            const validFields = Object.keys(declaredShape);
            const suggestion = findClosest(field, validFields);
            const msg = suggestion
              ? `Variant '${pattern.tag}' has no field '${field}' — did you mean '${suggestion}'?`
              : `Variant '${pattern.tag}' has no field '${field}'`;
            diagnostics.push(error('type.unknown_field', msg, pattern.location));
          }
        }
      }
    }
  }

  function checkMatchExhaustiveness(subjectType: TypeSig, arms: MatchArm[], loc?: Location) {
    if (!subjectType.variants) return;
    const allTags = new Set(Object.keys(subjectType.variants));
    let hasWildcard = false;

    for (const arm of arms) {
      const patterns = arm.pattern.kind === 'AlternativePattern' ? arm.pattern.patterns : [arm.pattern];
      for (const pat of patterns) {
        if (pat.kind === 'WildcardPattern') {
          hasWildcard = true;
        } else if (pat.kind === 'VariantPattern') {
          allTags.delete(pat.tag);
        }
      }
    }

    if (!hasWildcard && allTags.size > 0) {
      const missing = Array.from(allTags).join(', ');
      diagnostics.push(error(
        'type.non_exhaustive_match',
        `Match is not exhaustive — missing tags: ${missing}`,
        loc,
      ));
    }
  }

  // --- Coercion validation ---

  function checkCoercionValidity(source: TypeSig, target: TypeSig, loc?: Location) {
    // Valid coercions: string → number, string → money, number → money, number → string
    const validCoercions: [string, string][] = [
      ['string', 'number'],
      ['string', 'money'],
      ['number', 'money'],
      ['number', 'string'],
      ['money', 'number'],
      ['money', 'string'],
    ];
    if (isAssignable(source, target)) return; // already compatible, coercion is redundant but fine
    const isValid = validCoercions.some(([from, to]) => source.name === from && target.name === to);
    if (!isValid) {
      diagnostics.push(error(
        'type.invalid_coercion',
        `Cannot coerce ${typeToString(source)} to ${typeToString(target)}`,
        loc,
      ));
    }
  }

  // --- Pattern variable binding ---

  function bindPatternVars(pattern: Pattern, subjectType: TypeSig | null, scope: Map<string, TypeSig>): void {
    if (pattern.kind === 'TuplePattern') {
      for (const elem of pattern.elements) {
        bindPatternVars(elem, null, scope);
      }
      return;
    }
    if (pattern.kind === 'VariantPattern') {
      let payloadShape: Record<string, TypeSig> | null = null;
      if (subjectType?.variants) {
        payloadShape = subjectType.variants[pattern.tag] ?? null;
      }
      if (!payloadShape) {
        for (const [, typeSig] of namedTypes) {
          if (typeSig.variants && typeSig.variants[pattern.tag]) {
            payloadShape = typeSig.variants[pattern.tag];
            break;
          }
        }
      }

      for (const [field, alias] of Object.entries(pattern.bindings)) {
        if (alias === null) continue;
        const fieldType = payloadShape?.[field] ?? TYPE_MIXED;
        scope.set(alias, fieldType);
      }
    }
  }

  // --- Utility ---

  function findClosest(name: string, candidates: string[]): string | null {
    let best: string | null = null;
    let bestDist = Infinity;
    for (const c of candidates) {
      const d = editDistance(name.toLowerCase(), c.toLowerCase());
      if (d < bestDist && d <= 3) {
        bestDist = d;
        best = c;
      }
    }
    return best;
  }

  function editDistance(a: string, b: string): number {
    const m = a.length, n = b.length;
    const dp: number[][] = Array.from({ length: m + 1 }, () => Array(n + 1).fill(0));
    for (let i = 0; i <= m; i++) dp[i][0] = i;
    for (let j = 0; j <= n; j++) dp[0][j] = j;
    for (let i = 1; i <= m; i++) {
      for (let j = 1; j <= n; j++) {
        dp[i][j] = a[i - 1] === b[j - 1]
          ? dp[i - 1][j - 1]
          : 1 + Math.min(dp[i - 1][j], dp[i][j - 1], dp[i - 1][j - 1]);
      }
    }
    return dp[m][n];
  }
}
