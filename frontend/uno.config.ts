import { defineConfig, presetWind4 } from 'unocss'

export default defineConfig({
  presets: [
    presetWind4({
      preflights: {
        reset: true,
      },
    }),
  ],
  theme: {
    colors: {
      primary: 'var(--v0-primary)',
      secondary: 'var(--v0-secondary)',
      background: 'var(--v0-background)',
      surface: 'var(--v0-surface)',
      error: 'var(--v0-error)',
      warning: 'var(--v0-warning)',
      success: 'var(--v0-success)',
      'on-primary': 'var(--v0-on-primary)',
      'on-surface': 'var(--v0-on-surface)',
      'on-error': 'var(--v0-on-error)',
    },
    font: {
      sans: '"Inter", system-ui, -apple-system, "Segoe UI", sans-serif',
    },
  },
})
