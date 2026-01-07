import { defineConfig } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'

export default withMermaid(
  defineConfig({
    title: 'EventMachine',
    description: 'Event-driven state machine library for Laravel inspired by XState',

    head: [
      // Font preconnect (non-blocking)
      ['link', { rel: 'preconnect', href: 'https://fonts.googleapis.com' }],
      ['link', { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' }],
      ['link', { rel: 'preconnect', href: 'https://cdn.jsdelivr.net' }],

      // Fonts (loaded async, non-blocking)
      ['link', { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap' }],
      ['link', { rel: 'stylesheet', href: 'https://cdn.jsdelivr.net/npm/@fontsource/iosevka@5/400.css' }],
      ['link', { rel: 'stylesheet', href: 'https://cdn.jsdelivr.net/npm/@fontsource/iosevka@5/500.css' }],

      // Favicon
      ['link', { rel: 'icon', type: 'image/svg+xml', href: '/logo-light.svg' }],

      // Meta
      ['meta', { name: 'theme-color', content: '#10b981' }],
      ['meta', { property: 'og:type', content: 'website' }],
      ['meta', { property: 'og:title', content: 'EventMachine' }],
      ['meta', { property: 'og:description', content: 'Event-driven state machine library for Laravel inspired by XState' }],
      ['meta', { property: 'og:url', content: 'https://tarfin-labs.github.io/event-machine/' }],
    ],

    themeConfig: {
      logo: {
        light: '/logo-light.svg',
        dark: '/logo-dark.svg'
      },

      nav: [
        { text: 'Guide', link: '/getting-started/what-is-event-machine' },
        { text: 'Patterns', link: '/patterns/introduction' },
        {
          text: 'v3.x',
          items: [
            { text: 'Changelog', link: 'https://github.com/tarfin-labs/event-machine/releases' },
            { text: 'Contributing', link: 'https://github.com/tarfin-labs/event-machine/blob/main/CONTRIBUTING.md' }
          ]
        }
      ],

      sidebar: [
        {
          text: 'Getting Started',
          collapsed: false,
          items: [
            { text: 'What is EventMachine?', link: '/getting-started/what-is-event-machine' },
            { text: 'Comparison', link: '/getting-started/comparison' },
            { text: 'Installation', link: '/getting-started/installation' },
            { text: 'Upgrading', link: '/getting-started/upgrading' },
            { text: 'Your First Machine', link: '/getting-started/your-first-machine' }
          ]
        },
        {
          text: 'Understanding',
          collapsed: false,
          items: [
            { text: 'States & Transitions', link: '/understanding/states-and-transitions' },
            { text: 'Events', link: '/understanding/events' },
            { text: 'Context', link: '/understanding/context' },
            { text: 'Machine Lifecycle', link: '/understanding/machine-lifecycle' }
          ]
        },
        {
          text: 'Building Machines',
          collapsed: false,
          items: [
            { text: 'Defining States', link: '/building/defining-states' },
            { text: 'Writing Transitions', link: '/building/writing-transitions' },
            { text: 'Working with Context', link: '/building/working-with-context' },
            { text: 'Handling Events', link: '/building/handling-events' },
            { text: 'Configuration', link: '/building/configuration' }
          ]
        },
        {
          text: 'Behaviors',
          collapsed: false,
          items: [
            { text: 'Introduction', link: '/behaviors/introduction' },
            { text: 'Actions', link: '/behaviors/actions' },
            { text: 'Guards', link: '/behaviors/guards' },
            { text: 'Validation Guards', link: '/behaviors/validation-guards' },
            { text: 'Calculators', link: '/behaviors/calculators' },
            { text: 'Events', link: '/behaviors/events' },
            { text: 'Results', link: '/behaviors/results' }
          ]
        },
        {
          text: 'Patterns',
          collapsed: true,
          items: [
            { text: 'Introduction', link: '/patterns/introduction' },
            { text: 'Traffic Light', link: '/patterns/traffic-light' },
            { text: 'Order Processing', link: '/patterns/order-processing' },
            { text: 'Calculator', link: '/patterns/calculator' },
            { text: 'Elevator', link: '/patterns/elevator' },
            { text: 'Guarded Transitions', link: '/patterns/guarded-transitions' }
          ]
        },
        {
          text: 'Laravel Integration',
          collapsed: true,
          items: [
            { text: 'Overview', link: '/laravel-integration/overview' },
            { text: 'Eloquent Integration', link: '/laravel-integration/eloquent-integration' },
            { text: 'Persistence', link: '/laravel-integration/persistence' },
            { text: 'Archival & Compression', link: '/laravel-integration/archival-compression' },
            { text: 'Artisan Commands', link: '/laravel-integration/artisan-commands' }
          ]
        },
        {
          text: 'Advanced Topics',
          collapsed: true,
          items: [
            { text: 'Hierarchical States', link: '/advanced/hierarchical-states' },
            { text: '@always Transitions', link: '/advanced/always-transitions' },
            { text: 'Raised Events', link: '/advanced/raised-events' },
            { text: 'Entry/Exit Actions', link: '/advanced/entry-exit-actions' },
            { text: 'Scenarios', link: '/advanced/scenarios' },
            { text: 'Custom Context Classes', link: '/advanced/custom-context' },
            { text: 'Dependency Injection', link: '/advanced/dependency-injection' }
          ]
        },
        {
          text: 'Testing',
          collapsed: true,
          items: [
            { text: 'Overview', link: '/testing/overview' },
            { text: 'Fakeable Behaviors', link: '/testing/fakeable-behaviors' },
            { text: 'State Assertions', link: '/testing/state-assertions' },
            { text: 'Persistence Testing', link: '/testing/persistence-testing' }
          ]
        }
      ],

      socialLinks: [
        { icon: 'github', link: 'https://github.com/tarfin-labs/event-machine' }
      ],

      footer: {
        message: 'Released under the MIT License.',
        copyright: 'Copyright © 2024 TarFin Labs'
      },

      search: {
        provider: 'local'
      },

      editLink: {
        pattern: 'https://github.com/tarfin-labs/event-machine/edit/main/docs/:path',
        text: 'Edit this page on GitHub'
      },

      outline: {
        level: [2, 3]
      }
    },

    markdown: {
      lineNumbers: false,
      theme: {
        light: 'github-light',
        dark: 'github-dark'
      }
    },

    mermaid: {
      // Mermaid configuration
    },

    mermaidPlugin: {
      class: 'mermaid'
    }
  })
)
