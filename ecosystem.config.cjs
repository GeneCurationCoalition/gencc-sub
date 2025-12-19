module.exports = {
  apps: [
    {
      name: 'gencc-queue-worker',
      script: 'artisan',
      interpreter: 'php',
      args: 'queue:work --tries=3 --timeout=3600 --memory=2048 --sleep=3 --max-jobs=1000',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '2048M',
      max_restarts: 10,
      min_uptime: '10s',
      restart_delay: 4000,
      error_file: './storage/logs/pm2-queue-error.log',
      out_file: './storage/logs/pm2-queue-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
      merge_logs: true,
      env: {
        NODE_ENV: 'development',
        APP_ENV: 'local',
      },
      env_production: {
        NODE_ENV: 'production',
        APP_ENV: 'production',
      }
    },
    {
      name: 'gencc-dev-server',
      script: 'artisan',
      interpreter: 'php',
      args: 'serve --port=8001',
      instances: 1,
      autorestart: true,
      watch: false,
      max_restarts: 10,
      min_uptime: '5s',
      restart_delay: 2000,
      error_file: './storage/logs/pm2-server-error.log',
      out_file: './storage/logs/pm2-server-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
      merge_logs: true,
      env: {
        NODE_ENV: 'development',
      }
    },
    {
      name: 'gencc-vite',
      script: 'npm',
      args: 'run dev',
      instances: 1,
      autorestart: true,
      watch: false,
      max_restarts: 10,
      min_uptime: '5s',
      restart_delay: 2000,
      error_file: './storage/logs/pm2-vite-error.log',
      out_file: './storage/logs/pm2-vite-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
      merge_logs: true,
      env: {
        NODE_ENV: 'development',
      }
    }
  ]
};
