import {
  Frame,
  Navigation,
  Page,
  Layout,
  Card,
  Text,
  TextField,
  Button,
  Select,
  InlineStack,
  BlockStack,
  Banner,
  Spinner,
  Box,
  Divider,
  List,
  Toast,
  ButtonGroup,
  TextContainer,
  Modal,
  Tooltip,
  Badge,
} from '@shopify/polaris'
import { HomeIcon, ViewIcon, EditIcon, ImportIcon } from '@shopify/polaris-icons'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useApiClient } from './api/client'

const MigrationIcon = `
<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
  <path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm1 4a1 1 0 1 1 2 0v2.5a1 1 0 0 1-.293.707l-2.5 2.5a1 1 0 1 1-1.414-1.414L11 8.086V6Z" />
  <path d="M6 6a1 1 0 0 1 1-1h2a1 1 0 1 1 0 2H8v6h1a1 1 0 1 1 0 2H7a1 1 0 0 1-1-1V6Z" />
</svg>
`

function getRouteFromLocation() {
  const url = new URL(window.location.href)
  const route = url.searchParams.get('page')
  if (route === 'migration') return 'migration'
  return 'dashboard'
}

function navigate(route) {
  const url = new URL(window.location.href)
  url.searchParams.set('page', route)
  window.history.replaceState({}, '', url.toString())
}

function getRouteUrl(route) {
  const url = new URL(window.location.href)
  url.searchParams.set('page', route)
  return url.toString()
}

/**
 * LanguageSelectionCard — shown on Step 0 (Connection) when Magento is connected.
 * Auto-fetches available Magento languages and lets the user select which ones to
 * include in the translation migration. Saves the config via the existing connection endpoint.
 */
function LanguageSelectionCard({ api, languageConfig, onSave, saving }) {
  const [localConfig, setLocalConfig] = useState(null)
  const [fetching, setFetching] = useState(false)
  const [fetchError, setFetchError] = useState(null)
  const [dirty, setDirty] = useState(false)

  // Merge fetched languages with existing saved config
  const mergeLanguages = useCallback((fetched, saved) => {
    const savedById = {}
    if (Array.isArray(saved)) {
      saved.forEach((l) => { if (l?.id) savedById[l.id] = l })
    }
    return fetched.map((lang) => ({
      ...lang,
      enabled: savedById[lang.id]?.enabled ?? false,
    }))
  }, [])

  // Auto-fetch languages when component mounts
  useEffect(() => {
    setFetching(true)
    setFetchError(null)
    api.get('/api/shopware-languages')
      .then((data) => {
        const fetched = Array.isArray(data?.languages) ? data.languages : []
        setLocalConfig(mergeLanguages(fetched, languageConfig))
        setFetching(false)
      })
      .catch((e) => {
        setFetchError(e?.message || 'Failed to fetch languages from Magento')
        // Fall back to saved config
        if (Array.isArray(languageConfig) && languageConfig.length > 0) {
          setLocalConfig(languageConfig.map((l) => ({ ...l, enabled: l.enabled ?? false })))
        }
        setFetching(false)
      })
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const handleToggle = useCallback((id) => {
    setLocalConfig((prev) =>
      prev.map((l) => l.id === id ? { ...l, enabled: !l.enabled } : l)
    )
    setDirty(true)
  }, [])

  const handleSave = useCallback(() => {
    if (!localConfig) return
    onSave(localConfig)
    setDirty(false)
  }, [localConfig, onSave])

  const enabledCount = Array.isArray(localConfig) ? localConfig.filter((l) => l.enabled).length : 0

  return (
    <Box paddingBlockStart="400">
      <BlockStack gap="300">
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', justifyContent: 'space-between' }}>
          <div>
            <Text as="h3" variant="headingSm">Translation Languages</Text>
            <Text as="p" tone="subdued" variant="bodySm">
              Select which Magento languages/store views to migrate as translations to Shopify.
              Leave all unchecked to skip translation migration.
            </Text>
          </div>
          {fetching && <Spinner size="small" />}
        </div>

        {fetchError && (
          <Banner status="warning" title="Could not fetch languages">
            <p>{fetchError}</p>
          </Banner>
        )}

        {!fetching && Array.isArray(localConfig) && localConfig.length === 0 && (
          <Text as="p" tone="subdued" variant="bodySm">
            No languages found in your Magento store.
          </Text>
        )}

        {Array.isArray(localConfig) && localConfig.length > 0 && (
          <div style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))',
            gap: '8px',
            padding: '12px',
            background: 'var(--p-color-bg-surface-secondary)',
            borderRadius: '8px',
            border: '1px solid var(--p-color-border-subdued)',
          }}>
            {localConfig.map((lang) => (
              <label
                key={lang.id}
                htmlFor={`lang-${lang.id}`}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '8px',
                  padding: '8px 10px',
                  borderRadius: '6px',
                  cursor: 'pointer',
                  background: lang.enabled ? 'rgba(26,127,90,0.08)' : 'transparent',
                  border: lang.enabled ? '1px solid rgba(26,127,90,0.3)' : '1px solid transparent',
                  transition: 'all 0.15s ease',
                }}
              >
                <input
                  id={`lang-${lang.id}`}
                  type="checkbox"
                  checked={!!lang.enabled}
                  onChange={() => handleToggle(lang.id)}
                  style={{ accentColor: '#1a7f5a', width: '15px', height: '15px', cursor: 'pointer' }}
                />
                <div>
                  <Text as="span" variant="bodySm" fontWeight={lang.enabled ? 'semibold' : 'regular'}>
                    {lang.name || lang.locale}
                  </Text>
                  {lang.locale && lang.locale !== lang.name && (
                    <Text as="p" variant="bodySm" tone="subdued">{lang.locale}</Text>
                  )}
                </div>
              </label>
            ))}
          </div>
        )}

        {Array.isArray(localConfig) && localConfig.length > 0 && (
          <InlineStack gap="300" align="space-between" blockAlign="center">
            <Text as="span" tone="subdued" variant="bodySm">
              {enabledCount === 0
                ? 'No languages selected — translations will not be migrated'
                : `${enabledCount} language${enabledCount !== 1 ? 's' : ''} selected for translation migration`}
            </Text>
            <Button
              variant="primary"
              size="slim"
              loading={saving}
              disabled={!dirty && enabledCount === 0}
              onClick={handleSave}
            >
              Save Language Settings
            </Button>
          </InlineStack>
        )}
      </BlockStack>
    </Box>
  )
}

