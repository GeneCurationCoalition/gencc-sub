#!/bin/bash
#
# GenCC Submission Portal - Server Startup Script
#
# Usage:
#   ./scripts/start-dev-servers.sh          # Start locally with PM2 (default)
#   ./scripts/start-dev-servers.sh local    # Start locally with PM2
#   ./scripts/start-dev-servers.sh docker   # Start with Docker/Podman
#   ./scripts/start-dev-servers.sh stop     # Stop all servers (local and Docker)
#   ./scripts/start-dev-servers.sh status   # Show status (local or Docker)
#   ./scripts/start-dev-servers.sh logs     # View logs (local or Docker)
#   ./scripts/start-dev-servers.sh shell    # Shell into Docker container
#
# Options:
#   -r, --restore    Restore database from baseline backup before starting
#
# Examples:
#   ./scripts/start-dev-servers.sh local --restore    # Restore DB then start locally
#   ./scripts/start-dev-servers.sh docker -r          # Restore DB then start Docker
#   ./scripts/start-dev-servers.sh --restore          # Restore DB then start locally (default)
#
# This script:
# 1. Optionally restores database from baseline backup (with --restore flag)
# 2. Checks for pending database migrations
# 3. Runs any pending migrations
# 4. Starts servers using PM2 or Docker
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get the directory of this script
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_DIR="$( cd "$SCRIPT_DIR/.." && pwd )"

# Parse command line arguments
RESTORE_DB=false
MODE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        -r|--restore)
            RESTORE_DB=true
            shift
            ;;
        -*)
            echo -e "${RED}Unknown option: $1${NC}"
            exit 1
            ;;
        *)
            # First non-option argument is the mode
            if [ -z "$MODE" ]; then
                MODE="$1"
            fi
            shift
            ;;
    esac
done

# Default mode to local if not specified
MODE="${MODE:-local}"

# Detect container runtime (prefer podman, fallback to docker)
detect_container_runtime() {
    if command -v podman-compose &> /dev/null; then
        echo "podman-compose"
    elif command -v docker-compose &> /dev/null; then
        echo "docker-compose"
    elif command -v docker &> /dev/null && docker compose version &> /dev/null; then
        echo "docker compose"
    else
        echo ""
    fi
}

show_usage() {
    echo -e "${BLUE}Usage:${NC}"
    echo -e "  $0 [command] [options]"
    echo ""
    echo -e "${BLUE}Commands:${NC}"
    echo -e "  local    Start servers locally using PM2 (default)"
    echo -e "  docker   Start servers using Docker/Podman containers"
    echo -e "  stop     Stop all servers (both local and Docker)"
    echo -e "  status   Show server status (auto-detects local or Docker)"
    echo -e "  logs     View server logs (auto-detects local or Docker)"
    echo -e "  shell    Open shell in Docker container"
    echo ""
    echo -e "${BLUE}Options:${NC}"
    echo -e "  -r, --restore    Restore database from baseline backup before starting"
    echo ""
    echo -e "${BLUE}Examples:${NC}"
    echo -e "  $0                      # Start locally (default)"
    echo -e "  $0 local --restore      # Restore DB, then start locally"
    echo -e "  $0 docker -r            # Restore DB, then start with Docker"
    echo -e "  $0 --restore            # Restore DB, then start locally (default)"
    echo ""
}

# Function to restore database using restore-db.sh (handles restore, migrations, logos)
restore_database() {
    echo -e "${YELLOW}========================================${NC}"
    echo -e "${YELLOW}  Restoring Database from Baseline${NC}"
    echo -e "${YELLOW}========================================${NC}"
    echo ""
    "$PROJECT_DIR/restore-db.sh" --no-confirm
    echo ""
}

# Function to run migrations (for PM2 mode - runs locally)
run_migrations_local() {
    echo -e "${YELLOW}Checking database migration status...${NC}"

    # Get migration status and check for pending migrations
    MIGRATION_OUTPUT=$(php artisan migrate:status 2>&1)
    PENDING_COUNT=$(echo "$MIGRATION_OUTPUT" | grep -c "Pending" || true)

    if [ "$PENDING_COUNT" -gt 0 ]; then
        echo -e "${YELLOW}Found ${PENDING_COUNT} pending migration(s):${NC}"
        echo "$MIGRATION_OUTPUT" | grep "Pending" || true
        echo ""

        # Run migrations
        echo -e "${YELLOW}Running pending migrations...${NC}"
        if php artisan migrate --force; then
            echo -e "${GREEN}Migrations completed successfully!${NC}"
        else
            echo -e "${RED}Migration failed! Please fix the issue before starting servers.${NC}"
            exit 1
        fi
    else
        echo -e "${GREEN}All migrations are up to date.${NC}"
    fi
    echo ""
}

