import { test } from 'node:test'
import assert from 'node:assert/strict'
import { matchResponse } from '../lib/probe.js'

const cap = (url, body) => ({ url, method: 'GET', body })

test('matches a top-level response carrying the required keys', () => {
  const captured = [
    cap('https://www.freelancer.com/api/other', { unrelated: 1 }),
    cap('https://www.freelancer.com/api/insights/user', { userStats: { a: 1 }, marketplaceStats: { b: 2 } }),
  ]

  const r = matchResponse(captured, ['userStats', 'marketplaceStats'])

  assert.equal(r.strategy, 'xhr https://www.freelancer.com/api/insights/user')
  assert.deepEqual(r.data, { userStats: { a: 1 }, marketplaceStats: { b: 2 } })
})

test('unwraps a common API envelope (result/data/payload)', () => {
  const captured = [cap('u', { status: 'success', result: { userStats: { a: 1 } } })]

  const r = matchResponse(captured, ['userStats', 'marketplaceStats'])

  assert.equal(r.strategy, 'xhr u')
  assert.deepEqual(r.data, { userStats: { a: 1 } })
})

test('accepts a partial blob (only one required key present)', () => {
  const r = matchResponse([cap('u', { userStats: { a: 1 } })], ['userStats', 'marketplaceStats'])
  assert.ok(r.strategy)
})

test('requireArray rejects a match whose key is not an array', () => {
  const notArray = [cap('u', { items: { count: 3 } })]
  const isArray = [cap('u', { items: [1, 2] })]

  assert.equal(matchResponse(notArray, ['items'], { requireArray: true }).strategy, null)
  assert.ok(matchResponse(isArray, ['items'], { requireArray: true }).strategy)
})

test('diagnostics list every endpoint and its keys when nothing matches', () => {
  const captured = [
    cap('https://www.freelancer.com/api/a', { foo: 1, bar: 2 }),
    cap('https://www.freelancer.com/api/b', [1, 2, 3]),
  ]

  const r = matchResponse(captured, ['userStats'])

  assert.equal(r.strategy, null)
  assert.equal(r.data, null)
  assert.equal(r.diagnostics.capturedCount, 2)
  assert.deepEqual(r.diagnostics.endpoints[0], {
    url: 'https://www.freelancer.com/api/a',
    keys: ['foo', 'bar'],
  })
  assert.equal(r.diagnostics.endpoints[1].keys, 'array[3]')
})

test('tolerates junk entries without throwing', () => {
  assert.doesNotThrow(() => matchResponse([null, {}, cap('u', null), 'x'], ['userStats']))
  assert.equal(matchResponse(undefined, ['userStats']).strategy, null)
})
