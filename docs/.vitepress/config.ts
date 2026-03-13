import { defineConfig } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'
import { transformerHideLines } from 'shiki-hide-lines'
import { execSync } from 'node:child_process'

const gitTag = (() => {
  // Try local git tags first (works in full clones)
  try {
    return execSync('git describe --tags --abbrev=0', { encoding: 'utf-8' }).trim()
  } catch { /* shallow clone - no tags available */ }

  // Fallback: fetch latest release from GitHub API (works in Cloudflare Pages)
  try {
    const json = execSync(
      'curl -sf https://api.github.com/repos/tarfin-labs/event-machine/releases/latest',
      { encoding: 'utf-8', timeout: 5000 }
    )
    return JSON.parse(json).tag_name
  } catch { /* offline or API error */ }

  return 'dev'
})()

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
      ['meta', { property: 'og:url', content: 'https://eventmachine.dev' }],
    ],

    themeConfig: {
      logo: {
        light: '/logo-light.svg',
        dark: '/logo-dark.svg'
      },

      nav: [
        { text: 'Guide', link: '/getting-started/what-is-event-machine' },
        { text: 'Examples', link: '/examples/quick-start' },
        {
          text: `v${gitTag}`,
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
            { text: 'Configuration', link: '/building/configuration' },
            { text: 'Naming Conventions', link: '/building/conventions' }
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
          text: 'State Features',
          collapsed: true,
          items: [
            { text: 'Hierarchical States', link: '/advanced/hierarchical-states' },
            { text: 'Entry/Exit Actions', link: '/advanced/entry-exit-actions' },
            { text: '@always Transitions', link: '/advanced/always-transitions' },
            { text: 'Raised Events', link: '/advanced/raised-events' }
          ]
        },
        {
          text: 'Composition',
          collapsed: true,
          items: [
            {
              text: 'Parallel States',
              collapsed: true,
              items: [
                { text: 'Overview', link: '/advanced/parallel-states/' },
                { text: 'Event Handling', link: '/advanced/parallel-states/event-handling' },
                { text: 'Persistence', link: '/advanced/parallel-states/persistence' },
                { text: 'Parallel Dispatch', link: '/advanced/parallel-states/parallel-dispatch' }
              ]
            },
            {
              text: 'Machine Delegation',
              collapsed: true,
              items: [
                { text: 'Overview', link: '/advanced/machine-delegation' },
                { text: 'Sync vs Async', link: '/advanced/async-delegation' },
                { text: 'Data Flow', link: '/advanced/delegation-data-flow' },
                { text: 'Patterns', link: '/advanced/delegation-patterns' },
                { text: 'sendTo & Testing', link: '/advanced/sendto-and-testing' }
              ]
            }
          ]
        },
        {
          text: 'Customization',
          collapsed: true,
          items: [
            { text: 'Custom Context Classes', link: '/advanced/custom-context' },
            { text: 'Dependency Injection', link: '/advanced/dependency-injection' },
            { text: 'Scenarios', link: '/advanced/scenarios' }
          ]
        },
        {
          text: 'Laravel Integration',
          collapsed: true,
          items: [
            { text: 'Overview', link: '/laravel-integration/overview' },
            { text: 'Eloquent Integration', link: '/laravel-integration/eloquent-integration' },
            { text: 'Persistence', link: '/laravel-integration/persistence' },
            { text: 'Endpoints', link: '/laravel-integration/endpoints' },
            { text: 'Archival', link: '/laravel-integration/archival' },
            { text: 'Compression', link: '/laravel-integration/compression' },
            { text: 'Artisan Commands', link: '/laravel-integration/artisan-commands' }
          ]
        },
        {
          text: 'Testing',
          collapsed: true,
          items: [
            { text: 'Overview', link: '/testing/overview' },
            { text: 'Isolated Testing', link: '/testing/isolated-testing' },
            { text: 'Fakeable Behaviors', link: '/testing/fakeable-behaviors' },
            { text: 'Constructor DI', link: '/testing/constructor-di' },
            { text: 'Transitions & Paths', link: '/testing/transitions-and-paths' },
            { text: 'TestMachine', link: '/testing/test-machine' },
            { text: 'Parallel Testing', link: '/testing/parallel-testing' },
            { text: 'Persistence Testing', link: '/testing/persistence-testing' },
            { text: 'Recipes', link: '/testing/recipes' }
          ]
        },
        {
          text: 'Examples',
          collapsed: true,
          items: [
            { text: 'Quick Start', link: '/examples/quick-start' },
            { text: 'Real World', link: '/examples/real-world' }
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
      },
      codeTransformers: [
        transformerHideLines({
          reveal: true,
        })
      ]
    },

    mermaid: {
      // Mermaid configuration
    },

    mermaidPlugin: {
      class: 'mermaid'
    }
  })
)
