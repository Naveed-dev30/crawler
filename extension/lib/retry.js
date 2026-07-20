export const BACKOFF_MS = [2000, 8000, 32000]
export const MAX_ATTEMPTS = 3

const defaultSleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

export async function withRetry(fn, sleep = defaultSleep) {
  let lastError

  for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
    try {
      return await fn()
    } catch (error) {
      lastError = error

      // Retrying a bad token or missing config never succeeds.
      if (error.fatal) throw error

      if (attempt < MAX_ATTEMPTS - 1) await sleep(BACKOFF_MS[attempt])
    }
  }

  throw lastError
}
