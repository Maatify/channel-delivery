# How to Run the Worker

The email delivery worker pulls pending jobs from the `cd_email_queue` database, renders them via Twig, and dispatches them via SMTP.

## CLI Command

To run a single batch of emails:

```bash
php scripts/email_worker.php --batch=50
```

To run continuously (daemon mode):

```bash
php scripts/email_worker.php --batch=50 --loop --sleep=5
```

## Supervisor Configuration

For production, you should run the worker under a process manager like **Supervisor**.

Create a configuration file at `/etc/supervisor/conf.d/channel-delivery-email.conf`:

```ini
[program:cd_email_worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/channel-delivery/scripts/email_worker.php --batch=50 --loop --sleep=5
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/channel-delivery/var/log/email_worker.log
```

Then update Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start cd_email_worker:*
```