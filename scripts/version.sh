#!/bin/bash
# scripts/version.sh - Generate application version based on git status
#
# If APP_VERSION environment variable is already set, returns that value.
# Otherwise generates version from git:
#   - Tagged release: v1.2.3
#   - Tagged pre-release: pre-v1.3.0-beta
#   - Committed, no tag: PR-abc1234
#   - Uncommitted changes: dev-2026-01-14T13:45:00Z

get_version() {
    # If APP_VERSION is already set in environment, use it
    if [ -n "$APP_VERSION" ]; then
        echo "$APP_VERSION"
        return
    fi

    # Check for uncommitted changes first
    if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
        echo "dev-$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        return
    fi

    # Get the current commit hash
    COMMIT_HASH=$(git rev-parse --short HEAD 2>/dev/null)

    if [ -z "$COMMIT_HASH" ]; then
        # Not a git repository
        echo "dev-$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        return
    fi

    # Check if current commit has a tag
    TAG=$(git describe --tags --exact-match 2>/dev/null)

    if [ -n "$TAG" ]; then
        # Check if it's a pre-release (contains -alpha, -beta, -rc, -pre, etc.)
        if [[ "$TAG" =~ -(alpha|beta|rc|pre) ]]; then
            echo "pre-$TAG"
        else
            echo "$TAG"
        fi
        return
    fi

    # No exact tag - output as PR with commit hash
    echo "PR-$COMMIT_HASH"
}

get_version
