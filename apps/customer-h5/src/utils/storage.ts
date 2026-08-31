export function readStorage<T>(key: string, fallback: T): T {
  try {
    const value = localStorage.getItem(key)
    return value ? (JSON.parse(value) as T) : fallback
  } catch {
    localStorage.removeItem(key)
    return fallback
  }
}

export function writeStorage(key: string, value: unknown): void {
  localStorage.setItem(key, JSON.stringify(value))
}
