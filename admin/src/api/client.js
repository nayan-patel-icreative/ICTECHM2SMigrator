import { useAuthenticatedFetch } from '../hooks/useAuthenticatedFetch'

export function useApiClient() {
  const fetch = useAuthenticatedFetch()

  const request = async (path, options = {}) => {
    const res = await fetch(path, options)

    const contentType = res.headers.get('content-type') || ''
    const isJson = contentType.includes('application/json')

    if (res.status === 401) {
      const body = isJson ? await res.json() : await res.text()

      if (body && body.install_url) {
        open(body.install_url, '_top')
        return
      }

      throw new Error('Unauthorized')
    }

    if (res.status === 403) {
      const body = isJson ? await res.json() : await res.text()
      if (body && body.reauth_url) {
        open(body.reauth_url, '_top')
        return
      }

      const message = (body && body.message) || 'Forbidden'
      const err = new Error(message)
      err.details = body
      throw err
    }
    const body = isJson ? await res.json() : await res.text()

    if (!res.ok) {
      const message = (body && body.message) || `Request failed: ${res.status}`
      const err = new Error(message)
      err.details = body
      throw err
    }

    return body
  }

  const downloadBlob = async (path, options = {}) => {
    const res = await fetch(path, options)
    if (!res.ok) {
      throw new Error(`Download failed: ${res.status}`)
    }
    const blob = await res.blob()
    const contentDisposition = res.headers.get('content-disposition') || ''
    const filenameMatch = contentDisposition.match(/filename=\"?([^\";]+)\"?/)
    const filename = filenameMatch ? filenameMatch[1] : 'report.csv'
    return { blob, filename }
  }

  return {
    get: (path) => request(path, { method: 'GET' }),
    post: (path, data) =>
      request(path, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
      }),
    downloadBlob,
  }
}
