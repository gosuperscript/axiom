import * as monaco from 'monaco-editor';

export function registerAxiomTheme() {
  monaco.editor.defineTheme('axiom-dark', {
    base: 'vs-dark',
    inherit: true,
    rules: [
      { token: 'comment', foreground: '6c7086', fontStyle: 'italic' },
      { token: 'keyword', foreground: 'cba6f7', fontStyle: 'bold' },
      { token: 'type', foreground: '89b4fa' },
      { token: 'type.identifier', foreground: '89dceb' },
      { token: 'function.declaration', foreground: 'a6e3a1', fontStyle: 'bold' },
      { token: 'tag', foreground: 'fab387' },
      { token: 'constant', foreground: 'fab387' },
      { token: 'number', foreground: 'fab387' },
      { token: 'number.float', foreground: 'fab387' },
      { token: 'number.money', foreground: 'f9e2af', fontStyle: 'bold' },
      { token: 'string', foreground: 'a6e3a1' },
      { token: 'string.quote', foreground: 'a6e3a1' },
      { token: 'string.escape', foreground: 'f5c2e7' },
      { token: 'string.invalid', foreground: 'f38ba8' },
      { token: 'operator', foreground: '89dceb' },
      { token: 'delimiter', foreground: '9399b2' },
      { token: 'delimiter.arrow', foreground: 'cba6f7' },
      { token: 'identifier', foreground: 'cdd6f4' },
      { token: '@brackets', foreground: '9399b2' },
    ],
    colors: {
      'editor.background': '#1e1e2e',
      'editor.foreground': '#cdd6f4',
      'editor.lineHighlightBackground': '#2a2b3d',
      'editor.selectionBackground': '#45475a',
      'editorCursor.foreground': '#f5e0dc',
      'editorLineNumber.foreground': '#6c7086',
      'editorLineNumber.activeForeground': '#cba6f7',
      'editor.inactiveSelectionBackground': '#313244',
      'editorIndentGuide.background': '#313244',
      'editorIndentGuide.activeBackground': '#45475a',
      'editorBracketMatch.background': '#45475a44',
      'editorBracketMatch.border': '#cba6f7',
    },
  });
}
