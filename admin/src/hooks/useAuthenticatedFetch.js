import { getSessionToken } from '@shopify/app-bridge/utilities'
import { useAppBridge } from '../shopify/appBridgeContext'

export function useAuthenticatedFetch() {
  const app = useAppBridge()

  return async (uri, options = {}) => {
    const token = await getSessionToken(app)
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