# Function to clear caches
clear_caches() {
    echo -e "${YELLOW}Clearing application caches...${NC}"
    php artisan config:clear
    php artisan cache:clear
    php artisan route:clear
    php artisan view:clear
    echo -e "${GREEN}Caches cleared.${NC}"
    echo ""
}

# Function to start locally with PM2
start_pm2() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}  GenCC Startup (Local Mode)${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""

    cd "$PROJECT_DIR"

    # Restore database if --restore flag was passed (includes migrations + logos)
    if [ "$RESTORE_DB" = true ]; then
        restore_database
    else
        run_migrations_local
    fi

    clear_caches

    # Ensure storage symlink points to local path (Docker mode overwrites it)
    echo -e "${YELLOW}Ensuring storage symlink...${NC}"
    rm -f "$PROJECT_DIR/public/storage"
    php artisan storage:link
    echo ""

    # Stop existing PM2 processes
    echo -e "${YELLOW}Stopping existing PM2 processes...${NC}"
    pm2 stop all 2>/dev/null || true
    echo ""

    # Start PM2 with ecosystem config
    echo -e "${YELLOW}Starting PM2 processes...${NC}"
    pm2 start "$PROJECT_DIR/ecosystem.dev.config.cjs"
    echo ""

    # Show status
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}  All servers started successfully!${NC}"
    echo -e "${GREEN}========================================${NC}"
    echo ""
    pm2 list

    echo ""
    echo -e "${BLUE}Server URLs:${NC}"
    echo -e "  Laravel Dev Server: http://127.0.0.1:8001"
    echo -e "  Vite Dev Server:    http://127.0.0.1:5173"
    echo ""
    echo -e "${BLUE}Useful commands:${NC}"
    echo -e "  pm2 logs           - View all logs"
    echo -e "  pm2 monit          - Monitor processes"
    echo -e "  pm2 restart all    - Restart all processes"
    echo -e "  pm2 stop all       - Stop all processes"
    echo ""
}

# Function to start with Docker/Podman
start_docker() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}  GenCC Startup (Docker Mode)${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""

    cd "$PROJECT_DIR"

    CONTAINER_CMD=$(detect_container_runtime)
    if [ -z "$CONTAINER_CMD" ]; then
        echo -e "${RED}Error: Neither podman-compose nor docker-compose found.${NC}"
        echo -e "${RED}Please install one of them to use Docker mode.${NC}"
        exit 1
    fi

    echo -e "${YELLOW}Using container runtime: ${CONTAINER_CMD}${NC}"
    echo ""

    # Use existing APP_VERSION if set, otherwise generate from git
    if [ -z "$APP_VERSION" ]; then
        APP_VERSION=$("$SCRIPT_DIR/version.sh")
    fi
    export APP_VERSION
    echo -e "${YELLOW}Application version: ${APP_VERSION}${NC}"
    echo ""

    # Restore database if --restore flag was passed (includes migrations + logos)
    if [ "$RESTORE_DB" = true ]; then
        restore_database
    else
        # Run migrations locally (since Docker uses host MySQL via host.docker.internal)
        run_migrations_local
    fi

    # Stop any existing containers
    echo -e "${YELLOW}Stopping existing containers...${NC}"
    $CONTAINER_CMD -f docker-compose.dev.yml down 2>/dev/null || true
    echo ""

    # Build and start containers
    echo -e "${YELLOW}Building and starting containers...${NC}"
    $CONTAINER_CMD -f docker-compose.dev.yml up -d --build
    echo ""

    # Show status
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}  Containers started successfully!${NC}"
    echo -e "${GREEN}========================================${NC}"
    echo ""
    $CONTAINER_CMD -f docker-compose.dev.yml ps

    echo ""
    echo -e "${BLUE}Server URLs:${NC}"
    echo -e "  Laravel Dev Server: http://127.0.0.1:8001"
    echo -e "  Vite Dev Server:    http://127.0.0.1:5173"
    echo ""
    echo -e "${BLUE}Useful commands:${NC}"
    echo -e "  $CONTAINER_CMD -f docker-compose.dev.yml logs -f      - View logs"
    echo -e "  $CONTAINER_CMD -f docker-compose.dev.yml ps           - Show status"
    echo -e "  $CONTAINER_CMD -f docker-compose.dev.yml restart      - Restart"
    echo -e "  $CONTAINER_CMD -f docker-compose.dev.yml down         - Stop"
    echo -e "  $CONTAINER_CMD -f docker-compose.dev.yml exec app bash - Shell into container"
    echo ""
}

