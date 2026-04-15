import { AxiomPlugin } from '../lang/plugin';
import { TypeSig, typeMoney, TYPE_NUMBER, TYPE_BOOL } from '../lang/types';

// Currency symbol → ISO code mapping
const SYMBOL_MAP: Record<string, string> = {
  '£': 'GBP',
  '€': 'EUR',
  '$': 'USD',
  '¥': 'JPY',
};

// Reverse: ISO code → symbol (for display)
const SYMBOL_REVERSE: Record<string, string> = {
  GBP: '£', EUR: '€', USD: '$', JPY: '¥',
};

// Known ISO 4217 currency codes
const ISO_CODES = new Set([
  'GBP', 'EUR', 'USD', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY',
  'SEK', 'NOK', 'DKK', 'NZD', 'ZAR', 'SGD', 'HKD', 'INR', 'BRL', 'AED',
]);

export interface MoneyValue {
  _money: true;
  amount: number;
  currency: string;
}

export function isMoneyValue(v: unknown): v is MoneyValue {
  return v !== null && typeof v === 'object' && (v as MoneyValue)._money === true;
}

/** Format a money value for display: £100.00 or USD 100.00 */
export function formatMoney(v: MoneyValue): string {
  const sym = SYMBOL_REVERSE[v.currency];
  const formatted = Number.isInteger(v.amount) ? v.amount.toFixed(0) : v.amount.toFixed(2);
  return sym ? `${sym}${formatted}` : `${v.currency} ${formatted}`;
}

/** Read the numeric portion of a money literal starting at `numStart`. */
function readMoneyNumber(source: string, pos: number, prefixLen: number, currency: string) {
  let i = pos + prefixLen;
  let hasDecimal = false;
  while (i < source.length) {
    const ch = source[i];
    if (ch >= '0' && ch <= '9') {
      i++;
    } else if (ch === '.' && !hasDecimal && i + 1 < source.length && source[i + 1] >= '0' && source[i + 1] <= '9') {
      hasDecimal = true;
      i++;
    } else {
      break;
    }
  }
  if (i === pos + prefixLen) return null; // no digits after prefix

  const amount = parseFloat(source.slice(pos + prefixLen, i));
  return {
    tag: 'money',
    value: source.slice(pos, i),
    payload: { _money: true, amount, currency } as MoneyValue,
    length: i - pos,
  };
}

