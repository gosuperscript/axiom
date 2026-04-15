import * as monaco from 'monaco-editor';

export function registerAxiomLanguage() {
  monaco.languages.register({ id: 'axiom' });

  monaco.languages.setLanguageConfiguration('axiom', {
    comments: { lineComment: '//' },
    brackets: [
      ['{', '}'],
      ['[', ']'],
      ['(', ')'],
    ],
    autoClosingPairs: [
      { open: '{', close: '}' },
      { open: '[', close: ']' },
      { open: '(', close: ')' },
      { open: '"', close: '"', notIn: ['string'] },
    ],
    surroundingPairs: [
      { open: '{', close: '}' },
      { open: '[', close: ']' },
      { open: '(', close: ')' },
      { open: '"', close: '"' },
    ],
    indentationRules: {
      increaseIndentPattern: /[{([].*$/,
      decreaseIndentPattern: /^\s*[})\]]/,
    },
  });

  monaco.languages.setMonarchTokensProvider('axiom', {
    keywords: [
      'type', 'namespace', 'source', 'table', 'if', 'then', 'else', 'match',
      'not', 'in', 'as', 'any', 'all', 'collect', 'where',
    ],

    typeKeywords: [
      'number', 'string', 'bool', 'list', 'dict', 'money',
    ],

    constants: ['true', 'false'],

    operators: [
      '=', '==', '!=', '<', '>', '<=', '>=',
      '&&', '||', '!', '+', '-', '*', '/', '%', '**',
      '=>', '|', '..', '...',
    ],

    symbols: /[=><!~?:&|+\-*\/\^%]+/,

    tokenizer: {
      root: [
        // Comments
        [/\/\/.*$/, 'comment'],

        // Type declarations
        [/\b(type)\s+([A-Z]\w*)/, ['keyword', 'type.identifier']],

        // Expression declarations (capitalized identifiers followed by parens)
        [/\b([A-Z]\w*)(?=\s*\()/, 'function.declaration'],

        // Variant tags (lowercase identifier followed by {)
        [/\b([a-z]\w*)(?=\s*\{)/, 'tag'],

        // Keywords
        [/\b(type|namespace|source|table|if|then|else|match|not|in|as|any|all|collect|where)\b/, 'keyword'],

        // Type keywords
        [/\b(number|string|bool|list|dict|money)\b/, 'type'],

        // Constants
        [/\b(true|false)\b/, 'constant'],

        // Identifiers
        [/\b[A-Z]\w*/, 'type.identifier'],
        [/\b[a-z_]\w*/, 'identifier'],

        // Money literals: £100.50, €200, $50, ¥10000, GBP100, USD50.25
        [/[£€$¥]\d+(\.\d+)?/, 'number.money'],
        [/\b[A-Z]{3}\d+(\.\d+)?/, 'number.money'],

        // Numbers
        [/\b\d+\.\d+\b/, 'number.float'],
        [/\b\d+\b/, 'number'],

        // Strings
        [/"([^"\\]|\\.)*$/, 'string.invalid'],
        [/"/, { token: 'string.quote', bracket: '@open', next: '@string' }],

        // Spread operator
        [/\.\.\./, 'keyword'],

        // Operators
        [/=>/, 'delimiter.arrow'],
        [/[=><!~?:&|+\-*\/\^%]+/, {
          cases: {
            '@operators': 'operator',
            '@default': '',
          },
        }],

        // Delimiters
        [/[{}()\[\]]/, '@brackets'],
        [/[,.]/, 'delimiter'],
        [/_\b/, 'keyword'],
      ],

      string: [
        [/[^\\"]+/, 'string'],
        [/\\./, 'string.escape'],
        [/"/, { token: 'string.quote', bracket: '@close', next: '@pop' }],
      ],
    },
  });
}
