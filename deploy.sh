#!/bin/bash

# CloudPanel Laravel Automated Deployment Script
# This script must be run as root.

# Exit immediately if any command fails
set -e

# Ensure the script is run as root
if [ "$EUID" -ne 0 ]; then
  echo "Error: Please run this script as root."
  exit 1
fi

# ==========================================
# CONFIGURATION VARIABLES
# Change these values for your new deployment
# ==========================================
DOMAIN="newsite.poslycloud.com"
SITE_USER="poslycloud-newsite"
SITE_PASS="!ChangeThisSitePassword123!"
DB_NAME="newsite_db"
DB_USER="newsite_user"
DB_PASS="!ChangeThisDbPassword123!"
GIT_REPO="git@github.com:ssekimuli/chart-backend.git"
PHP_VERSION="8.4"
VHOST_TEMPLATE="Laravel 12"

# ==========================================
# SCRIPT EXECUTION
# ==========================================

echo "=============================================="
echo "Starting deployment for $DOMAIN..."
echo "=============================================="

# 1. Create the Site in CloudPanel
echo "[1/7] Creating PHP site via CloudPanel..."
clpctl site:add:php \
  --domainName="$DOMAIN" \
  --phpVersion="$PHP_VERSION" \
  --vhostTemplate="$VHOST_TEMPLATE" \
  --siteUser="$SITE_USER" \
  --siteUserPassword="$SITE_PASS"

# 2. Create the Database
echo "[2/7] Creating database and user..."
clpctl db:add \
  --domainName="$DOMAIN" \
  --databaseName="$DB_NAME" \
  --databaseUserName="$DB_USER" \
  --databaseUserPassword="$DB_PASS"

# 3. Clone the Codebase
echo "[3/7] Cleaning directories and cloning repository..."
HTDOCS_DIR="/home/$SITE_USER/htdocs/$DOMAIN"
rm -rf "$HTDOCS_DIR"/* "$HTDOCS_DIR"/.[^.]*
git clone "$GIT_REPO" "$HTDOCS_DIR"
chown -R "$SITE_USER:$SITE_USER" "$HTDOCS_DIR"

# 4. Setup Laravel Environment, Dependencies, and Assets
echo "[4/7] Running installer tasks as $SITE_USER..."
sudo -u "$SITE_USER" bash -c "
  cd '$HTDOCS_DIR'
  
  # Setup .env file
  cp .env.example .env
  
  # Configure database and URLs in .env
  sed -i 's/^DB_DATABASE=.*/DB_DATABASE=$DB_NAME/' .env
  sed -i 's/^DB_USERNAME=.*/DB_USERNAME=$DB_USER/' .env
  sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/' .env
  sed -i 's|^APP_URL=.*|APP_URL=https://$DOMAIN|' .env
  sed -i 's/^APP_ENV=.*/APP_ENV=production/' .env
  sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env

  # Install PHP dependencies
  composer install --no-dev --optimize-autoloader

  # Generate Application Key
  php artisan key:generate

  # Run Database Migrations and Seeders
  php artisan migrate --force
  php artisan db:seed --force

  # Install and build frontend assets
  npm install
  npm run build

  # Correct directory permissions
  chmod -R 775 storage bootstrap/cache
"

# 5. Configure the Cron Scheduler
echo "[5/7] Configuring cron scheduler for $SITE_USER..."
CRON_JOB="* * * * * cd $HTDOCS_DIR && php artisan schedule:run >> /dev/null 2>&1"
(crontab -u "$SITE_USER" -l 2>/dev/null; echo "$CRON_JOB") | sort -u | crontab -u "$SITE_USER" -

# 6. Install SSL Certificate
echo "[6/7] Requesting Let's Encrypt SSL certificate..."
clpctl lets-encrypt:install:certificate --domainName="$DOMAIN"

# 7. Verification
echo "[7/7] Verifying site status..."
echo "Deployment completed successfully!"
echo "----------------------------------------------"
echo "Domain:   https://$DOMAIN"
echo "Database: $DB_NAME"
echo "DB User:  $DB_USER"
echo "=============================================="
