# AI Agent Skill

EventMachine ships with an official **Agent Skill** — a package that teaches AI coding agents how to write correct, idiomatic EventMachine code without relying on stale training data.

Once installed, the skill activates whenever you edit a file that uses EventMachine, or whenever you ask your agent to "build a state machine", "write a TestMachine test", "add a parallel state", or similar.

## Install

```bash
npx skills add tarfin-labs/event-machine#plugin-dist
```

That's it. [`npx skills`](https://github.com/vercel-labs/skills) is the open agent skills package manager from Vercel Labs. It clones the `plugin-dist` branch of this repository and installs the skill into your agents (per-project by default, or globally with `--global`).

## Supported agents

The skill conforms to the [Agent Skills specification](https://agentskills.io) and works with **45+ agents**, including:

- **Claude Code**
- **Cursor**
- **GitHub Copilot**
- **Cline**
- **Codex** (OpenAI)
- **OpenCode**
- **Gemini CLI** (Google)
- **Warp** (terminal)
- **Amp** (Sourcegraph)
- **Antigravity**
- **Kimi Code CLI**
- **Deep Agents**
- **Firebender**

…and 30+ more. The `npx skills` CLI automatically detects your installed agents and wires the skill into each one's expected location. Browse the full agent list at [skills.sh](https://skills.sh).

## What the skill provides

The skill is organized around **progressive disclosure** — the most important information loads immediately when the skill is triggered, while deeper reference material is read on-demand. This keeps your agent's context window lean.

### Loaded immediately

When your agent detects EventMachine usage, it loads `SKILL.md` into context. This single file includes:

- The complete **naming conventions** table — events, states, classes, context keys, config keys
- **13 best-practice principles** distilled into one-liners, with five critical expansions (guard purity, action idempotency, event tense, region separation, state explosion)
- **Core concepts** — vocabulary, machine lifecycle, event bubbling, behavior pipeline
- **Quick-start snippets** for machine definition, class-based behaviors, and test assertions
- A **testing API cheat-sheet** covering `Machine::test()`, `startingAt()`, `runWithState()`, and the 10 most-used assertions
- A **Laravel integration** page covering `HasMachines`, `MachineCast`, HTTP endpoints, and Artisan commands
- A **delegation and parallel** section with sync/async decision tree and the 10 gotchas agents most often hit
- A **documentation navigation table** pointing to every one of the 87 VitePress pages

### Loaded on-demand

Curated cheat-sheets (synthesized from the full docs for agent consumption):

- `references/INDEX.md` — task-based navigation ("how do I test delegation?" → docs path)
- `references/testing.md` — complete TestMachine assertions, fakes, four-layer strategy
- `references/delegation.md` — sync/async decision tree, `@done` / `@fail` / `@timeout` recipes, critical gotchas
- `references/parallel.md` — region design checklist, dispatch mode, 8 top gotchas
- `references/qa-setup.md` — LocalQA setup distilled into 10 rules and common troubleshooting

And the complete `docs/` tree — all 87 pages are available to the agent, but only loaded when relevant.

## Why this matters

LLMs trained before your current EventMachine version will:

- Invent method names that don't exist (`Machine::run()` instead of `send()`)
- Use wrong naming conventions (imperative state verbs, command-style event names)
- Miss subtle constraints (guards must be pure, regions need disjoint context keys)
- Hallucinate assertion methods on `TestMachine`
- Miss release-specific changes (typed contracts, scenario system, archival)

The skill gives the agent ground-truth, version-accurate reference material. Every release of EventMachine publishes a fresh `plugin-dist` branch so installed skills stay in sync with the library you're using.

## Updating

```bash
npx skills update
```

Re-fetches the latest `plugin-dist` branch. Run this after upgrading the EventMachine composer package to keep the skill aligned.

## Removing

```bash
npx skills remove event-machine
```

## How releases work

The `plugin-dist` branch is an automated artifact, not a source branch — **do not commit to it directly**. A GitHub workflow runs on every semantic-version tag push (`9.7.3`, `10.0.0`, etc.), materializes the VitePress docs into the skill directory, and force-pushes the result to `plugin-dist`. The main branch uses a symlink for developer convenience; the dist branch uses real files so installation works on every platform and every agent's installer, regardless of symlink handling.

## Repository layout

For contributors curious about the skill structure:

```
skills/event-machine/
├── SKILL.md             # immediate-load content
├── README.md            # maintainer notes
├── references/          # curated cheat-sheets (on-demand)
│   ├── INDEX.md
│   ├── testing.md
│   ├── delegation.md
│   ├── parallel.md
│   └── qa-setup.md
└── docs/                # symlink to ../../docs on main; materialized on plugin-dist
```

The skill lives alongside the library because the docs, library, and skill must all move together on every release.

## Learn more

- Install tool: [vercel-labs/skills](https://github.com/vercel-labs/skills) · [skills.sh](https://skills.sh)
- Agent Skills specification: [agentskills.io](https://agentskills.io)
- Skill source in this repo: [`skills/event-machine/`](https://github.com/tarfin-labs/event-machine/tree/main/skills/event-machine)
