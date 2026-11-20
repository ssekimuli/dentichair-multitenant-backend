# CloudPanel Laravel Site Deployment Guide

This guide describes how to deploy a new instance of the **Dental API (chart-backend)** codebase on your CloudPanel server from start to finish.

---

## Prerequisites

Before starting, prepare the following details for your new deployment:
* **Domain Name**: e.g., `newsite.poslycloud.com`
* **Site User**: e.g., `poslycloud-newsite`
* **Database Name**: e.g., `newsite_db`
* **Database Username**: e.g., `newsite_user`

---

## Step 1: Create the Site via CloudPanel CLI

Run the following command as the **`root`** user to add the PHP site. This command configures the system user, creates directories, and sets up Nginx with the Laravel Virtual Host template (which maps requests to the `/public` folder).

```bash
clpctl site:add:php \
  --domainName=newsite.poslycloud.com \
  --phpVersion=8.4 \
  --vhostTemplate='Laravel 12' \
  --siteUser=poslycloud-newsite \
  --siteUserPassword='!StrongPassword!'
```

---

## Step 2: Create the Database

Provision a new database and database user via the CloudPanel CLI:

```bash
clpctl db:add \
  --domainName=newsite.poslycloud.com \
  --databaseName=newsite_db \
  --databaseUserName=newsite_user \
  --databaseUserPassword='!StrongDBPassword!'
```

---

## Step 3: Clone the Codebase

1. Switch to the newly created site user:
   ```bash
   sudo su - poslycloud-newsite
   ```

2. Navigate to your site's document root directory and clean out any placeholder files created by CloudPanel:
   ```bash
   cd htdocs/newsite.poslycloud.com
   rm -rf * .[^.]*
   ```

3. Clone your Git repository directly into the directory:
   ```bash
   git clone git@github.com:ssekimuli/chart-backend.git .
   ```

---

## Step 4: Configure the Environment (`.env`)

1. Copy the example configuration file:
   ```bash
   cp .env.example .env
   ```

2. Edit `.env` to configure your database connection and environment parameters:
   ```ini
   APP_NAME="Dental API"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://newsite.poslycloud.com

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=newsite_db
   DB_USERNAME=newsite_user
   DB_PASSWORD=!StrongDBPassword!
   ```

---

## Step 5: Install Dependencies and Build Assets

Run the following commands as the **site user** (`poslycloud-newsite`) within `/home/poslycloud-newsite/htdocs/newsite.poslycloud.com`:

1. Install PHP dependencies:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

2. Generate the unique application key:
   ```bash
   php artisan key:generate
   ```

3. Run migrations and seed default database records:
   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   ```

4. Install frontend assets and build the production bundle:
   ```bash
   npm install
   npm run build
   ```

5. Correct storage and cache folder permissions:
   ```bash
   chmod -R 775 storage bootstrap/cache
   ```

---

## Step 6: Configure the Cron Scheduler

To automate scheduled tasks (like overdue invoice marking and appointment reminders), configure the cron job for your new site user:

1. Open the user's crontab editor:
   ```bash
   crontab -e
   ```

2. Paste the following line:
   ```text
    * * * * * cd /home/poslycloud-newsite/htdocs/newsite.poslycloud.com && php artisan schedule:run >> /home/poslycloud-newsite/htdocs/newsite.poslycloud.com/storage/logs/cron.log 2>&1
   ```

3. Save and close the editor.

---

## Step 7: Install Let's Encrypt SSL Certificate

Exit back to the **`root`** shell and install a trusted Let's Encrypt SSL certificate:

1. Exit the site user shell:
   ```bash
   exit
   ```

2. Issue and configure the certificate:
   ```bash
   clpctl lets-encrypt:install:certificate --domainName=newsite.poslycloud.com
   ```

---

## Multi-tenant deployment

The central tenant registry uses the `tenant_registry` connection. It defaults to the main database connection; set `TENANCY_REGISTRY_*` values in `.env` if the registry uses a separate database. Keep `APP_KEY` deployment-wide because tenant database passwords and SMS client IDs are encrypted with it.

Each tenant's display name and application URL are applied as `app.name` and `app.url` while handling that tenant. The SMS client ID and email branding are also resolved from the tenant registry. Leave `SESSION_CONNECTION` unset when using database sessions so each tenant uses its own `sessions` table. Use a taggable cache store (such as the configured database, Redis, or Memcached store); Laravel's file cache does not support tenant cache tags.

New file uploads for a tenant are stored under `tenants/<tenant-id>/` on local/public storage and in R2. Existing file URLs are not moved or rewritten.

For an existing installation, first run the central registry migration and register its current database as a tenant:

```bash
php artisan migrate --database=tenant_registry --path=database/migrations/central --force
php artisan tenants:add clinic.example.com "Clinic Display Name" --database=existing_tenant_db --host=127.0.0.1 --username=tenant_user
php artisan tenants:migrate
```

`tenants:add` prompts for the tenant database password and SMS client ID without placing them in shell history. Provision the database and its user before registering the tenant. After the tenant migration succeeds, enable `TENANCY_ENABLED=true` and restart the web and queue workers.

The central API can also register a tenant at `POST /api/admin/tenants`. Send the system key in the `X-App-Key` request header and call the endpoint on a configured central domain. Submit `domain`, `display_name`, `database`, and (for non-SQLite databases) `username`; optional fields include `host`, `port`, `password`, `sms_client_id`, `app_url`, and email branding. The shared `VerifyAppKey` middleware now requires this header for tenant registration and the other app-key-protected routes; sending `app_key` in the request body is not accepted. Password and SMS client ID are encrypted in the registry and are never returned by the endpoint. The API registers an already-provisioned database only; run `php artisan tenants:migrate` afterward to install its schema and activate it.

For every future schema change, add one migration under `database/migrations` and run:

```bash
php artisan tenants:migrate
```

The tenancy migration command runs the same migration files against every registered tenant database. Each database keeps its own Laravel migration history. New tenant databases should be registered and migrated with the same command before being activated.

---

## Verification

Test the setup by sending a GET request to verify the service is running:
```bash
curl -I https://newsite.poslycloud.com/api/patients
```
*(Should return `HTTP/2 200` or `401 Unauthenticated` instead of any SSL or database connection errors)*.
