import { createContext, useContext } from 'react'

export const AppBridgeContext = createContext(null)

export function useAppBridge() {
  const app = useContext(AppBridgeContext)
  if (!app) {
    throw new Error('App Bridge app is not available')
  }
  return app
}
