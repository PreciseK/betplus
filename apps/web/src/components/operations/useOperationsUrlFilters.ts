"use client";

import { useEffect, useState } from "react";

type StringFilters = Record<string, string>;
const OPERATIONS_FILTER_EVENT = "betplus:operations-filter-change";

export function useOperationsUrlFilters<T extends StringFilters>(defaults: T) {
  const [filters, setFilters] = useState<T>(defaults);

  useEffect(() => {
    const readLocation = () => {
      const params = new URLSearchParams(window.location.search);
      setFilters((current) => {
        const next = { ...current };
        for (const key of Object.keys(defaults) as (keyof T)[]) {
          const value = params.get(String(key));
          next[key] = (value ?? defaults[key]) as T[keyof T];
        }
        return next;
      });
    };

    readLocation();
    window.addEventListener("popstate", readLocation);
    window.addEventListener(OPERATIONS_FILTER_EVENT, readLocation);
    return () => {
      window.removeEventListener("popstate", readLocation);
      window.removeEventListener(OPERATIONS_FILTER_EVENT, readLocation);
    };
  }, [defaults]);

  function updateFilter<K extends keyof T>(key: K, value: T[K]) {
    const next = { ...filters, [key]: value };
    setFilters(next);

    const params = new URLSearchParams(window.location.search);
    for (const filterKey of Object.keys(next) as (keyof T)[]) {
      params.set(String(filterKey), next[filterKey]);
    }
    window.history.replaceState(window.history.state, "", `${window.location.pathname}?${params.toString()}${window.location.hash}`);
    window.dispatchEvent(new Event(OPERATIONS_FILTER_EVENT));
  }

  return { filters, updateFilter };
}
