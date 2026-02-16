#!/bin/sh
#
# Install Git hooks for Station development
#
# Usage: ./scripts/install-hooks.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
HOOKS_DIR="$SCRIPT_DIR/hooks"
GIT_HOOKS_DIR="$(git rev-parse --show-toplevel)/.git/hooks"

echo "📦 Installing Git hooks..."

# Install pre-commit hook
cp "$HOOKS_DIR/pre-commit" "$GIT_HOOKS_DIR/pre-commit"
chmod +x "$GIT_HOOKS_DIR/pre-commit"
echo "✅ Installed pre-commit hook"

# Install pre-push hook
cp "$HOOKS_DIR/pre-push" "$GIT_HOOKS_DIR/pre-push"
chmod +x "$GIT_HOOKS_DIR/pre-push"
echo "✅ Installed pre-push hook"

echo ""
echo "✅ Git hooks installed successfully!"
echo ""
echo "Hooks will run automatically:"
echo "  • pre-commit: PHPStan + code style checks"
echo "  • pre-push: Security audit + PHPUnit tests + browser tests"
