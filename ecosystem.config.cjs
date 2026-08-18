/**
 * PM2 ecosystem for the Telegram Ads Bot.
 *
 *   pm2 start ecosystem.config.cjs
 *   pm2 save
 *
 * Processes:
 *   tgads-queue   — database queue worker (broadcasts, Telegram messages)
 *   tgads-sched   — runs the Laravel scheduler once a minute
 *
 * The scheduler process uses the cron-like `schedule:work` command so a
 * separate system cron entry is not required; pm2 keeps it alive.
 */
module.exports = {
  apps: [
    {
      name: 'tgads-queue',
      cwd: __dirname,
      script: 'artisan',
      args: 'queue:work --tries=3 --backoff=5 --max-time=3600 --memory=128',
      interpreter: process.env.PHP_BIN || 'php',
      instances: 1,
      exec_mode: 'fork',
      autorestart: true,
      max_restarts: 10,
      restart_delay: 2000,
      out_file: './storage/logs/pm2-queue.out.log',
      error_file: './storage/logs/pm2-queue.err.log',
      time: true,
    },
    {
      name: 'tgads-sched',
      cwd: __dirname,
      script: 'artisan',
      args: 'schedule:work --verbose',
      interpreter: process.env.PHP_BIN || 'php',
      instances: 1,
      exec_mode: 'fork',
      autorestart: true,
      max_restarts: 10,
      restart_delay: 2000,
      out_file: './storage/logs/pm2-sched.out.log',
      error_file: './storage/logs/pm2-sched.err.log',
      time: true,
    },
  ],
};
