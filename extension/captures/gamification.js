import { scrapeGamification } from '../lib/scrape.js'

export default {
  source: 'gamification',
  url: 'https://www.freelancer.com/users/game/',
  path: '/api/gamification/ingest',
  mode: 'scrape',
  scrape: scrapeGamification,

  warnings(body) {
    const w = []
    if (!body.user || !body.user.public_name) {
      w.push('Profile name not found on the page — cannot identify self in the leaderboard.')
    } else if (!(body.leaderboard?.nearby || []).some((r) => r.is_current_user)) {
      w.push('No leaderboard row matched the profile name — self rank/score will be null.')
    }
    return w
  },
}
