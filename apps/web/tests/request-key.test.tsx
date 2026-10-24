import { afterEach, expect, it, vi } from "vitest";
import { requestKey } from "@/lib/request-key";
afterEach(() => vi.unstubAllGlobals());
it("uses getRandomValues when randomUUID is unavailable on a private HTTP origin", () => {
  const getRandomValues = vi.fn((bytes: Uint8Array) => { bytes.fill(255); return bytes; });
  vi.stubGlobal("crypto", { getRandomValues });
  expect(requestKey()).toBe("ffffffff-ffff-4fff-bfff-ffffffffffff");
  expect(getRandomValues).toHaveBeenCalledOnce();
});
it("retains the platform randomUUID implementation on secure origins", () => {
  const randomUUID = vi.fn(() => "synthetic-platform-uuid"); vi.stubGlobal("crypto", { randomUUID });
  expect(requestKey()).toBe("synthetic-platform-uuid"); expect(randomUUID).toHaveBeenCalledOnce();
});
