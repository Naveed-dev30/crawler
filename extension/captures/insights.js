import { scrapeUserStats, scrapeMarketplace } from '../lib/scrape.js'

const BASE = 'https://www.freelancer.com/insights/'

export default {
  source: 'insights',
  url: BASE + '#/userStats',
  path: '/api/insights/ingest',
  mode: 'scrape',
  // Daily: the dashboard must render in a visible tab to be scraped, so it opens
  // in the foreground. Running it hourly would pop a tab to the front every hour.
  cadence: 'daily',
  activeTab: true,
  views: [
    { key: 'userStats', url: BASE + '#/userStats', scrape: scrapeUserStats },
    { key: 'marketplaceStats', url: BASE + '#/marketplaceStats', scrape: scrapeMarketplace },
  ],
  combine(collected, scrapedAt) {
    return {
      __empty: !collected.userStats && !collected.marketplaceStats,
      scraped_at: scrapedAt,
      userStats: collected.userStats || null,
      marketplaceStats: collected.marketplaceStats || null,
    }
  },
  warnings(body) {
    const w = []
    if (!body.userStats) w.push('User Statistics tab produced no data.')
    if (!body.marketplaceStats) w.push('Marketplace Statistics tab produced no data.')
    // Trend directions are read off the arrow icons, so a markup change breaks
    // them silently: the payload still lists every skill, just all neutral. A
    // whole widget with no movement at all is the signal that the read broke.
    const trending = (body.marketplaceStats && body.marketplaceStats.trendingSkills) || []
    if (trending.length && trending.every((s) => (s.direction ?? 'even') === 'even')) {
      w.push('Trending skills captured with no up/down directions — the arrow markup may have changed.')
    }
    return w
  },
}
