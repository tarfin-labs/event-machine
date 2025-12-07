import { defineConfig } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'

export default withMermaid(
  defineConfig({
    title: 'EventMachine',
    description: 'Event-driven state machine library for Laravel inspired by XState',

    head: [
      ['link', { rel: 'icon', type: 'image/svg+xml', href: '/logo-light.svg' }],
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
        { text: 'Guide', link: '/introduction/what-is-event-machine' },
        { text: 'API', link: '/api-reference/machine-definition' },
        { text: 'Examples', link: '/examples/traffic-lights' },
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
          text: 'Introduction',
          collapsed: false,
          items: [
            { text: 'What is EventMachine?', link: '/introduction/what-is-event-machine' },
            { text: 'Installation', link: '/introduction/installation' },
            { text: 'Quick Start', link: '/introduction/quick-start' },
            { text: 'XState Comparison', link: '/introduction/xstate-comparison' }
          ]
        },
        {
          text: 'Core Concepts',
          collapsed: false,
          items: [
            { text: 'Machine Definition', link: '/core-concepts/machine-definition' },
            { text: 'States', link: '/core-concepts/states' },
            { text: 'Transitions', link: '/core-concepts/transitions' },
            { text: 'Context', link: '/core-concepts/context' },
            { text: 'Events', link: '/core-concepts/events' },
            { text: 'Event Sourcing', link: '/core-concepts/event-sourcing' }
          ]
        },
        {
          text: 'Behaviors',
          collapsed: false,
          items: [
            { text: 'Overview', link: '/behaviors/overview' },
            { text: 'Actions', link: '/behaviors/actions' },
            { text: 'Guards', link: '/behaviors/guards' },
            { text: 'Validation Guards', link: '/behaviors/validation-guards' },
            { text: 'Calculators', link: '/behaviors/calculators' },
            { text: 'Event Behaviors', link: '/behaviors/events' },
            { text: 'Results', link: '/behaviors/results' }
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
          text: 'Testing',
          collapsed: true,
          items: [
            { text: 'Overview', link: '/testing/overview' },
            { text: 'Fakeable Behaviors', link: '/testing/fakeable-behaviors' },
            { text: 'State Assertions', link: '/testing/state-assertions' },
            { text: 'Persistence Testing', link: '/testing/persistence-testing' }
          ]
        },
        {
          text: 'Examples',
          collapsed: true,
          items: [
            { text: 'Traffic Lights', link: '/examples/traffic-lights' },
            { text: 'Calculator', link: '/examples/calculator' },
            { text: 'Elevator', link: '/examples/elevator' },
            { text: 'Order Processing', link: '/examples/order-processing' },
            { text: 'Guarded Transitions', link: '/examples/guarded-transitions' }
          ]
        },
        {
          text: 'API Reference',
          collapsed: true,
          items: [
            { text: 'MachineDefinition', link: '/api-reference/machine-definition' },
            { text: 'Machine', link: '/api-reference/machine' },
            { text: 'State', link: '/api-reference/state' },
            { text: 'StateDefinition', link: '/api-reference/state-definition' },
            { text: 'TransitionDefinition', link: '/api-reference/transition-definition' },
            { text: 'ContextManager', link: '/api-reference/context-manager' },
            { text: 'Behaviors', link: '/api-reference/behaviors' },
            { text: 'Exceptions', link: '/api-reference/exceptions' }
          ]
        },
        {
          text: 'Migration',
          collapsed: true,
          items: [
            { text: 'Upgrading', link: '/migration/upgrading' }
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
      lineNumbers: true,
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
