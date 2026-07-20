import { test } from 'node:test'
import assert from 'node:assert/strict'
import { findPageData } from '../lib/probe.js'

function stubPage({ globals = {}, scripts = [], title = 'Insights', url = 'https://www.freelancer.com/insights/' }) {
  globalThis.window = { ...globals }
  globalThis.document = {
    title,
    querySelectorAll: () => scripts.map((textContent) => ({ textContent })),
  }
  globalThis.location = { href: url }
}

test('finds data on a known global', () => {
  stubPage({ globals: { serverData: { userStats: { a: 1 }, marketplaceStats: { b: 2 } } } })

  const result = findPageData(['userStats', 'marketplaceStats'])

  assert.equal(result.strategy, 'window.serverData')
  assert.deepEqual(result.data, { userStats: { a: 1 }, marketplaceStats: { b: 2 } })
})

test('accepts an object carrying only one of the required keys', () => {
  // Main's spec allows partial blobs: the 1h cycle may send only some sections.
  stubPage({ globals: { serverData: { userStats: { a: 1 } } } })

  assert.equal(findPageData(['userStats', 'marketplaceStats']).strategy, 'window.serverData')
})

test('falls back to scanning globals when the name is unknown', () => {
  stubPage({ globals: { __weirdName: { userStats: { a: 1 } } } })

  const result = findPageData(['userStats', 'marketplaceStats'])

  assert.equal(result.strategy, 'window.__weirdName (scan)')
  assert.deepEqual(result.data, { userStats: { a: 1 } })
})

test('extracts from an inline script assignment', () => {
  stubPage({
    globals: {},
    scripts: ['var x = 1; window.serverData = {"userStats":{"a":1}}; more()'],
  })

  const result = findPageData(['userStats', 'marketplaceStats'])

  assert.equal(result.strategy, 'inline script')
  assert.deepEqual(result.data, { userStats: { a: 1 } })
})

test('reports diagnostics when nothing matches', () => {
  stubPage({
    globals: { unrelated: { nope: true } },
    scripts: ['console.log("hi")', 'var userStats = "mentioned but not assigned"'],
    title: 'Freelancer Insights',
  })

  const result = findPageData(['userStats', 'marketplaceStats'])

  assert.equal(result.strategy, null)
  assert.equal(result.data, null)
  assert.equal(result.diagnostics.inlineScripts, 2)
  assert.equal(result.diagnostics.scriptsMentioningKeys, 1)
  assert.equal(result.diagnostics.title, 'Freelancer Insights')
  assert.ok(Array.isArray(result.diagnostics.globalsSampled))
})

test('never throws on a global that errors when read', () => {
  globalThis.window = {}
  Object.defineProperty(globalThis.window, 'boobytrap', {
    enumerable: true,
    get() { throw new Error('cross-origin') },
  })
  globalThis.document = { title: 't', querySelectorAll: () => [] }
  globalThis.location = { href: 'https://www.freelancer.com/insights/' }

  assert.doesNotThrow(() => findPageData(['userStats']))
})

// The discriminating case for finding 2: the bids capture's requiredKeys
// ('bids', 'bidList', 'projects', 'items') are generic enough that plain key
// presence can match an unrelated global whose 'items' property isn't a list
// at all. Without requireArray this test's candidate would be a false match.
test('requireArray rejects a candidate whose only matching key is not an array', () => {
  stubPage({ globals: { unrelatedGlobal: { items: { count: 3 } } } })

  const withoutOption = findPageData(['bids', 'items'])
  assert.equal(withoutOption.strategy, 'window.unrelatedGlobal (scan)', 'sanity: proves the option is non-vacuous')

  const withOption = findPageData(['bids', 'items'], { requireArray: true })
  assert.equal(withOption.strategy, null)
  assert.equal(withOption.data, null)
})

test('requireArray accepts a candidate whose matching key value is an actual array', () => {
  stubPage({ globals: { serverData: { bids: [{ project_id: 1 }] } } })

  const result = findPageData(['bids', 'items'], { requireArray: true })

  assert.equal(result.strategy, 'window.serverData')
  assert.deepEqual(result.data, { bids: [{ project_id: 1 }] })
})

test('requireArray only needs one of several required keys to be an array', () => {
  stubPage({ globals: { serverData: { projects: 'not-a-list', items: [1, 2] } } })

  const result = findPageData(['bids', 'bidList', 'projects', 'items'], { requireArray: true })

  assert.equal(result.strategy, 'window.serverData')
})

test('requireArray defaults to off (key presence only) when options is omitted', () => {
  stubPage({ globals: { serverData: { bids: 'not-a-list' } } })

  assert.doesNotThrow(() => findPageData(['bids']))
  assert.equal(findPageData(['bids']).strategy, 'window.serverData')
})

test('an undefined options argument never throws', () => {
  stubPage({ globals: { serverData: { userStats: { a: 1 } } } })

  assert.doesNotThrow(() => findPageData(['userStats'], undefined))
})
