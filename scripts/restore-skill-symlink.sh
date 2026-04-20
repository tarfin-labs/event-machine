#!/usr/bin/env bash
# restore-skill-symlink.sh — Restore the dev symlink skills/event-machine/docs -> ../../docs
#
# After running scripts/build-skill.sh, the skill directory contains a materialized
# copy of docs/. Use this script to switch back to the symlinked dev setup.
#
# Run from project root:   bash scripts/restore-skill-symlink.sh

set -euo pipefail

SKILL_DIR="skills/event-machine"
TARGET="$SKILL_DIR/docs"

if [[ ! -d "$SKILL_DIR" ]]; then
    echo "Error: $SKILL_DIR/ not found. Run from project root." >&2
    exit 1
fi

if [[ -L "$TARGET" ]]; then
    echo "Symlink already exists at $TARGET → $(readlink "$TARGET")"
    exit 0
fi

if [[ -d "$TARGET" ]]; then
    echo "→ Removing materialized $TARGET"
    rm -rf "$TARGET"
fi

echo "→ Creating symlink $TARGET -> ../../docs"
cd "$SKILL_DIR"
ln -s ../../docs docs
cd - > /dev/null

echo "✓ Dev symlink restored."
echo "Run   ls $TARGET/building/   to verify."
