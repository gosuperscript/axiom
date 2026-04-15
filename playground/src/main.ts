import * as monaco from 'monaco-editor';
import { registerAxiomLanguage } from './editor/language';
import { registerAxiomTheme } from './editor/theme';
import { tokenize } from './lang/lexer';
import { parse } from './lang/parser';
import { check } from './lang/checker';
import { evaluate } from './lang/evaluator';
import { INSURANCE_EXAMPLE, INSURANCE_INPUT } from './examples/insurance.axiom';
import { HEALTHCARE_EXAMPLE, HEALTHCARE_INPUT } from './examples/healthcare.axiom';
import { TRADESPEOPLE_EXAMPLE, TRADESPEOPLE_INPUT } from './examples/tradespeople.axiom';
import { HOSPITALITY_EXAMPLE, HOSPITALITY_INPUT } from './examples/hospitality.axiom';
import { LANDLORDS_EXAMPLE, LANDLORDS_INPUT } from './examples/landlords.axiom';
import { MONEY_EXAMPLE, MONEY_INPUT } from './examples/money.axiom';
import { ProgramNode, ExpressionDeclaration } from './lang/ast';
import { AxiomPlugin } from './lang/plugin';
import { moneyPlugin } from './plugins/money';
import { isMoneyValue, formatMoney } from './plugins/money';
import { Diagnostic } from './lang/diagnostics';

// Monaco worker setup
self.MonacoEnvironment = {
  getWorkerUrl(_moduleId: string, label: string) {
    if (label === 'json') {
      return new URL('monaco-editor/esm/vs/language/json/json.worker.js', import.meta.url).href;
    }
    if (label === 'css' || label === 'scss' || label === 'less') {
      return new URL('monaco-editor/esm/vs/language/css/css.worker.js', import.meta.url).href;
    }
    if (label === 'html' || label === 'handlebars' || label === 'razor') {
      return new URL('monaco-editor/esm/vs/language/html/html.worker.js', import.meta.url).href;
    }
    if (label === 'typescript' || label === 'javascript') {
      return new URL('monaco-editor/esm/vs/language/typescript/ts.worker.js', import.meta.url).href;
    }
    return new URL('monaco-editor/esm/vs/editor/editor.worker.js', import.meta.url).href;
  },
};

// Register language and theme
registerAxiomLanguage();
registerAxiomTheme();

// Create editor
const editorContainer = document.getElementById('editor-container')!;
const editor = monaco.editor.create(editorContainer, {
  value: INSURANCE_EXAMPLE,
  language: 'axiom',
  theme: 'axiom-dark',
  fontSize: 14,
  fontFamily: "'SF Mono', 'Fira Code', 'Cascadia Code', 'JetBrains Mono', monospace",
  lineNumbers: 'on',
  minimap: { enabled: false },
  scrollBeyondLastLine: false,
  padding: { top: 12 },
  renderLineHighlight: 'line',
  bracketPairColorization: { enabled: true },
  autoIndent: 'full',
  tabSize: 4,
  insertSpaces: true,
  wordWrap: 'on',
  smoothScrolling: true,
  cursorBlinking: 'smooth',
  cursorSmoothCaretAnimation: 'on',
});

// Elements
const exampleSelect = document.getElementById('example-select') as HTMLSelectElement;
const exprSelect = document.getElementById('expr-select') as HTMLSelectElement;
const inputTextarea = document.getElementById('input-data') as HTMLTextAreaElement;
const outputPre = document.getElementById('output')!;
const diagnosticsPre = document.getElementById('diagnostics')!;

const EXAMPLES: Record<string, { code: string; input: Record<string, unknown> }> = {
  insurance: { code: INSURANCE_EXAMPLE, input: INSURANCE_INPUT },
  healthcare: { code: HEALTHCARE_EXAMPLE, input: HEALTHCARE_INPUT },
  tradespeople: { code: TRADESPEOPLE_EXAMPLE, input: TRADESPEOPLE_INPUT },
  hospitality: { code: HOSPITALITY_EXAMPLE, input: HOSPITALITY_INPUT },
  landlords: { code: LANDLORDS_EXAMPLE, input: LANDLORDS_INPUT },
  money: { code: MONEY_EXAMPLE, input: MONEY_INPUT },
};

const PLUGINS: AxiomPlugin[] = [moneyPlugin];

function loadExample(name: string) {
  const example = EXAMPLES[name];
  if (!example) return;
  editor.setValue(example.code);
  inputTextarea.value = JSON.stringify(example.input, null, 2);
}

// Set default input
inputTextarea.value = JSON.stringify(INSURANCE_INPUT, null, 2);

// Resizer
const resizer = document.getElementById('resizer')!;
const outputPane = document.querySelector('.output-pane') as HTMLElement;
let isResizing = false;

resizer.addEventListener('mousedown', () => { isResizing = true; });
document.addEventListener('mousemove', (e) => {
  if (!isResizing) return;
  const containerWidth = document.querySelector('.main')!.getBoundingClientRect().width;
  const newWidth = containerWidth - e.clientX;
  outputPane.style.width = Math.max(250, Math.min(newWidth, containerWidth - 300)) + 'px';
  editor.layout();
});
document.addEventListener('mouseup', () => { isResizing = false; });

// Processing pipeline
let debounceTimer: number | undefined;

