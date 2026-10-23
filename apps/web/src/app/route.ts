import { NextResponse } from "next/server";
export function GET(request: Request) { return NextResponse.redirect(new URL("/en", request.url), 307); }
