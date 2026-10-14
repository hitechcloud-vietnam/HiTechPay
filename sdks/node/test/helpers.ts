import type { FetchLike } from '../src';

export interface RecordedCall {
  url: string;
  method: string;
  headers: Record<string, string>;
  body?: string;
}

export type MockResponse = { status: number; body?: unknown; headers?: Record<string, string> } | Error;

/** A scripted fetch mock: each call consumes the next response (last one repeats). */
export function mockFetch(responses: MockResponse[]): { fetch: FetchLike; calls: RecordedCall[] } {
  const calls: RecordedCall[] = [];
  let i = 0;
  const fetch: FetchLike = async (url, init) => {
    calls.push({ url, method: init.method, headers: { ...init.headers }, body: init.body });
    const r = responses[Math.min(i++, responses.length - 1)];
    if (r instanceof Error) throw r;
    const text = r.body === undefined ? '' : typeof r.body === 'string' ? r.body : JSON.stringify(r.body);
    const headers = r.headers ?? {};
    return {
      status: r.status,
      ok: r.status >= 200 && r.status < 300,
      headers: { forEach: (cb: (v: string, k: string) => void) => Object.entries(headers).forEach(([k, v]) => cb(v, k)) },
      text: async () => text,
    };
  };
  return { fetch, calls };
}
