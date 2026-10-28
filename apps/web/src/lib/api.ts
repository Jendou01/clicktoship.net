export type Envelope<T> = { data: T; meta: { request_id: string }; error: null | { code: string; message: string; details?: Record<string, string[]> } };
export class ApiError extends Error {
  constructor(public status: number, public code: string, public details: Record<string, string[]> = {}) { super(code); }
}
export async function api<T>(path: string, method = "GET", body?: unknown, merchant?: string): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (merchant) headers["X-Merchant-ID"] = merchant;
  if (method !== "GET") {
    const csrf = await fetch("/sanctum/csrf-cookie", { credentials: "same-origin", cache: "no-store", referrerPolicy: "same-origin" });
    if (!csrf.ok) throw new ApiError(csrf.status, "SESSION_UNAVAILABLE");
    const token = document.cookie.split("; ").find((cookie) => cookie.startsWith("XSRF-TOKEN="))?.slice(11);
    if (!token) throw new ApiError(419, "CSRF_TOKEN_MISMATCH");
    headers["X-XSRF-TOKEN"] = decodeURIComponent(token);
    headers["Content-Type"] = "application/json";
  }
  // Sanctum needs first-party Referer/Origin metadata even for GET. Preserve it
  // only for these same-origin requests under the gateway's no-referrer policy.
  const response = await fetch(`/api/v1${path}`, { method, headers, credentials: "same-origin", cache: "no-store", referrerPolicy: "same-origin", body: body === undefined ? undefined : JSON.stringify(body) });
  let envelope: Envelope<T>;
  try { envelope = await response.json(); } catch { throw new ApiError(response.status, "SERVICE_UNAVAILABLE"); }
  if (!response.ok || envelope.error) throw new ApiError(response.status, envelope.error?.code || "REQUEST_FAILED", envelope.error?.details);
  return envelope.data;
}
