/* Veilige toegang tot localStorage (kan ontbreken in privévensters). */
export function load<T>(key: string, fallback: T): T {
  try {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : fallback;
  } catch {
    return fallback;
  }
}

export function save(key: string, value: unknown): void {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch {
    /* geen opslag beschikbaar */
  }
}

export function loadText(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

export function saveText(key: string, value: string): void {
  try {
    localStorage.setItem(key, value);
  } catch {
    /* geen opslag beschikbaar */
  }
}
