import { defineConfig } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'
import { transformerHideLines } from 'shiki-hide-lines'
import { execSync } from 'node:child_process'
import llmstxt from 'vitepress-plugin-llms'

const gitTag = await (async () => {
  // Try GitHub API first — always returns the latest published release
  try {
    const res = await fetch('https://api.github.com/repos/tarfin-labs/event-machine/releases/latest')
    if (res.ok) {
      const data = await res.json()
      if (data.tag_name) return data.tag_name
    }
  } catch { /* offline or API error */ }

  // Fallback: local git tags sorted by version (works in full clones)
  try {
    return execSync('git tag --sort=-v:refname', { encoding: 'utf-8' }).split('\n').filter(Boolean)[0]
  } catch { /* shallow clone - no tags available */ }

  // Last resort: git describe
  try {
    return execSync('git describe --tags --abbrev=0', { encoding: 'utf-8' }).trim()
  } catch { /* no tags at all */ }

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
        {
          text: gitTag,
          items: [
            { text: 'Changelog', link: 'https://github.com/tarfin-labs/event-machine/releases' }
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
            { text: 'Your First Machine', link: '/getting-started/your-first-machine' },
            { text: 'States & Transitions', link: '/understanding/states-and-transitions' },
            { text: 'Events', link: '/understanding/events' },
            { text: 'Context', link: '/understanding/context' },
            { text: 'Machine Lifecycle', link: '/understanding/machine-lifecycle' },
            { text: 'The Actor Model', link: '/getting-started/actor-model' },
            { text: 'Upgrading', link: '/getting-started/upgrading' }
          ]
        },
        {
          text: 'Building Machines',
          collapsed: true,
          items: [
            { text: 'Defining States', link: '/building/defining-states' },
            { text: 'Writing Transitions', link: '/building/writing-transitions' },
            { text: 'Working with Context', link: '/building/working-with-context' },
            { text: 'Handling Events', link: '/building/handling-events' },
            { text: 'Configuration', link: '/building/configuration' },
            { text: 'Custom Context Classes', link: '/advanced/custom-context' },
            { text: 'Scenarios', link: '/advanced/scenarios' }
          ]
        },
        {
          text: 'Behaviors',
          collapsed: true,
          items: [
            { text: 'Introduction', link: '/behaviors/introduction' },
            { text: 'Actions', link: '/behaviors/actions' },
            { text: 'Guards', link: '/behaviors/guards' },
            { text: 'Validation Guards', link: '/behaviors/validation-guards' },
            { text: 'Calculators', link: '/behaviors/calculators' },
            { text: 'Events', link: '/behaviors/events' },
            { text: 'Results', link: '/behaviors/results' },
            { text: 'Dependency Injection', link: '/advanced/dependency-injection' }
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
          text: 'Inter-Machine',
          collapsed: true,
          items: [
            { text: 'Overview', link: '/advanced/machine-delegation' },
            { text: 'Sync vs Async', link: '/advanced/async-delegation' },
            { text: 'Data Flow', link: '/advanced/delegation-data-flow' },
            { text: 'Patterns', link: '/advanced/delegation-patterns' },
            { text: 'Job Actors', link: '/advanced/job-actors' },
            { text: 'Time-Based Events', link: '/advanced/time-based-events' },
            { text: 'Scheduled Events', link: '/advanced/scheduled-events' },
            { text: 'Cross-Machine Messaging', link: '/advanced/sendto' }
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
            { text: 'Available Events', link: '/laravel-integration/available-events' },
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
            { text: 'Inter-Machine Testing', link: '/testing/delegation-testing' },
            { text: 'Time-Based Testing', link: '/testing/time-based-testing' },
            { text: 'Scheduled Testing', link: '/testing/scheduled-testing' },
            { text: 'Recipes', link: '/testing/recipes' }
          ]
        },
        {
          text: 'Best Practices',
          collapsed: true,
          items: [
            { text: 'Overview', link: '/best-practices/' },
            { text: 'Naming & Style', link: '/building/conventions' },
            { text: 'Event Bubbling', link: '/best-practices/event-bubbling' },
            { text: 'State Design', link: '/best-practices/state-design' },
            { text: 'Guard Design', link: '/best-practices/guard-design' },
            { text: 'Action Design', link: '/best-practices/action-design' },
            { text: 'Context Design', link: '/best-practices/context-design' },
            { text: 'Transition Design', link: '/best-practices/transition-design' },
            { text: 'Machine Decomposition', link: '/best-practices/machine-decomposition' },
            { text: 'Event Design', link: '/best-practices/event-design' },
            { text: 'Time-Based Patterns', link: '/best-practices/time-based-patterns' },
            { text: 'Parallel Patterns', link: '/best-practices/parallel-patterns' },
            { text: 'Testing Strategy', link: '/best-practices/testing-strategy' },
          ]
        },
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

    vite: {
      plugins: [llmstxt()],
    },

    mermaid: {
      // Mermaid configuration
    },

    mermaidPlugin: {
      class: 'mermaid'
    }
  })
)
