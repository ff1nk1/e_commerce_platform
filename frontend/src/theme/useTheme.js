import { useCallback, useEffect, useState } from "react";
import {
  applyTheme,
  getStoredTheme,
  toggleTheme as toggleStoredTheme,
} from "./theme";

export function useTheme() {
  const [theme, setTheme] = useState(() => getStoredTheme());

  useEffect(() => {
    applyTheme(theme);
  }, [theme]);

  const toggleTheme = useCallback(() => {
    setTheme((current) => toggleStoredTheme(current));
  }, []);

  const setThemeValue = useCallback((next) => {
    setTheme(applyTheme(next));
  }, []);

  return {
    theme,
    toggleTheme,
    setTheme: setThemeValue,
    isDark: theme === "dark",
  };
}
