export default {
  source: 'insights',
  // Explicit hash route so the SPA lands on the userStats view, and activeTab so
  // the chart data actually fetches — a background (hidden) tab defers it.
  url: 'https://www.freelancer.com/insights/#/userStats',
  path: '/api/insights/ingest',
  requiredKeys: ['userStats', 'marketplaceStats'],
  matchPattern: 'https://www.freelancer.com/insights/*',
  activeTab: true,

  normalize(data, scrapedAt) {
    return {
      scraped_at: scrapedAt,
      userStats: data.userStats ?? null,
      marketplaceStats: data.marketplaceStats ?? null,
    }
  },
}
