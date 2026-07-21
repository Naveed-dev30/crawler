import { test } from 'node:test'
import assert from 'node:assert/strict'
import gamification from '../captures/gamification.js'
import insights from '../captures/insights.js'
import insightsBids from '../captures/insights-bids.js'
import { CAPTURES } from '../captures/index.js'
import { scrapeGamification, scrapeInsights } from '../lib/scrape.js'

const AT = '2026-07-20T09:00:00.000Z'

test('registry exposes all three sources', () => {
  assert.deepEqual(CAPTURES.map((c) => c.source).sort(), ['gamification', 'insights', 'insights_bids'])
})

test('every module has the required interface', () => {
  for (const c of CAPTURES) {
    assert.equal(typeof c.source, 'string')
    assert.equal(typeof c.url, 'string')
    assert.equal(typeof c.path, 'string')

    if (c.mode === 'scrape') {
      assert.equal(typeof c.scrape, 'function')
    } else {
      assert.ok(Array.isArray(c.requiredKeys) && c.requiredKeys.length > 0)
      assert.equal(typeof c.normalize, 'function')
      assert.equal(typeof c.matchPattern, 'string')
      assert.ok(c.matchPattern.length > 0)
    }
  }
})

test('gamification and insights are scrape-mode captures delegating to lib/scrape.js', () => {
  assert.equal(gamification.mode, 'scrape')
  assert.equal(typeof gamification.scrape, 'function')
  assert.equal(gamification.scrape, scrapeGamification)

  assert.equal(insights.mode, 'scrape')
  assert.equal(typeof insights.scrape, 'function')
  assert.equal(insights.scrape, scrapeInsights)
})

test('bids stays on the interception path: path and requiredKeys still present', () => {
  assert.notEqual(insightsBids.mode, 'scrape')
  assert.equal(typeof insightsBids.path, 'string')
  assert.ok(Array.isArray(insightsBids.requiredKeys) && insightsBids.requiredKeys.length > 0)
  assert.equal(insightsBids.matchPattern, 'https://www.freelancer.com/insights/bids*')
})

test('modules target the endpoints main actually exposes', () => {
  assert.equal(gamification.path, '/api/gamification/ingest')
  assert.equal(insights.path, '/api/insights/ingest')
  assert.equal(insightsBids.path, '/api/insights/bids/ingest')
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

// The bids capture's requiredKeys ('bids', 'bidList', 'projects', 'items')
// are generic enough that key-presence-only matching can hit an unrelated
// global. This declares the opt-in that makes probe.js require an actual
// array value before accepting a candidate — see probe.test.js for proof
// that the option itself is non-vacuous.
test('bids capture declares requireArray so the probe cannot false-match a non-list global', () => {
  assert.deepEqual(insightsBids.probeOptions, { requireArray: true })
})
