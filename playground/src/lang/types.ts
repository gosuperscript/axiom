export interface TypeSig {
  name: string;
  params: string[];
  shape?: Record<string, TypeSig>;
  variants?: Record<string, Record<string, TypeSig>>; // tag -> shape
  isPluginProvided?: boolean;
  elementType?: TypeSig; // Full element type for list(T), preserved through nesting
}

export const TYPE_NUMBER: TypeSig = { name: 'number', params: [] };
export const TYPE_STRING: TypeSig = { name: 'string', params: [] };
export const TYPE_BOOL: TypeSig = { name: 'bool', params: [] };
export const TYPE_MIXED: TypeSig = { name: 'mixed', params: [] };

export function typeList(element?: TypeSig): TypeSig {
  return { name: 'list', params: element ? [element.name] : [], elementType: element };
}

export function typeDict(shape?: Record<string, TypeSig>, valueType?: TypeSig): TypeSig {
  return { name: 'dict', params: valueType ? [valueType.name] : [], shape, elementType: valueType };
}

export function typeVariant(name: string, variants: Record<string, Record<string, TypeSig>>): TypeSig {
  return { name, params: [], variants };
}

export function typeMoney(currency: string): TypeSig {
  // For the PoC, money is just a number alias with a label
  return { name: 'money', params: [currency] };
}

export function isAssignable(source: TypeSig, target: TypeSig): boolean {
  if (target.name === 'mixed') return true;

  if (source.name === target.name) {
    // List: check element type compatibility
    if (source.name === 'list') {
      // Untyped list or mixed-element list is assignable to any list
      if (source.params.length === 0 || target.params.length === 0) return true;
      if (source.params[0] === 'mixed' || target.params[0] === 'mixed') return true;
      // Use full elementType for deep comparison when available
      if (source.elementType && target.elementType) {
        return isAssignable(source.elementType, target.elementType);
      }
      // Element type names must match (nominal)
      return source.params[0] === target.params[0];
    }
    // Dict: check value type compatibility
    if (source.name === 'dict') {
      if (source.params.length === 0 || target.params.length === 0) return true;
      if (source.params[0] === 'mixed' || target.params[0] === 'mixed') return true;
      if (source.elementType && target.elementType) {
        return isAssignable(source.elementType, target.elementType);
      }
      return source.params[0] === target.params[0];
    }
    // Money: check currency
    if (source.name === 'money') {
      return source.params[0] === target.params[0] || target.params.length === 0;
    }
    // Same-named variant types are the same type (nominal)
    return true;
  }

  // number <-> money interop for PoC
  if (source.name === 'number' && target.name === 'money') return true;
  if (source.name === 'money' && target.name === 'number') return true;

  // A variant value inferred from construction (anonymous/structural) is
  // assignable to a named variant if the source is the SAME named type,
  // OR if the source was resolved to the target type by the checker.
  // For nominal typing: different names = different types, even if
  // structurally identical.
  if (source.variants && target.variants) {
    // Only assignable if they share the same type name
    // This is the nominal check.
    if (source.name === target.name) return true;
    // Allow anonymous/ad-hoc variants (no declared type) to match if tags AND fields match
    const sourceIsAnonymous = !source.name || source.name === Object.keys(source.variants)[0];
    if (sourceIsAnonymous) {
      for (const tag of Object.keys(source.variants)) {
        if (!(tag in target.variants)) return false;
        const sourceShape = source.variants[tag];
        const targetShape = target.variants[tag];
        // All target fields must exist in source with compatible types
        for (const [field, targetFieldType] of Object.entries(targetShape)) {
          if (!(field in sourceShape)) return false;
          if (!isAssignable(sourceShape[field], targetFieldType)) return false;
        }
        // No extra fields in source
        for (const field of Object.keys(sourceShape)) {
          if (!(field in targetShape)) return false;
        }
      }
      return true;
    }
    return false;
  }

  return false;
}

export function typeToString(t: TypeSig): string {
  if (t.name === 'list' && t.elementType) {
    return `list(${typeToString(t.elementType)})`;
  }
  if (t.name === 'dict' && t.elementType) {
    return `dict(${typeToString(t.elementType)})`;
  }
  if (t.params.length > 0) return `${t.name}(${t.params.join(', ')})`;
  if (t.shape) {
    const fields = Object.entries(t.shape).map(([k, v]) => `${k}: ${typeToString(v)}`);
    return `{ ${fields.join(', ')} }`;
  }
  if (t.variants) {
    // If the type has a meaningful name (not just the first tag), show it
    const tags = Object.keys(t.variants);
    const isNamed = t.name && t.name !== tags[0];
    if (isNamed) return t.name;
    const alts = Object.entries(t.variants).map(([tag, shape]) => {
      const fields = Object.entries(shape).map(([k, v]) => `${k}: ${typeToString(v)}`);
      return `${tag} { ${fields.join(', ')} }`;
    });
    return alts.join(' | ');
  }
  return t.name;
}

export function propertyType(t: TypeSig, prop: string): TypeSig | null {
  if (t.shape && t.shape[prop]) return t.shape[prop];
  return null;
}
