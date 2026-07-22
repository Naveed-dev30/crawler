import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { scrapeGamification, scrapeUserStats, scrapeMarketplace } from '../lib/scrape.js'
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
  const out = scrapeUserStats(read('insights-page.txt'))

  assert.notEqual(out, null)
  // InsightsController reads userStats.totalEarnings[0].value and [1].value
  assert.equal(out.totalEarnings[0].value, '$363,466.04')
  assert.equal(out.totalEarnings[1].value, '$0.00')
})

test('insights: bid summary and job proficiency', () => {
  const out = scrapeUserStats(read('insights-page.txt'))

  const remaining = out.bidSummary.find((b) => b.label === 'Bids Remaining')
  assert.equal(remaining.value, 51)

  const completed = out.jobProficiency.find((p) => p.label === 'Completed Jobs')
  assert.equal(completed.value, '99%')
  assert.equal(out.jobProficiency.length, 4)
})

test('insights: earnings per skill pairs', () => {
  const out = scrapeUserStats(read('insights-page.txt'))

  assert.deepEqual(out.earningsPerSkill[0], { name: 'PHP', value: '$264,756.45' })
  assert.ok(out.earningsPerSkill.length >= 5)
})

test('insights: rating per skill captures the skill list (ratings are star icons, not text)', () => {
  const out = scrapeUserStats(read('insights-page.txt'))

  assert.deepEqual(out.ratingPerSkill, [
    { name: 'WooCommerce' },
    { name: 'WordPress Plugin' },
    { name: 'After Effects' },
  ])
})

test('insights: rating per skill uses DOM star values when the worker supplies them', () => {
  // The worker extracts each skill's data-star_rating attribute and passes it as
  // dom.ratingPerSkill; the scraper must prefer that over the text-only name list.
  const dom = { ratingPerSkill: [{ name: 'WooCommerce', value: '5.0' }, { name: 'Laravel', value: '4.8' }] }
  const out = scrapeUserStats(read('insights-page.txt'), dom)

  assert.deepEqual(out.ratingPerSkill, [
    { name: 'WooCommerce', value: '5.0' },
    { name: 'Laravel', value: '4.8' },
  ])
})

test('insights: rating per skill is bounded — a missing end heading yields none', () => {
  // '51'/'BIDS REMAINING' keeps userStats non-empty; the rating section has no
  // 'Bid conversion' end heading, so it must not pull in the footer that follows.
  const text = ['51', 'BIDS REMAINING', 'Rating per skill', 'WooCommerce', 'Network', 'Privacy Policy'].join('\n')
  const out = scrapeUserStats(text)

  assert.notEqual(out, null)
  assert.deepEqual(out.ratingPerSkill, [])
})

test('insights: empty on unrecognizable text', () => {
  assert.equal(scrapeUserStats('just some navigation text'), null)
})

test('insights: a missing proficiency value does not steal the next section number', () => {
  const text = [
    'Job proficiency',
    'COMPLETED JOBS', '99%', '99%',
    'ON TIME JOBS', '95%', '95%',
    'ON BUDGET JOBS',           // <-- no value line for On Budget
    'REHIRE RATE', '24%', '24%',
  ].join('\n')
  const out = scrapeUserStats(text)
  const onBudget = out.jobProficiency.find((p) => p.label === 'On Budget Jobs')
  assert.equal(onBudget, undefined, 'On Budget must be absent, not stolen from Rehire Rate')
  const rehire = out.jobProficiency.find((p) => p.label === 'Rehire Rate')
  assert.equal(rehire.value, '24%')
})

test('insights: not empty when only job proficiency rendered', () => {
  const text = ['Job proficiency', 'COMPLETED JOBS', '99%', '99%'].join('\n')
  assert.notEqual(scrapeUserStats(text), null)
})

test('marketplace: overall ranking, bids per milestone, and list sections', () => {
  const out = scrapeMarketplace(read('marketplace-page.txt'))

  assert.notEqual(out, null)
  assert.equal(out.overallRanking[0].value, '25%')
  assert.equal(out.bidsPerMilestoneMarketplace, '18.50')

  assert.deepEqual(out.rankingPerSkill[0], { name: 'JSON', value: 'Top 9%' })
  assert.ok(out.rankingPerSkill.length >= 10)

  const photography = out.highDemandSkills.find((s) => s.name === 'Photography')
  assert.equal(photography.value, '+48%')
  const contentCreation = out.highDemandSkills.find((s) => s.name === 'Content Creation')
  assert.equal(contentCreation.value, 'New')

  assert.ok(out.trendingSkills.length >= 15)
  assert.equal(out.trendingSkills[0].name, 'Graphic Design')
})

test('marketplace: empty on unrecognizable text', () => {
  assert.equal(scrapeMarketplace('just navigation text'), null)
})

test('marketplace: profile-view chart data from the worker is passed through', () => {
  const dom = {
    profileViewCountPastWeek: { labels: ['Mon', 'Tue'], values: [3, 5] },
    profileViewCountPastYear: { labels: ['Jan'], values: [42] },
  }
  const out = scrapeMarketplace(read('marketplace-page.txt'), dom)

  assert.deepEqual(out.profileViewCountPastWeek, { labels: ['Mon', 'Tue'], values: [3, 5] })
  assert.deepEqual(out.profileViewCountPastYear, { labels: ['Jan'], values: [42] })
})

test('marketplace: profile-view counts are null when the worker supplies no chart data', () => {
  const out = scrapeMarketplace(read('marketplace-page.txt'))

  assert.equal(out.profileViewCountPastWeek, null)
  assert.equal(out.profileViewCountPastYear, null)
})

test('marketplace: trending skills does not swallow the footer if the end heading is missing', () => {
  // No 'Overall ranking' heading, so trending cannot be bounded. It must come
  // back empty rather than pulling nav/footer lines into trendingSkills.
  const text = [
    'Trending skills',
    'Graphic Design', 'PHP',
    'Network', 'Privacy Policy', 'Copyright © 2026 Freelancer',
  ].join('\n')

  assert.equal(scrapeMarketplace(text), null)
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
