export const THEME_STORAGE_KEY = "theme";
export const THEMES = Object.freeze({ LIGHT: "light", DARK: "dark" });

export function getStoredTheme() {
  try {
    const stored = localStorage.getItem(THEME_STORAGE_KEY);
    if (stored === THEMES.LIGHT || stored === THEMES.DARK) {
      return stored;
    }
  } catch {
    // ignore
  }
  return THEMES.LIGHT;
}

export function applyTheme(theme) {
  const next = theme === THEMES.DARK ? THEMES.DARK : THEMES.LIGHT;
  document.documentElement.dataset.theme = next;
  try {
    localStorage.setItem(THEME_STORAGE_KEY, next);
  } catch {
    // ignore
  }
  return next;
}

export function toggleTheme(current) {
  const next = current === THEMES.DARK ? THEMES.LIGHT : THEMES.DARK;
  return applyTheme(next);
}
