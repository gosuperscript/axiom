/**
 * Simple CSV parser for table artifacts.
 * In production, the runtime (PHP) would handle this.
 * This simulates artifact loading for the playground.
 */
export function parseCSV(text: string): Record<string, string | number>[] {
  const lines = text.trim().split('\n');
  if (lines.length < 2) return [];

  const headers = parseLine(lines[0]);
  const rows: Record<string, string | number>[] = [];

  for (let i = 1; i < lines.length; i++) {
    const values = parseLine(lines[i]);
    if (values.length === 0) continue;
    const row: Record<string, string | number> = {};
    for (let j = 0; j < headers.length; j++) {
      const raw = values[j] ?? '';
      row[headers[j]] = coerce(raw);
    }
    rows.push(row);
  }

  return rows;
}

function parseLine(line: string): string[] {
  const fields: string[] = [];
  let current = '';
  let inQuotes = false;

  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (inQuotes) {
      if (ch === '"' && line[i + 1] === '"') {
        current += '"';
        i++;
      } else if (ch === '"') {
        inQuotes = false;
      } else {
        current += ch;
      }
    } else if (ch === '"') {
      inQuotes = true;
    } else if (ch === ',') {
      fields.push(current);
      current = '';
    } else {
      current += ch;
    }
  }
  fields.push(current);
  return fields;
}

function coerce(value: string): string | number {
  if (value === '') return '';
  const num = Number(value);
  if (!isNaN(num) && value.trim() !== '') return num;
  return value;
}
