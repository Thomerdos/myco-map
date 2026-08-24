import { createThemePlugin } from '@vuetify/v0'
import type { App } from 'vue'

export function vuetify0(app: App) {
  app.use(
    createThemePlugin({
      default: 'light',
      foreground: true,
      themes: {
        light: {
          dark: false,
          colors: {
            primary: '#14342a',
            secondary: '#6a6153',
            background: '#1a2420',
            surface: '#f7f4ec',
            error: '#8a2c22',
            warning: '#c98a3a',
            success: '#1c6b3a',
          },
        },
      },
    }),
  )
}