export const moneyPlugin: AxiomPlugin = {
  name: 'money',

  lexer: {
    tryTokenize(source: string, pos: number) {
      const ch = source[pos];

      // Symbol form: £123.45
      const currency = SYMBOL_MAP[ch];
      if (currency) {
        return readMoneyNumber(source, pos, 1, currency);
      }

      // ISO code form: GBP123.45 — 3 uppercase letters followed by a digit
      if (ch >= 'A' && ch <= 'Z' && pos + 3 < source.length) {
        const code = source.slice(pos, pos + 3);
        if (ISO_CODES.has(code) && source[pos + 3] >= '0' && source[pos + 3] <= '9') {
          return readMoneyNumber(source, pos, 3, code);
        }
      }

      return null;
    },
  },

  checker: {
    inferLiteralType(tag: string, payload: unknown) {
      if (tag === 'money') {
        return typeMoney((payload as MoneyValue).currency);
      }
      return null;
    },

    checkBinaryOp(op: string, left: TypeSig, right: TypeSig) {
      const leftIsMoney = left.name === 'money';
      const rightIsMoney = right.name === 'money';
      if (!leftIsMoney && !rightIsMoney) return null; // not our concern

      // money OP money
      if (leftIsMoney && rightIsMoney) {
        if (['+', '-'].includes(op)) {
          if (left.params[0] !== right.params[0]) {
            return { error: `Cannot ${op} money(${left.params[0]}) and money(${right.params[0]}) — currency mismatch` };
          }
          return left;
        }
        if (op === '/') {
          if (left.params[0] !== right.params[0]) {
            return { error: `Cannot divide money(${left.params[0]}) by money(${right.params[0]}) — currency mismatch` };
          }
          return TYPE_NUMBER; // ratio
        }
        if (['==', '!=', '<', '>', '<=', '>='].includes(op)) {
          if (left.params[0] !== right.params[0]) {
            return { error: `Cannot compare money(${left.params[0]}) and money(${right.params[0]}) — currency mismatch` };
          }
          return TYPE_BOOL;
        }
        return { error: `Operator '${op}' is not supported between money values` };
      }

      // money OP number
      if (leftIsMoney && right.name === 'number') {
        if (op === '*' || op === '/') return left;
        return { error: `Cannot '${op}' money(${left.params[0]}) and number — use * or / to scale money` };
      }

      // number OP money
      if (left.name === 'number' && rightIsMoney) {
        if (op === '*') return right;
        return { error: `Cannot '${op}' number and money(${right.params[0]}) — use * to scale money` };
      }

      return null;
    },

    checkCall(name: string, argTypes: TypeSig[]) {
      // round(money, number) → money
      if (name === 'round' && argTypes.length === 2 && argTypes[0].name === 'money') {
        return argTypes[0];
      }
      // max/min(money, money) → money
      if ((name === 'max' || name === 'min') && argTypes.length === 2
          && argTypes[0].name === 'money' && argTypes[1].name === 'money') {
        return argTypes[0];
      }
      // sum(list(money)) → money
      if (name === 'sum' && argTypes.length === 1
          && argTypes[0].name === 'list' && argTypes[0].elementType?.name === 'money') {
        return argTypes[0].elementType;
      }
      return null;
    },
  },

  evaluator: {
    supportsOp(left: unknown, right: unknown, op: string) {
      return isMoneyValue(left) || isMoneyValue(right);
    },

    evaluateOp(left: unknown, right: unknown, op: string): unknown {
      if (isMoneyValue(left) && isMoneyValue(right)) {
        if (left.currency !== right.currency) {
          throw new Error(`Cannot ${op} ${formatMoney(left)} and ${formatMoney(right)} — currency mismatch`);
        }
        switch (op) {
          case '+': return { _money: true, amount: left.amount + right.amount, currency: left.currency };
          case '-': return { _money: true, amount: left.amount - right.amount, currency: left.currency };
          case '/': return left.amount / right.amount; // ratio → number
          case '==': return left.amount === right.amount;
          case '!=': return left.amount !== right.amount;
          case '<': return left.amount < right.amount;
          case '>': return left.amount > right.amount;
          case '<=': return left.amount <= right.amount;
          case '>=': return left.amount >= right.amount;
          default: throw new Error(`Unsupported operator '${op}' for money`);
        }
      }

      if (isMoneyValue(left) && typeof right === 'number') {
        switch (op) {
          case '*': return { _money: true, amount: left.amount * right, currency: left.currency };
          case '/': return right === 0 ? { _money: true, amount: 0, currency: left.currency }
                                       : { _money: true, amount: left.amount / right, currency: left.currency };
          default: throw new Error(`Cannot '${op}' money and number`);
        }
      }

      if (typeof left === 'number' && isMoneyValue(right)) {
        if (op === '*') return { _money: true, amount: left * right.amount, currency: right.currency };
        throw new Error(`Cannot '${op}' number and money`);
      }

      throw new Error('Unsupported money operation');
    },

    intrinsics: {
      sum: (list: unknown) => {
        if (!Array.isArray(list) || list.length === 0 || !isMoneyValue(list[0])) return undefined;
        const currency = list[0].currency;
        const total = list.reduce((acc: number, v: unknown) => acc + (isMoneyValue(v) ? v.amount : 0), 0);
        return { _money: true, amount: total, currency } as MoneyValue;
      },
      round: (n: unknown, decimals: unknown) => {
        if (isMoneyValue(n)) {
          const d = typeof decimals === 'number' ? decimals : 0;
          const factor = Math.pow(10, d);
          return { _money: true, amount: Math.round(n.amount * factor) / factor, currency: n.currency };
        }
        return undefined; // fall through to built-in
      },
      max: (...args: unknown[]) => {
        if (args.length === 2 && isMoneyValue(args[0]) && isMoneyValue(args[1])) {
          const a = args[0], b = args[1];
          return a.amount >= b.amount ? a : b;
        }
        return undefined;
      },
      min: (...args: unknown[]) => {
        if (args.length === 2 && isMoneyValue(args[0]) && isMoneyValue(args[1])) {
          const a = args[0], b = args[1];
          return a.amount <= b.amount ? a : b;
        }
        return undefined;
      },
    },
  },
};
