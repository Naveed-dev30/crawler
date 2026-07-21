import { scrapeInsights } from '../lib/scrape.js'

export default {
  source: 'insights',
  url: 'https://www.freelancer.com/insights/#/userStats',
  path: '/api/insights/ingest',
  mode: 'scrape',
  activeTab: true,
  scrape: scrapeInsights,
}
