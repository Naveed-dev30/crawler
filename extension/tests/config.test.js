import { test } from 'node:test'
import assert from 'node:assert/strict'
import { getConfig, setConfig } from '../lib/config.js'

// Minimal chrome.storage.local stub — the extension only uses get/set.
// config.js reads globalThis.chrome at call time, so a plain static import
// works and each test just resets the stub.
function stubChrome(initial = {}) {
  let store = { ...initial }
  globalThis.chrome = {
    storage: {
      local: {
        async get(keys) {
          if (typeof keys === 'string') return { [keys]: store[keys] }
          return Object.fromEntries(Object.keys(keys).map((k) => [k, store[k] ?? keys[k]]))
        },
        async set(obj) {
          store = { ...store, ...obj }
        },
      },
    },
  }
}

test('getConfig returns empty defaults when nothing stored', async () => {
  stubChrome()
  assert.deepEqual(await getConfig(), { apiBaseUrl: '', token: '' })
})

test('setConfig then getConfig round-trips', async () => {
  stubChrome()

  await setConfig({ apiBaseUrl: 'https://crawler.test', token: 'abc' })

  assert.deepEqual(await getConfig(), { apiBaseUrl: 'https://crawler.test', token: 'abc' })
})

test('setConfig strips a trailing slash from apiBaseUrl', async () => {
  stubChrome()

  await setConfig({ apiBaseUrl: 'https://crawler.test/', token: 'abc' })

  assert.equal((await getConfig()).apiBaseUrl, 'https://crawler.test')
})
