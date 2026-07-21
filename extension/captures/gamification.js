import { scrapeGamification } from '../lib/scrape.js'

export default {
  source: 'gamification',
  url: 'https://www.freelancer.com/users/game/',
  path: '/api/gamification/ingest',
  mode: 'scrape',
  scrape: scrapeGamification,
}