# Function to stop all servers
stop_all() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}  Stopping All GenCC Servers${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""

    cd "$PROJECT_DIR"

    # Stop PM2
    echo -e "${YELLOW}Stopping PM2 processes...${NC}"
    pm2 stop all 2>/dev/null || echo -e "${YELLOW}No PM2 processes running${NC}"
    echo ""

    # Stop Docker/Podman
    CONTAINER_CMD=$(detect_container_runtime)
    if [ -n "$CONTAINER_CMD" ]; then
        echo -e "${YELLOW}Stopping Docker containers...${NC}"
        $CONTAINER_CMD -f docker-compose.dev.yml down 2>/dev/null || echo -e "${YELLOW}No containers running${NC}"
    fi
    echo ""

    echo -e "${GREEN}All servers stopped.${NC}"
    echo ""
}

# Function to detect if Docker container is running
is_docker_running() {
    cd "$PROJECT_DIR"
    CONTAINER_CMD=$(detect_container_runtime)
    if [ -n "$CONTAINER_CMD" ]; then
        # Check if gencc-dev container is running
        if $CONTAINER_CMD -f docker-compose.dev.yml ps 2>/dev/null | grep -q "Up\|running"; then
            return 0
        fi
    fi
    return 1
}

# Function to show status
show_status() {
    cd "$PROJECT_DIR"

    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}  GenCC Server Status${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""

    # Check Docker first
    if is_docker_running; then
        echo -e "${GREEN}Docker containers are running:${NC}"
        echo ""
        CONTAINER_CMD=$(detect_container_runtime)
        $CONTAINER_CMD -f docker-compose.dev.yml ps
        echo ""
        echo -e "${YELLOW}PM2 status inside container:${NC}"
        $CONTAINER_CMD -f docker-compose.dev.yml exec app pm2 list 2>/dev/null || echo -e "${YELLOW}Could not get PM2 status from container${NC}"
    else
        echo -e "${YELLOW}Docker containers are not running${NC}"
        echo ""
        echo -e "${GREEN}Local PM2 status:${NC}"
        pm2 list 2>/dev/null || echo -e "${YELLOW}PM2 is not running${NC}"
    fi
    echo ""
}

# Function to show logs
show_logs() {
    cd "$PROJECT_DIR"

    # Check Docker first
    if is_docker_running; then
        echo -e "${BLUE}Showing Docker container logs (Ctrl+C to exit)...${NC}"
        echo ""
        CONTAINER_CMD=$(detect_container_runtime)
        $CONTAINER_CMD -f docker-compose.dev.yml logs -f
    else
        echo -e "${BLUE}Showing PM2 logs (Ctrl+C to exit)...${NC}"
        echo ""
        pm2 logs
    fi
}

# Function to open shell in Docker container
open_shell() {
    cd "$PROJECT_DIR"

    CONTAINER_CMD=$(detect_container_runtime)
    if [ -z "$CONTAINER_CMD" ]; then
        echo -e "${RED}Error: Neither podman-compose nor docker-compose found.${NC}"
        exit 1
    fi

    if is_docker_running; then
        echo -e "${BLUE}Opening shell in Docker container...${NC}"
        echo ""
        $CONTAINER_CMD -f docker-compose.dev.yml exec app bash
    else
        echo -e "${RED}Error: Docker container is not running.${NC}"
        echo -e "${YELLOW}Start it first with: $0 docker${NC}"
        exit 1
    fi
}

# Main
case "$MODE" in
    local|pm2)
        start_pm2
        ;;
    docker|podman)
        start_docker
        ;;
    stop)
        stop_all
        ;;
    status)
        show_status
        ;;
    logs)
        show_logs
        ;;
    shell|bash|sh)
        open_shell
        ;;
    -h|--help|help)
        show_usage
        ;;
    *)
        echo -e "${RED}Unknown option: $MODE${NC}"
        echo ""
        show_usage
        exit 1
        ;;
esac
