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
  matchPattern: 'https://www.freelancer.com/users/game/*',

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

  // Called with the *normalized* body (the same shape posted to the API), not
  // the raw page data. A capture with warnings is still `ok: true` — it
  // succeeded — but self-identification failing silently is exactly the kind
  // of thing that must show up in the report rather than vanish into a null
  // column server-side.
  warnings(body) {
    const warnings = []

    if (body.user.id === null) {
      warnings.push(
        'Could not determine current user id (raw.user.id and raw.current_user.id were both missing) — self identification will fail.'
      )
    }

    const hasSelfFlag = body.leaderboard.nearby.some((e) => e.is_current_user === true)
    if (!hasSelfFlag) {
      warnings.push(
        'No leaderboard.nearby entry is flagged is_current_user — self_rank/self_username/self_public_name will be stored as null.'
      )
    }

    return warnings
  },
}
