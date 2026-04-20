#!/usr/bin/env bash
# build-skill.sh — Materialize docs/ into the skill directory for release distribution.
#
# During development, skills/event-machine/docs is a symlink to ../../docs.
# This is convenient for live editing but unreliable when the skill is
# installed via a plugin marketplace (symlinks may not be resolved).
#
# This script:
#   1. Removes the symlink
#   2. Copies only markdown files (and essential VitePress assets) into place
#   3. Leaves the skill directory self-contained and distribution-ready
#
# To restore the dev symlink after building: bash scripts/restore-skill-symlink.sh
#
# Run from project root:   bash scripts/build-skill.sh

set -euo pipefail

SKILL_DIR="skills/event-machine"
TARGET="$SKILL_DIR/docs"
SOURCE="docs"

if [[ ! -d "$SOURCE" ]]; then
    echo "Error: $SOURCE/ not found. Run from project root." >&2
    exit 1
fi

if [[ ! -d "$SKILL_DIR" ]]; then
    echo "Error: $SKILL_DIR/ not found." >&2
    exit 1
fi

# Remove existing symlink or materialized dir
if [[ -L "$TARGET" ]]; then
    echo "→ Removing symlink $TARGET"
    rm "$TARGET"
elif [[ -d "$TARGET" ]]; then
    echo "→ Removing existing materialized dir $TARGET"
    rm -rf "$TARGET"
fi

# Copy only what agents need: markdown files.
# Skip node_modules, .vitepress/dist, package-lock.json, etc.
echo "→ Materializing markdown docs into $TARGET"
mkdir -p "$TARGET"

# rsync with include/exclude rules:
#   - include all directories (so traversal works)
#   - include all *.md files
#   - exclude everything else
rsync -a \
    --exclude='node_modules' \
    --exclude='.vitepress/cache' \
    --exclude='.vitepress/dist' \
    --include='*/' \
    --include='*.md' \
    --exclude='*' \
    --prune-empty-dirs \
    "$SOURCE/" "$TARGET/"

MD_COUNT=$(find "$TARGET" -name '*.md' | wc -l | tr -d ' ')
echo "✓ Materialized $MD_COUNT markdown files into $TARGET"
echo ""
echo "The skill is now self-contained and distribution-ready."
echo "Commit with:   git add $SKILL_DIR"
echo "Restore dev symlink with:   bash scripts/restore-skill-symlink.sh"
