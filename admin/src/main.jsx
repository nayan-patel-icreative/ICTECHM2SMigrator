import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { AppProvider as PolarisAppProvider } from '@shopify/polaris'
import '@shopify/polaris/build/esm/styles.css'
import './overrides.css'
import { createApp } from '@shopify/app-bridge'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import App from './App.jsx'
import { AppBridgeContext } from './shopify/appBridgeContext'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: false,
    },
  },
})

const cfg = window.__APP_CONFIG__ || {}

const host = new URLSearchParams(window.location.search).get('host') || cfg.host
const appBridgeApp = createApp({
  apiKey: cfg.apiKey,
  host,
  forceRedirect: true,
})

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <AppBridgeContext.Provider value={appBridgeApp}>
      <PolarisAppProvider i18n={{}}>
        <QueryClientProvider client={queryClient}>
          <App />
        </QueryClientProvider>
      </PolarisAppProvider>
    </AppBridgeContext.Provider>
  </StrictMode>,
)
