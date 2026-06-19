import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { AppProvider as PolarisAppProvider } from '@shopify/polaris'
import '@shopify/polaris/build/esm/styles.css'
import './overrides.css'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import App from './App.jsx'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: false,
    },
  },
})

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <PolarisAppProvider i18n={{}}>
      <QueryClientProvider client={queryClient}>
        <App />
      </QueryClientProvider>
    </PolarisAppProvider>
  </StrictMode>,
)
