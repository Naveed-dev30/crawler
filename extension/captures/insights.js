export default {
  source: 'insights',
  url: 'https://www.freelancer.com/insights/',
  path: '/api/insights/ingest',
  requiredKeys: ['userStats', 'marketplaceStats'],
  matchPattern: 'https://www.freelancer.com/insights/*',

  normalize(data, scrapedAt) {
    return {
      scraped_at: scrapedAt,
      userStats: data.userStats ?? null,
      marketplaceStats: data.marketplaceStats ?? null,
    }
  },
}
