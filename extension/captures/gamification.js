const URL = 'https://www.freelancer.com/users/game/'

function entry(e, currentUserId) {
  const id = e.user_id ?? e.id ?? null

  return {
    rank: e.rank ?? null,
    user_id: id,
    username: e.username ?? null,
    public_name: e.public_name ?? e.display_name ?? null,
    level: e.level ?? null,
    score: e.score ?? e.xp ?? null,
    is_current_user: id !== null && id === currentUserId,
  }
}

export default {
  source: 'gamification',
  url: URL,
  path: '/api/gamification/ingest',
  requiredKeys: ['leaderboard', 'level'],

  normalize(raw, scrapedAt) {
    const currentUserId = raw.user?.id ?? raw.current_user?.id ?? null

    return {
      source: { site: 'Freelancer.com', url: URL, scraped_at: scrapedAt },
      user: {
        id: currentUserId,
        username: raw.user?.username ?? null,
        public_name: raw.user?.public_name ?? null,
      },
      level: {
        level: raw.level?.level ?? null,
        rank: raw.level?.rank ?? null,
        xp_total: raw.level?.xp_total ?? null,
      },
      leaderboard: {
        top: (raw.leaderboard?.top ?? []).map((e) => entry(e, currentUserId)),
        nearby: (raw.leaderboard?.nearby ?? []).map((e) => entry(e, currentUserId)),
      },
      raw_source: raw,
    }
  },
}
