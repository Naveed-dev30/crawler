import { test } from 'node:test'
import assert from 'node:assert/strict'
import { withRetry, BACKOFF_MS } from '../lib/retry.js'

const noSleep = async () => {}

test('returns the result on first success without retrying', async () => {
  let calls = 0
  const result = await withRetry(async () => { calls++; return 'ok' }, noSleep)

  assert.equal(result, 'ok')
  assert.equal(calls, 1)
})

test('retries up to three attempts then throws', async () => {
  let calls = 0
  const boom = async () => { calls++; throw new Error('nope') }

  await assert.rejects(() => withRetry(boom, noSleep), /nope/)
  assert.equal(calls, 3)
})

test('succeeds on the third attempt', async () => {
  let calls = 0
  const flaky = async () => {
    calls++
    if (calls < 3) throw new Error('transient')
    return 'recovered'
  }

  assert.equal(await withRetry(flaky, noSleep), 'recovered')
  assert.equal(calls, 3)
})

test('does not retry errors marked as fatal', async () => {
  let calls = 0
  const fatal = async () => {
    calls++
    const e = new Error('bad token')
    e.fatal = true
    throw e
  }

  await assert.rejects(() => withRetry(fatal, noSleep), /bad token/)
  assert.equal(calls, 1)
})

test('backoff schedule is 2s, 8s, 32s', () => {
  assert.deepEqual(BACKOFF_MS, [2000, 8000, 32000])
})

test('sleeps for the scheduled backoff between attempts', async () => {
  const slept = []
  let calls = 0
  const flaky = async () => {
    calls++
    if (calls < 3) throw new Error('transient')
    return 'ok'
  }

  await withRetry(flaky, async (ms) => { slept.push(ms) })

  assert.deepEqual(slept, [2000, 8000])
})