function processCode() {
  const source = editor.getValue();

  // 1. Tokenize
  const { tokens, diagnostics: lexDiags } = tokenize(source, PLUGINS);

  // 2. Parse
  const { ast, diagnostics: parseDiags } = parse(tokens);
  const allDiags = [...lexDiags, ...parseDiags];

  // 3. Type check
  const checkResult = check(ast, PLUGINS);
  allDiags.push(...checkResult.diagnostics);

  // 4. Update expression selector
  updateExpressionSelector(ast);

  // 5. Show diagnostics in Monaco
  showDiagnostics(allDiags);

  // 6. Evaluate
  const selectedExpr = exprSelect.value;
  if (selectedExpr) {
    tryEvaluate(ast, selectedExpr);
  } else {
    outputPre.textContent = 'Select an expression to evaluate';
    outputPre.style.color = '#6c7086';
  }
}

function updateExpressionSelector(ast: ProgramNode) {
  const previous = exprSelect.value;
  const exprs = ast.body
    .filter((d): d is ExpressionDeclaration => d.kind === 'ExpressionDeclaration')
    .map(d => d.name);

  // Only update if the list changed
  const currentOptions = Array.from(exprSelect.options).map(o => o.value);
  if (JSON.stringify(exprs) !== JSON.stringify(currentOptions)) {
    exprSelect.innerHTML = '';
    for (const name of exprs) {
      const option = document.createElement('option');
      option.value = name;
      option.textContent = name;
      exprSelect.appendChild(option);
    }
    // Restore previous selection or default to last
    if (exprs.includes(previous)) {
      exprSelect.value = previous;
    } else if (exprs.length > 0) {
      exprSelect.value = exprs[exprs.length - 1];
    }
  }
}

function showDiagnostics(diags: Diagnostic[]) {
  // Monaco markers
  const model = editor.getModel()!;
  const markers: monaco.editor.IMarkerData[] = diags
    .filter(d => d.location)
    .map(d => {
      const startPos = model.getPositionAt(d.location!.offset);
      const endPos = model.getPositionAt(d.location!.offset + Math.max(d.location!.length, 1));
      return {
        severity: d.severity === 'error'
          ? monaco.MarkerSeverity.Error
          : d.severity === 'warning'
            ? monaco.MarkerSeverity.Warning
            : monaco.MarkerSeverity.Info,
        startLineNumber: startPos.lineNumber,
        startColumn: startPos.column,
        endLineNumber: endPos.lineNumber,
        endColumn: endPos.column,
        message: d.message,
        code: d.code,
      };
    });
  monaco.editor.setModelMarkers(model, 'axiom', markers);

  // Diagnostics panel
  const errors = diags.filter(d => d.severity === 'error');
  const warnings = diags.filter(d => d.severity === 'warning');

  if (errors.length === 0 && warnings.length === 0) {
    diagnosticsPre.textContent = 'No issues';
    diagnosticsPre.className = 'clean';
  } else {
    const lines: string[] = [];
    for (const d of [...errors, ...warnings]) {
      const loc = d.location ? `[${d.location.line}:${d.location.column}]` : '';
      const prefix = d.severity === 'error' ? 'ERROR' : 'WARN';
      lines.push(`${prefix} ${loc} ${d.message}`);
    }
    diagnosticsPre.textContent = lines.join('\n');
    diagnosticsPre.className = errors.length > 0 ? '' : 'clean';
    if (errors.length > 0) {
      diagnosticsPre.style.color = '#f38ba8';
    } else {
      diagnosticsPre.style.color = '#f9e2af';
    }
  }
}

function tryEvaluate(ast: ProgramNode, exprName: string) {
  let inputData: Record<string, unknown>;
  try {
    inputData = JSON.parse(inputTextarea.value || '{}');
  } catch (e) {
    outputPre.textContent = `Invalid JSON input: ${e instanceof Error ? e.message : String(e)}`;
    outputPre.style.color = '#f38ba8';
    return;
  }

  const { _sources, _tables, ...evalInput } = inputData as any;
  const result = evaluate(ast, exprName, evalInput, _sources ?? undefined, _tables ?? undefined, PLUGINS);

  if (result.error) {
    outputPre.textContent = `Error: ${result.error}`;
    outputPre.style.color = '#f38ba8';
  } else {
    outputPre.textContent = JSON.stringify(formatOutput(result.value), null, 2);
    outputPre.style.color = '#a6e3a1';
  }
}

/** Format output for display — converts money objects to readable strings. */
function formatOutput(value: unknown): unknown {
  if (value === null || value === undefined) return value;
  if (isMoneyValue(value)) return formatMoney(value);
  if (Array.isArray(value)) return value.map(formatOutput);
  if (typeof value === 'object') {
    const result: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
      result[k] = formatOutput(v);
    }
    return result;
  }
  return value;
}

// Event listeners
editor.onDidChangeModelContent(() => {
  clearTimeout(debounceTimer);
  debounceTimer = window.setTimeout(processCode, 300);
});

exprSelect.addEventListener('change', processCode);
exampleSelect.addEventListener('change', () => loadExample(exampleSelect.value));
inputTextarea.addEventListener('input', () => {
  clearTimeout(debounceTimer);
  debounceTimer = window.setTimeout(processCode, 300);
});

// Handle resize
window.addEventListener('resize', () => editor.layout());

// Initial processing
processCode();
