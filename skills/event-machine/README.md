# EventMachine — Claude Code Skill

This directory is a [Claude Code Skill](https://code.claude.com/docs/en/skills) that teaches AI agents how to use the EventMachine package effectively.

## Loading behaviour (progressive disclosure)

| File | When loaded | Purpose |
|------|-------------|---------|
| `SKILL.md` | **Immediately** on skill trigger | Naming conventions, best practices, core concepts, quick-start, testing API, navigation — everything an agent needs for typical tasks without reading further |
| `references/*.md` | On-demand (agent reads when relevant) | Curated, synthesized cheat-sheets: testing, delegation, parallel, QA setup |
| `docs/**/*.md` | On-demand (agent reads when relevant) | Full VitePress documentation (87 pages) — authoritative reference |

`SKILL.md` is designed to be the single file that gives an agent enough context to write correct EventMachine code in most situations. The `docs/` and `references/` folders are for when the agent needs deeper detail on a specific topic.

## `docs/` — symlink or materialized?

By default, `docs/` is a **relative symlink** to `../../docs/` (the VitePress source at the repo root). This means:

- Dev experience: edits to the real docs are live here immediately
- Git commits: the symlink is committed (~10 bytes)
- Plugin install: `git clone` preserves symlinks on macOS/Linux, so Claude Code can read through them

For distributions that can't follow symlinks (tarballs, some marketplace packagers, Windows), run:

```bash
bash scripts/build-skill.sh           # from repo root
```

This replaces the symlink with a materialized copy of all `*.md` files. Restore the symlink afterwards with:

```bash
bash scripts/restore-skill-symlink.sh
```

## Installation

This skill is distributed as a Claude Code plugin. From the repo root, the `.claude-plugin/marketplace.json` manifest points Claude Code at this skill directory.

Users install via:

```
/plugin marketplace add tarfin-labs/event-machine
/plugin install event-machine@event-machine
```

Or add the repo to their `plugins:` in `~/.claude/settings.json`.

## Updating the skill

When EventMachine docs change:

1. The symlink means the skill automatically reflects the latest docs — no action needed.
2. If the docs add a major new area, update the **Documentation Navigation** table in `SKILL.md` (§8) with the new file(s) and a one-line purpose.
3. If core concepts change (execution model, lifecycle semantics), update §3 of `SKILL.md`.
4. If naming conventions change, update §1 of `SKILL.md` — this section is the skill's most load-bearing.
5. When curated `references/*.md` drift from source, regenerate them from the updated docs.

## Version

This skill targets EventMachine package version matching the containing repo. The plugin version in `.claude-plugin/plugin.json` is bumped on each release.