function App() {
  const api = useApiClient()

  const [activePage, setActivePage] = useState(() => getRouteFromLocation())

  useEffect(() => {
    document.body.setAttribute('data-ictech-route', activePage)
  }, [activePage])

  const handleNavigate = useCallback((route) => {
    setActivePage(route)
    navigate(route)
  }, [])

  const [apiUrl, setApiUrl] = useState('')
  const [filesPath, setFilesPath] = useState('')
  const [accessToken, setAccessToken] = useState('')
  const [editingSecret, setEditingSecret] = useState(false)
  const [showSecret, setShowSecret] = useState(false)
  const [locationGid, setLocationGid] = useState('')
  const [error, setError] = useState(null)
  const [fieldErrors, setFieldErrors] = useState({})
  const [toast, setToast] = useState(null)
  const [preview, setPreview] = useState(null)
  const [redirectPreview, setRedirectPreview] = useState(null)
  const [redirectImportResult, setRedirectImportResult] = useState(null)
  const [collectionRedirectPreview, setCollectionRedirectPreview] = useState(null)
  const [collectionRedirectImportResult, setCollectionRedirectImportResult] = useState(null)
  const [manufacturerPreview, setManufacturerPreview] = useState(null)
  const [customerPreview, setCustomerPreview] = useState(null)
  const [customerDateMode, setCustomerDateMode] = useState('after')
  const [customerAfterDate, setCustomerAfterDate] = useState('')
  const [customerBeforeDate, setCustomerBeforeDate] = useState('')
  const [customerFilteredPreview, setCustomerFilteredPreview] = useState(null)
  const [customerFilteredModalOpen, setCustomerFilteredModalOpen] = useState(false)
  const [confirmCustomerFilteredStartOpen, setConfirmCustomerFilteredStartOpen] = useState(false)

  const customerAfterInputRef = useRef(null)
  const customerBeforeInputRef = useRef(null)
  const orderAfterInputRef = useRef(null)
  const orderBeforeInputRef = useRef(null)
  const [orderPreview, setOrderPreview] = useState(null)
  const [orderDateMode, setOrderDateMode] = useState('after')
  const [orderAfterDate, setOrderAfterDate] = useState('')
  const [orderBeforeDate, setOrderBeforeDate] = useState('')
  const [orderFilteredPreview, setOrderFilteredPreview] = useState(null)
  const [orderFilteredModalOpen, setOrderFilteredModalOpen] = useState(false)
  const [confirmOrderFilteredStartOpen, setConfirmOrderFilteredStartOpen] = useState(false)
  const [newsletterPreview, setNewsletterPreview] = useState(null)
  const [confirmStartOpen, setConfirmStartOpen] = useState(false)
  const [confirmCustomerStartOpen, setConfirmCustomerStartOpen] = useState(false)
  const [confirmOrderStartOpen, setConfirmOrderStartOpen] = useState(false)
  const [confirmNewsletterStartOpen, setConfirmNewsletterStartOpen] = useState(false)
  const [expandedCustomerPreviewId, setExpandedCustomerPreviewId] = useState(null)
  const [expandedOrderPreviewId, setExpandedOrderPreviewId] = useState(null)
  const [expandedNewsletterPreviewId, setExpandedNewsletterPreviewId] = useState(null)

  // Product filtered migration state
  const [productDateMode, setProductDateMode] = useState('after')
  const [productAfterDate, setProductAfterDate] = useState('')
  const [productBeforeDate, setProductBeforeDate] = useState('')
  const [productFilteredPreview, setProductFilteredPreview] = useState(null)
  const [productFilteredModalOpen, setProductFilteredModalOpen] = useState(false)
  const [confirmProductFilteredStartOpen, setConfirmProductFilteredStartOpen] = useState(false)
  const productAfterInputRef = useRef(null)
  const productBeforeInputRef = useRef(null)

  const [productMappingInfoOpen, setProductMappingInfoOpen] = useState(false)
  const [productLimitationsOpen, setProductLimitationsOpen] = useState(false)

  const [manufacturerMappingInfoOpen, setManufacturerMappingInfoOpen] = useState(false)
  const [manufacturerLimitationsOpen, setManufacturerLimitationsOpen] = useState(false)

  const [customerMappingInfoOpen, setCustomerMappingInfoOpen] = useState(false)
  const [customerLimitationsOpen, setCustomerLimitationsOpen] = useState(false)

  const [orderMappingInfoOpen, setOrderMappingInfoOpen] = useState(false)
  const [orderLimitationsOpen, setOrderLimitationsOpen] = useState(false)

  const [newsletterMappingInfoOpen, setNewsletterMappingInfoOpen] = useState(false)
  const [newsletterLimitationsOpen, setNewsletterLimitationsOpen] = useState(false)

  // State mapping card
  const [stateMappingTab, setStateMappingTab] = useState(0)
  const [stateMappings, setStateMappings] = useState(null)
  const [stateMappingOptions, setStateMappingOptions] = useState(null)
  const [stateMappingDirty, setStateMappingDirty] = useState(false)

  // Wizard state
  const [wizardStep, setWizardStep] = useState(0)

  const formatDurationSeconds = useCallback((seconds) => {
    if (seconds == null) return null
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    const s = seconds % 60
    if (h > 0) return `${h}h ${m}m ${s}s`
    if (m > 0) return `${m}m ${s}s`
    return `${s}s`
  }, [])

  const durationSecondsFromRun = useCallback((migrationRun) => {
    if (!migrationRun) return null
    if (typeof migrationRun.duration_seconds === 'number') {
      return migrationRun.duration_seconds
    }
    if (migrationRun.started_at) {
      const d = new Date(migrationRun.started_at)
      if (!Number.isNaN(d.getTime())) {
        return Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000))
      }
    }
    return null
  }, [])

  const connectionQuery = useQuery({
    queryKey: ['shopware-connection'],
    queryFn: () => api.get('/api/shopware-connection'),
  })

  const previewFilteredOrderMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/orders/preview-filtered', payload),
    onSuccess: (data) => {
      setError(null)
      setOrderFilteredPreview(data)
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to preview filtered orders', error: true })
    },
  })

  const startFilteredOrderMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/orders/start-filtered', payload),
    onSuccess: () => {
      setError(null)
      orderStatusQuery.refetch()
      setToast({ content: 'Filtered order migration started' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to start filtered order migration', error: true })
    },
  })

  const previewFilteredCustomerMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/customers/preview-filtered', payload),
    onSuccess: (data) => {
      setError(null)
      setCustomerFilteredPreview(data)
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to preview filtered customers', error: true })
    },
  })

  const startFilteredCustomerMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/customers/start-filtered', payload),
    onSuccess: () => {
      setError(null)
      customerStatusQuery.refetch()
      setToast({ content: 'Filtered customer migration started' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to start filtered customer migration', error: true })
    },
  })

  const previewCustomerMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/customers/preview', payload),
    onSuccess: (data) => {
      setCustomerPreview(data)
      const items = Array.isArray(data?.items) ? data.items : []
      setToast({
        content: `Customer preview loaded: ${items.length}`,
      })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Customer preview failed', error: true })
    },
  })

  const previewOrderMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/orders/preview', payload),
    onSuccess: (data) => {
      setOrderPreview(data)
      const items = Array.isArray(data?.items) ? data.items : []
      setToast({
        content: `Order preview loaded: ${items.length}`,
      })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Order preview failed', error: true })
    },
  })

  const previewNewsletterMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/newsletter/preview', payload),
    onSuccess: (data) => {
      setNewsletterPreview(data)
      const items = Array.isArray(data?.items) ? data.items : []
      setToast({
        content: `Newsletter preview loaded: ${items.length}`,
      })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Newsletter preview failed', error: true })
    },
  })

  const previewManufacturerMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/manufacturers/preview', payload),
    onSuccess: (data) => {
      setManufacturerPreview(data)
      const items = Array.isArray(data?.items) ? data.items : []
      setToast({
        content: `Manufacturer preview loaded: ${items.length}`,
      })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Manufacturer preview failed', error: true })
    },
  })

  const previewMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/products/preview', payload),
    onSuccess: (data) => {
      setPreview(data)
      const items = Array.isArray(data?.items) ? data.items : []
      const withIssues = items.filter((i) => Array.isArray(i?.issues) && i.issues.length > 0).length
      setToast({
        content: `Preview loaded: ${items.length} products${withIssues ? `, ${withIssues} with issues` : ''}`,
      })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Preview failed', error: true })
    },
  })

  const previewRedirectMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/products/redirects/preview', payload),
    onSuccess: (data) => {
      setRedirectPreview(data)
      const items = Array.isArray(data?.items) ? data.items : []
      setToast({ content: `Redirect preview loaded: ${items.length}` })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Redirect preview failed', error: true })
    },
  })

  const importRedirectMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/products/redirects/import', payload),
    onSuccess: (data) => {
      setRedirectImportResult(data)
      setToast({
        content: `Redirect import done: ${data?.succeeded ?? 0} succeeded, ${data?.failed ?? 0} failed`,
      })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Redirect import failed', error: true })
    },
  })

  const previewCollectionRedirectMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/collections/redirects/preview', payload),
    onSuccess: (data) => {
      setCollectionRedirectPreview(data)
      const items = Array.isArray(data?.items) ? data.items : []
      setToast({ content: `Collection redirect preview loaded: ${items.length}` })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Collection redirect preview failed', error: true })
    },
  })

  const importCollectionRedirectMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/collections/redirects/import', payload),
    onSuccess: (data) => {
      setCollectionRedirectImportResult(data)
      setToast({
        content: `Collection redirect import done: ${data?.succeeded ?? 0} succeeded, ${data?.failed ?? 0} failed`,
      })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Collection redirect import failed', error: true })
    },
  })

  const previewFilteredProductMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/products/preview-filtered', payload),
    onSuccess: (data) => {
      setError(null)
      setProductFilteredPreview(data)
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to preview filtered products', error: true })
    },
  })

  const startFilteredProductMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/products/start-filtered', payload),
    onSuccess: () => {
      setError(null)
      statusQuery.refetch()
      setToast({ content: 'Filtered product migration started' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to start filtered product migration', error: true })
    },
  })

  useEffect(() => {
    const d = connectionQuery.data
    if (!d || d.connected !== true) return

    if (typeof d.api_url === 'string' && d.api_url !== '' && apiUrl === '') {
      setApiUrl(d.api_url)
    }
    if (typeof d.files_path === 'string' && d.files_path !== '' && filesPath === '') {
      setFilesPath(d.files_path)
    }
    if (typeof d.shopify_location_gid === 'string' && d.shopify_location_gid !== '' && locationGid === '') {
      setLocationGid(d.shopify_location_gid)
    }
  }, [connectionQuery.data, apiUrl, filesPath, locationGid])

  const meQuery = useQuery({
    queryKey: ['me'],
    queryFn: () => api.get('/api/me'),
  })

  const installedAtLabel = useMemo(() => {
    const raw = meQuery.data?.installed_at
    if (!raw) return '-'
    const d = new Date(raw)
    if (Number.isNaN(d.getTime())) return raw
    return d.toLocaleString()
  }, [meQuery.data?.installed_at])

  const locationsQuery = useQuery({
    queryKey: ['locations'],
    queryFn: () => api.get('/api/shopify/locations'),
  })

  const queueHealthQuery = useQuery({
    queryKey: ['queue-health'],
    queryFn: () => api.get('/api/queue/health'),
    refetchInterval: 10000,
  })

  const manufacturerStatusQuery = useQuery({
    queryKey: ['manufacturer-migration-status'],
    queryFn: () => api.get('/api/migration/manufacturers/status'),
    refetchInterval: 3000,
  })

  const statusQuery = useQuery({
    queryKey: ['migration-status'],
    queryFn: () => api.get('/api/migration/products/status'),
    refetchInterval: 3000,
  })

  const customerStatusQuery = useQuery({
    queryKey: ['customer-migration-status'],
    queryFn: () => api.get('/api/migration/customers/status'),
    refetchInterval: 3000,
  })

  const orderStatusQuery = useQuery({
    queryKey: ['order-migration-status'],
    queryFn: () => api.get('/api/migration/orders/status'),
    refetchInterval: 3000,
  })

  const newsletterStatusQuery = useQuery({
    queryKey: ['newsletter-migration-status'],
    queryFn: () => api.get('/api/migration/newsletter/status'),
    refetchInterval: 3000,
  })

  const stateMappingQuery = useQuery({
    queryKey: ['state-mappings'],
    queryFn: () => api.get('/api/state-mappings'),
    onSuccess: (data) => {
      if (data?.mappings && !stateMappingDirty) {
        setStateMappings(JSON.parse(JSON.stringify(data.mappings)))
      }
      if (data?.options) {
        setStateMappingOptions(data.options)
      }
    },
  })

  useEffect(() => {
    const data = stateMappingQuery.data
    if (!data) return
    if (data.mappings && !stateMappingDirty) {
      setStateMappings(JSON.parse(JSON.stringify(data.mappings)))
    }
    if (data.options) {
      setStateMappingOptions(data.options)
    }
  }, [stateMappingQuery.data])

  const saveStateMappings = useMutation({
    mutationFn: (payload) => api.post('/api/state-mappings', payload),
    onSuccess: () => {
      setStateMappingDirty(false)
      stateMappingQuery.refetch()
      setToast({ content: 'State mappings saved' })
    },
    onError: (e) => {
      setToast({ content: e.message || 'Failed to save state mappings', error: true })
    },
  })

  const saveConnection = useMutation({
    mutationFn: (payload) => api.post('/api/shopware-connection', payload),
    onSuccess: () => {
      setError(null)
      setFieldErrors({})
      connectionQuery.refetch()
      setToast({ content: 'Magento connection saved' })
      setEditingSecret(false)
      setShowSecret(false)
      setAccessToken('')
    },
    onError: (e) => {
      const errors = e?.details?.errors
      if (errors && typeof errors === 'object') {
        setFieldErrors(errors)
        setError(null)
        return
      }

      setFieldErrors({})
      setError(e.message)
      setToast({ content: e.message || 'Failed to save connection', error: true })
    },
  })

  const saveLocationMutation = useMutation({
    mutationFn: (locationId) => api.post('/api/shopify/location', { location_gid: locationId }),
    onSuccess: () => {
      connectionQuery.refetch()
      setToast({ content: 'Shopify location saved' })
    },
    onError: (e) => {
      setToast({ content: e.message || 'Failed to save Shopify location', error: true })
    },
  })

  const handleLocationChange = useCallback((value) => {
    setLocationGid(value)
    if (value) {
      saveLocationMutation.mutate(value)
    }
  }, [saveLocationMutation])

  const startManufacturerMigration = useMutation({
    mutationFn: () => api.post('/api/migration/manufacturers/start', {}),
    onSuccess: () => {
      setError(null)
      manufacturerStatusQuery.refetch()
      setToast({ content: 'Manufacturer migration started' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to start manufacturer migration', error: true })
    },
  })

  const cancelManufacturerMigration = useMutation({
    mutationFn: () => api.post('/api/migration/manufacturers/cancel', {}),
    onSuccess: () => {
      manufacturerStatusQuery.refetch()
      setToast({ content: 'Manufacturer migration cancelled' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to cancel manufacturer migration', error: true })
    },
  })

  const startMigration = useMutation({
    mutationFn: () => api.post('/api/migration/products/start', { location_gid: locationGid }),
    onSuccess: () => {
      setError(null)
      statusQuery.refetch()
      setToast({ content: 'Migration started' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to start migration', error: true })
    },
  })

  const cancelMigration = useMutation({
    mutationFn: () => api.post('/api/migration/products/cancel', {}),
    onSuccess: () => {
      statusQuery.refetch()
      setToast({ content: 'Migration cancelled' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to cancel migration', error: true })
    },
  })

  const startCustomerMigration = useMutation({
    mutationFn: () => api.post('/api/migration/customers/start', {}),
    onSuccess: () => {
      setError(null)
      customerStatusQuery.refetch()
      setToast({ content: 'Customer migration started' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to start customer migration', error: true })
    },
  })

  const cancelCustomerMigration = useMutation({
    mutationFn: () => api.post('/api/migration/customers/cancel', {}),
    onSuccess: () => {
      customerStatusQuery.refetch()
      setToast({ content: 'Customer migration cancelled' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to cancel customer migration', error: true })
    },
  })

  const startOrderMigration = useMutation({
    mutationFn: () => api.post('/api/migration/orders/start', { location_gid: locationGid }),
    onSuccess: () => {
      setError(null)
      orderStatusQuery.refetch()
      setToast({ content: 'Order migration started' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to start order migration', error: true })
    },
  })

  const cancelOrderMigration = useMutation({
    mutationFn: () => api.post('/api/migration/orders/cancel', {}),
    onSuccess: () => {
      orderStatusQuery.refetch()
      setToast({ content: 'Order migration cancelled' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to cancel order migration', error: true })
    },
  })

  const startNewsletterMigration = useMutation({
    mutationFn: () => api.post('/api/migration/newsletter/start', {}),
    onSuccess: () => {
      setError(null)
      newsletterStatusQuery.refetch()
      setToast({ content: 'Newsletter migration started' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to start newsletter migration', error: true })
    },
  })

  const cancelNewsletterMigration = useMutation({
    mutationFn: () => api.post('/api/migration/newsletter/cancel', {}),
    onSuccess: () => {
      newsletterStatusQuery.refetch()
      setToast({ content: 'Newsletter migration cancelled' })
    },
    onError: (e) => {
      setError(e.message)
      setToast({ content: e.message || 'Failed to cancel newsletter migration', error: true })
    },
  })

  const locations = locationsQuery.data?.locations || []
  useEffect(() => {
    if (locationGid) return
    if (!Array.isArray(locations)) return
    if (locations.length === 1 && locations[0]?.id) {
      const locId = locations[0].id
      setLocationGid(locId)
      saveLocationMutation.mutate(locId)
    }
  }, [locations, locationGid, saveLocationMutation])

  const locationOptions = useMemo(() => {
    return [
      { label: 'Select a location', value: '' },
      ...locations
        .filter((l) => l?.id)
        .map((l) => ({
          label: `${l.name}${l.is_active ? '' : ' (inactive)'}`,
          value: l.id,
        })),
    ]
  }, [locations])

  const workerOnline = queueHealthQuery.data?.worker_online === true
  const connected = connectionQuery.data?.connected === true

  const [tick, setTick] = useState(0)

  const orderRun = orderStatusQuery.data?.run || null
  const isOrderRunning = orderRun?.status === 'running' || orderRun?.status === 'queued'
  const orderPrerequisites = orderStatusQuery.data?.prerequisites || null
  const orderPrerequisitesReady = orderPrerequisites?.ready === true
  const orderPrerequisiteMessages = Array.isArray(orderPrerequisites?.messages) ? orderPrerequisites.messages : []
  const canStartOrders = connected && workerOnline && orderPrerequisitesReady && !isOrderRunning
  const orderFilteredPreviewTotal = typeof orderFilteredPreview?.total === 'number' ? orderFilteredPreview.total : null
  const canStartOrderFiltered = connected && workerOnline && orderPrerequisitesReady && !isOrderRunning

  const newsletterRun = newsletterStatusQuery.data?.run || null
  const isNewsletterRunning = newsletterRun?.status === 'running' || newsletterRun?.status === 'queued'
  const newsletterPrerequisites = newsletterStatusQuery.data?.prerequisites || null
  const newsletterPrerequisitesReady = newsletterPrerequisites?.ready === true
  const newsletterPrerequisiteMessages = Array.isArray(newsletterPrerequisites?.messages) ? newsletterPrerequisites.messages : []
  const canStartNewsletter = connected && workerOnline && newsletterPrerequisitesReady && !isNewsletterRunning

  const orderDurationLabel = useMemo(() => {
    if (!orderRun) return null

    let seconds = null

    if (typeof orderRun.duration_seconds === 'number') {
      seconds = orderRun.duration_seconds
    } else if (orderRun.started_at) {
      const start = new Date(orderRun.started_at)
      const end = orderRun.finished_at ? new Date(orderRun.finished_at) : new Date()
      const ms = end.getTime() - start.getTime()
      if (Number.isFinite(ms) && ms > 0) {
        seconds = Math.floor(ms / 1000)
      }
    }

    if (seconds == null) return null
    const m = Math.floor(seconds / 60)
    const s = seconds % 60
    return `${m}m ${s}s`
  }, [orderRun?.duration_seconds, orderRun?.started_at, orderRun?.finished_at, tick])

  const newsletterDurationLabel = useMemo(() => {
    if (!newsletterRun) return null

    let seconds = null

    if (typeof newsletterRun.duration_seconds === 'number') {
      seconds = newsletterRun.duration_seconds
    } else if (newsletterRun.started_at) {
      const start = new Date(newsletterRun.started_at)
      const end = newsletterRun.finished_at ? new Date(newsletterRun.finished_at) : new Date()
      const ms = end.getTime() - start.getTime()
      if (Number.isFinite(ms) && ms > 0) {
        seconds = Math.floor(ms / 1000)
      }
    }

    if (seconds == null) return null
    const m = Math.floor(seconds / 60)
    const s = seconds % 60
    return `${m}m ${s}s`
  }, [newsletterRun?.duration_seconds, newsletterRun?.started_at, newsletterRun?.finished_at, tick])

  const orderPreviewItems = useMemo(() => {
    return Array.isArray(orderPreview?.items) ? orderPreview.items : []
  }, [orderPreview])

  const newsletterPreviewItems = useMemo(() => {
    return Array.isArray(newsletterPreview?.items) ? newsletterPreview.items : []
  }, [newsletterPreview])

  const isOrderFinishedSuccess = orderRun?.status === 'finished' && (orderRun?.succeeded || 0) > 0
  const orderSkippedCount = orderRun
    ? Math.max(0, Number(orderRun.processed || 0) - Number(orderRun.succeeded || 0) - Number(orderRun.failed || 0))
    : 0
  const isOrderFinishedNoChanges =
    orderRun?.status === 'finished' && (orderRun?.succeeded || 0) === 0 && (orderRun?.failed || 0) === 0 && orderSkippedCount > 0

  const recentOrderFailedItems = useMemo(() => {
    const items = orderStatusQuery.data?.recent_failed_items
    return Array.isArray(items) ? items : []
  }, [orderStatusQuery.data])

  const recentNewsletterFailedItems = useMemo(() => {
    const items = newsletterStatusQuery.data?.recent_failed_items
    return Array.isArray(items) ? items : []
  }, [newsletterStatusQuery.data])
  const secretSaved = connectionQuery.data?.access_token_saved === true
  const manufacturerRun = manufacturerStatusQuery.data?.run
  const isManufacturerRunning = manufacturerRun?.status === 'running' || manufacturerRun?.status === 'queued'
  const manufacturerPreviewItems = useMemo(() => {
    return Array.isArray(manufacturerPreview?.items) ? manufacturerPreview.items : []
  }, [manufacturerPreview])

  // Market migration state
  const [marketPreview, setMarketPreview] = useState(null)
  const [confirmMarketStartOpen, setConfirmMarketStartOpen] = useState(false)

  const marketStatusQuery = useQuery({
    queryKey: ['market-migration-status'],
    queryFn: () => api.get('/api/migration/markets/status'),
    refetchInterval: 3000,
  })

  const marketRun = marketStatusQuery.data?.run || null
  const isMarketRunning = marketRun?.status === 'running' || marketRun?.status === 'queued'

  const previewMarketMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/markets/preview', payload),
    onSuccess: (data) => {
      setMarketPreview(data)
      const items = Array.isArray(data?.items) ? data.items : []
      setToast({ content: `Market preview loaded: ${items.length}` })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Market preview failed', error: true })
    },
  })

  const startMarketMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/markets/start', payload ?? {}),
    onSuccess: () => {
      marketStatusQuery.refetch()
      setToast({ content: 'Market migration started' })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Failed to start market migration', error: true })
    },
  })

  const cancelMarketMigration = useMutation({
    mutationFn: () => api.post('/api/migration/markets/cancel', {}),
    onSuccess: () => {
      marketStatusQuery.refetch()
      setToast({ content: 'Market migration cancelled' })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Failed to cancel market migration', error: true })
    },
  })

  const marketPreviewItems = useMemo(() => {
    return Array.isArray(marketPreview?.items) ? marketPreview.items : []
  }, [marketPreview])

  const recentMarketFailedItems = useMemo(() => {
    const items = marketStatusQuery.data?.recent_failed_items
    return Array.isArray(items) ? items : []
  }, [marketStatusQuery.data])

  const marketSkippedCount = marketRun
    ? Math.max(0, Number(marketRun.processed || 0) - Number(marketRun.succeeded || 0) - Number(marketRun.failed || 0))
    : 0

  const marketDurationLabel = useMemo(() => {
    return formatDurationSeconds(durationSecondsFromRun(marketRun))
  }, [marketRun, formatDurationSeconds, durationSecondsFromRun, tick])

  const run = statusQuery.data?.run
  const recentFailedItems = Array.isArray(statusQuery.data?.recent_failed_items) ? statusQuery.data.recent_failed_items : []

  const customerRun = customerStatusQuery.data?.run
  const recentCustomerFailedItems = Array.isArray(customerStatusQuery.data?.recent_failed_items)
    ? customerStatusQuery.data.recent_failed_items
    : []


  const isFinishedSuccess =
    run &&
    run.status === 'finished' &&
    Number(run.failed || 0) === 0 &&
    Number(run.succeeded || 0) > 0

  const skippedCount = run ? Math.max(0, Number(run.processed || 0) - Number(run.succeeded || 0) - Number(run.failed || 0)) : 0

  const isFinishedNoChanges =
    run &&
    run.status === 'finished' &&
    Number(run.failed || 0) === 0 &&
    Number(run.succeeded || 0) === 0 &&
    skippedCount > 0

  const canStart = connected && workerOnline && locationGid && (!run || !['queued', 'running'].includes(run.status))
  const isRunning = run && ['queued', 'running'].includes(run.status)
  const previewItems = Array.isArray(preview?.items) ? preview.items : []
  const productFilteredPreviewTotal = typeof productFilteredPreview?.total === 'number' ? productFilteredPreview.total : null
  const canStartProductFiltered = connected && workerOnline && locationGid && !isRunning

  const canStartCustomers = connected && workerOnline && (!customerRun || !['queued', 'running'].includes(customerRun.status))
  const isCustomerRunning = customerRun && ['queued', 'running'].includes(customerRun.status)
  const customerPreviewItems = Array.isArray(customerPreview?.items) ? customerPreview.items : []
  const customerFilteredPreviewTotal = typeof customerFilteredPreview?.total === 'number' ? customerFilteredPreview.total : null
  const canStartCustomerFiltered = connected && workerOnline && !isCustomerRunning

  // Discount migration state
  const [discountPreview, setDiscountPreview] = useState(null)
  const [confirmDiscountStartOpen, setConfirmDiscountStartOpen] = useState(false)

  const discountStatusQuery = useQuery({
    queryKey: ['discount-migration-status'],
    queryFn: () => api.get('/api/migration/discounts/status'),
    refetchInterval: 3000,
  })

  const discountRun = discountStatusQuery.data?.run || null
  const isDiscountRunning = discountRun?.status === 'running' || discountRun?.status === 'queued'

  const previewDiscountMigration = useMutation({
    mutationFn: (payload) => api.post('/api/migration/discounts/preview', payload),
    onSuccess: (data) => {
      setDiscountPreview(data)
      const items = Array.isArray(data?.items) ? data.items : []
      setToast({ content: `Discount preview loaded: ${items.length}` })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Discount preview failed', error: true })
    },
  })

  const startDiscountMigration = useMutation({
    mutationFn: () => api.post('/api/migration/discounts/start', {}),
    onSuccess: () => {
      discountStatusQuery.refetch()
      setToast({ content: 'Discount migration started' })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Failed to start discount migration', error: true })
    },
  })

  const cancelDiscountMigration = useMutation({
    mutationFn: () => api.post('/api/migration/discounts/cancel', {}),
    onSuccess: () => {
      discountStatusQuery.refetch()
      setToast({ content: 'Discount migration cancelled' })
    },
    onError: (e) => {
      setToast({ content: e?.message || 'Failed to cancel discount migration', error: true })
    },
  })

  useEffect(() => {
    if (!isRunning && !isCustomerRunning && !isOrderRunning && !isNewsletterRunning && !isManufacturerRunning && !isDiscountRunning && !isMarketRunning) return
    const id = setInterval(() => setTick((t) => t + 1), 1000)
    return () => clearInterval(id)
  }, [isRunning, isCustomerRunning, isOrderRunning, isNewsletterRunning, isManufacturerRunning, isDiscountRunning, isMarketRunning])

  const handleDownloadReport = useCallback(async (migrationRun) => {
    if (!migrationRun?.report_download_url) return
    try {
      const { blob, filename } = await api.downloadBlob(migrationRun.report_download_url, { method: 'GET' })
      const url = window.URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = filename || `migration-run-${migrationRun.id}.csv`
      document.body.appendChild(a)
      a.click()
      a.remove()
      window.URL.revokeObjectURL(url)
    } catch (e) {
      setToast({ content: e?.message || 'Failed to download report', error: true })
    }
  }, [api])


  const durationLabel = useMemo(() => {
    return formatDurationSeconds(durationSecondsFromRun(run))
  }, [run, formatDurationSeconds, durationSecondsFromRun, tick])

  const manufacturerDurationLabel = useMemo(() => {
    return formatDurationSeconds(durationSecondsFromRun(manufacturerRun))
  }, [manufacturerRun, formatDurationSeconds, durationSecondsFromRun, tick])

  const manufacturerSkippedCount = manufacturerRun
    ? Math.max(0, Number(manufacturerRun.processed || 0) - Number(manufacturerRun.succeeded || 0) - Number(manufacturerRun.failed || 0))
    : 0

  const manufacturerRecentFailedItems = Array.isArray(manufacturerStatusQuery.data?.recent_failed_items)
    ? manufacturerStatusQuery.data.recent_failed_items
    : []

  const customerDurationLabel = useMemo(() => {
    if (!customerRun) return null

    let seconds = null

    if (typeof customerRun.duration_seconds === 'number') {
      seconds = customerRun.duration_seconds
    } else if (customerRun.started_at) {
      const d = new Date(customerRun.started_at)
      if (!Number.isNaN(d.getTime())) {
        seconds = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000))
      }
    }

    if (seconds == null) return null

    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    const s = seconds % 60

    if (h > 0) return `${h}h ${m}m ${s}s`
    if (m > 0) return `${m}m ${s}s`
    return `${s}s`
  }, [customerRun?.duration_seconds, customerRun?.started_at, customerRun?.finished_at, tick])

  const customerSkippedCount = customerRun
    ? Math.max(0, Number(customerRun.processed || 0) - Number(customerRun.succeeded || 0) - Number(customerRun.failed || 0))
    : 0

  const isCustomerFinishedSuccess =
    customerRun &&
    customerRun.status === 'finished' &&
    Number(customerRun.failed || 0) === 0 &&
    Number(customerRun.succeeded || 0) > 0

  const isCustomerFinishedNoChanges =
    customerRun &&
    customerRun.status === 'finished' &&
    Number(customerRun.failed || 0) === 0 &&
    Number(customerRun.succeeded || 0) === 0 &&
    customerSkippedCount > 0

  const navigation = (
    <Navigation location={activePage}>
      <Navigation.Section
        items={[
          {
            label: 'Dashboard',
            icon: HomeIcon,
            selected: activePage === 'dashboard',
            url: getRouteUrl('dashboard'),
            onClick: () => handleNavigate('dashboard'),
          },
          {
            label: 'Migration',
            icon: MigrationIcon,
            selected: activePage === 'migration',
            url: getRouteUrl('migration'),
            onClick: () => handleNavigate('migration'),
          },
        ]}
      />
    </Navigation>
  )

  const dashboardContent = (
    <Page>
      <Layout>
        <Layout.Section>
          {meQuery.isError ? (
            <Banner status="critical" title="Shopify connection error">
              <p>{meQuery.error?.message || 'Failed to load Shopify connection details.'}</p>
            </Banner>
          ) : null}
        </Layout.Section>
        <Layout.Section>
          <Card>
            <BlockStack gap="300">
              <Text as="h2" variant="headingMd">
                Welcome
              </Text>
              <Text as="p" tone="subdued">
                Use the navigation to configure Magento access and run your product + variant migration.
              </Text>
              <Divider />
              <InlineStack gap="300" align="start" blockAlign="center">
                <Text as="span">Magento connection:</Text>
                <Text as="span" tone={connected ? 'success' : 'critical'}>
                  {connected ? 'Connected' : 'Not connected'}
                </Text>
                {connectionQuery.isFetching ? <Spinner size="small" /> : null}
              </InlineStack>

              {preview ? (
                <Box paddingBlockStart="200">
                  <Text variant="headingSm" as="h3">
                    Preview results
                  </Text>
                  <TextContainer spacing="tight">
                    <Text as="p">Page: {preview.page ?? '-'}</Text>
                    <Text as="p">Total (Magento): {preview.total ?? '-'}</Text>
                  </TextContainer>

                  {previewItems.length > 0 ? (
                    <Box paddingBlockStart="200">
                      <List type="bullet">
                        {previewItems.map((it) => {
                          const issues = Array.isArray(it?.issues) ? it.issues : []
                          const sample = Array.isArray(it?.child_sample) ? it.child_sample : []
                          return (
                            <List.Item key={it.source_id}>
                              <Text as="span" fontWeight="semibold">
                                {it.title || it.source_id}
                              </Text>
                              <Text as="span">
                                {' '}
                                — Magento children: {it.shopware_child_count ?? '-'}; mapped variants: {it.variant_count ?? '-'} (expected:{' '}
                                {it.expected_variant_count ?? '-'}) ; options: {it.option_count ?? '-'}; bytes: {it.payload_bytes ?? '-'}
                              </Text>
                              {sample.length > 0 ? (
                                <Box paddingBlockStart="100">
                                  <Text as="p" tone="subdued">
                                    Sample variants: {sample.map((s) => s?.sku || s?.id).filter(Boolean).join(', ')}
                                  </Text>
                                </Box>
                              ) : null}
                              {issues.length > 0 ? (
                                <Box paddingBlockStart="100">
                                  <Text as="p" tone="critical">
                                    Issues: {issues.join(' | ')}
                                  </Text>
                                </Box>
                              ) : null}
                            </List.Item>
                          )
                        })}
                      </List>
                    </Box>
                  ) : (
                    <Box paddingBlockStart="200">
                      <Text as="p">No items returned for this page.</Text>
                    </Box>
                  )}
                </Box>
              ) : null}
            </BlockStack>
          </Card>
        </Layout.Section>

        <Layout.Section>
          <Card>
            <BlockStack gap="300">
              <Text as="h2" variant="headingMd">
                Shopify connection
              </Text>
              <Text as="p" tone="subdued">
                Shopify is connected automatically when you install this app. You do not need to enter Shopify store URL or access tokens.
              </Text>
              <Divider />
              <InlineStack gap="300" align="start" blockAlign="center">
                <Text as="span">Shop:</Text>
                <Text as="span">{meQuery.data?.shop_domain || '-'}</Text>
                <Text as="span" tone="subdued">
                  Installed at: {installedAtLabel}
                </Text>
                {meQuery.isFetching ? <Spinner size="small" /> : null}
              </InlineStack>
            </BlockStack>
          </Card>
        </Layout.Section>

        <Layout.Section>
          <Card>
            <BlockStack gap="400">
              <Text as="h2" variant="headingMd">
                Magento credentials (how to get them)
              </Text>
              <Text as="p" tone="subdued">
                You will need your Magento API URL plus an integration Access Token.
              </Text>

              <Card background="bg-surface-secondary">
                <BlockStack gap="200">
                  <Text as="h3" variant="headingSm">
                    Steps
                  </Text>
                  <List type="number">
                    <List.Item>Open your Magento Admin panel.</List.Item>
                    <List.Item>Go to System - Extensions - Integrations.</List.Item>
                    <List.Item>Create a new integration and grant appropriate API resource permissions.</List.Item>
                    <List.Item>Copy the Secret Access Token.</List.Item>
                    <List.Item>
                      Set the API URL (example): https://your-magento-domain.com
                    </List.Item>
                  </List>
                </BlockStack>
              </Card>

              <Banner status="info" title="Tip">
                <p>
                  We will add a guided wizard later. For now, paste the API URL and Access Token in the Migration page.
                </p>
              </Banner>
            </BlockStack>
          </Card>
        </Layout.Section>
      </Layout>
    </Page>
  )

  // Wizard step completion checks
  const isStep0Complete = connected
  const isStep1Complete = locationGid !== ''
  const isStep2Complete = stateMappings !== null && !stateMappingDirty

  const wizardSteps = [
    { id: 0, label: 'Connection', complete: isStep0Complete },
    { id: 1, label: 'Location', complete: isStep1Complete },
    { id: 2, label: 'Assignments', complete: isStep2Complete },
    { id: 3, label: 'Migration', complete: false },
  ]

  const migrationContent = (
    <Page>
      <Layout>
        {(error || (!queueHealthQuery.isLoading && !workerOnline)) ? (
          <Layout.Section>
            {error ? (
              <Banner status="critical" title="Error">
                <p>{error}</p>
              </Banner>
            ) : null}
            {!queueHealthQuery.isLoading && !workerOnline ? (
              <Banner status="critical" title="Queue worker is offline">
                <p>
                  Migration runs in the background. The server queue worker process must be running for the migration to start.
                </p>
              </Banner>
            ) : null}
          </Layout.Section>
        ) : null}

        {/* Wizard Progress Indicator */}
        <Layout.Section>
          <Card>
            <BlockStack gap="500">
              <div style={{ textAlign: 'center' }}>
                <Text as="h2" variant="headingLg">
                  Migration Setup Wizard
                </Text>
                <Box paddingBlockStart="200">
                  <Text as="p" tone="subdued">
                    Follow these steps to configure and run your migration
                  </Text>
                </Box>
              </div>
              <div style={{
                display: 'flex',
                gap: '0',
                alignItems: 'stretch',
                flexWrap: 'wrap',
                background: 'var(--p-color-bg-surface-secondary)',
                borderRadius: '12px',
                padding: '6px',
                border: '1px solid var(--p-color-border-subdued)'
              }}>
                {wizardSteps.map((step, idx) => (
                  <div key={step.id} style={{ display: 'flex', alignItems: 'center', flex: '1 1 0' }}>
                    <button
                      className="wizard-step-button"
                      onClick={() => setWizardStep(step.id)}
                      style={{
                        flex: 1,
                        padding: '12px 16px',
                        border: 'none',
                        background: wizardStep === step.id ? '#1a7f5a' : 'transparent',
                        color: wizardStep === step.id ? 'white' : 'var(--p-color-text)',
                        borderRadius: '8px',
                        cursor: 'pointer',
                        fontWeight: wizardStep === step.id ? '600' : '400',
                        fontSize: '14px',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        gap: '8px',
                        transition: 'all 0.25s ease',
                        transform: wizardStep === step.id ? 'scale(1.02)' : 'scale(1)',
                        boxShadow: wizardStep === step.id ? '0 2px 8px rgba(26,127,90,0.3)' : 'none',
                      }}
                      onMouseEnter={(e) => {
                        if (wizardStep !== step.id) {
                          e.currentTarget.style.background = 'var(--p-color-bg-surface-hover)'
                        }
                      }}
                      onMouseLeave={(e) => {
                        if (wizardStep !== step.id) {
                          e.currentTarget.style.background = 'transparent'
                        }
                      }}
                    >
                      <span style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        width: '28px',
                        height: '28px',
                        borderRadius: '50%',
                        background: wizardStep === step.id ? 'rgba(255,255,255,0.25)' : 'var(--p-color-bg-surface-tertiary)',
                        color: wizardStep === step.id ? 'white' : 'var(--p-color-text-subdued)',
                        fontSize: '13px',
                        fontWeight: '700',
                        transition: 'all 0.25s ease',
                        flexShrink: 0,
                      }}>
                        {idx + 1}
                      </span>
                      <span style={{
                        transition: 'all 0.25s ease',
                        fontWeight: wizardStep === step.id ? '600' : '400',
                      }}>{step.label}</span>
                    </button>
                    {idx < wizardSteps.length - 1 && (
                      <div style={{
                        width: '20px',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        color: 'var(--p-color-text-subdued)',
                        fontSize: '14px',
                        flexShrink: 0,
                      }}>
                        →
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </BlockStack>
          </Card>
        </Layout.Section>

        {/* Step 0: Connection */}
        {wizardStep === 0 && (
          <Layout.Section>
            <Card>
              <BlockStack gap="400">
                <Text as="h2" variant="headingMd">
                  Step 1: Connect Magento
                </Text>

                <TextField
                  label="Magento API URL"
                  value={apiUrl}
                  onChange={(v) => {
                    setApiUrl(v)
                    if (fieldErrors.api_url) setFieldErrors((s) => ({ ...s, api_url: undefined }))
                  }}
                  error={Array.isArray(fieldErrors.api_url) ? fieldErrors.api_url[0] : undefined}
                  autoComplete="off"
                />
                <TextField
                  label={secretSaved ? 'Access Token (saved)' : 'Access Token'}
                  value={secretSaved && !editingSecret ? '••••••••••••••••' : accessToken}
                  readOnly={secretSaved && !editingSecret}
                  onChange={(v) => {
                    setAccessToken(v)
                    if (fieldErrors.access_token) setFieldErrors((s) => ({ ...s, access_token: undefined }))
                  }}
                  error={Array.isArray(fieldErrors.access_token) ? fieldErrors.access_token[0] : undefined}
                  helpText={secretSaved ? 'Token is stored securely and not displayed. Enter a new token only if you want to replace it.' : undefined}
                  type={secretSaved && !editingSecret ? 'password' : showSecret ? 'text' : 'password'}
                  autoComplete="off"
                  connectedRight={
                    secretSaved && !editingSecret ? (
                      <ButtonGroup>
                        <Button
                          size="slim"
                          icon={ViewIcon}
                          accessibilityLabel="Reveal token"
                          onClick={() => setToast({ content: 'Saved access tokens are not displayed. Click Change to enter a new token.', error: true })}
                        />
                        <Button
                          size="slim"
                          icon={EditIcon}
                          onClick={() => {
                            setEditingSecret(true)
                            setShowSecret(false)
                            setAccessToken('')
                          }}
                        >
                          Change
                        </Button>
                      </ButtonGroup>
                    ) : (
                      <Button
                        size="slim"
                        icon={ViewIcon}
                        accessibilityLabel={showSecret ? 'Hide token' : 'Show token'}
                        onClick={() => setShowSecret((s) => !s)}
                      />
                    )
                  }
                />

                <TextField
                  label="Media Directory Path (for products)"
                  value={filesPath}
                  onChange={(v) => setFilesPath(v)}
                  placeholder="e.g. /var/www/html/magento/pub/media"
                  helpText="Absolute path to Magento's pub/media directory. Required to download product files. Usually {magento_root}/pub/media."
                  autoComplete="off"
                />

                <InlineStack gap="300" align="start" blockAlign="center">
                  <Button
                    variant="primary"
                    loading={saveConnection.isPending}
                    onClick={() => {
                      const payload = { api_url: apiUrl, files_path: filesPath }
                      const token = (accessToken || '').trim()
                      const needsSecret = !secretSaved || editingSecret
                      if (needsSecret && token) {
                        payload.access_token = token
                      }
                      saveConnection.mutate(payload)
                    }}
                  >
                    Save connection
                  </Button>
                  {connectionQuery.isFetching ? <Spinner size="small" /> : null}
                  <Text as="span">Status: {connected ? 'Connected' : 'Not connected'}</Text>
                </InlineStack>

                {/* Language Selection — shown when connected */}
                {connected && (
                  <LanguageSelectionCard
                    api={api}
                    languageConfig={connectionQuery.data?.language_config || []}
                    onSave={(langConfig) => {
                      const payload = { api_url: apiUrl, files_path: filesPath, language_config: langConfig }
                      saveConnection.mutate(payload)
                    }}
                    saving={saveConnection.isPending}
                  />
                )}

                {connected && (
                  <Box paddingBlockStart="300">
                    <InlineStack gap="300" align="end" blockAlign="center">
                      <Button variant="primary" onClick={() => setWizardStep(1)}>
                        Next: Shop Location
                      </Button>
                    </InlineStack>
                  </Box>
                )}
              </BlockStack>
            </Card>
          </Layout.Section>
        )}

        {/* Step 1: Location */}
        {wizardStep === 1 && (
          <Layout.Section>
            <Card roundedAbove="sm">
              <BlockStack gap="400">
                <Text variant="headingMd" as="h2">
                  Step 2: Select Location
                </Text>
                <Select
                  label="Location"
                  options={locationOptions}
                  value={locationGid}
                  onChange={handleLocationChange}
                  disabled={locationsQuery.isLoading}
                />
                {locationsQuery.isError ? (
                  <Banner status="critical" title="Failed to load locations">
                    <p>{locationsQuery.error?.message || 'Could not fetch Shopify locations.'}</p>
                    <Box paddingBlockStart="200">
                      <Button onClick={() => locationsQuery.refetch()}>Retry</Button>
                    </Box>
                  </Banner>
                ) : null}

                {!locationsQuery.isLoading && !locationsQuery.isError && Array.isArray(locations) && locations.length === 0 ? (
                  <Banner status="warning" title="No locations found">
                    <p>
                      Your store returned no locations. Make sure you have at least one location in Shopify (Settings → Locations) and that the app
                      has permission to read inventory.
                    </p>
                    <Box paddingBlockStart="200">
                      <Button onClick={() => locationsQuery.refetch()}>Retry</Button>
                    </Box>
                  </Banner>
                ) : null}

                <InlineStack gap="300" align="space-between" blockAlign="center">
                  <Button variant="secondary" onClick={() => setWizardStep(0)}>
                    Previous
                  </Button>
                  {locationGid && (
                    <Button variant="primary" onClick={() => setWizardStep(2)}>
                      Next
                    </Button>
                  )}
                </InlineStack>
              </BlockStack>
            </Card>
          </Layout.Section>
        )}

        {/* Step 2: Assignments */}
        {wizardStep === 2 && (
          <Layout.Section>
            <Card>
              <BlockStack gap="400">
                <InlineStack gap="300" align="space-between" blockAlign="center">
                  <BlockStack gap="100">
                    <Text as="h2" variant="headingMd">
                      Step 3: Assignments
                    </Text>
                    <Text as="p" tone="subdued">
                      Map Magento values to Shopify equivalents. Applied during migration.
                    </Text>
                  </BlockStack>
                  {stateMappingDirty ? (
                    <Badge tone="attention">Unsaved changes</Badge>
                  ) : (
                    <Badge tone="success">Saved</Badge>
                  )}
                </InlineStack>

                {stateMappingQuery.isLoading ? (
                  <InlineStack gap="200" align="start" blockAlign="center">
                    <Spinner size="small" />
                    <Text as="span" tone="subdued">Loading state mappings…</Text>
                  </InlineStack>
                ) : stateMappings && stateMappingOptions ? (
                  <BlockStack gap="400">
                    {/* Custom tab bar matching wizard style */}
                    <div style={{
                      display: 'flex',
                      gap: '0',
                      background: 'var(--p-color-bg-surface-secondary)',
                      borderRadius: '10px',
                      padding: '4px',
                      border: '1px solid var(--p-color-border-subdued)',
                      flexWrap: 'wrap',
                    }}>
                      {[
                        'Transaction States',
                        'Delivery States',
                        'Order States',
                        'Payment Methods',
                        'Shipping Methods',
                      ].map((label, idx) => (
                        <button
                          key={idx}
                          onClick={() => setStateMappingTab(idx)}
                          style={{
                            flex: '1 1 auto',
                            padding: '8px 14px',
                            border: 'none',
                            background: stateMappingTab === idx ? '#1a7f5a' : 'transparent',
                            color: stateMappingTab === idx ? 'white' : 'var(--p-color-text)',
                            borderRadius: '7px',
                            cursor: 'pointer',
                            fontSize: '13px',
                            fontWeight: stateMappingTab === idx ? '600' : '400',
                            transition: 'all 0.2s ease',
                            whiteSpace: 'nowrap',
                            boxShadow: stateMappingTab === idx ? '0 2px 6px rgba(26,127,90,0.25)' : 'none',
                          }}
                          onMouseEnter={(e) => {
                            if (stateMappingTab !== idx) e.currentTarget.style.background = 'var(--p-color-bg-surface-hover)'
                          }}
                          onMouseLeave={(e) => {
                            if (stateMappingTab !== idx) e.currentTarget.style.background = 'transparent'
                          }}
                        >
                          {label}
                        </button>
                      ))}
                    </div>

                    {/* Header row */}
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', padding: '10px 12px', background: 'var(--p-color-bg-surface-secondary)', borderRadius: '8px' }}>
                      <Text as="p" variant="bodySm" fontWeight="semibold" tone="subdued">Previous value</Text>
                      <Text as="p" variant="bodySm" fontWeight="semibold" tone="subdued">New assignment</Text>
                    </div>

                    {stateMappingTab === 0 && (
                      <BlockStack gap="0">
                        {Object.entries(stateMappings.transaction_state || {}).map(([swState, sfStatus], idx) => (
                          <div key={swState} className="mapping-row" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', alignItems: 'center', padding: '10px 12px', borderBottom: '1px solid var(--p-color-border-subdued)', background: idx % 2 === 0 ? 'transparent' : 'var(--p-color-bg-surface-secondary)' }}>
                            <div>
                              <Text as="span" variant="bodyMd" fontWeight="medium">
                                {swState.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                              </Text>
                              {'  '}
                              <Text as="span" variant="bodySm" tone="subdued">({swState})</Text>
                            </div>
                            <Select
                              label="" labelHidden
                              options={(stateMappingOptions.order_financial || []).map(o => ({ label: o.label, value: o.value }))}
                              value={sfStatus}
                              onChange={(val) => {
                                setStateMappings(prev => ({ ...prev, transaction_state: { ...prev.transaction_state, [swState]: val } }))
                                setStateMappingDirty(true)
                              }}
                            />
                          </div>
                        ))}
                      </BlockStack>
                    )}

                    {stateMappingTab === 1 && (
                      <BlockStack gap="0">
                        {Object.entries(stateMappings.delivery_state || {}).map(([swState, sfStatus], idx) => (
                          <div key={swState} className="mapping-row" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', alignItems: 'center', padding: '10px 12px', borderBottom: '1px solid var(--p-color-border-subdued)', background: idx % 2 === 0 ? 'transparent' : 'var(--p-color-bg-surface-secondary)' }}>
                            <div>
                              <Text as="span" variant="bodyMd" fontWeight="medium">
                                {swState.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                              </Text>
                              {'  '}
                              <Text as="span" variant="bodySm" tone="subdued">({swState})</Text>
                            </div>
                            <Select
                              label="" labelHidden
                              options={(stateMappingOptions.fulfillment || []).map(o => ({ label: o.label, value: o.value }))}
                              value={sfStatus}
                              onChange={(val) => {
                                setStateMappings(prev => ({ ...prev, delivery_state: { ...prev.delivery_state, [swState]: val } }))
                                setStateMappingDirty(true)
                              }}
                            />
                          </div>
                        ))}
                      </BlockStack>
                    )}

                    {stateMappingTab === 2 && (
                      <BlockStack gap="0">
                        {Object.entries(stateMappings.order_state || {}).map(([swState, sfStatus], idx) => (
                          <div key={swState} className="mapping-row" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', alignItems: 'center', padding: '10px 12px', borderBottom: '1px solid var(--p-color-border-subdued)', background: idx % 2 === 0 ? 'transparent' : 'var(--p-color-bg-surface-secondary)' }}>
                            <div>
                              <Text as="span" variant="bodyMd" fontWeight="medium">
                                {swState.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                              </Text>
                              {'  '}
                              <Text as="span" variant="bodySm" tone="subdued">({swState})</Text>
                            </div>
                            <Select
                              label="" labelHidden
                              options={(stateMappingOptions.order_financial || []).map(o => ({ label: o.label, value: o.value }))}
                              value={sfStatus}
                              onChange={(val) => {
                                setStateMappings(prev => ({ ...prev, order_state: { ...prev.order_state, [swState]: val } }))
                                setStateMappingDirty(true)
                              }}
                            />
                          </div>
                        ))}
                      </BlockStack>
                    )}

                    {stateMappingTab === 3 && (
                      <BlockStack gap="0">
                        {Object.entries(stateMappings.payment_methods || {}).length === 0 ? (
                          <Box padding="400">
                            <BlockStack gap="200">
                              <Text as="p" tone="subdued">No Magento payment methods found yet.</Text>
                              <Text as="p" tone="subdued">Payment method names are auto-discovered from your Magento orders during migration. Run an order preview to populate this list.</Text>
                            </BlockStack>
                          </Box>
                        ) : (
                          Object.entries(stateMappings.payment_methods || {}).map(([swMethod, sfMethod], idx) => (
                            <div key={swMethod} className="mapping-row" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', alignItems: 'center', padding: '10px 12px', borderBottom: '1px solid var(--p-color-border-subdued)', background: idx % 2 === 0 ? 'transparent' : 'var(--p-color-bg-surface-secondary)' }}>
                              <div>
                                <Text as="span" variant="bodyMd" fontWeight="medium">{swMethod}</Text>
                              </div>
                              <Select
                                label="" labelHidden
                                options={(stateMappingOptions.payment_methods || []).map(o => ({ label: o.label, value: o.value }))}
                                value={sfMethod}
                                onChange={(val) => {
                                  setStateMappings(prev => ({ ...prev, payment_methods: { ...prev.payment_methods, [swMethod]: val } }))
                                  setStateMappingDirty(true)
                                }}
                              />
                            </div>
                          ))
                        )}
                      </BlockStack>
                    )}

                    {stateMappingTab === 4 && (
                      <BlockStack gap="0">
                        {Object.entries(stateMappings.shipping_methods || {}).length === 0 ? (
                          <Box padding="400">
                            <BlockStack gap="200">
                              <Text as="p" tone="subdued">No Magento shipping methods found yet.</Text>
                              <Text as="p" tone="subdued">Shipping method names are auto-discovered from your Magento orders during migration. Run an order preview to populate this list.</Text>
                            </BlockStack>
                          </Box>
                        ) : (
                          Object.entries(stateMappings.shipping_methods || {}).map(([swMethod, sfMethod], idx) => (
                            <div key={swMethod} className="mapping-row" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', alignItems: 'center', padding: '10px 12px', borderBottom: '1px solid var(--p-color-border-subdued)', background: idx % 2 === 0 ? 'transparent' : 'var(--p-color-bg-surface-secondary)' }}>
                              <div>
                                <Text as="span" variant="bodyMd" fontWeight="medium">{swMethod}</Text>
                              </div>
                              <Select
                                label="" labelHidden
                                options={(stateMappingOptions.shipping_methods || []).map(o => ({ label: o.label, value: o.value }))}
                                value={sfMethod}
                                onChange={(val) => {
                                  setStateMappings(prev => ({ ...prev, shipping_methods: { ...prev.shipping_methods, [swMethod]: val } }))
                                  setStateMappingDirty(true)
                                }}
                              />
                            </div>
                          ))
                        )}
                      </BlockStack>
                    )}



                    <Divider />

                    <InlineStack gap="300" align="start" blockAlign="center">
                      <Button
                        variant="primary"
                        loading={saveStateMappings.isPending}
                        disabled={!stateMappingDirty}
                        onClick={() => saveStateMappings.mutate({ mappings: stateMappings })}
                      >
                        Save mappings
                      </Button>
                      <Button
                        variant="secondary"
                        disabled={!stateMappingDirty || saveStateMappings.isPending}
                        onClick={() => {
                          const data = stateMappingQuery.data
                          if (data?.mappings) {
                            setStateMappings(JSON.parse(JSON.stringify(data.mappings)))
                            setStateMappingDirty(false)
                          }
                        }}
                      >
                        Reset to saved
                      </Button>
                      {stateMappingQuery.isFetching ? <Spinner size="small" /> : null}
                    </InlineStack>

                    <Banner tone="info">
                      <p>Changes take effect on the next migration run. Values not listed here use the default fallback logic.</p>
                    </Banner>

                    <InlineStack gap="300" align="space-between" blockAlign="center">
                      <Button variant="secondary" onClick={() => setWizardStep(1)}>
                        Previous
                      </Button>
                      <Button variant="primary" onClick={() => setWizardStep(3)}>
                        Next
                      </Button>
                    </InlineStack>
                  </BlockStack>
                ) : (
                  <Banner tone="warning">
                    <p>Connect Magento first to load assignments.</p>
                  </Banner>
                )}
              </BlockStack>
            </Card>
          </Layout.Section>
        )}

        {/* Step 3: Migration Cards */}
        {wizardStep === 3 && (
          <>
            <Layout.Section>
              <Card>
                <BlockStack gap="300">
                  <Text as="h2" variant="headingMd">
                    Step 4: Run Migrations
                  </Text>
                  <Text as="p" tone="subdued">
                    Complete the setup steps above before starting migrations. You can run migrations in any order.
                  </Text>
                  <InlineStack gap="300" align="start" blockAlign="center">
                    <Button variant="secondary" onClick={() => setWizardStep(2)}>
                      Previous
                    </Button>
                  </InlineStack>
                </BlockStack>
              </Card>
            </Layout.Section>

            {/* ── Markets Migration ── */}
            <Layout.Section>
              <MarketsMigrationCard
                connected={connected}
                workerOnline={workerOnline}
                marketRun={marketRun}
                isMarketRunning={isMarketRunning}
                marketPreview={marketPreview}
                previewMarketMigration={previewMarketMigration}
                startMarketMigration={startMarketMigration}
                cancelMarketMigration={cancelMarketMigration}
                handleDownloadReport={handleDownloadReport}
                recentMarketFailedItems={recentMarketFailedItems}
                marketSkippedCount={marketSkippedCount}
                marketDurationLabel={marketDurationLabel}
                confirmMarketStartOpen={confirmMarketStartOpen}
                setConfirmMarketStartOpen={setConfirmMarketStartOpen}
              />
            </Layout.Section>

            <Layout.Section>
              <Card>
                <BlockStack gap="400">
                  <InlineStack align="space-between" blockAlign="center">
                    <Text as="h2" variant="headingMd">
                      5) Migrate manufacturers (vendors)
                    </Text>
                    <InlineStack gap="200">
                      {manufacturerRun?.report_available ? (
                        <Button icon={ImportIcon} variant="tertiary" onClick={() => handleDownloadReport(manufacturerRun)}>
                          Download CSV
                        </Button>
                      ) : null}
                    </InlineStack>
                  </InlineStack>
                  <Text as="p" tone="subdued">
                    Import Magento manufacturers first. Product migration uses these as Shopify vendor names (run before products).
                  </Text>

                  {/* What gets migrated — collapsible */}
                  <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#f0f7ff' }}>
                    <button
                      type="button"
                      onClick={() => setManufacturerMappingInfoOpen((v) => !v)}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        padding: '12px 16px',
                        textAlign: 'left',
                      }}
                    >
                      <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#0070c0' }}>
                        <span>ℹ</span> What gets migrated
                      </span>
                      <span style={{ fontSize: '12px', color: '#6d7175', transform: manufacturerMappingInfoOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
                    </button>
                    {manufacturerMappingInfoOpen && (
                      <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
                        <List type="bullet">
                          <List.Item><strong>Manufacturers as Shopify Vendors</strong> — Magento manufacturers are migrated and mapped to Shopify vendor names.</List.Item>
                          <List.Item><strong>Product Vendor Mapping</strong> — These names are assigned to the vendor attribute of migrated products.</List.Item>
                        </List>
                      </div>
                    )}
                  </div>

                  {/* Known limitations — collapsible */}
                  <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#fff8f0' }}>
                    <button
                      type="button"
                      onClick={() => setManufacturerLimitationsOpen((v) => !v)}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        padding: '12px 16px',
                        textAlign: 'left',
                      }}
                    >
                      <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#b98900' }}>
                        <span>⚠</span> Known limitations
                      </span>
                      <span style={{ fontSize: '12px', color: '#6d7175', transform: manufacturerLimitationsOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
                    </button>
                    {manufacturerLimitationsOpen && (
                      <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
                        <List type="bullet">
                          <List.Item><strong>No dedicated Manufacturer entity</strong> — Shopify does not have a separate Manufacturer resource. They are simply text tags on products, so manufacturer descriptions, URLs, and logos are not migrated.</List.Item>
                        </List>
                      </div>
                    )}
                  </div>

                  <InlineStack gap="300" align="start" blockAlign="center">
                    <Button
                      loading={previewManufacturerMigration.isPending}
                      disabled={!connected || !workerOnline || isManufacturerRunning}
                      onClick={() => {
                        setManufacturerPreview(null)
                        previewManufacturerMigration.mutate({ limit: 10, page: 1 })
                      }}
                    >
                      Preview (dry run)
                    </Button>
                    {!workerOnline ? <Text as="span" tone="subdued">Queue worker offline</Text> : null}
                    <Button
                      variant="primary"
                      disabled={!connected || !workerOnline || isManufacturerRunning}
                      loading={startManufacturerMigration.isPending || isManufacturerRunning}
                      onClick={() => startManufacturerMigration.mutate()}
                    >
                      Start manufacturer migration
                    </Button>
                    <Button
                      variant="secondary"
                      disabled={!isManufacturerRunning}
                      loading={cancelManufacturerMigration.isPending}
                      onClick={() => cancelManufacturerMigration.mutate()}
                    >
                      Cancel
                    </Button>
                  </InlineStack>

                  {manufacturerPreview ? (
                    <Card background="bg-surface-secondary">
                      <BlockStack gap="200">
                        <Text as="h3" variant="headingSm">
                          Preview results
                        </Text>
                        <Text as="p">Total (Magento): {manufacturerPreview.total ?? '-'}</Text>
                        {manufacturerPreviewItems.length > 0 ? (
                          <List type="bullet">
                            {manufacturerPreviewItems.map((it) => (
                              <List.Item key={it.source_id}>
                                <Text as="span" fontWeight="semibold">
                                  {it.name || it.source_id}
                                </Text>
                                <Text as="span"> — Shopify vendor: {it.vendor || '-'}</Text>
                              </List.Item>
                            ))}
                          </List>
                        ) : (
                          <Text as="p">No manufacturers returned for this page.</Text>
                        )}
                      </BlockStack>
                    </Card>
                  ) : null}

                  {manufacturerRun ? (
                    <Card background="bg-surface-secondary">
                      <BlockStack gap="200">
                        <Text as="h3" variant="headingSm">
                          Status
                        </Text>
                        <Text as="p">Run: #{manufacturerRun.id}</Text>
                        <Text as="p">State: {manufacturerRun.status}</Text>
                        {manufacturerDurationLabel ? (
                          <Text as="p">
                            {isManufacturerRunning ? 'Elapsed: ' : 'Total time: '}
                            {manufacturerDurationLabel}
                          </Text>
                        ) : null}
                        <Text as="p">Processed: {manufacturerRun.processed ?? 0}</Text>
                        <Text as="p">Succeeded: {manufacturerRun.succeeded ?? 0}</Text>
                        <Text as="p">Failed: {manufacturerRun.failed ?? 0}</Text>
                        <Text as="p">Skipped: {manufacturerSkippedCount}</Text>

                        {manufacturerRecentFailedItems.length > 0 ? (
                          <Box paddingBlockStart="200">
                            <Text as="h4" variant="headingSm">
                              Recent failures
                            </Text>
                            <List type="bullet">
                              {manufacturerRecentFailedItems.map((it) => (
                                <List.Item key={it.id}>
                                  <Text as="span" fontWeight="semibold">
                                    {it.source_id}
                                  </Text>
                                  <Text as="span">
                                    {' '}
                                    — {it.error_message || 'Failed'}
                                  </Text>
                                </List.Item>
                              ))}
                            </List>
                          </Box>
                        ) : null}
                      </BlockStack>
                    </Card>
                  ) : null}
                </BlockStack>
              </Card>
            </Layout.Section>

            <Layout.Section>
              <Card>
                <BlockStack gap="400">
                  <InlineStack align="space-between" blockAlign="center">
                    <Text as="h2" variant="headingMd">
                      6) Migrate products + variants
                    </Text>
                    <InlineStack gap="200">
                      {run?.report_available ? (
                        <Button icon={ImportIcon} variant="tertiary" onClick={() => handleDownloadReport(run)}>
                          Download CSV
                        </Button>
                      ) : null}
                    </InlineStack>
                  </InlineStack>

                  {/* What gets migrated — collapsible */}
                  <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#f0f7ff' }}>
                    <button
                      type="button"
                      onClick={() => setProductMappingInfoOpen((v) => !v)}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        padding: '12px 16px',
                        textAlign: 'left',
                      }}
                    >
                      <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#0070c0' }}>
                        <span>ℹ</span> What gets migrated
                      </span>
                      <span style={{ fontSize: '12px', color: '#6d7175', transform: productMappingInfoOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
                    </button>
                    {productMappingInfoOpen && (
                      <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
                        <List type="bullet">
                          <List.Item><strong>Products &amp; Variants</strong> — fully migrated with titles, descriptions, options, values, price, SKU, barcode, and active status.</List.Item>
                          <List.Item><strong>Variant Images</strong> — parent and variant cover images are automatically downloaded and uploaded to Shopify.</List.Item>
                          <List.Item><strong>Inventory &amp; Quantities</strong> — stock levels are mapped to your selected Shopify location.</List.Item>
                          <List.Item><strong>Taxes &amp; Taxable Flag</strong> — variants are flagged as taxable if the Magento tax rate is greater than 0%.</List.Item>
                          <List.Item><strong>Price Mode (Gross/Net)</strong> — product prices are migrated according to your chosen price mode (gross or net).</List.Item>
                        </List>
                      </div>
                    )}
                  </div>

                  {/* Known limitations — collapsible */}
                  <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#fff8f0' }}>
                    <button
                      type="button"
                      onClick={() => setProductLimitationsOpen((v) => !v)}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        padding: '12px 16px',
                        textAlign: 'left',
                      }}
                    >
                      <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#b98900' }}>
                        <span>⚠</span> Known limitations
                      </span>
                      <span style={{ fontSize: '12px', color: '#6d7175', transform: productLimitationsOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
                    </button>
                    {productLimitationsOpen && (
                      <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
                        <List type="bullet">
                          <List.Item><strong>Shopify Taxes and duties</strong> must be configured manually. Shopify's API does not support programmatic tax registration setup.</List.Item>
                          <List.Item><strong>Static Tax Rates</strong> (e.g. 19% or 7% rate name/percentage) are stored as product metafields (<code>tax_rate</code>, <code>tax_name</code>) for storefront reference, as Shopify variants do not natively store custom tax rate values.</List.Item>
                          <List.Item><strong>Multiple Currencies</strong> are mapped based on the primary shop currency. Individual market pricing can be adjusted in Shopify Markets.</List.Item>
                          <List.Item><strong>Advanced tier prices</strong> are synced, but dynamic rule-based pricing should be reviewed in Shopify Markets or via price lists.</List.Item>
                          <List.Item><strong>Digital Files</strong> are migrated to Shopify's Files CDN and stored as clickable links in the <code>magento.download_links</code> metafield on products and variants. Native Shopify digital delivery setup is outside the scope of this implementation.</List.Item>
                        </List>
                      </div>
                    )}
                  </div>

                  <InlineStack gap="300" align="start" blockAlign="center">
                    <Button
                      loading={previewMigration.isPending}
                      disabled={!connected || !workerOnline || !locationGid || isRunning}
                      onClick={() => {
                        setPreview(null)
                        previewMigration.mutate({ location_gid: locationGid, limit: 10, page: 1, include_payload: false })
                      }}
                    >
                      Preview (dry run)
                    </Button>
                    {!workerOnline ? <Text as="span" tone="subdued">Queue worker offline</Text> : null}
                    {!locationGid ? <Text as="span" tone="subdued">Select a location to enable preview</Text> : null}
                    <Tooltip content="Migrate all products from Magento.">
                      <Button
                        variant="primary"
                        disabled={!canStart}
                        loading={startMigration.isPending || isRunning}
                        onClick={() => setConfirmStartOpen(true)}
                      >
                        Start migration
                      </Button>
                    </Tooltip>
                    <Tooltip content="Migrate products created after a date or within a date range.">
                      <Button
                        variant="secondary"
                        disabled={!canStartProductFiltered}
                        loading={startFilteredProductMigration.isPending || isRunning}
                        onClick={() => {
                          setProductFilteredPreview(null)
                          setProductFilteredModalOpen(true)
                        }}
                      >
                        Migrate By Date
                      </Button>
                    </Tooltip>
                    <Button variant="secondary" disabled={!isRunning} loading={cancelMigration.isPending} onClick={() => cancelMigration.mutate()}>
                      Cancel
                    </Button>
                  </InlineStack>

                  <Box paddingBlockStart="300">
                    <InlineStack gap="300" align="start" blockAlign="center">
                      <Button
                        loading={previewRedirectMigration.isPending}
                        disabled={!connected || !workerOnline}
                        onClick={() => {
                          setRedirectPreview(null)
                          previewRedirectMigration.mutate({ limit: 20, page: 1 })
                        }}
                      >
                        Preview 301 redirects
                      </Button>
                      <Button
                        variant="secondary"
                        loading={importRedirectMigration.isPending}
                        disabled={!connected || !workerOnline}
                        onClick={() => importRedirectMigration.mutate({ limit: 20, page: 1 })}
                      >
                        Import 301 redirects
                      </Button>
                    </InlineStack>
                    {redirectImportResult ? (
                      <Box paddingBlockStart="200">
                        <Text as="p" tone="subdued">
                          Redirect import result: processed {redirectImportResult.processed ?? 0}, succeeded {redirectImportResult.succeeded ?? 0}, failed {redirectImportResult.failed ?? 0}
                        </Text>
                      </Box>
                    ) : null}
                    {redirectPreview && Array.isArray(redirectPreview.items) && redirectPreview.items.length > 0 ? (
                      <Box paddingBlockStart="200">
                        <Text as="p" fontWeight="semibold">Redirect preview (sample)</Text>
                        <List type="bullet">
                          {redirectPreview.items.slice(0, 10).map((it) => (
                            <List.Item key={`${it.source_id}:${it.old_path}`}>
                              <Text as="span">{it.old_path}{' -> '}{it.new_path}</Text>
                            </List.Item>
                          ))}
                        </List>
                      </Box>
                    ) : null}
                  </Box>

                  <Box paddingBlockStart="300">
                    <InlineStack gap="300" align="start" blockAlign="center">
                      <Button
                        loading={previewCollectionRedirectMigration.isPending}
                        disabled={!connected || !workerOnline}
                        onClick={() => {
                          setCollectionRedirectPreview(null)
                          previewCollectionRedirectMigration.mutate({ limit: 20, page: 1 })
                        }}
                      >
                        Preview collection 301 redirects
                      </Button>
                      <Button
                        variant="secondary"
                        loading={importCollectionRedirectMigration.isPending}
                        disabled={!connected || !workerOnline}
                        onClick={() => importCollectionRedirectMigration.mutate({ limit: 20, page: 1 })}
                      >
                        Import collection 301 redirects
                      </Button>
                    </InlineStack>
                    {collectionRedirectImportResult ? (
                      <Box paddingBlockStart="200">
                        <Text as="p" tone="subdued">
                          Collection redirect import result: processed {collectionRedirectImportResult.processed ?? 0}, succeeded {collectionRedirectImportResult.succeeded ?? 0}, failed {collectionRedirectImportResult.failed ?? 0}
                        </Text>
                      </Box>
                    ) : null}
                    {collectionRedirectPreview && Array.isArray(collectionRedirectPreview.items) && collectionRedirectPreview.items.length > 0 ? (
                      <Box paddingBlockStart="200">
                        <Text as="p" fontWeight="semibold">Collection redirect preview (sample)</Text>
                        <List type="bullet">
                          {collectionRedirectPreview.items.slice(0, 10).map((it) => (
                            <List.Item key={`${it.source_id}:${it.old_path}`}>
                              <Text as="span">{it.old_path}{' -> '}{it.new_path}</Text>
                            </List.Item>
                          ))}
                        </List>
                      </Box>
                    ) : null}
                  </Box>

                  {/* Migrate By Date modal */}
                  <Modal
                    open={productFilteredModalOpen}
                    onClose={() => setProductFilteredModalOpen(false)}
                    title="Migrate products by created date"
                    primaryAction={{
                      content: 'Preview filtered products',
                      onAction: () => {
                        setProductFilteredPreview(null)
                        previewFilteredProductMigration.mutate({
                          mode: productDateMode,
                          after: productAfterDate || null,
                          before: productBeforeDate || null,
                          location_gid: locationGid,
                          limit: 10,
                          page: 1,
                        })
                      },
                    }}
                    secondaryActions={[{ content: 'Close', onAction: () => setProductFilteredModalOpen(false) }]}
                  >
                    <Modal.Section>
                      <BlockStack gap="300">
                        <Select
                          label="Filter mode"
                          options={[
                            { label: 'After a date', value: 'after' },
                            { label: 'Between two dates', value: 'between' },
                          ]}
                          value={productDateMode}
                          onChange={(v) => setProductDateMode(v)}
                        />
                        {(productDateMode === 'after' || productDateMode === 'between') ? (
                          <Box onClick={() => productAfterInputRef.current?.showPicker?.()} onFocusCapture={() => productAfterInputRef.current?.showPicker?.()}>
                            <TextField
                              label="After (YYYY-MM-DD)"
                              type="date"
                              value={productAfterDate}
                              onChange={(v) => setProductAfterDate(v)}
                              inputRef={productAfterInputRef}
                              autoComplete="off"
                            />
                          </Box>
                        ) : null}
                        {productDateMode === 'between' ? (
                          <Box onClick={() => productBeforeInputRef.current?.showPicker?.()} onFocusCapture={() => productBeforeInputRef.current?.showPicker?.()}>
                            <TextField
                              label="Before (YYYY-MM-DD)"
                              type="date"
                              value={productBeforeDate}
                              onChange={(v) => setProductBeforeDate(v)}
                              inputRef={productBeforeInputRef}
                              autoComplete="off"
                            />
                          </Box>
                        ) : null}
                        {previewFilteredProductMigration.isPending ? (
                          <InlineStack gap="200" align="start" blockAlign="center">
                            <Spinner size="small" />
                            <Text as="span">Fetching total…</Text>
                          </InlineStack>
                        ) : null}
                        {productFilteredPreviewTotal != null ? (
                          <Banner status="info" title="Filtered total">
                            <p>Total products matching filter (Magento): {productFilteredPreviewTotal}</p>
                          </Banner>
                        ) : null}
                        <Button
                          variant="primary"
                          disabled={productFilteredPreviewTotal == null || productFilteredPreviewTotal <= 0}
                          onClick={() => setConfirmProductFilteredStartOpen(true)}
                        >
                          Continue to start filtered migration
                        </Button>
                      </BlockStack>
                    </Modal.Section>
                  </Modal>

                  {/* Confirm filtered start modal */}
                  <Modal
                    open={confirmProductFilteredStartOpen}
                    onClose={() => setConfirmProductFilteredStartOpen(false)}
                    title="Start filtered product migration?"
                    primaryAction={{
                      content: 'Start filtered migration',
                      destructive: true,
                      onAction: () => {
                        setConfirmProductFilteredStartOpen(false)
                        setProductFilteredModalOpen(false)
                        startFilteredProductMigration.mutate({
                          mode: productDateMode,
                          after: productAfterDate || null,
                          before: productBeforeDate || null,
                          location_gid: locationGid,
                        })
                      },
                    }}
                    secondaryActions={[{ content: 'Cancel', onAction: () => setConfirmProductFilteredStartOpen(false) }]}
                  >
                    <Modal.Section>
                      <Text as="p">This will migrate products that match your date filter.</Text>
                      {productFilteredPreviewTotal != null ? (
                        <Box paddingBlockStart="200">
                          <Text as="p" tone="subdued">Matching products (Magento): {productFilteredPreviewTotal}</Text>
                        </Box>
                      ) : null}
                    </Modal.Section>
                  </Modal>

                  {isFinishedSuccess ? (
                    <Banner status="success" title="Migration completed">
                      <p>
                        Migrated {run.succeeded} products successfully. You can now review them in Shopify Admin.
                      </p>
                    </Banner>
                  ) : null}

                  {isFinishedNoChanges ? (
                    <Banner status="info" title="No changes detected">
                      <p>
                        This run checked {skippedCount} products and skipped them because the mapped data is unchanged.
                      </p>
                    </Banner>
                  ) : null}

                  {preview ? (
                    <Card background="bg-surface-secondary">
                      <BlockStack gap="200">
                        <Text as="h3" variant="headingSm">
                          Preview results
                        </Text>
                        <Text as="p">Page: {preview.page ?? '-'}</Text>
                        <Text as="p">Total (Magento): {preview.total ?? '-'}</Text>

                        {previewItems.length > 0 ? (
                          <List type="bullet">
                            {previewItems.map((it) => {
                              const issues = Array.isArray(it?.issues) ? it.issues : []
                              const sample = Array.isArray(it?.child_sample) ? it.child_sample : []
                              const cats = Array.isArray(it?.categories) ? it.categories : []
                              const vSample = it?.variant_sample && typeof it.variant_sample === 'object' ? it.variant_sample : null
                              return (
                                <List.Item key={it.source_id}>
                                  <Text as="span" fontWeight="semibold">
                                    {it.title || it.source_id}
                                  </Text>
                                  <Text as="span">
                                    {' '}
                                    — Magento children: {it.shopware_child_count ?? '-'}; mapped variants: {it.variant_count ?? '-'} (expected:{' '}
                                    {it.expected_variant_count ?? '-'})
                                  </Text>
                                  <Box paddingBlockStart="100">
                                    <Text as="p" tone="subdued">
                                      Vendor: {it.vendor || '-'}; Type: {it.product_type || '-'}; SEO: {it.seo_present ? 'Yes' : 'No'}; Media:{' '}
                                      {it.media_count ?? 0} (cover: {it.has_cover ? 'Yes' : 'No'}); Categories: {cats.length}
                                    </Text>
                                  </Box>
                                  {vSample ? (
                                    <Box paddingBlockStart="100">
                                      <Text as="p" tone="subdued">
                                        Variant sample — SKU: {vSample.sku || '-'}; Price: {vSample.price || '-'}; Qty: {vSample.qty ?? '-'}; Weight:{' '}
                                        {vSample.weight ?? '-'}
                                      </Text>
                                    </Box>
                                  ) : null}
                                  {sample.length > 0 ? (
                                    <Box paddingBlockStart="100">
                                      <Text as="p" tone="subdued">
                                        Sample variants: {sample.map((s) => s?.sku || s?.id).filter(Boolean).join(', ')}
                                      </Text>
                                    </Box>
                                  ) : null}
                                  {issues.length > 0 ? (
                                    <Box paddingBlockStart="100">
                                      <Text as="p" tone="critical">
                                        Issues: {issues.join(' | ')}
                                      </Text>
                                    </Box>
                                  ) : null}
                                </List.Item>
                              )
                            })}
                          </List>
                        ) : (
                          <Text as="p">No items returned for this page.</Text>
                        )}
                      </BlockStack>
                    </Card>
                  ) : null}

                  <Modal
                    open={confirmStartOpen}
                    onClose={() => setConfirmStartOpen(false)}
                    title="Ready to start migration?"
                    primaryAction={{
                      content: 'Start migration',
                      destructive: true,
                      onAction: () => {
                        setConfirmStartOpen(false)
                        startMigration.mutate()
                      },
                    }}
                    secondaryActions={[
                      {
                        content: 'Cancel',
                        onAction: () => setConfirmStartOpen(false),
                      },
                    ]}
                  >
                    <Modal.Section>
                      <Text as="p">
                        We’ll start syncing your Magento catalog into Shopify (products, variants, inventory, images, and collections).
                      </Text>
                      <Box paddingBlockStart="200">
                        <Text as="p" tone="subdued">
                          Before you continue, we recommend:
                        </Text>
                        <Box paddingBlockStart="100">
                          <List type="bullet">
                            <List.Item>Run <Text as="span" fontWeight="semibold">Preview (dry run)</Text> to review counts and key fields.</List.Item>
                            <List.Item>Export your current Shopify products CSV (Products → Export) as a baseline.</List.Item>
                          </List>
                        </Box>
                        <Box paddingBlockStart="200">
                          <Text as="p" tone="subdued">
                            You can safely cancel the migration while it’s running from this screen.
                          </Text>
                        </Box>
                      </Box>
                    </Modal.Section>
                  </Modal>

                  <Card background="bg-surface-secondary">
                    <BlockStack gap="200">
                      <Text as="h3" variant="headingSm">
                        Status
                      </Text>
                      <Text as="p">Run: {run ? `#${run.id}` : '-'}</Text>
                      <Text as="p">State: {run ? run.status : '-'}</Text>
                      {durationLabel ? (
                        <Text as="p">
                          {isRunning ? 'Elapsed: ' : 'Total time: '}
                          {durationLabel}
                        </Text>
                      ) : null}
                      <Text as="p">Processed: {run ? run.processed : 0}</Text>
                      <Text as="p">Succeeded: {run ? run.succeeded : 0}</Text>
                      <Text as="p">Failed: {run ? run.failed : 0}</Text>
                      {run ? <Text as="p">Skipped: {skippedCount}</Text> : null}

                      {recentFailedItems.length > 0 ? (
                        <Box paddingBlockStart="200">
                          <Text as="h4" variant="headingSm">
                            Recent failures
                          </Text>
                          <List type="bullet">
                            {recentFailedItems.map((it) => (
                              <List.Item key={it.id}>
                                <Text as="span" fontWeight="semibold">
                                  {it.source_id}
                                </Text>
                                <Text as="span">
                                  {' '}
                                  —
                                  {(() => {
                                    const ctx = it?.error_context && typeof it.error_context === 'object' ? it.error_context : null
                                    const shopifyErrMsg = ctx?.errors?.[0]?.message || ctx?.errors?.[0]?.error || null
                                    const userErrMsg = ctx?.userErrors?.[0]?.message || null
                                    return shopifyErrMsg || userErrMsg || it.error_message || 'Failed'
                                  })()}
                                </Text>
                              </List.Item>
                            ))}
                          </List>
                        </Box>
                      ) : null}
                    </BlockStack>
                  </Card>
                </BlockStack>
              </Card>
            </Layout.Section>

            <Layout.Section>
              <Card>
                <BlockStack gap="400">
                  <InlineStack align="space-between" blockAlign="center">
                    <Text as="h2" variant="headingMd">
                      7) Migrate customers
                    </Text>
                    <InlineStack gap="200">
                      {customerRun?.report_available ? (
                        <Button icon={ImportIcon} variant="tertiary" onClick={() => handleDownloadReport(customerRun)}>
                          Download CSV
                        </Button>
                      ) : null}
                    </InlineStack>
                  </InlineStack>
                  <Text as="p" tone="subdued">
                    Migrate Magento customers and their address book into Shopify.
                  </Text>

                  {/* What gets migrated — collapsible */}
                  <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#f0f7ff' }}>
                    <button
                      type="button"
                      onClick={() => setCustomerMappingInfoOpen((v) => !v)}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        padding: '12px 16px',
                        textAlign: 'left',
                      }}
                    >
                      <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#0070c0' }}>
                        <span>ℹ</span> What gets migrated
                      </span>
                      <span style={{ fontSize: '12px', color: '#6d7175', transform: customerMappingInfoOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
                    </button>
                    {customerMappingInfoOpen && (
                      <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
                        <List type="bullet">
                          <List.Item><strong>Customer Profiles</strong> — Migrates first name, last name, email, phone number, and account state.</List.Item>
                          <List.Item><strong>Addresses</strong> — Default billing and default shipping addresses are migrated and assigned to the customer profile.</List.Item>
                          <List.Item><strong>Metadata</strong> — Companies and VAT registration IDs (where applicable) are mapped to customer details.</List.Item>
                        </List>
                      </div>
                    )}
                  </div>

                  {/* Known limitations — collapsible */}
                  <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#fff8f0' }}>
                    <button
                      type="button"
                      onClick={() => setCustomerLimitationsOpen((v) => !v)}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        padding: '12px 16px',
                        textAlign: 'left',
                      }}
                    >
                      <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#b98900' }}>
                        <span>⚠</span> Known limitations
                      </span>
                      <span style={{ fontSize: '12px', color: '#6d7175', transform: customerLimitationsOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
                    </button>
                    {customerLimitationsOpen && (
                      <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
                        <List type="bullet">
                          <List.Item><strong>Passwords cannot be migrated</strong> — due to secure hashing, passwords cannot be transferred. Customers will need to set a new password or activate their accounts upon first login.</List.Item>
                          <List.Item><strong>Unique Email Constraint</strong> — Shopify requires unique email addresses. Duplicate emails in Magento will be skipped or merged.</List.Item>
                        </List>
                      </div>
                    )}
                  </div>

                  <InlineStack gap="300" align="start" blockAlign="center">
                    <Button
                      loading={previewCustomerMigration.isPending}
                      disabled={!connected || !workerOnline || isCustomerRunning}
                      onClick={() => {
                        setCustomerPreview(null)
                        setExpandedCustomerPreviewId(null)
                        previewCustomerMigration.mutate({ limit: 10, page: 1, include_payload: true })
                      }}
                    >
                      Preview (dry run)
                    </Button>
                    {!workerOnline ? <Text as="span" tone="subdued">Queue worker offline</Text> : null}
                    <Tooltip content="Migrates all customers from Magento (no date filter).">
                      <Button
                        variant="primary"
                        disabled={!canStartCustomers}
                        loading={startCustomerMigration.isPending || isCustomerRunning}
                        onClick={() => setConfirmCustomerStartOpen(true)}
                      >
                        Start customer migration
                      </Button>
                    </Tooltip>

                    <Tooltip content="Migrate customers created within a selected date filter (after, before, or between dates).">
                      <Button
                        variant="secondary"
                        disabled={!canStartCustomerFiltered}
                        loading={startFilteredCustomerMigration.isPending || isCustomerRunning}
                        onClick={() => {
                          setCustomerFilteredPreview(null)
                          setCustomerFilteredModalOpen(true)
                        }}
                        style={{
                          background: 'var(--p-color-bg-surface-info)',
                          borderColor: 'var(--p-color-border-info)',
                          color: 'var(--p-color-text)',
                        }}
                      >
                        Migrate By Date
                      </Button>
                    </Tooltip>
                    <Button
                      variant="secondary"
                      disabled={!isCustomerRunning}
                      loading={cancelCustomerMigration.isPending}
                      onClick={() => cancelCustomerMigration.mutate()}
                    >
                      Cancel
                    </Button>
                  </InlineStack>

                  <Modal
                    open={customerFilteredModalOpen}
                    onClose={() => setCustomerFilteredModalOpen(false)}
                    title="Migrate customers by created date"
                    primaryAction={{
                      content: 'Preview filtered customers',
                      onAction: () => {
                        setCustomerFilteredPreview(null)
                        previewFilteredCustomerMigration.mutate({
                          mode: customerDateMode,
                          after: customerAfterDate || null,
                          before: customerBeforeDate || null,
                          limit: 10,
                          page: 1,
                          include_payload: false,
                        })
                      },
                    }}
                    secondaryActions={[
                      {
                        content: 'Close',
                        onAction: () => setCustomerFilteredModalOpen(false),
                      },
                    ]}
                  >
                    <Modal.Section>
                      <BlockStack gap="300">
                        <Select
                          label="Filter mode"
                          options={[
                            { label: 'After a date', value: 'after' },
                            { label: 'Between two dates', value: 'between' },
                          ]}
                          value={customerDateMode}
                          onChange={(v) => setCustomerDateMode(v)}
                        />

                        {(customerDateMode === 'after' || customerDateMode === 'between') ? (
                          <Box
                            onClick={() => customerAfterInputRef.current?.showPicker?.()}
                            onFocusCapture={() => customerAfterInputRef.current?.showPicker?.()}
                          >
                            <TextField
                              label="After (YYYY-MM-DD)"
                              type="date"
                              value={customerAfterDate}
                              onChange={(v) => setCustomerAfterDate(v)}
                              inputRef={customerAfterInputRef}
                              autoComplete="off"
                            />
                          </Box>
                        ) : null}

                        {customerDateMode === 'between' ? (
                          <Box
                            onClick={() => customerBeforeInputRef.current?.showPicker?.()}
                            onFocusCapture={() => customerBeforeInputRef.current?.showPicker?.()}
                          >
                            <TextField
                              label="Before (YYYY-MM-DD)"
                              type="date"
                              value={customerBeforeDate}
                              onChange={(v) => setCustomerBeforeDate(v)}
                              inputRef={customerBeforeInputRef}
                              autoComplete="off"
                            />
                          </Box>
                        ) : null}

                        {previewFilteredCustomerMigration.isPending ? (
                          <InlineStack gap="200" align="start" blockAlign="center">
                            <Spinner size="small" />
                            <Text as="span">Fetching total…</Text>
                          </InlineStack>
                        ) : null}

                        {customerFilteredPreviewTotal != null ? (
                          <Banner status="info" title="Filtered total">
                            <p>Total customers matching filter (Magento): {customerFilteredPreviewTotal}</p>
                          </Banner>
                        ) : null}

                        <Button
                          variant="primary"
                          disabled={customerFilteredPreviewTotal == null || customerFilteredPreviewTotal <= 0}
                          onClick={() => setConfirmCustomerFilteredStartOpen(true)}
                        >
                          Continue to start filtered migration
                        </Button>
                      </BlockStack>
                    </Modal.Section>
                  </Modal>

                  <Modal
                    open={confirmCustomerFilteredStartOpen}
                    onClose={() => setConfirmCustomerFilteredStartOpen(false)}
                    title="Start filtered customer migration?"
                    primaryAction={{
                      content: 'Start filtered migration',
                      destructive: true,
                      onAction: () => {
                        setConfirmCustomerFilteredStartOpen(false)
                        setCustomerFilteredModalOpen(false)
                        startFilteredCustomerMigration.mutate({
                          mode: customerDateMode,
                          after: customerAfterDate || null,
                          before: customerBeforeDate || null,
                        })
                      },
                    }}
                    secondaryActions={[
                      {
                        content: 'Cancel',
                        onAction: () => setConfirmCustomerFilteredStartOpen(false),
                      },
                    ]}
                  >
                    <Modal.Section>
                      <Text as="p">
                        This will migrate customers that match your date filter.
                      </Text>
                      {customerFilteredPreviewTotal != null ? (
                        <Box paddingBlockStart="200">
                          <Text as="p" tone="subdued">
                            Matching customers (Magento): {customerFilteredPreviewTotal}
                          </Text>
                        </Box>
                      ) : null}
                    </Modal.Section>
                  </Modal>

                  {isCustomerFinishedSuccess ? (
                    <Banner status="success" title="Customer migration completed">
                      <p>
                        Migrated {customerRun.succeeded} customers successfully. You can now review them in Shopify Admin.
                      </p>
                    </Banner>
                  ) : null}

                  {isCustomerFinishedNoChanges ? (
                    <Banner status="info" title="No changes detected">
                      <p>
                        This run checked {customerSkippedCount} customers and skipped them because the mapped data is unchanged.
                      </p>
                    </Banner>
                  ) : null}

                  {customerPreview ? (
                    <Card background="bg-surface-secondary">
                      <BlockStack gap="200">
                        <Text as="h3" variant="headingSm">
                          Preview results
                        </Text>
                        <Text as="p">Page: {customerPreview.page ?? '-'}</Text>
                        <Text as="p">Total (Magento): {customerPreview.total ?? '-'}</Text>

                        {customerPreviewItems.length > 0 ? (
                          <List type="bullet">
                            {customerPreviewItems.map((it) => (
                              <List.Item key={it.source_id}>
                                <Text as="span" fontWeight="semibold">
                                  {it.email || it.source_id}
                                </Text>
                                <Text as="span">
                                  {' '}
                                  — {it.first_name || '-'} {it.last_name || '-'}; addresses: {it.addresses_count ?? 0}
                                </Text>

                                {it.payload || it.shopware_raw || it.shopware_metafields ? (
                                  <Box paddingBlockStart="150">
                                    <ButtonGroup>
                                      <Button
                                        size="micro"
                                        onClick={() =>
                                          setExpandedCustomerPreviewId((prev) => (prev === it.source_id ? null : it.source_id))
                                        }
                                      >
                                        {expandedCustomerPreviewId === it.source_id ? 'Hide details' : 'Show details'}
                                      </Button>
                                    </ButtonGroup>
                                    {expandedCustomerPreviewId === it.source_id ? (
                                      <Box paddingBlockStart="300">
                                        <Card background="bg-surface-secondary">
                                          <BlockStack gap="400">
                                            <BlockStack gap="200">
                                              <Text as="h4" variant="headingSm">Customer Profile Info</Text>
                                              <div style={{ display: 'grid', gridTemplateColumns: '150px 1fr', gap: '8px 16px', fontSize: '13px' }}>
                                                <Text as="span" tone="subdued">Magento Customer ID:</Text>
                                                <Text as="span" fontWeight="medium">{it.source_id}</Text>
                                                
                                                <Text as="span" tone="subdued">Full Name:</Text>
                                                <Text as="span">{it.first_name || '-'} {it.last_name || '-'}</Text>
                                                
                                                <Text as="span" tone="subdued">Email:</Text>
                                                <Text as="span">{it.email || '-'}</Text>
                                                
                                                <Text as="span" tone="subdued">Phone:</Text>
                                                <Text as="span">{it.payload?.phone || it.shopware_raw?.telephone || '-'}</Text>

                                                <Text as="span" tone="subdued">Magento Group ID:</Text>
                                                <Text as="span">{it.shopware_raw?.group_id || '-'}</Text>

                                                <Text as="span" tone="subdued">Newsletter Subscription:</Text>
                                                <span style={{ display: 'inline-flex' }}>
                                                  {it.shopware_raw?.extension_attributes?.is_subscribed ? (
                                                    <span style={{ backgroundColor: '#e2f9e4', color: '#1f6a27', padding: '2px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: 'bold' }}>
                                                      Subscribed (Magento)
                                                    </span>
                                                  ) : (
                                                    <span style={{ backgroundColor: '#f1f2f3', color: '#6d7175', padding: '2px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: 'bold' }}>
                                                      Not Subscribed
                                                    </span>
                                                  )}
                                                </span>

                                                <Text as="span" tone="subdued">Shopify Marketing Consent:</Text>
                                                <span style={{ display: 'inline-flex' }}>
                                                  {it.payload?.emailMarketingConsent?.marketingState === 'SUBSCRIBED' ? (
                                                    <span style={{ backgroundColor: '#e2f9e4', color: '#1f6a27', padding: '2px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: 'bold' }}>
                                                      SUBSCRIBED (Shopify)
                                                    </span>
                                                  ) : (
                                                    <span style={{ backgroundColor: '#f1f2f3', color: '#6d7175', padding: '2px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: 'bold' }}>
                                                      NOT_SUBSCRIBED
                                                    </span>
                                                  )}
                                                </span>

                                                <Text as="span" tone="subdued">Shopify Tags:</Text>
                                                <Text as="span">{it.payload?.tags || '-'}</Text>
                                              </div>
                                            </BlockStack>

                                            <hr style={{ border: '0', borderTop: '1px solid var(--p-color-border-subdued)', margin: '0' }} />

                                            <BlockStack gap="200">
                                              <Text as="h4" variant="headingSm">Address Book ({it.payload?.addresses?.length || 0})</Text>
                                              {it.payload?.addresses && it.payload.addresses.length > 0 ? (
                                                <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                                                  {it.payload.addresses.map((addr, idx) => (
                                                    <div 
                                                      key={idx} 
                                                      style={{ 
                                                        padding: '10px', 
                                                        border: '1px solid var(--p-color-border-subdued)', 
                                                        borderRadius: '6px', 
                                                        background: 'var(--p-color-bg-surface)',
                                                        fontSize: '13px'
                                                      }}
                                                    >
                                                      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '6px' }}>
                                                        <Text as="span" fontWeight="bold">Address #{idx + 1}</Text>
                                                        <div style={{ display: 'flex', gap: '4px' }}>
                                                          {addr.default_shipping && <span style={{ backgroundColor: '#e0f2fe', color: '#0369a1', padding: '2px 6px', borderRadius: '4px', fontSize: '10px', fontWeight: 'bold' }}>Default Shipping</span>}
                                                          {addr.default_billing && <span style={{ backgroundColor: '#faf5ff', color: '#6b21a8', padding: '2px 6px', borderRadius: '4px', fontSize: '10px', fontWeight: 'bold' }}>Default Billing</span>}
                                                        </div>
                                                      </div>
                                                      <div style={{ display: 'grid', gridTemplateColumns: '100px 1fr', gap: '4px 8px' }}>
                                                        <span style={{ color: 'var(--p-color-text-subdued)' }}>Recipient:</span>
                                                        <span>{addr.firstName} {addr.lastName} {addr.company ? `(${addr.company})` : ''}</span>

                                                        <span style={{ color: 'var(--p-color-text-subdued)' }}>Street:</span>
                                                        <span>{addr.address1} {addr.address2 ? `, ${addr.address2}` : ''}</span>

                                                        <span style={{ color: 'var(--p-color-text-subdued)' }}>City/Region:</span>
                                                        <span>{addr.city}, {addr.province} {addr.zip}</span>

                                                        <span style={{ color: 'var(--p-color-text-subdued)' }}>Country:</span>
                                                        <span>{addr.countryCode}</span>

                                                        <span style={{ color: 'var(--p-color-text-subdued)' }}>Phone:</span>
                                                        <span>{addr.phone || '-'}</span>
                                                      </div>
                                                    </div>
                                                  ))}
                                                </div>
                                              ) : (
                                                <Text as="span" tone="subdued">No addresses mapped for this customer.</Text>
                                              )}
                                            </BlockStack>
                                          </BlockStack>
                                        </Card>
                                      </Box>
                                    ) : null}
                                  </Box>
                                ) : null}
                              </List.Item>
                            ))}
                          </List>
                        ) : (
                          <Text as="p">No items returned for this page.</Text>
                        )}
                      </BlockStack>
                    </Card>
                  ) : null}

                  <Modal
                    open={confirmCustomerStartOpen}
                    onClose={() => setConfirmCustomerStartOpen(false)}
                    title="Ready to start customer migration?"
                    primaryAction={{
                      content: 'Start customer migration',
                      destructive: true,
                      onAction: () => {
                        setConfirmCustomerStartOpen(false)
                        startCustomerMigration.mutate()
                      },
                    }}
                    secondaryActions={[
                      {
                        content: 'Cancel',
                        onAction: () => setConfirmCustomerStartOpen(false),
                      },
                    ]}
                  >
                    <Modal.Section>
                      <Text as="p">We’ll start syncing your Magento customers into Shopify.</Text>
                      <Box paddingBlockStart="200">
                        <Text as="p" tone="subdued">
                          Before you continue, we recommend running <Text as="span" fontWeight="semibold">Preview (dry run)</Text>.
                        </Text>
                      </Box>
                    </Modal.Section>
                  </Modal>

                  <Card background="bg-surface-secondary">
                    <BlockStack gap="200">
                      <Text as="h3" variant="headingSm">
                        Status
                      </Text>
                      <Text as="p">Run: {customerRun ? `#${customerRun.id}` : '-'}</Text>
                      <Text as="p">State: {customerRun ? customerRun.status : '-'}</Text>
                      {customerDurationLabel ? (
                        <Text as="p">
                          {isCustomerRunning ? 'Elapsed: ' : 'Total time: '}
                          {customerDurationLabel}
                        </Text>
                      ) : null}
                      <Text as="p">Processed: {customerRun ? customerRun.processed : 0}</Text>
                      <Text as="p">Succeeded: {customerRun ? customerRun.succeeded : 0}</Text>
                      <Text as="p">Failed: {customerRun ? customerRun.failed : 0}</Text>
                      {customerRun ? <Text as="p">Skipped: {customerSkippedCount}</Text> : null}

                      {recentCustomerFailedItems.length > 0 ? (
                        <Box paddingBlockStart="200">
                          <Text as="h4" variant="headingSm">
                            Recent failures
                          </Text>
                          <List type="bullet">
                            {recentCustomerFailedItems.map((it) => (
                              <List.Item key={it.id}>
                                <Text as="span" fontWeight="semibold">
                                  {it.source_id}
                                </Text>
                                <Text as="span">
                                  {' '}
                                  —
                                  {(() => {
                                    const ctx = it?.error_context && typeof it.error_context === 'object' ? it.error_context : null
                                    const shopifyErrMsg = ctx?.errors?.[0]?.message || ctx?.errors?.[0]?.error || null
                                    const userErrMsg = ctx?.userErrors?.[0]?.message || null
                                    return shopifyErrMsg || userErrMsg || it.error_message || 'Failed'
                                  })()}
                                </Text>
                              </List.Item>
                            ))}
                          </List>
                        </Box>
                      ) : null}
                    </BlockStack>
                  </Card>
                </BlockStack>
              </Card>
            </Layout.Section>

            <Layout.Section>
              <Card>
                <BlockStack gap="400">
                  <InlineStack align="space-between" blockAlign="center">
                    <Text as="h2" variant="headingMd">
                      8) Migrate orders
                    </Text>
                    <InlineStack gap="200">
                      {orderRun?.report_available ? (
                        <Button icon={ImportIcon} variant="tertiary" onClick={() => handleDownloadReport(orderRun)}>
                          Download CSV
                        </Button>
                      ) : null}
                    </InlineStack>
                  </InlineStack>
                  <Text as="p" tone="subdued">
                    Migrate historical Magento orders including line items, billing/shipping addresses, and transaction status.
                  </Text>

                  {/* What gets migrated — collapsible */}
                  <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#f0f7ff' }}>
                    <button
                      type="button"
                      onClick={() => setOrderMappingInfoOpen((v) => !v)}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        padding: '12px 16px',
                        textAlign: 'left',
                      }}
                    >
                      <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#0070c0' }}>
                        <span>ℹ</span> What gets migrated
                      </span>
                      <span style={{ fontSize: '12px', color: '#6d7175', transform: orderMappingInfoOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
                    </button>
                    {orderMappingInfoOpen && (
                      <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
                        <List type="bullet">
                          <List.Item><strong>Order Details</strong> — Order number, creation date, subtotal, total, and taxes.</List.Item>
                          <List.Item><strong>Line Items</strong> — Products, variants, quantities, unit prices, and applied taxes.</List.Item>
                          <List.Item><strong>Status Mappings</strong> — Financial (payment) status and fulfillment status are mapped based on your configuration.</List.Item>
                          <List.Item><strong>Shipping &amp; Payments</strong> — Shipping costs, methods, and payment gateway references.</List.Item>
                        </List>
                      </div>
                    )}
                  </div>

                  {/* Known limitations — collapsible */}
                  <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#fff8f0' }}>
                    <button
                      type="button"
                      onClick={() => setOrderLimitationsOpen((v) => !v)}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        padding: '12px 16px',
                        textAlign: 'left',
                      }}
                    >
                      <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#b98900' }}>
                        <span>⚠</span> Known limitations
                      </span>
                      <span style={{ fontSize: '12px', color: '#6d7175', transform: orderLimitationsOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
                    </button>
                    {orderLimitationsOpen && (
                      <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
                        <List type="bullet">
                          <List.Item><strong>Archived Orders</strong> — Historical orders are created in Shopify as archived and read-only.</List.Item>
                          <List.Item><strong>Inventory Adjustment</strong> — Migrating past orders does not automatically decrement current inventory levels.</List.Item>
                          <List.Item><strong>Payment Gateways</strong> — Raw transaction tokens or gateway actions cannot be recreated in Shopify; payment is marked as recorded.</List.Item>
                          <List.Item><strong>Catalog Dependencies</strong> — Orders containing products/variants not previously migrated will fail to import or be created with custom line items.</List.Item>
                        </List>
                      </div>
                    )}
                  </div>

                  <InlineStack gap="300" align="start" blockAlign="center">
                    <Button
                      loading={previewOrderMigration.isPending}
                      disabled={!connected || !workerOnline || isOrderRunning}
                      onClick={() => {
                        setOrderPreview(null)
                        setExpandedOrderPreviewId(null)
                        previewOrderMigration.mutate({ limit: 10, page: 1, include_payload: true })
                      }}
                    >
                      Preview (dry run)
                    </Button>
                    {!workerOnline ? <Text as="span" tone="subdued">Queue worker offline</Text> : null}
                    <Tooltip content="Start a full order migration (all orders).">
                      <Button
                        variant="primary"
                        disabled={!canStartOrders}
                        loading={startOrderMigration.isPending || isOrderRunning}
                        onClick={() => setConfirmOrderStartOpen(true)}
                      >
                        Start order migration
                      </Button>
                    </Tooltip>

                    <Tooltip content="Migrate orders after a date or within a date range.">
                      <Button
                        variant="secondary"
                        disabled={!canStartOrderFiltered}
                        loading={startFilteredOrderMigration.isPending || isOrderRunning}
                        onClick={() => {
                          setOrderFilteredPreview(null)
                          setOrderFilteredModalOpen(true)
                        }}
                        style={{
                          background: 'var(--p-color-bg-surface-info)',
                          borderColor: 'var(--p-color-border-info)',
                          color: 'var(--p-color-text)',
                        }}
                      >
                        Migrate By Date
                      </Button>
                    </Tooltip>
                    <Button
                      variant="secondary"
                      disabled={!isOrderRunning}
                      loading={cancelOrderMigration.isPending}
                      onClick={() => cancelOrderMigration.mutate()}
                    >
                      Cancel
                    </Button>
                  </InlineStack>

                  <Modal
                    open={orderFilteredModalOpen}
                    onClose={() => setOrderFilteredModalOpen(false)}
                    title="Migrate orders by order date"
                    primaryAction={{
                      content: 'Preview filtered orders',
                      onAction: () => {
                        setOrderFilteredPreview(null)
                        previewFilteredOrderMigration.mutate({
                          mode: orderDateMode,
                          after: orderAfterDate || null,
                          before: orderBeforeDate || null,
                          limit: 10,
                          page: 1,
                          include_payload: false,
                        })
                      },
                    }}
                    secondaryActions={[
                      {
                        content: 'Close',
                        onAction: () => setOrderFilteredModalOpen(false),
                      },
                    ]}
                  >
                    <Modal.Section>
                      <BlockStack gap="300">
                        <Select
                          label="Filter mode"
                          options={[
                            { label: 'After a date', value: 'after' },
                            { label: 'Between two dates', value: 'between' },
                          ]}
                          value={orderDateMode}
                          onChange={(v) => setOrderDateMode(v)}
                        />

                        {(orderDateMode === 'after' || orderDateMode === 'between') ? (
                          <Box onClick={() => orderAfterInputRef.current?.showPicker?.()} onFocusCapture={() => orderAfterInputRef.current?.showPicker?.()}>
                            <TextField
                              label="After (YYYY-MM-DD)"
                              type="date"
                              value={orderAfterDate}
                              onChange={(v) => setOrderAfterDate(v)}
                              inputRef={orderAfterInputRef}
                              autoComplete="off"
                            />
                          </Box>
                        ) : null}

                        {orderDateMode === 'between' ? (
                          <Box onClick={() => orderBeforeInputRef.current?.showPicker?.()} onFocusCapture={() => orderBeforeInputRef.current?.showPicker?.()}>
                            <TextField
                              label="Before (YYYY-MM-DD)"
                              type="date"
                              value={orderBeforeDate}
                              onChange={(v) => setOrderBeforeDate(v)}
                              inputRef={orderBeforeInputRef}
                              autoComplete="off"
                            />
                          </Box>
                        ) : null}

                        {previewFilteredOrderMigration.isPending ? (
                          <InlineStack gap="200" align="start" blockAlign="center">
                            <Spinner size="small" />
                            <Text as="span">Fetching total…</Text>
                          </InlineStack>
                        ) : null}

                        {orderFilteredPreviewTotal != null ? (
                          <Banner status="info" title="Filtered total">
                            <p>Total orders matching filter (Magento): {orderFilteredPreviewTotal}</p>
                          </Banner>
                        ) : null}

                        <Button
                          variant="primary"
                          disabled={orderFilteredPreviewTotal == null || orderFilteredPreviewTotal <= 0}
                          onClick={() => setConfirmOrderFilteredStartOpen(true)}
                        >
                          Continue to start filtered migration
                        </Button>
                      </BlockStack>
                    </Modal.Section>
                  </Modal>

                  <Modal
                    open={confirmOrderFilteredStartOpen}
                    onClose={() => setConfirmOrderFilteredStartOpen(false)}
                    title="Start filtered order migration?"
                    primaryAction={{
                      content: 'Start filtered migration',
                      destructive: true,
                      onAction: () => {
                        setConfirmOrderFilteredStartOpen(false)
                        setOrderFilteredModalOpen(false)
                        startFilteredOrderMigration.mutate({
                          location_gid: locationGid,
                          mode: orderDateMode,
                          after: orderAfterDate || null,
                          before: orderBeforeDate || null,
                        })
                      },
                    }}
                    secondaryActions={[
                      {
                        content: 'Cancel',
                        onAction: () => setConfirmOrderFilteredStartOpen(false),
                      },
                    ]}
                  >
                    <Modal.Section>
                      <Text as="p">This will migrate orders that match your date filter.</Text>
                      {orderFilteredPreviewTotal != null ? (
                        <Box paddingBlockStart="200">
                          <Text as="p" tone="subdued">Matching orders (Magento): {orderFilteredPreviewTotal}</Text>
                        </Box>
                      ) : null}
                    </Modal.Section>
                  </Modal>

                  {connected && workerOnline && !orderPrerequisitesReady && !isOrderRunning ? (
                    <Banner status="warning" title="Order migration prerequisites required">
                      {orderPrerequisiteMessages.length > 0 ? (
                        <List type="bullet">
                          {orderPrerequisiteMessages.map((message) => (
                            <List.Item key={message}>{message}</List.Item>
                          ))}
                        </List>
                      ) : (
                        <p>Complete product and customer migration before migrating orders.</p>
                      )}
                    </Banner>
                  ) : null}

                  {isOrderFinishedSuccess ? (
                    <Banner status="success" title="Order migration completed">
                      <p>
                        Migrated {orderRun.succeeded} orders successfully. You can now review them in Shopify Admin.
                      </p>
                    </Banner>
                  ) : null}

                  {isOrderFinishedNoChanges ? (
                    <Banner status="info" title="No changes detected">
                      <p>This run did not create new orders.</p>
                    </Banner>
                  ) : null}

                  {orderPreview ? (
                    <Card background="bg-surface-secondary">
                      <BlockStack gap="200">
                        <Text as="h3" variant="headingSm">
                          Preview results
                        </Text>
                        <Text as="p">Page: {orderPreview.page ?? '-'}</Text>
                        <Text as="p">Total (Magento): {orderPreview.total ?? '-'}</Text>

                        {orderPreviewItems.length > 0 ? (
                          <List type="bullet">
                            {orderPreviewItems.map((it) => (
                              <List.Item key={it.source_id}>
                                <Text as="span" fontWeight="semibold">
                                  {it.order_number || it.source_id}
                                </Text>
                                <Text as="span">
                                  {' '}
                                  — {it.email || '-'}; items: {it.line_items_count ?? 0}; {it.currency || '-'}
                                </Text>

                                {it.payload || it.shopware_raw || it.shopware_metafields ? (
                                  <Box paddingBlockStart="150">
                                    <ButtonGroup>
                                      <Button
                                        size="micro"
                                        onClick={() =>
                                          setExpandedOrderPreviewId((prev) => (prev === it.source_id ? null : it.source_id))
                                        }
                                      >
                                        {expandedOrderPreviewId === it.source_id ? 'Hide details' : 'Show details'}
                                      </Button>
                                    </ButtonGroup>
                                    {expandedOrderPreviewId === it.source_id ? (
                                      <Box paddingBlockStart="150">
                                        {it.payload ? (
                                          <Box paddingBlockStart="150">
                                            <Text as="p" tone="subdued">
                                              Shopify payload
                                            </Text>
                                            <Box
                                              padding="200"
                                              background="bg-surface"
                                              borderColor="border"
                                              borderWidth="025"
                                              borderRadius="200"
                                              overflowX="scroll"
                                            >
                                              <pre style={{ margin: 0, whiteSpace: 'pre-wrap' }}>
                                                {JSON.stringify(it.payload, null, 2)}
                                              </pre>
                                            </Box>
                                          </Box>
                                        ) : null}

                                        {it.shopware_metafields ? (
                                          <Box paddingBlockStart="150">
                                            <Text as="p" tone="subdued">
                                              Magento metafields
                                            </Text>
                                            <Box
                                              padding="200"
                                              background="bg-surface"
                                              borderColor="border"
                                              borderWidth="025"
                                              borderRadius="200"
                                              overflowX="scroll"
                                            >
                                              <pre style={{ margin: 0, whiteSpace: 'pre-wrap' }}>
                                                {JSON.stringify(it.shopware_metafields, null, 2)}
                                              </pre>
                                            </Box>
                                          </Box>
                                        ) : null}

                                        {it.shopware_raw ? (
                                          <Box paddingBlockStart="150">
                                            <Text as="p" tone="subdued">
                                              Magento raw
                                            </Text>
                                            <Box
                                              padding="200"
                                              background="bg-surface"
                                              borderColor="border"
                                              borderWidth="025"
                                              borderRadius="200"
                                              overflowX="scroll"
                                            >
                                              <pre style={{ margin: 0, whiteSpace: 'pre-wrap' }}>
                                                {JSON.stringify(it.shopware_raw, null, 2)}
                                              </pre>
                                            </Box>
                                          </Box>
                                        ) : null}
                                      </Box>
                                    ) : null}
                                  </Box>
                                ) : null}
                              </List.Item>
                            ))}
                          </List>
                        ) : (
                          <Text as="p">No items returned for this page.</Text>
                        )}
                      </BlockStack>
                    </Card>
                  ) : null}

                  <Modal
                    open={confirmOrderStartOpen}
                    onClose={() => setConfirmOrderStartOpen(false)}
                    title="Ready to start order migration?"
                    primaryAction={{
                      content: 'Start order migration',
                      destructive: true,
                      onAction: () => {
                        setConfirmOrderStartOpen(false)
                        startOrderMigration.mutate()
                      },
                    }}
                    secondaryActions={[
                      {
                        content: 'Cancel',
                        onAction: () => setConfirmOrderStartOpen(false),
                      },
                    ]}
                  >
                    <Modal.Section>
                      <Text as="p">We’ll start creating orders in Shopify based on your Magento order history.</Text>
                      <Box paddingBlockStart="200">
                        <Text as="p" tone="subdued">
                          Before you continue, we recommend running <Text as="span" fontWeight="semibold">Preview (dry run)</Text> and validating the mapped
                          totals, addresses, and line items.
                        </Text>
                      </Box>
                    </Modal.Section>
                  </Modal>

                  <Card background="bg-surface-secondary">
                    <BlockStack gap="200">
                      <Text as="h3" variant="headingSm">
                        Status
                      </Text>
                      <Text as="p">Run: {orderRun ? `#${orderRun.id}` : '-'}</Text>
                      <Text as="p">State: {orderRun ? orderRun.status : '-'}</Text>
                      {orderDurationLabel ? (
                        <Text as="p">
                          {isOrderRunning ? 'Elapsed: ' : 'Total time: '}
                          {orderDurationLabel}
                        </Text>
                      ) : null}
                      <Text as="p">Processed: {orderRun ? orderRun.processed : 0}</Text>
                      <Text as="p">Succeeded: {orderRun ? orderRun.succeeded : 0}</Text>
                      <Text as="p">Failed: {orderRun ? orderRun.failed : 0}</Text>
                      {orderRun ? <Text as="p">Skipped: {orderSkippedCount}</Text> : null}

                      {recentOrderFailedItems.length > 0 ? (
                        <Box paddingBlockStart="200">
                          <Text as="h4" variant="headingSm">
                            Recent failures
                          </Text>
                          <List type="bullet">
                            {recentOrderFailedItems.map((it) => (
                              <List.Item key={it.id}>
                                <Text as="span" fontWeight="semibold">
                                  {it.source_id}
                                </Text>
                                <Text as="span">
                                  {' '}
                                  —
                                  {(() => {
                                    const ctx = it?.error_context && typeof it.error_context === 'object' ? it.error_context : null
                                    const shopifyErrMsg = ctx?.errors?.[0]?.message || ctx?.errors?.[0]?.error || null
                                    const userErrMsg = ctx?.userErrors?.[0]?.message || null
                                    return shopifyErrMsg || userErrMsg || it.error_message || 'Failed'
                                  })()}
                                </Text>
                              </List.Item>
                            ))}
                          </List>
                        </Box>
                      ) : null}
                    </BlockStack>
                  </Card>
                </BlockStack>
              </Card>
            </Layout.Section>

            <Layout.Section>
              <Card>
                <BlockStack gap="400">
                  <InlineStack align="space-between" blockAlign="center">
                    <Text as="h2" variant="headingMd">
                      9) Migrate newsletter recipients
                    </Text>
                    <InlineStack gap="200">
                      {newsletterRun?.report_available ? (
                        <Button icon={ImportIcon} variant="tertiary" onClick={() => handleDownloadReport(newsletterRun)}>
                          Download CSV
                        </Button>
                      ) : null}
                    </InlineStack>
                  </InlineStack>
                  <Text as="p" tone="subdued">
                    Migrate newsletter subscribers and update their email marketing consent in Shopify.
                  </Text>

                  {/* What gets migrated — collapsible */}
                  <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#f0f7ff' }}>
                    <button
                      type="button"
                      onClick={() => setNewsletterMappingInfoOpen((v) => !v)}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        padding: '12px 16px',
                        textAlign: 'left',
                      }}
                    >
                      <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#0070c0' }}>
                        <span>ℹ</span> What gets migrated
                      </span>
                      <span style={{ fontSize: '12px', color: '#6d7175', transform: newsletterMappingInfoOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
                    </button>
                    {newsletterMappingInfoOpen && (
                      <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
                        <List type="bullet">
                          <List.Item><strong>Email Subscribers</strong> — Active and inactive newsletter subscribers are migrated.</List.Item>
                          <List.Item><strong>Marketing Consent</strong> — Subscriber opt-in status is mapped to Shopify customer email marketing consent.</List.Item>
                        </List>
                      </div>
                    )}
                  </div>

                  {/* Known limitations — collapsible */}
                  <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#fff8f0' }}>
                    <button
                      type="button"
                      onClick={() => setNewsletterLimitationsOpen((v) => !v)}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        padding: '12px 16px',
                        textAlign: 'left',
                      }}
                    >
                      <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#b98900' }}>
                        <span>⚠</span> Known limitations
                      </span>
                      <span style={{ fontSize: '12px', color: '#6d7175', transform: newsletterLimitationsOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
                    </button>
                    {newsletterLimitationsOpen && (
                      <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
                        <List type="bullet">
                          <List.Item><strong>Campaign History</strong> — Past email campaigns, logs, statistics, and click history are not migrated.</List.Item>
                          <List.Item><strong>Consent Double Opt-In</strong> — Any double opt-in settings and communications must be configured directly in Shopify Admin.</List.Item>
                        </List>
                      </div>
                    )}
                  </div>

                  {connected && workerOnline && !newsletterPrerequisitesReady && !isNewsletterRunning ? (
                    <Banner status="warning" title="Newsletter migration prerequisites required">
                      {newsletterPrerequisiteMessages.length > 0 ? (
                        <List type="bullet">
                          {newsletterPrerequisiteMessages.map((message) => (
                            <List.Item key={message}>{message}</List.Item>
                          ))}
                        </List>
                      ) : (
                        <p>Complete customer migration before migrating newsletter recipients.</p>
                      )}
                    </Banner>
                  ) : null}

                  <InlineStack gap="300" align="start" blockAlign="center">
                    <Button
                      loading={previewNewsletterMigration.isPending}
                      disabled={!connected || !workerOnline || isNewsletterRunning}
                      onClick={() => {
                        setNewsletterPreview(null)
                        setExpandedNewsletterPreviewId(null)
                        previewNewsletterMigration.mutate({ limit: 10, page: 1, include_payload: true })
                      }}
                    >
                      Preview (dry run)
                    </Button>
                    {!workerOnline ? <Text as="span" tone="subdued">Queue worker offline</Text> : null}
                    <Button
                      variant="primary"
                      disabled={!canStartNewsletter}
                      loading={startNewsletterMigration.isPending || isNewsletterRunning}
                      onClick={() => setConfirmNewsletterStartOpen(true)}
                    >
                      Start newsletter migration
                    </Button>
                    <Button
                      variant="secondary"
                      disabled={!isNewsletterRunning}
                      loading={cancelNewsletterMigration.isPending}
                      onClick={() => cancelNewsletterMigration.mutate()}
                    >
                      Cancel
                    </Button>
                  </InlineStack>

                  {newsletterPreview ? (
                    <Card background="bg-surface-secondary">
                      <BlockStack gap="200">
                        <Text as="h3" variant="headingSm">
                          Preview results
                        </Text>
                        <Text as="p">Page: {newsletterPreview.page ?? '-'}</Text>
                        <Text as="p">Total (Magento): {newsletterPreview.total ?? '-'}</Text>

                        {newsletterPreviewItems.length > 0 ? (
                          <List type="bullet">
                            {newsletterPreviewItems.map((it) => (
                              <List.Item key={it.source_id || it.email}>
                                <Text as="span" fontWeight="semibold">
                                  {it.email || it.source_id || '-'}
                                </Text>
                                <Text as="span">
                                  {' '}
                                  — {it.active ? 'Active' : 'Inactive'}
                                </Text>

                                {it.payload || it.shopware_raw ? (
                                  <Box paddingBlockStart="150">
                                    <ButtonGroup>
                                      <Button
                                        size="micro"
                                        onClick={() =>
                                          setExpandedNewsletterPreviewId((prev) => (prev === (it.source_id || it.email) ? null : it.source_id || it.email))
                                        }
                                      >
                                        {expandedNewsletterPreviewId === (it.source_id || it.email) ? 'Hide details' : 'Show details'}
                                      </Button>
                                    </ButtonGroup>
                                    {expandedNewsletterPreviewId === (it.source_id || it.email) ? (
                                      <Box paddingBlockStart="300">
                                        <Card background="bg-surface-secondary">
                                          <BlockStack gap="300">
                                            <BlockStack gap="200">
                                              <Text as="h4" variant="headingSm">Newsletter Subscriber Details</Text>
                                              <div style={{ display: 'grid', gridTemplateColumns: '150px 1fr', gap: '8px 16px', fontSize: '13px' }}>
                                                <Text as="span" tone="subdued">Subscriber ID:</Text>
                                                <Text as="span" fontWeight="medium">{it.source_id || it.shopware_raw?.subscriber_id || '-'}</Text>

                                                <Text as="span" tone="subdued">Customer ID:</Text>
                                                <Text as="span">{it.shopware_raw?.customer_id || '-'}</Text>

                                                <Text as="span" tone="subdued">Email:</Text>
                                                <Text as="span">{it.email || '-'}</Text>

                                                <Text as="span" tone="subdued">Subscriber Status:</Text>
                                                <span style={{ display: 'inline-flex' }}>
                                                  {it.shopware_raw?.subscriber_status === 1 || it.active ? (
                                                    <span style={{ backgroundColor: '#e2f9e4', color: '#1f6a27', padding: '2px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: 'bold' }}>
                                                      Subscribed
                                                    </span>
                                                  ) : (
                                                    <span style={{ backgroundColor: '#fff0f0', color: '#8c1d18', padding: '2px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: 'bold' }}>
                                                      Unsubscribed
                                                    </span>
                                                  )}
                                                </span>

                                                <Text as="span" tone="subdued">Shopify Marketing State:</Text>
                                                <span style={{ display: 'inline-flex' }}>
                                                  {it.payload?.emailMarketingConsent?.marketingState === 'SUBSCRIBED' ? (
                                                    <span style={{ backgroundColor: '#e2f9e4', color: '#1f6a27', padding: '2px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: 'bold' }}>
                                                      SUBSCRIBED
                                                    </span>
                                                  ) : (
                                                    <span style={{ backgroundColor: '#f1f2f3', color: '#6d7175', padding: '2px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: 'bold' }}>
                                                      NOT_SUBSCRIBED
                                                    </span>
                                                  )}
                                                </span>

                                                <Text as="span" tone="subdued">Store ID / View:</Text>
                                                <Text as="span">{it.shopware_raw?.store_id || '-'}</Text>
                                              </div>
                                            </BlockStack>
                                          </BlockStack>
                                        </Card>
                                      </Box>
                                    ) : null}
                                  </Box>
                                ) : null}
                              </List.Item>
                            ))}
                          </List>
                        ) : (
                          <Text as="p">No items returned for this page.</Text>
                        )}
                      </BlockStack>
                    </Card>
                  ) : null}

                  <Modal
                    open={confirmNewsletterStartOpen}
                    onClose={() => setConfirmNewsletterStartOpen(false)}
                    title="Ready to start newsletter migration?"
                    primaryAction={{
                      content: 'Start newsletter migration',
                      destructive: true,
                      onAction: () => {
                        setConfirmNewsletterStartOpen(false)
                        startNewsletterMigration.mutate()
                      },
                    }}
                    secondaryActions={[
                      {
                        content: 'Cancel',
                        onAction: () => setConfirmNewsletterStartOpen(false),
                      },
                    ]}
                  >
                    <Modal.Section>
                      <Text as="p">
                        We’ll sync Magento newsletter recipients into Shopify Customers and set Email subscription based on their active status.
                      </Text>
                    </Modal.Section>
                  </Modal>

                  <Card background="bg-surface-secondary">
                    <BlockStack gap="200">
                      <Text as="h3" variant="headingSm">
                        Status
                      </Text>
                      <Text as="p">Run: {newsletterRun ? `#${newsletterRun.id}` : '-'}</Text>
                      <Text as="p">State: {newsletterRun ? newsletterRun.status : '-'}</Text>
                      {newsletterDurationLabel ? <Text as="p">Total time: {newsletterDurationLabel}</Text> : null}
                      <Text as="p">Processed: {newsletterRun ? newsletterRun.processed : 0}</Text>
                      <Text as="p">Succeeded: {newsletterRun ? newsletterRun.succeeded : 0}</Text>
                      <Text as="p">Failed: {newsletterRun ? newsletterRun.failed : 0}</Text>
                      {newsletterRun ? (
                        <Text as="p">
                          Skipped:{' '}
                          {Math.max(
                            0,
                            Number(newsletterRun.processed || 0) - Number(newsletterRun.succeeded || 0) - Number(newsletterRun.failed || 0)
                          )}
                        </Text>
                      ) : null}

                      {recentNewsletterFailedItems.length > 0 ? (
                        <Box paddingBlockStart="200">
                          <Text as="h4" variant="headingSm">
                            Recent failures
                          </Text>
                          <List type="bullet">
                            {recentNewsletterFailedItems.map((it) => (
                              <List.Item key={it.id}>
                                <Text as="span" fontWeight="semibold">
                                  {it.source_id}
                                </Text>
                                <Text as="span">
                                  {' '}
                                  —
                                  {(() => {
                                    const ctx = it?.error_context && typeof it.error_context === 'object' ? it.error_context : null
                                    const shopifyErrMsg = ctx?.errors?.[0]?.message || ctx?.errors?.[0]?.error || null
                                    const userErrMsg = ctx?.userErrors?.[0]?.message || null
                                    return shopifyErrMsg || userErrMsg || it.error_message || 'Failed'
                                  })()}
                                </Text>
                              </List.Item>
                            ))}
                          </List>
                        </Box>
                      ) : null}
                    </BlockStack>
                  </Card>
                </BlockStack>
              </Card>
            </Layout.Section>

            {/* ── Discounts Migration ── */}
            <Layout.Section>
              <DiscountsMigrationCard
                connected={connected}
                workerOnline={workerOnline}
                discountRun={discountRun}
                isDiscountRunning={isDiscountRunning}
                discountPreview={discountPreview}
                previewDiscountMigration={previewDiscountMigration}
                startDiscountMigration={startDiscountMigration}
                cancelDiscountMigration={cancelDiscountMigration}
                handleDownloadReport={handleDownloadReport}
                formatDurationSeconds={formatDurationSeconds}
                durationSecondsFromRun={durationSecondsFromRun}
                tick={tick}
                recentDiscountFailedItems={Array.isArray(discountStatusQuery.data?.recent_failed_items) ? discountStatusQuery.data.recent_failed_items : []}
                confirmDiscountStartOpen={confirmDiscountStartOpen}
                setConfirmDiscountStartOpen={setConfirmDiscountStartOpen}
              />
            </Layout.Section>



          </>
        )}
      </Layout>
    </Page>
  )

  const toastMarkup = toast ? <Toast content={toast.content} error={toast.error} onDismiss={() => setToast(null)} /> : null

  return (
    <Frame navigation={navigation}>
      {activePage === 'dashboard' ? dashboardContent : migrationContent}
      {toastMarkup}
    </Frame>
  )
}

function DiscountsMigrationCard({
  connected,
  workerOnline,
  discountRun,
  isDiscountRunning,
  discountPreview,
  previewDiscountMigration,
  startDiscountMigration,
  cancelDiscountMigration,
  handleDownloadReport,
  formatDurationSeconds,
  durationSecondsFromRun,
  tick,
  recentDiscountFailedItems,
  confirmDiscountStartOpen,
  setConfirmDiscountStartOpen,
}) {
  const [mappingInfoOpen, setMappingInfoOpen] = useState(false)
  const [limitationsOpen, setLimitationsOpen] = useState(false)
  const discountPreviewItems = Array.isArray(discountPreview?.items) ? discountPreview.items : []
  const canStartDiscounts = connected && workerOnline && !isDiscountRunning
  const discountSkippedCount = discountRun
    ? Math.max(0, Number(discountRun.processed || 0) - Number(discountRun.succeeded || 0) - Number(discountRun.failed || 0))
    : 0

  const discountDurationLabel = (() => {
    const secs = durationSecondsFromRun(discountRun)
    return secs != null ? formatDurationSeconds(secs) : null
  })()

  return (
    <Card>
      <BlockStack gap="400">
        <InlineStack align="space-between" blockAlign="center">
          <Text as="h2" variant="headingMd">
            10) Migrate discounts
          </Text>
          <InlineStack gap="200">
            {discountRun?.report_available ? (
              <Button icon={ImportIcon} variant="tertiary" onClick={() => handleDownloadReport(discountRun)}>
                Download CSV
              </Button>
            ) : null}
          </InlineStack>
        </InlineStack>

        {/* What gets migrated — collapsible */}
        <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#f0f7ff' }}>
          <button
            type="button"
            onClick={() => setMappingInfoOpen((v) => !v)}
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              width: '100%',
              background: 'none',
              border: 'none',
              cursor: 'pointer',
              padding: '12px 16px',
              textAlign: 'left',
            }}
          >
            <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#0070c0' }}>
              <span>ℹ</span> What gets migrated
            </span>
            <span style={{ fontSize: '12px', color: '#6d7175', transform: mappingInfoOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
          </button>
          {mappingInfoOpen && (
            <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
              <List type="bullet">
                <List.Item><strong>Percentage &amp; fixed amount discounts</strong> — fully migrated to Shopify.</List.Item>
                <List.Item><strong>Free shipping discounts</strong> — fully migrated to Shopify.</List.Item>
                <List.Item><strong>Promotion codes</strong> — all codes migrated (up to 1,000 per promotion).</List.Item>
                <List.Item><strong>Validity dates, usage limits, once-per-customer</strong> — fully migrated.</List.Item>
                <List.Item><strong>Multiple discount rules per promotion</strong> — first mappable rule is migrated; others are noted in the report.</List.Item>
              </List>
            </div>
          )}
        </div>

        {/* Known limitations — collapsible */}
        <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#fff8f0' }}>
          <button
            type="button"
            onClick={() => setLimitationsOpen((v) => !v)}
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              width: '100%',
              background: 'none',
              border: 'none',
              cursor: 'pointer',
              padding: '12px 16px',
              textAlign: 'left',
            }}
          >
            <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#b98900' }}>
              <span>⚠</span> Known limitations
            </span>
            <span style={{ fontSize: '12px', color: '#6d7175', transform: limitationsOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
          </button>
          {limitationsOpen && (
            <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
              <List type="bullet">
                <List.Item><strong>fixed_unit_price</strong> and <strong>free_item</strong> discount types have no Shopify equivalent — these promotions are skipped.</List.Item>
                <List.Item>Sales channel scoping is not supported in Shopify — stored as a metafield for reference.</List.Item>
                <List.Item>Combination rules (prevent combination) have no Shopify equivalent — stored as a metafield.</List.Item>
                <List.Item>Per-customer limits greater than 1 are mapped as once-per-customer in Shopify.</List.Item>
                <List.Item>Product-scoped discounts apply to all products in Shopify — review after migration.</List.Item>
              </List>
            </div>
          )}
        </div>

        <InlineStack gap="300" align="start" blockAlign="center">
          <Button
            loading={previewDiscountMigration.isPending}
            disabled={!connected || !workerOnline || isDiscountRunning}
            onClick={() => {
              previewDiscountMigration.mutate({ limit: 10, page: 1 })
            }}
          >
            Preview (dry run)
          </Button>
          {!workerOnline ? <Text as="span" tone="subdued">Queue worker offline</Text> : null}
          <Button
            variant="primary"
            disabled={!canStartDiscounts}
            loading={startDiscountMigration.isPending || isDiscountRunning}
            onClick={() => setConfirmDiscountStartOpen(true)}
          >
            Start discount migration
          </Button>
          <Button
            variant="secondary"
            disabled={!isDiscountRunning}
            loading={cancelDiscountMigration.isPending}
            onClick={() => cancelDiscountMigration.mutate()}
          >
            Cancel
          </Button>
        </InlineStack>

        {discountPreview ? (
          <Card background="bg-surface-secondary">
            <BlockStack gap="200">
              <Text as="h3" variant="headingSm">
                Preview results
              </Text>
              <Text as="p">Page: {discountPreview.page ?? '-'}</Text>
              <Text as="p">Total (Magento): {discountPreview.total ?? '-'}</Text>

              {discountPreviewItems.length > 0 ? (
                <List type="bullet">
                  {discountPreviewItems.map((it) => (
                    <List.Item key={it.source_id}>
                      <Text as="span" fontWeight="semibold">
                        {it.name || it.source_id || '-'}
                      </Text>
                      <Text as="span">
                        {' '}— {it.shopify_discount_type ?? 'skipped'}{it.shopify_discount_type ? (it.is_automatic ? ' (automatic)' : ' (code-based)') : ''}, {it.value_type} {it.value}, codes: {it.code_count}
                      </Text>
                      {Array.isArray(it.issues) && it.issues.length > 0 ? (
                        <Box paddingBlockStart="100">
                          <Text as="p" tone="caution">
                            ⚠ {it.issues.join(' | ')}
                          </Text>
                        </Box>
                      ) : null}
                    </List.Item>
                  ))}
                </List>
              ) : (
                <Text as="p">No items returned for this page.</Text>
              )}
            </BlockStack>
          </Card>
        ) : null}

        <Modal
          open={confirmDiscountStartOpen}
          onClose={() => setConfirmDiscountStartOpen(false)}
          title="Ready to start discount migration?"
          primaryAction={{
            content: 'Start discount migration',
            destructive: true,
            onAction: () => {
              setConfirmDiscountStartOpen(false)
              startDiscountMigration.mutate()
            },
          }}
          secondaryActions={[
            {
              content: 'Cancel',
              onAction: () => setConfirmDiscountStartOpen(false),
            },
          ]}
        >
          <Modal.Section>
            <Text as="p">
              We'll migrate Magento promotions to Shopify discounts. Promotions without codes become automatic discounts; promotions with codes become code-based discounts. Unmappable types (fixed_unit_price, free_item) will be skipped.
            </Text>
          </Modal.Section>
        </Modal>

        <Card background="bg-surface-secondary">
          <BlockStack gap="200">
            <Text as="h3" variant="headingSm">
              Status
            </Text>
            <Text as="p">Run: {discountRun ? `#${discountRun.id}` : '-'}</Text>
            <Text as="p">State: {discountRun ? discountRun.status : '-'}</Text>
            {discountDurationLabel ? <Text as="p">Total time: {discountDurationLabel}</Text> : null}
            <Text as="p">Processed: {discountRun ? discountRun.processed : 0}</Text>
            <Text as="p">Succeeded: {discountRun ? discountRun.succeeded : 0}</Text>
            <Text as="p">Failed: {discountRun ? discountRun.failed : 0}</Text>
            {discountRun ? (
              <Text as="p">
                Skipped: {discountSkippedCount}
              </Text>
            ) : null}

            {recentDiscountFailedItems.length > 0 ? (
              <Box paddingBlockStart="200">
                <Text as="h4" variant="headingSm">
                  Recent failures
                </Text>
                <List type="bullet">
                  {recentDiscountFailedItems.map((it) => (
                    <List.Item key={it.id}>
                      <Text as="span" fontWeight="semibold">
                        {it.source_id}
                      </Text>
                      <Text as="span">
                        {' '}—{' '}
                        {(() => {
                          const ctx = it?.error_context && typeof it.error_context === 'object' ? it.error_context : null
                          const userErrMsg = ctx?.user_errors?.[0]?.message || null
                          return userErrMsg || it.error_message || 'Failed'
                        })()}
                      </Text>
                    </List.Item>
                  ))}
                </List>
              </Box>
            ) : null}
          </BlockStack>
        </Card>
      </BlockStack>
    </Card>
  )
}

function MarketsMigrationCard({
  connected,
  workerOnline,
  marketRun,
  isMarketRunning,
  marketPreview,
  previewMarketMigration,
  startMarketMigration,
  cancelMarketMigration,
  handleDownloadReport,
  recentMarketFailedItems,
  marketSkippedCount,
  marketDurationLabel,
  confirmMarketStartOpen,
  setConfirmMarketStartOpen,
}) {
  const [mappingInfoOpen, setMappingInfoOpen] = useState(false)
  const [limitationsOpen, setLimitationsOpen] = useState(false)
  // Track which channel IDs the user has selected (null = "all", array = specific subset)
  const [selectedChannelIds, setSelectedChannelIds] = useState(null)

  const marketPreviewItems = Array.isArray(marketPreview?.items) ? marketPreview.items : []
  const canStartMarkets = connected && workerOnline && !isMarketRunning

  // When preview loads, initialise all channels as selected
  const prevPreviewRef = useCallback((items) => {
    if (items && items.length > 0 && selectedChannelIds === null) {
      setSelectedChannelIds(items.map((it) => it.source_id))
    }
  }, [selectedChannelIds])

  // Sync selection state when preview first arrives
  useEffect(() => {
    if (marketPreviewItems.length > 0 && selectedChannelIds === null) {
      setSelectedChannelIds(marketPreviewItems.map((it) => it.source_id))
    }
  }, [marketPreviewItems.length]) // eslint-disable-line react-hooks/exhaustive-deps

  const toggleChannel = useCallback((id) => {
    setSelectedChannelIds((prev) => {
      const current = prev ?? marketPreviewItems.map((it) => it.source_id)
      if (current.includes(id)) {
        return current.filter((x) => x !== id)
      }
      return [...current, id]
    })
  }, [marketPreviewItems])

  const toggleAll = useCallback(() => {
    const allIds = marketPreviewItems.map((it) => it.source_id)
    setSelectedChannelIds((prev) => {
      const current = prev ?? allIds
      return current.length === allIds.length ? [] : allIds
    })
  }, [marketPreviewItems])

  const selectedIds = selectedChannelIds ?? marketPreviewItems.map((it) => it.source_id)
  const allSelected = marketPreviewItems.length > 0 && selectedIds.length === marketPreviewItems.length
  const noneSelected = selectedIds.length === 0

  // Build the payload for start — omit channel_ids if all are selected (migrate all)
  const buildStartPayload = useCallback(() => {
    const allIds = marketPreviewItems.map((it) => it.source_id)
    if (selectedIds.length === allIds.length) {
      return {}  // all selected → no filter needed
    }
    return { channel_ids: selectedIds }
  }, [selectedIds, marketPreviewItems])

  return (
    <Card>
      <BlockStack gap="400">
        <InlineStack align="space-between" blockAlign="center">
          <Text as="h2" variant="headingMd">
            4) Migrate sales channel
          </Text>
          <InlineStack gap="200">
            {marketRun?.report_available ? (
              <Button icon={ImportIcon} variant="tertiary" onClick={() => handleDownloadReport(marketRun)}>
                Download CSV
              </Button>
            ) : null}
          </InlineStack>
        </InlineStack>

        <Text as="p" tone="subdued">
          Replicate Magento storefront Store Views as Shopify Markets. For each storefront, this will create a Shopify Market (force-reassigning country collisions) and set up a Web Presence (subfolder suffix or custom domain).
        </Text>

        {/* What gets migrated — collapsible */}
        <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#f0f7ff' }}>
          <button
            type="button"
            onClick={() => setMappingInfoOpen((v) => !v)}
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              width: '100%',
              background: 'none',
              border: 'none',
              cursor: 'pointer',
              padding: '12px 16px',
              textAlign: 'left',
            }}
          >
            <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#0070c0' }}>
              <span>ℹ</span> What gets migrated
            </span>
            <span style={{ fontSize: '12px', color: '#6d7175', transform: mappingInfoOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
          </button>
          {mappingInfoOpen && (
            <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
              <List type="bullet">
                <List.Item><strong>Storefront Sales Channels</strong> — each channel is migrated as a distinct Shopify Market.</List.Item>
                <List.Item><strong>Primary Country</strong> — the channel's default country is assigned as the market region condition (pulling it from any other Shopify market if it's already assigned there).</List.Item>
                <List.Item><strong>Primary Locale</strong> — the channel's default language/locale is mapped to the market default locale.</List.Item>
                <List.Item><strong>Storefront URLs</strong> — automatically mapped to subfolders (e.g. <code>/demo</code>) on your primary domain, or mapped to custom domains if the domain is already added in Shopify settings.</List.Item>
              </List>
            </div>
          )}
        </div>

        {/* Known limitations — collapsible */}
        <div style={{ border: '1px solid #c9cccf', borderRadius: '8px', overflow: 'hidden', background: '#fff8f0' }}>
          <button
            type="button"
            onClick={() => setLimitationsOpen((v) => !v)}
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              width: '100%',
              background: 'none',
              border: 'none',
              cursor: 'pointer',
              padding: '12px 16px',
              textAlign: 'left',
            }}
          >
            <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: '#b98900' }}>
              <span>⚠</span> Known limitations
            </span>
            <span style={{ fontSize: '12px', color: '#6d7175', transform: limitationsOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s', display: 'inline-block' }}>▼</span>
          </button>
          {limitationsOpen && (
            <div style={{ padding: '0 16px 12px 16px', borderTop: '1px solid #c9cccf' }}>
              <List type="bullet">
                <List.Item><strong>Country Collisions</strong> — Shopify requires each country to be assigned to exactly one market. If a country is already active in another Shopify market, this migration will re-assign it to the new market.</List.Item>
                <List.Item><strong>Domains &amp; Web Presence</strong> — Custom storefront domains must be pre-registered in your Shopify settings first; otherwise, they will fall back to subfolders.</List.Item>
              </List>
            </div>
          )}
        </div>

        <InlineStack gap="300" align="start" blockAlign="center">
          <Button
            loading={previewMarketMigration.isPending}
            disabled={!connected || !workerOnline || isMarketRunning}
            onClick={() => {
              setSelectedChannelIds(null)
              previewMarketMigration.mutate({ limit: 100, page: 1 })
            }}
          >
            Preview (dry run)
          </Button>
          {!workerOnline ? <Text as="span" tone="subdued">Queue worker offline</Text> : null}
          <Button
            variant="primary"
            disabled={!canStartMarkets || noneSelected}
            loading={startMarketMigration.isPending || isMarketRunning}
            onClick={() => setConfirmMarketStartOpen(true)}
          >
            {marketPreviewItems.length > 0 && !allSelected
              ? `Start migration (${selectedIds.length} of ${marketPreviewItems.length})`
              : 'Start market migration'}
          </Button>
          <Button
            variant="secondary"
            disabled={!isMarketRunning}
            loading={cancelMarketMigration.isPending}
            onClick={() => cancelMarketMigration.mutate()}
          >
            Cancel
          </Button>
        </InlineStack>

        {/* Channel selector — shown after preview and only while not running */}
        {marketPreviewItems.length > 0 && !isMarketRunning && !startMarketMigration.isPending ? (
          <Box>
            <BlockStack gap="200">
              <InlineStack gap="300" align="space-between" blockAlign="center">
                <Text as="h3" variant="headingSm">
                  Select Sales Channels to migrate
                </Text>
                <Button size="slim" variant="plain" onClick={toggleAll}>
                  {allSelected ? 'Deselect all' : 'Select all'}
                </Button>
              </InlineStack>

              <div style={{
                border: '1px solid var(--p-color-border-subdued)',
                borderRadius: '8px',
                overflow: 'hidden',
              }}>
                {marketPreviewItems.map((it, idx) => {
                  const checked = selectedIds.includes(it.source_id)
                  return (
                    <label
                      key={it.source_id}
                      htmlFor={`ch-${it.source_id}`}
                      style={{
                        display: 'grid',
                        gridTemplateColumns: 'auto 1fr auto',
                        gap: '12px',
                        alignItems: 'center',
                        padding: '12px 16px',
                        cursor: 'pointer',
                        background: checked
                          ? 'rgba(26,127,90,0.06)'
                          : idx % 2 === 0 ? 'var(--p-color-bg-surface)' : 'var(--p-color-bg-surface-secondary)',
                        borderBottom: idx < marketPreviewItems.length - 1 ? '1px solid var(--p-color-border-subdued)' : 'none',
                        transition: 'background 0.15s',
                      }}
                    >
                      <input
                        id={`ch-${it.source_id}`}
                        type="checkbox"
                        checked={checked}
                        onChange={() => toggleChannel(it.source_id)}
                        style={{ accentColor: '#1a7f5a', width: '16px', height: '16px', cursor: 'pointer', flexShrink: 0 }}
                      />
                      <div>
                        <Text as="p" variant="bodyMd" fontWeight={checked ? 'semibold' : 'regular'}>
                          {it.name || it.source_id}
                        </Text>
                        <Text as="p" variant="bodySm" tone="subdued">
                          Country: {it.default_country} · Locale: {it.default_locale} · {it.domains} domain{it.domains !== 1 ? 's' : ''}
                        </Text>
                      </div>
                      <Text as="span" variant="bodySm" tone="subdued">
                        <code style={{ fontSize: '12px', background: 'var(--p-color-bg-surface-secondary)', padding: '2px 6px', borderRadius: '4px' }}>
                          {it.proposed_subfolder}
                        </code>
                      </Text>
                    </label>
                  )
                })}
              </div>

              {noneSelected && (
                <Banner tone="warning">
                  <p>No channels selected. Select at least one channel to start migration.</p>
                </Banner>
              )}
              {!allSelected && !noneSelected && (
                <Text as="p" tone="subdued" variant="bodySm">
                  {selectedIds.length} of {marketPreviewItems.length} channels selected for migration
                </Text>
              )}
            </BlockStack>
          </Box>
        ) : null}

        {marketRun ? (
          <Card background="bg-surface-secondary">
            <BlockStack gap="200">
              <Text as="h3" variant="headingSm">
                Status
              </Text>
              <Text as="p">Run: #{marketRun.id}</Text>
              <Text as="p">State: {marketRun.status}</Text>
              {marketDurationLabel ? (
                <Text as="p">
                  {isMarketRunning ? 'Elapsed: ' : 'Total time: '}
                  {marketDurationLabel}
                </Text>
              ) : null}
              <Text as="p">Processed: {marketRun.processed ?? 0}</Text>
              <Text as="p">Succeeded: {marketRun.succeeded ?? 0}</Text>
              <Text as="p">Failed: {marketRun.failed ?? 0}</Text>
              <Text as="p">Skipped: {marketSkippedCount}</Text>

              {recentMarketFailedItems.length > 0 ? (
                <Box paddingBlockStart="200">
                  <Text as="h4" variant="headingSm">
                    Recent failures
                  </Text>
                  <List type="bullet">
                    {recentMarketFailedItems.map((it) => (
                      <List.Item key={it.id}>
                        <Text as="span" fontWeight="semibold">
                          {it.source_id}
                        </Text>
                        <Text as="span">
                          {' '}—{' '}
                          {(() => {
                            const ctx = it?.error_context && typeof it.error_context === 'object' ? it.error_context : null
                            const userErrMsg = ctx?.userErrors?.[0]?.message || null
                            return userErrMsg || it.error_message || 'Failed'
                          })()}
                        </Text>
                      </List.Item>
                    ))}
                  </List>
                </Box>
              ) : null}
            </BlockStack>
          </Card>
        ) : null}
      </BlockStack>

      <Modal
        open={confirmMarketStartOpen}
        onClose={() => setConfirmMarketStartOpen(false)}
        title="Ready to start sales channel migration?"
        primaryAction={{
          content: 'Start market migration',
          destructive: true,
          onAction: () => {
            setConfirmMarketStartOpen(false)
            startMarketMigration.mutate(buildStartPayload())
          },
        }}
        secondaryActions={[
          {
            content: 'Cancel',
            onAction: () => setConfirmMarketStartOpen(false),
          },
        ]}
      >
        <Modal.Section>
          <Text as="p">
            {allSelected
              ? 'This will migrate all Magento storefront Store Views as Shopify Markets.'
              : `This will migrate ${selectedIds.length} of ${marketPreviewItems.length} selected Sales Channel${selectedIds.length !== 1 ? 's' : ''} as Shopify Markets.`}
          </Text>
          {!allSelected && selectedIds.length > 0 ? (
            <Box paddingBlockStart="200">
              <Text as="p" variant="bodySm" tone="subdued">Selected: {
                marketPreviewItems
                  .filter((it) => selectedIds.includes(it.source_id))
                  .map((it) => it.name || it.source_id)
                  .join(', ')
              }</Text>
            </Box>
          ) : null}
          <Box paddingBlockStart="200">
            <Banner tone="info" title="Important considerations">
              <List>
                <List.Item>Country assignments will be forced (colliding assignments will be moved to the new markets).</List.Item>
                <List.Item>Web presence URLs will fall back to subfolders if domain is not pre-registered in Shopify.</List.Item>
              </List>
            </Banner>
          </Box>
        </Modal.Section>
      </Modal>
    </Card>
  )
}

export default App
