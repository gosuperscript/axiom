export interface Location {
  line: number;
  column: number;
  offset: number;
  length: number;
}

export type Severity = 'error' | 'warning' | 'info';

export interface Diagnostic {
  severity: Severity;
  code: string;
  message: string;
  location?: Location;
}

export function error(code: string, message: string, location?: Location): Diagnostic {
  return { severity: 'error', code, message, location };
}

export function warning(code: string, message: string, location?: Location): Diagnostic {
  return { severity: 'warning', code, message, location };
}
