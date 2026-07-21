export class LoggedOutError extends Error {
  constructor(reason) {
    super('Not signed in to Freelancer.com: ' + reason)
    this.name = 'LoggedOutError'
    // A session that isn't logged in can never succeed on retry — retrying it
    // just burns the 2s/8s/32s backoff before the correct diagnosis surfaces.
    this.fatal = true
  }
}

// Anchored to the start of the PATH. Matching the raw URL string would flag an
// authenticated request that merely carries a login URL in a query parameter,
// e.g. /api/insights?redirect=https://www.freelancer.com/login.
const AUTH_PATH = /^\/(login|signup|signin)\b/i
// A login form in the body, not merely the word "login" appearing in data.
const LOGIN_FORM = /<form[^>]*action=["'][^"']*\/(login|signin)\b/i

function pathOf(url) {
  try {
    return new URL(url).pathname
  } catch (e) {
    return ''
  }
}

export function assertLoggedIn(response, text) {
  if (response.status === 401 || response.status === 403) {
    throw new LoggedOutError('HTTP ' + response.status)
  }

  if (AUTH_PATH.test(pathOf(response.url ?? ''))) {
    throw new LoggedOutError('redirected to ' + response.url)
  }

  if (LOGIN_FORM.test(text ?? '')) {
    throw new LoggedOutError('response body contains a login form')
  }
}
