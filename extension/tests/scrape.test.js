import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { scrapeGamification, scrapeInsights } from '../lib/scrape.js'
import gamificationCapture from '../captures/gamification.js'

const read = (name) => readFileSync(new URL(`./fixtures/${name}`, import.meta.url), 'utf8')
const AT = '2026-07-21T05:39:09.018Z'

test('gamification: level, rank, and self identity', () => {
  const out = scrapeGamification(read('gamification-page.txt'), AT)

  assert.equal(out.__empty, false)
  assert.equal(out.level.level, 20)
  assert.equal(out.level.rank, 'Colt')
  assert.equal(out.user.public_name, 'Raja Ahmad Ayaz N.')
  assert.equal(out.source.scraped_at, AT)
})

test('gamification: leaderboard top and nearby with self flagged', () => {
  const out = scrapeGamification(read('gamification-page.txt'), AT)

  assert.equal(out.leaderboard.top.length, 5)
  assert.deepEqual(
    { rank: out.leaderboard.top[0].rank, name: out.leaderboard.top[0].public_name, score: out.leaderboard.top[0].score },
    { rank: 1, name: 'Chandrasekhar G.', score: 4593118 }
  )
  assert.equal(out.leaderboard.top.every((r) => r.is_current_user === false), true)

  const self = out.leaderboard.nearby.find((r) => r.is_current_user)
  assert.ok(self, 'a nearby row must be flagged as the current user')
  assert.equal(self.rank, 269)
  assert.equal(self.score, 310012)
  assert.equal(self.public_name, 'Raja Ahmad Ayaz N.')
  // GamificationController derives self_score from this flagged nearby entry.
  assert.equal(out.level.xp_total, 310012)
})

test('gamification: empty on unrecognizable text', () => {
  assert.equal(scrapeGamification('just some navigation text', AT).__empty, true)
})

test('insights: total earnings and 30-day, in the order the controller reads', () => {
  const out = scrapeInsights(read('insights-page.txt'), AT)

  assert.equal(out.__empty, false)
  // InsightsController reads userStats.totalEarnings[0].value and [1].value
  assert.equal(out.userStats.totalEarnings[0].value, '$363,466.04')
  assert.equal(out.userStats.totalEarnings[1].value, '$0.00')
  assert.equal(out.scraped_at, AT)
  assert.equal(out.marketplaceStats, null)
})

test('insights: bid summary and job proficiency', () => {
  const out = scrapeInsights(read('insights-page.txt'), AT)

  const remaining = out.userStats.bidSummary.find((b) => b.label === 'Bids Remaining')
  assert.equal(remaining.value, 51)

  const completed = out.userStats.jobProficiency.find((p) => p.label === 'Completed Jobs')
  assert.equal(completed.value, '99%')
  assert.equal(out.userStats.jobProficiency.length, 4)
})

test('insights: earnings per skill pairs', () => {
  const out = scrapeInsights(read('insights-page.txt'), AT)

  assert.deepEqual(out.userStats.earningsPerSkill[0], { name: 'PHP', value: '$264,756.45' })
  assert.ok(out.userStats.earningsPerSkill.length >= 5)
})

test('insights: empty on unrecognizable text', () => {
  assert.equal(scrapeInsights('just some navigation text', AT).__empty, true)
})

test('insights: a missing proficiency value does not steal the next section number', () => {
  const text = [
    'Job proficiency',
    'COMPLETED JOBS', '99%', '99%',
    'ON TIME JOBS', '95%', '95%',
    'ON BUDGET JOBS',           // <-- no value line for On Budget
    'REHIRE RATE', '24%', '24%',
  ].join('\n')
  const out = scrapeInsights(text, 'T')
  const onBudget = out.userStats.jobProficiency.find((p) => p.label === 'On Budget Jobs')
  assert.equal(onBudget, undefined, 'On Budget must be absent, not stolen from Rehire Rate')
  const rehire = out.userStats.jobProficiency.find((p) => p.label === 'Rehire Rate')
  assert.equal(rehire.value, '24%')
})

test('insights: not empty when only job proficiency rendered', () => {
  const text = ['Job proficiency', 'COMPLETED JOBS', '99%', '99%'].join('\n')
  assert.equal(scrapeInsights(text, 'T').__empty, false)
})

test('gamification: warns when no leaderboard row matches the profile', () => {
  const text = [
    'Someone Else',
    'Level 20 Colt',
    'Leaderboard', 'Rank\tUsername\tLevel\tScore',
    '1\tAlice A.\tLevel 20\t100',
    '268\tBob B.\tLevel 20\t50',
  ].join('\n')
  const body = gamificationCapture.scrape(text, 'T')
  const warnings = gamificationCapture.warnings(body)
  assert.ok(warnings.length >= 1, 'must warn when self is not found in the leaderboard')
})
