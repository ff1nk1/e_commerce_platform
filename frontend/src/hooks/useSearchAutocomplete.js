import { useEffect, useRef, useState } from "react";
import { searchAutocomplete } from "../api/catalog";

/**
 * Debounced OpenSearch autocomplete for product name suggestions.
 *
 * @param {string} query
 * @param {{ enabled?: boolean, limit?: number, fuzziness?: string, debounceMs?: number }} [options]
 */
export default function useSearchAutocomplete(query, options = {}) {
  const {
    enabled = true,
    limit = 8,
    fuzziness = "AUTO",
    debounceMs = 250,
  } = options;

  const q = (query || "").trim();
  const active = enabled && q.length >= 2;
  const [suggestions, setSuggestions] = useState([]);
  const [open, setOpen] = useState(false);
  const requestIdRef = useRef(0);

  useEffect(() => {
    if (!active) return undefined;

    const timer = setTimeout(() => {
      const requestId = ++requestIdRef.current;
      searchAutocomplete({ q, limit, fuzziness })
        .then((response) => {
          if (requestId !== requestIdRef.current) return;
          const items = response.data?.suggestions || [];
          setSuggestions(items);
          setOpen(items.length > 0);
        })
        .catch(() => {
          if (requestId !== requestIdRef.current) return;
          setSuggestions([]);
          setOpen(false);
        });
    }, debounceMs);

    return () => clearTimeout(timer);
  }, [q, active, limit, fuzziness, debounceMs]);

  const close = () => setOpen(false);

  return {
    suggestions: active ? suggestions : [],
    open: active && open,
    setOpen,
    close,
  };
}
