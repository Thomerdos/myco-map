import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import UnoCSS from '@unocss/vite'

export default defineConfig({
  plugins: [UnoCSS(), vue()],
  cacheDir: 'var/vite-cache',
  server: {
    host: '0.0.0.0',
    port: 43123,
    proxy: {
      '/api': {
        target: process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:8765',
        changeOrigin: true,
      },
    },
  },
})
