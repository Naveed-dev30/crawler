import { test } from 'node:test'
import assert from 'node:assert/strict'
import { postCapture, MissingConfigError } from '../lib/http.js'

// http.js resolves config and fetch at call time, so a static import is fine.
function stubChrome(config) {
  globalThis.chrome = {
    storage: { local: { async get() { return config }, async set() {} } },
  }
}

test('posts to the configured base URL with a bearer token', async () => {
  stubChrome({ apiBaseUrl: 'https://crawler.test', token: 'secret' })
  const calls = []
  globalThis.fetch = async (url, opts) => {
    calls.push({ url, opts })
    return { ok: true, status: 200, async json() { return { success: true } } }
  }

  const result = await postCapture('/api/insights/ingest', { source: 'insights' })

  assert.equal(calls[0].url, 'https://crawler.test/api/insights/ingest')
  assert.equal(calls[0].opts.headers.Authorization, 'Bearer secret')
  assert.equal(calls[0].opts.headers['Content-Type'], 'application/json')
  assert.equal(calls[0].opts.body, JSON.stringify({ source: 'insights' }))
  assert.deepEqual(result, { ok: true, status: 200, data: { success: true } })
})

test('returns ok:false with the status on a non-2xx response', async () => {
  stubChrome({ apiBaseUrl: 'https://crawler.test', token: 'secret' })
  globalThis.fetch = async () => ({
    ok: false,
    status: 401,
    async json() { return { message: 'Unauthorized' } },
  })

  const result = await postCapture('/api/insights/ingest', {})

  assert.equal(result.ok, false)
  assert.equal(result.status, 401)
})

test('throws MissingConfigError when the token is unset', async () => {
  stubChrome({ apiBaseUrl: 'https://crawler.test', token: '' })

  await assert.rejects(() => postCapture('/api/insights/ingest', {}), MissingConfigError)
})

test('throws MissingConfigError when the base URL is unset', async () => {
  stubChrome({ apiBaseUrl: '', token: 'secret' })

  await assert.rejects(() => postCapture('/api/insights/ingest', {}), MissingConfigError)
})

// Without this test, deleting the try/catch around response.json() would still
// pass every other test — none of their stubs ever reject.
test('yields data:null when the response body is not valid JSON', async () => {
  stubChrome({ apiBaseUrl: 'https://crawler.test', token: 'secret' })
  globalThis.fetch = async () => ({
    ok: true,
    status: 200,
    async json() { throw new SyntaxError('Unexpected token < in JSON at position 0') },
  })

  const result = await postCapture('/api/insights/ingest', {})

  assert.deepEqual(result, { ok: true, status: 200, data: null })
})

// A rejected fetch (DNS failure, offline) intentionally propagates: the service
// worker's retry layer classifies it as retryable, unlike MissingConfigError.
test('propagates a rejected fetch rather than swallowing it', async () => {
  stubChrome({ apiBaseUrl: 'https://crawler.test', token: 'secret' })
  globalThis.fetch = async () => { throw new TypeError('Failed to fetch') }

  await assert.rejects(() => postCapture('/api/insights/ingest', {}), TypeError)
})

// Missing config can never resolve itself between retries — only the user
// filling in the options page fixes it. Without `.fatal`, withRetry burns the
// full 2s/8s/32s backoff before this reaches the report.
test('MissingConfigError is marked fatal so withRetry does not retry it', () => {
  assert.equal(new MissingConfigError().fatal, true)
})
