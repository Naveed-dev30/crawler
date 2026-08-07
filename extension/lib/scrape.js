const GAME_URL = 'https://www.freelancer.com/users/game/'

function toInt(s) {
  const n = String(s ?? '').replace(/[^\d]/g, '')
  return n === '' ? null : parseInt(n, 10)
}

export function scrapeGamification(text, scrapedAt) {
  const lines = String(text ?? '').split('\n').map((l) => l.trim())

  let level = null
  let rank = null
  let publicName = null

  // Profile line, e.g. "Level 20 Colt". The rank is a capitalized word, which
  // distinguishes it from "Level 20 congratulations!" (lowercase) and from a
  // leaderboard row (followed by a score, not a word).
  for (let i = 0; i < lines.length; i++) {
    const m = lines[i].match(/^Level (\d+) ([A-Z][A-Za-z]+)$/)
    if (m) {
      level = toInt(m[1])
      rank = m[2]
      for (let j = i - 1; j >= 0; j--) {
        if (lines[j]) { publicName = lines[j]; break }
      }
      break
    }
  }

  // Leaderboard rows: "<rank> <name> Level <n> <score>".
  const rowRe = /^(\d+)\s+(.+?)\s+Level (\d+)\s+([\d,]+)$/
  const rows = []
  for (const line of lines) {
    const m = line.match(rowRe)
    if (m) {
      rows.push({
        rank: toInt(m[1]),
        user_id: null,
        username: null,
        public_name: m[2].trim(),
        level: toInt(m[3]),
        score: toInt(m[4]),
      })
    }
  }

  const flag = (r) => ({ ...r, is_current_user: publicName != null && r.public_name === publicName })
  const top = rows.filter((r) => r.rank <= 5).map(flag)
  const nearby = rows.filter((r) => r.rank > 5).map(flag)
  const self = nearby.find((r) => r.is_current_user) || null

  return {
    __empty: top.length === 0 && nearby.length === 0 && level === null,
    source: { site: 'Freelancer.com', url: GAME_URL, scraped_at: scrapedAt },
    user: { id: null, username: null, public_name: publicName },
    level: { level, rank, xp_total: self ? self.score : null },
    leaderboard: { top, nearby },
    raw_source: { scraped_via: 'dom-innertext' },
  }
}

const MONEY = /^\$[\d,]+\.\d{2}$/

// An all-caps label line (section/metric heading), e.g. "REHIRE RATE",
// "YOUR TOTAL EARNINGS SINCE JOINING FREELANCER". Used as a scan boundary so a
// missing value never steals a neighbouring metric's number.
const isLabelLine = (l) => typeof l === 'string' && /^[A-Z][A-Z0-9 /&.-]+$/.test(l) && l.length > 2

export function scrapeUserStats(text, dom) {
  const lines = String(text ?? '').split('\n').map((l) => l.trim())
  const indexOf = (label) => lines.findIndex((l) => l === label)

  // Value on a line shortly BEFORE a label line (the label sits under its number).
  const before = (label, re) => {
    const i = indexOf(label)
    if (i < 0) return null
    for (let j = i - 1; j >= Math.max(0, i - 4); j--) {
      if (re.test(lines[j])) return lines[j]
      if (lines[j] !== label && isLabelLine(lines[j])) return null
    }
    return null
  }
  // Value on a line shortly AFTER a label line.
  const after = (label, re) => {
    const i = indexOf(label)
    if (i < 0) return null
    for (let j = i + 1; j < Math.min(i + 6, lines.length); j++) {
      if (re.test(lines[j])) return lines[j]
      if (lines[j] !== label && isLabelLine(lines[j])) return null
    }
    return null
  }

  const total = before('YOUR TOTAL EARNINGS SINCE JOINING FREELANCER', MONEY)
  const last30 = before('YOUR TOTAL EARNINGS FROM THE PAST 30 DAYS', MONEY)
  const bidsRemaining = before('BIDS REMAINING', /^\d+$/)

  const jobProficiency = [
    ['Completed Jobs', 'COMPLETED JOBS'],
    ['On Time Jobs', 'ON TIME JOBS'],
    ['On Budget Jobs', 'ON BUDGET JOBS'],
    ['Rehire Rate', 'REHIRE RATE'],
  ]
    .map(([label, anchor]) => ({ label, value: after(anchor, /^\d+%$/) }))
    .filter((x) => x.value)

  // Earnings per skill: (name, $amount) pairs between the two section headings.
  const start = indexOf('Earnings per skill')
  const end = indexOf('Earnings per client')
  const earningsPerSkill = []
  if (start >= 0) {
    const slice = lines.slice(start + 1, end < 0 ? lines.length : end)
    for (let k = 0; k < slice.length - 1; k++) {
      const name = slice[k]
      const value = slice[k + 1]
      if (name && !MONEY.test(name) && MONEY.test(value)) {
        earningsPerSkill.push({ name, value })
        k++
      }
    }
  }

  // Rating per skill: the star values live in a `data-star_rating` DOM attribute,
  // NOT in the page text — so prefer the DOM-extracted [{name, value}] the worker
  // passes in. Fall back to a text-only skill list (bounded by 'Bid conversion')
  // when no DOM data is available, e.g. in unit tests or if the markup changes.
  const rpStart = indexOf('Rating per skill')
  const rpEnd = indexOf('Bid conversion')
  const ratingNames = (rpStart >= 0 && rpEnd > rpStart)
    ? lines.slice(rpStart + 1, rpEnd).map((name) => ({ name }))
    : []
  const domRatings = dom && Array.isArray(dom.ratingPerSkill) ? dom.ratingPerSkill : []
  const ratingPerSkill = domRatings.length ? domRatings : ratingNames

  const userStats = {
    totalEarnings: [{ value: total }, { value: last30 }],
    bidSummary: [{ label: 'Bids Remaining', value: bidsRemaining ? toInt(bidsRemaining) : null }],
    jobProficiency,
    earningsPerSkill,
    ratingPerSkill,
  }
  const empty = !total && !bidsRemaining && earningsPerSkill.length === 0 &&
    jobProficiency.length === 0 && ratingPerSkill.length === 0
  return empty ? null : userStats
}

