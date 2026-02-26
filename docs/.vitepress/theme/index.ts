import DefaultTheme from 'vitepress/theme'
import { setupHiddenLinesToggle } from 'shiki-hide-lines'
import type { Theme } from 'vitepress'
import HomeFeatures from './components/HomeFeatures.vue'
import 'shiki-hide-lines/style.css'
import './style.css'

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    app.component('HomeFeatures', HomeFeatures)
    if (typeof window !== 'undefined') {
      setupHiddenLinesToggle()
    }
  },
} satisfies Theme
