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

export function scrapeInsights(text, scrapedAt) {
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

  const userStats = {
    totalEarnings: [{ value: total }, { value: last30 }],
    bidSummary: [{ label: 'Bids Remaining', value: bidsRemaining ? toInt(bidsRemaining) : null }],
    jobProficiency,
    earningsPerSkill,
  }

  return {
    __empty: !total && !bidsRemaining && earningsPerSkill.length === 0 && jobProficiency.length === 0,
    scraped_at: scrapedAt,
    userStats,
    marketplaceStats: null,
  }
}
