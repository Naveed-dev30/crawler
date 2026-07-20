export class LoggedOutError extends Error {
  constructor(reason) {
    super('Not signed in to Freelancer.com: ' + reason)
    this.name = 'LoggedOutError'
  }
}

const AUTH_PATH = /freelancer\.com\/(login|signup|signin)\b/i
// A login form in the body, not merely the word "login" appearing in data.
const LOGIN_FORM = /<form[^>]*action=["'][^"']*\/(login|signin)\b/i

export function assertLoggedIn(response, text) {
  if (response.status === 401 || response.status === 403) {
    throw new LoggedOutError('HTTP ' + response.status)
  }

  if (AUTH_PATH.test(response.url ?? '')) {
    throw new LoggedOutError('redirected to ' + response.url)
  }

  if (LOGIN_FORM.test(text ?? '')) {
    throw new LoggedOutError('response body contains a login form')
  }
}
