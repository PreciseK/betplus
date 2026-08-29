"use client";

import { useEffect, useState } from "react";
import type { useRouter } from "next/navigation";

const SIGNED_IN_COOKIE = "betplus_signed_in";

/**
 * Reads the non-HttpOnly `betplus_signed_in` cookie the backend sets alongside the real
 * (HttpOnly) session cookies — see IssuesSessionCookies::withSessionCookies. This is a
 * synchronous UX signal only ("has a session worth trying"), not proof the access token
 * is currently valid; packages/api-client's http.ts handles token expiry via refresh-on-401.
 */
export function isSignedIn(): boolean {
  if (typeof document === "undefined") return false;
  return document.cookie.split("; ").includes(`${SIGNED_IN_COOKIE}=1`);
}

/**
 * `checked` distinguishes "haven't read the cookie yet" from "read it and it's absent" —
 * this build is a static export, so the first paint can't know auth state server-side;
 * consumers should hold a neutral/loading state until `checked` is true to avoid flashing
 * a signed-out gate at an actually-signed-in visitor during hydration.
 */
export function useSignedIn(): { signedIn: boolean; checked: boolean; refresh: () => void } {
  const [signedIn, setSignedIn] = useState(false);
  const [checked, setChecked] = useState(false);

  useEffect(() => {
    setSignedIn(isSignedIn());
    setChecked(true);
  }, []);

  return {
    signedIn,
    checked,
    refresh: () => setSignedIn(isSignedIn()),
  };
}

/** Runs `action` if signed in, otherwise routes to sign-in instead of letting a real gateway call 401. */
export function requireAuth(router: ReturnType<typeof useRouter>, action: () => void): void {
  if (isSignedIn()) {
    action();
    return;
  }
  router.push("/login");
}
