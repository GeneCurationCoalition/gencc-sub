# PM2 Process Manager Setup

This project uses PM2 to manage development services and the Laravel queue worker. PM2 provides automatic restarts, log management, and monitoring.

## Quick Start

```bash
# Start all services
pm2 start ecosystem.config.cjs

# View status
pm2 status

# View logs
pm2 logs

# Stop all services
pm2 stop all

# Restart all services
pm2 restart all
```

## Individual Service Management

```bash
# Queue worker only
pm2 start ecosystem.config.cjs --only gencc-queue-worker
pm2 restart gencc-queue-worker
pm2 logs gencc-queue-worker

# Dev server only
pm2 start ecosystem.config.cjs --only gencc-dev-server
pm2 restart gencc-dev-server

# Vite only
pm2 start ecosystem.config.cjs --only gencc-vite
pm2 restart gencc-vite
```

## Monitoring

```bash
# Real-time monitoring (CPU, memory, logs)
pm2 monit

# Detailed process info
pm2 show gencc-queue-worker

# View recent logs
pm2 logs --lines 50

# View specific service logs
pm2 logs gencc-queue-worker --lines 100
```

## Log Files

PM2 logs are stored in `storage/logs/`:
- `pm2-queue-out.log` / `pm2-queue-error.log` - Queue worker logs
- `pm2-server-out.log` / `pm2-server-error.log` - Laravel dev server logs
- `pm2-vite-out.log` / `pm2-vite-error.log` - Vite dev server logs

## Configuration

The `ecosystem.config.cjs` file defines all services:

### Queue Worker Settings
- **Memory limit**: 512MB (auto-restart if exceeded)
- **Timeout**: 3600 seconds (1 hour per job)
- **Max jobs**: 1000 (restart after processing 1000 jobs to prevent memory leaks)
- **Tries**: 3 (retry failed jobs up to 3 times)
- **Sleep**: 3 seconds between queue checks

### Auto-Restart
All services automatically restart if they crash or exceed memory limits.

## Common Commands

```bash
# Start development environment
pm2 start ecosystem.config.cjs

# Stop development environment
pm2 stop all

# Restart after code changes
pm2 restart gencc-queue-worker  # After changing queue/job code
pm2 restart gencc-dev-server    # After changing routes/controllers
pm2 restart gencc-vite          # Usually not needed (hot reload)

# Clean restart (remove from PM2 list)
pm2 delete all
pm2 start ecosystem.config.cjs

# Save process list (persist across reboots)
pm2 save

# View PM2 version
pm2 --version

# Update PM2
npm install -g pm2@latest
pm2 update
```

## Production Setup

For production, only run the queue worker with PM2:

```bash
# Start queue worker in production mode
pm2 start ecosystem.config.cjs --only gencc-queue-worker --env production

# Enable auto-start on server boot
pm2 startup
# Run the command it outputs

# Save current process list
pm2 save
```

## Troubleshooting

### Queue worker not processing jobs
```bash
# Check if worker is running
pm2 status

# Check for errors
pm2 logs gencc-queue-worker --err

# Restart worker
pm2 restart gencc-queue-worker
```

### Services won't start
```bash
# Check PM2 daemon status
pm2 ping

# View all logs
pm2 logs --lines 50

# Remove and restart
pm2 delete all
pm2 start ecosystem.config.cjs
```

### Memory issues
```bash
# Monitor memory usage
pm2 monit

# Check if approaching memory limit
pm2 show gencc-queue-worker
```

## Why PM2 Solves the Queue Worker Issue

**Problem**: The queue worker was dying unexpectedly due to memory limits (default 128MB).

**Solution**: PM2 provides:
1. **Automatic restart** when worker dies from memory exhaustion
2. **Higher memory limit** (512MB configured, with auto-restart at limit)
3. **Max jobs limit** (1000 jobs) - prevents memory leaks by restarting periodically
4. **Monitoring** - easily see if worker is running and consuming resources
5. **Persistent logs** - all output saved to files for debugging

The queue worker will now automatically restart if it crashes, ensuring file uploads always get processed.

## Additional Resources

- [PM2 Official Documentation](https://pm2.keymetrics.io/docs/usage/quick-start/)
- [PM2 Process Management](https://pm2.keymetrics.io/docs/usage/process-management/)
- [PM2 Log Management](https://pm2.keymetrics.io/docs/usage/log-management/)
