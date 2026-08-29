const BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/v1";

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly body: Record<string, unknown>,
  ) {
    super(typeof body.message === "string" ? body.message : `Request failed (${status})`);
  }
}

/**
 * `credentials: "include"` is what makes Story 1.11's HttpOnly session cookies actually
 * work — without it the browser never sends or stores them on this cross-origin request
 * (apps/web is a static export, a separate origin from the API; see config/cors.php).
 */
export interface RequestOptions {
  headers?: Record<string, string>;
  idempotencyKey?: string;
}

export async function post<T>(path: string, body: unknown, options?: RequestOptions): Promise<T> {
  return request<T>(path, "POST", body, options);
}

export async function get<T>(path: string, options?: RequestOptions): Promise<T> {
  return request<T>(path, "GET", undefined, options);
}

// Access tokens live 30 minutes (SessionService::ACCESS_TOKEN_TTL_SECONDS); without this,
// every player session would hard-fail with a 401 the moment that window passes, even
// though a valid refresh_token cookie is sitting right there. One in-flight refresh is
// shared across concurrent 401s so two requests expiring at once don't race the backend's
// refresh-token rotation (a second use of the same now-rotated-away token reads as reuse).
let refreshPromise: Promise<boolean> | null = null;

async function refreshSession(): Promise<boolean> {
  if (refreshPromise === null) {
    refreshPromise = (async () => {
      try {
        const response = await fetch(`${BASE_URL}/auth/refresh`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({}),
        });
        if (!response.ok) return false;
        const json = (await response.json()) as { status?: string };
        return json.status === "signed_in";
      } catch {
        return false;
      } finally {
        refreshPromise = null;
      }
    })();
  }
  return refreshPromise;
}

async function request<T>(
  path: string,
  method: "GET" | "POST",
  body?: unknown,
  options?: RequestOptions,
  isRetry = false,
): Promise<T> {
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    ...(options?.headers ?? {}),
  };

  if (options?.idempotencyKey) {
    headers["Idempotency-Key"] = options.idempotencyKey;
  }

  const response = await fetch(`${BASE_URL}${path}`, {
    method,
    credentials: "include",
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  if (response.status === 401 && !isRetry && path !== "/auth/refresh") {
    const refreshed = await refreshSession();
    if (refreshed) return request<T>(path, method, body, options, true);
  }

  const json = (await response.json()) as Record<string, unknown>;
  if (!response.ok) throw new ApiError(response.status, json);

  return json as T;
}
