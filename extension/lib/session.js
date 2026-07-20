export class LoggedOutError extends Error {
  constructor(reason) {
    super('Not signed in to Freelancer.com: ' + reason)
    this.name = 'LoggedOutError'
  }
}

// Anchored to the start of the PATH. Matching the raw URL string would flag
// a legitimate authenticated request like /api/insights?next=/login.
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
