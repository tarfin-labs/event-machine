import DefaultTheme from 'vitepress/theme'
import HomeFeatures from './components/HomeFeatures.vue'
import './style.css'

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    app.component('HomeFeatures', HomeFeatures)
  }
}
