import { scrapeInsights } from '../lib/scrape.js'

export default {
  source: 'insights',
  url: 'https://www.freelancer.com/insights/#/userStats',
  path: '/api/insights/ingest',
  mode: 'scrape',
  // Daily: the dashboard must render in a visible tab to be scraped, so it opens
  // in the foreground. Running it hourly would pop a tab to the front every hour.
  cadence: 'daily',
  activeTab: true,
  scrape: scrapeInsights,
}
