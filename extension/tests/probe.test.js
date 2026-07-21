import { test } from 'node:test'
import assert from 'node:assert/strict'
import { matchResponse } from '../lib/probe.js'

const cap = (url, body) => ({ url, method: 'GET', body })

test('finds keys nested several levels deep', () => {
  const captured = [cap('u', { a: { b: { userStats: { x: 1 }, marketplaceStats: { y: 2 } } } })]

  const r = matchResponse(captured, ['userStats', 'marketplaceStats'])

  assert.equal(r.strategy, 'xhr u')
  assert.deepEqual(r.data, { userStats: { x: 1 }, marketplaceStats: { y: 2 } })
})

test('prefers the object carrying MORE required keys (co-location)', () => {
  const captured = [
    cap('a', { level: 3 }),                                  // 1 key, unrelated
    cap('b', { result: { level: { xp: 9 }, leaderboard: { top: [] } } }), // 2 keys — the real blob
  ]

  const r = matchResponse(captured, ['leaderboard', 'level'])

  assert.equal(r.strategy, 'xhr b')
  assert.ok('leaderboard' in r.data && 'level' in r.data)
})

test('requireArray still gates: a non-array key does not match', () => {
  const notArray = [cap('u', { deep: { items: { count: 3 } } })]
  const isArray = [cap('u', { deep: { items: [1, 2] } })]

  assert.equal(matchResponse(notArray, ['items'], { requireArray: true }).strategy, null)
  assert.ok(matchResponse(isArray, ['items'], { requireArray: true }).strategy)
})

test('a single found key still matches (partial blob allowed)', () => {
  const r = matchResponse([cap('u', { wrap: { userStats: { x: 1 } } })], ['userStats', 'marketplaceStats'])
  assert.ok(r.strategy)
  assert.deepEqual(r.data, { userStats: { x: 1 } })
})

test('among equal-score matches, the richer object wins', () => {
  const captured = [
    cap('a', { userStats: 1 }),
    cap('b', { userStats: 1, extra: 2, more: 3 }),
  ]
  assert.equal(matchResponse(captured, ['userStats']).strategy, 'xhr b')
})

test('no match lists every endpoint and its keys', () => {
  const captured = [cap('https://x/a', { foo: 1 }), cap('https://x/b', [1, 2, 3])]

  const r = matchResponse(captured, ['userStats'])

  assert.equal(r.strategy, null)
  assert.equal(r.diagnostics.capturedCount, 2)
  assert.deepEqual(r.diagnostics.endpoints[0], { url: 'https://x/a', keys: ['foo'] })
  assert.equal(r.diagnostics.endpoints[1].keys, 'array[3]')
})

test('total on junk: undefined, nulls, primitives, cycles', () => {
  const cyclic = { a: 1 }
  cyclic.self = cyclic
  assert.doesNotThrow(() => matchResponse([null, {}, cap('u', null), cap('u', cyclic), 'x'], ['userStats']))
  assert.equal(matchResponse(undefined, ['userStats']).strategy, null)
})

test('throwing-getter-under-requireArray does not throw and returns no match', () => {
  const throwing = {
    get bids() {
      throw new Error('boom')
    },
  }

  let r
  assert.doesNotThrow(() => {
    r = matchResponse([cap('u', throwing)], ['bids', 'bidList', 'projects', 'items'], { requireArray: true })
  })
  assert.equal(r.strategy, null)
})

test('the real projects list beats a nested attachments.items array', () => {
  const projects = [
    { id: 1, title: 'Build a website', attachments: { items: [{ id: 'a1', name: 'spec.pdf' }], count: 1 } },
    { id: 2, title: 'Fix a bug' },
    { id: 3, title: 'Design a logo' },
  ]
  const captured = [cap('u', { projects })]

  const r = matchResponse(captured, ['bids', 'bidList', 'projects', 'items'], { requireArray: true })

  assert.equal(r.strategy, 'xhr u')
  assert.deepEqual(r.data, { projects })
})

test('the real projects list beats a shallow empty items: []', () => {
  const projects = [
    { id: 1, title: 'Build a website' },
    { id: 2, title: 'Fix a bug' },
  ]
  // `items: []` sits at the top level (depth 0, but empty); the real list is
  // nested one level deeper (depth 1). Depth must not outrank array size.
  const captured = [cap('u', { items: [], result: { projects } })]

  const r = matchResponse(captured, ['bids', 'bidList', 'projects', 'items'], { requireArray: true })

  assert.equal(r.strategy, 'xhr u')
  assert.deepEqual(r.data, { projects })
})
