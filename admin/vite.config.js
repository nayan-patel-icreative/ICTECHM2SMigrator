import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  base: '/admin/',
  build: {
    outDir: '../backend/public/admin',
    emptyOutDir: true,
    manifest: true,
  },
  server: {
    host: '0.0.0.0',
    port: 5174,
    strictPort: true,
    origin: process.env.VITE_DEV_ORIGIN,
  },
})
