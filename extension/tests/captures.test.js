import { test } from 'node:test'
import assert from 'node:assert/strict'
import gamification from '../captures/gamification.js'
import insights from '../captures/insights.js'
import insightsBids from '../captures/insights-bids.js'
import { CAPTURES } from '../captures/index.js'

const AT = '2026-07-20T09:00:00.000Z'

test('registry exposes all three sources', () => {
  assert.deepEqual(CAPTURES.map((c) => c.source).sort(), ['gamification', 'insights', 'insights_bids'])
})

test('every module has the required interface', () => {
  for (const c of CAPTURES) {
    assert.equal(typeof c.source, 'string')
    assert.equal(typeof c.url, 'string')
    assert.equal(typeof c.path, 'string')
    assert.ok(Array.isArray(c.requiredKeys) && c.requiredKeys.length > 0)
    assert.equal(typeof c.normalize, 'function')
  }
})

test('modules target the endpoints main actually exposes', () => {
  assert.equal(gamification.path, '/api/gamification/ingest')
  assert.equal(insights.path, '/api/insights/ingest')
  assert.equal(insightsBids.path, '/api/insights/bids/ingest')
})

test('insights normalize matches main InsightsController contract', () => {
  const data = { userStats: { totalEarnings: [1] }, marketplaceStats: { overallRanking: [2] }, extra: 'kept' }

  const out = insights.normalize(data, AT)

  assert.equal(out.scraped_at, AT)
  assert.deepEqual(out.userStats, { totalEarnings: [1] })
  assert.deepEqual(out.marketplaceStats, { overallRanking: [2] })
})

test('insights normalize tolerates a partial blob', () => {
  // Main's controller 422s only when BOTH sections are absent.
  const out = insights.normalize({ userStats: { a: 1 } }, AT)

  assert.deepEqual(out.userStats, { a: 1 })
  assert.equal(out.marketplaceStats, null)
})

test('bids normalize wraps the list with a crawl_type', () => {
  const out = insightsBids.normalize({ bids: [{ project_id: 1 }] }, AT)

  assert.equal(out.scraped_at, AT)
  assert.equal(out.crawl_type, 'initial')
  assert.deepEqual(out.bids, [{ project_id: 1 }])
})

test('bids normalize finds the list under an alternate key', () => {
  const out = insightsBids.normalize({ bidList: [{ project_id: 2 }] }, AT)

  assert.deepEqual(out.bids, [{ project_id: 2 }])
})

test('gamification normalize produces the contract GamificationController reads', () => {
  const raw = {
    user: { id: 7, username: 'u', public_name: 'U' },
    level: { level: 20, rank: 'Colt', xp_total: 300 },
    leaderboard: {
      top: [{ rank: 1, user_id: 9, username: 'a', public_name: 'A', level: 20, score: 500 }],
      nearby: [{ rank: 268, user_id: 7, username: 'u', public_name: 'U', level: 20, score: 300 }],
    },
  }

  const out = gamification.normalize(raw, AT)

  assert.equal(out.source.scraped_at, AT)
  assert.equal(out.source.url, 'https://www.freelancer.com/users/game/')
  assert.ok(Array.isArray(out.leaderboard.top))
  assert.equal(out.leaderboard.top[0].is_current_user, false)
  // The nearby entry matching our own user id must be flagged — the controller
  // derives self_rank/self_score from exactly that flag.
  assert.equal(out.leaderboard.nearby[0].is_current_user, true)
  assert.deepEqual(out.raw_source, raw)
})
