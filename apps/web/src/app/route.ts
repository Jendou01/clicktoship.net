export function GET() {
  // Preserve the browser's public origin behind the reverse proxy.
  return new Response(null, { status: 307, headers: { Location: "/en" } });
}
