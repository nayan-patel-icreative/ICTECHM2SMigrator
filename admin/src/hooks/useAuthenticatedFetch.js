export function useAuthenticatedFetch() {
  return async (uri, options = {}) => {
    if (!window.shopify || typeof window.shopify.idToken !== 'function') {
      throw new Error('Shopify App Bridge is not initialized.')
    }
    const token = await window.shopify.idToken()
    return fetch(uri, {
      ...options,
      headers: {
        ...(options.headers || {}),
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    })
  }
}
