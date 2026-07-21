const LIST_KEYS = ['bids', 'bidList', 'projects', 'items']

export default {
  source: 'insights_bids',
  url: 'https://www.freelancer.com/insights/bids',
  path: '/api/insights/bids/ingest',
  // Bid activity changes fast; capture it hourly. It intercepts a background
  // request, so an hourly run never steals focus.
  cadence: 'hourly',
  requiredKeys: LIST_KEYS,
  matchPattern: 'https://www.freelancer.com/insights/bids*',
  // requiredKeys here are generic enough ('items', 'projects'...) that plain
  // key-presence matching can hit an unrelated global. Require that at least
  // one matching key actually holds an array before accepting a candidate.
  probeOptions: { requireArray: true },

  normalize(data, scrapedAt) {
    const key = LIST_KEYS.find((k) => Array.isArray(data[k]))

    return {
      scraped_at: scrapedAt,
      // Main's ingest treats 'initial' as the first full crawl. We always send
      // the full list we can see, so 'initial' is honest; incremental crawling
      // is a later refinement.
      crawl_type: 'initial',
      bids: key ? data[key] : [],
    }
  },
}
