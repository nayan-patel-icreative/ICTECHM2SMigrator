# Deployment (Ubuntu VPS)

## Queue worker (required for migrations)

This app uses Laravel queues (`QUEUE_CONNECTION=database`). Product migration runs as background jobs and **requires** a running queue worker on the server.

Merchants (Shopify store owners) do not run this. It must run on your app server.

### Supervisor (recommended)

1. Install Supervisor

```bash
sudo apt-get update
sudo apt-get install supervisor
```

2. Create Supervisor program file

Create:

`/etc/supervisor/conf.d/ictechs2smigrator-worker.conf`

```ini
[program:ictechs2smigrator-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/html/ICTECHS2SMigrator/backend/artisan queue:work --queue=default --sleep=1 --tries=3 --timeout=120
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/ictechs2smigrator-worker.log
stopwaitsecs=3600
```

Notes:
- Update the `command` path if your project path differs.
- Update `user` to the same user that owns/serves the PHP app (commonly `www-data`).

3. Load and start the worker

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

### Restarting workers after code deploy

After deploying new code, restart workers so they load new PHP classes:

```bash
php /var/www/html/ICTECHS2SMigrator/backend/artisan queue:restart
```

## Health check

The app exposes:

- `GET /api/queue/health` → `{ "worker_online": true|false }`

The Admin UI will disable Preview/Start and show a banner if the worker is offline.
