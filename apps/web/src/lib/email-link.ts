"use client";

import { useEffect, useMemo, useState, useSyncExternalStore } from "react";

// Capture fragments only in the browser. Keep link secrets in memory, outside
// server requests, browser storage and rendered server HTML.
export function useEmailLink() {
  const [store] = useState(() => {
    let value: string | null = null;
    const listeners = new Set<() => void>();
    return {
      snapshot: () => (value ??= window.location.hash),
      serverSnapshot: () => "",
      subscribe: (listener: () => void) => {
        listeners.add(listener);
        const capture = () => {
          if (!window.location.hash) return;
          value = window.location.hash;
          window.history.replaceState(null, "", window.location.pathname);
          listeners.forEach((notify) => notify());
        };
        window.addEventListener("hashchange", capture);
        return () => { listeners.delete(listener); window.removeEventListener("hashchange", capture); };
      },
      clear: () => { value = ""; listeners.forEach((listener) => listener()); },
    };
  });
  const hash = useSyncExternalStore(store.subscribe, store.snapshot, store.serverSnapshot);
  useEffect(() => { store.snapshot(); if (window.location.hash) window.history.replaceState(null, "", window.location.pathname); }, [store]);
  const values = useMemo(() => {
    const parameters = new URLSearchParams(hash.slice(1));
    return { verification: parameters.get("verification") || "", token: parameters.get("token") || "", email: parameters.get("email") || "" };
  }, [hash]);
  return { values, clear: store.clear, read: () => Object.fromEntries(new URLSearchParams(store.snapshot().slice(1))) };
}