const PCT = /^[+-]\d+%$/
const RANK = /^Top \d+%$/

// The dashboard renders three trend states; anything else the DOM read reports
// is treated as unknown, which shows as the neutral 'even' marker rather than a
// guessed arrow.
const normalizeDirection = (d) => {
  const v = String(d ?? '').trim().toLowerCase()
  return v === 'up' || v === 'down' ? v : 'even'
}

export function scrapeMarketplace(text, dom) {
  const lines = String(text ?? '').split('\n').map((l) => l.trim()).filter((l) => l.length)
  const idx = (label, from = 0) => lines.indexOf(label, from)

  const sliceBetween = (start, end, requireEnd = false) => {
    const i = idx(start)
    if (i < 0) return []
    const j = idx(end, i + 1)
    // When requireEnd is set, a missing end heading means we cannot safely bound
    // the section — return nothing rather than reading to end-of-page.
    if (j < 0 && requireEnd) return []
    return lines.slice(i + 1, j < 0 ? lines.length : j)
  }

  // High-demand skills: name then "+27%" / "-3%" / "New".
  const highDemandSkills = []
  {
    let name = null
    for (const l of sliceBetween('High demand skills', 'Trending skills')) {
      if (PCT.test(l) || l === 'New') { if (name) { highDemandSkills.push({ name, value: l }); name = null } }
      else name = l
    }
  }

  // Trending skills: names only in the text. These have no value pattern to
  // anchor on and include all-caps names (PHP, HTML, SEO), so the only defense
  // against pulling in the page footer is a hard boundary — require the end
  // heading.
  //
  // Each row's up/down movement is drawn as an arrow icon, so it contributes
  // nothing to innerText — the worker reads it off the DOM and passes
  // [{name, direction}] via dom.trendingSkills. Names stay text-derived (that
  // path is bounded and tested); the DOM read only supplies the direction,
  // matched by name so a partial or reordered read can never shift arrows onto
  // the wrong skills. A name the DOM read missed stays 'even'.
  const domTrending = (dom && Array.isArray(dom.trendingSkills) ? dom.trendingSkills : [])
    .filter((r) => r && typeof r.name === 'string' && r.name.trim())
  const key = (name) => String(name).replace(/\s+/g, ' ').trim().toLowerCase()
  const directionByName = new Map(domTrending.map((r) => [key(r.name), normalizeDirection(r.direction)]))
  const trendingNames = sliceBetween('Trending skills', 'Overall ranking', true)
  const trendingSkills = (trendingNames.length ? trendingNames : domTrending.map((r) => r.name.trim()))
    .map((name) => ({ name, direction: directionByName.get(key(name)) ?? 'even' }))

  // Overall ranking: first "\d+%" after the heading.
  let overall = null
  {
    const i = idx('Overall ranking')
    if (i >= 0) for (let j = i + 1; j < Math.min(i + 4, lines.length); j++) { if (/^\d+%$/.test(lines[j])) { overall = lines[j]; break } }
  }

  // Ranking per skill: name then "Top N%".
  const rankingPerSkill = []
  {
    let name = null
    for (const l of sliceBetween('Ranking per skill', 'Bids per milestone')) {
      if (RANK.test(l)) { if (name) { rankingPerSkill.push({ name, value: l }); name = null } }
      else name = l
    }
  }

  // Bids per milestone: number after the heading.
  let bpm = null
  {
    const i = idx('Bids per milestone')
    if (i >= 0) for (let j = i + 1; j < Math.min(i + 4, lines.length); j++) { if (/^\d+(\.\d+)?$/.test(lines[j])) { bpm = lines[j]; break } }
  }

  const empty = highDemandSkills.length === 0 && trendingSkills.length === 0 && !overall && rankingPerSkill.length === 0 && !bpm
  if (empty) return null

  return {
    overallRanking: overall ? [{ value: overall }] : [],
    rankingPerSkill,
    highDemandSkills,
    trendingSkills,
    bidsPerMilestoneMarketplace: bpm,
    // Profile-view counts are Chart.js line charts drawn on a <canvas>; their
    // numbers live in JS chart state, not the page text. The worker reads the
    // chart data ({labels, values}) in the MAIN world and passes it via dom.
    profileViewCountPastWeek: (dom && dom.profileViewCountPastWeek) || null,
    profileViewCountPastYear: (dom && dom.profileViewCountPastYear) || null,
  }
}
