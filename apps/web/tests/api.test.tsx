import { beforeEach, afterEach, describe, expect, it, vi } from "vitest";
import { api, ApiError } from "../src/lib/api";

beforeEach(() => { document.cookie = "XSRF-TOKEN=encrypted%3Dcsrf; path=/"; });
afterEach(() => vi.unstubAllGlobals());
describe("same-origin API client", () => {
  it("keeps first-party GET metadata for stateful authentication behind a no-referrer gateway", async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => ({ data: { authenticated: true }, error: null }) });
    vi.stubGlobal("fetch", fetchMock);
    await expect(api("/auth/me")).resolves.toEqual({ authenticated: true });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, request] = fetchMock.mock.calls[0];
    expect(url).toBe("/api/v1/auth/me");
    expect(request.referrerPolicy).toBe("same-origin");
    expect(request.credentials).toBe("same-origin");
    expect(request.headers.Authorization).toBeUndefined();
  });
  it("sends CSRF and merchant context on mutations without putting credentials in URLs", async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true }).mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ data: { saved: true }, error: null }) });
    vi.stubGlobal("fetch", fetchMock);
    await expect(api("/team/id", "PATCH", { version: 1, role: "merchant.viewer" }, "merchant-id")).resolves.toEqual({ saved: true });
    expect(fetchMock.mock.calls[0][0]).toBe("/sanctum/csrf-cookie");
    const [url, request] = fetchMock.mock.calls[1];
    expect(url).toBe("/api/v1/team/id");
    expect(request.credentials).toBe("same-origin");
    expect(request.headers["X-XSRF-TOKEN"]).toBe("encrypted=csrf");
    expect(request.headers["X-Merchant-ID"]).toBe("merchant-id");
    expect(request.headers.Authorization).toBeUndefined();
  });
  it("does not automatically retry a conflicting mutation", async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true }).mockResolvedValueOnce({ ok: false, status: 409, json: async () => ({ data: null, error: { code: "CONFLICT", details: {} } }) });
    vi.stubGlobal("fetch", fetchMock);
    await expect(api("/team/id", "PATCH", { version: 1 })).rejects.toBeInstanceOf(ApiError);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });
});
