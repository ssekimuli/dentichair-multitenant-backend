cat > deploy.sh << 'EOF'
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
# ==========================================
DOMAIN="victoriousdentalapi.poslycloud.com"
SITE_USER="poslycloud-victoriousdentalapi"
SITE_PASS="!ChangeThisSitePassword123!"
DB_NAME="victoriousdentalapi_db"
DB_USER="victoriousdentalapi_user"
DB_PASS="!ug256victoriousdentalapi!"
GIT_REPO="git@github.com:ssekimuli/chart-backend.git"
PHP_VERSION="8.4"
VHOST_TEMPLATE="Laravel 12"

# ==========================================
# SCRIPT EXECUTION
# ==========================================

echo "=============================================="
echo "Starting deployment for $DOMAIN..."
echo "=============================================="

# 0. Sanity checks before touching anything
echo "[0/7] Running pre-flight checks..."

if ! command -v clpctl >/dev/null 2>&1; then
  echo "Error: clpctl not found. Is CloudPanel installed?"
  exit 1
fi

echo "Checking SSH access to GitHub..."
ssh -T git@github.com -o StrictHostKeyChecking=accept-new 2>&1 | grep -qi "successfully authenticated" \
  && echo "GitHub SSH OK" \
  || echo "Warning: could not confirm GitHub SSH auth. Clone may fail if the deploy key isn't set up."

# Get server IP for DNS reminder
SERVER_IP=$(curl -s ifconfig.me)
echo "Server IP: $SERVER_IP"

# 1. Create the Site in CloudPanel (skip if exists)
echo "[1/7] Creating PHP site via CloudPanel..."

# Check if site exists - using app:list
if clpctl app:list 2>/dev/null | grep -q "$DOMAIN"; then
  echo "⚠️  Site $DOMAIN already exists, skipping creation..."
else
  clpctl site:add:php \
    --domainName="$DOMAIN" \
    --phpVersion="$PHP_VERSION" \
    --vhostTemplate="$VHOST_TEMPLATE" \
    --siteUser="$SITE_USER" \
    --siteUserPassword="$SITE_PASS"
  echo "✅ Site created successfully"
fi

# 2. Clone the Codebase
echo "[2/7] Cleaning directories and cloning repository..."
HTDOCS_DIR="/home/$SITE_USER/htdocs/$DOMAIN"

if [ -d "$HTDOCS_DIR" ]; then
  # Safe even if the directory is already empty
  find "$HTDOCS_DIR" -mindepth 1 -delete
else
  mkdir -p "$HTDOCS_DIR"
fi

# Check if directory is not empty after cleanup
if [ -z "$(ls -A "$HTDOCS_DIR" 2>/dev/null)" ]; then
  echo "Cloning repository..."
  git clone "$GIT_REPO" "$HTDOCS_DIR"
else
  echo "⚠️  Directory not empty, attempting git pull..."
  cd "$HTDOCS_DIR"
  git pull origin main || git pull origin master
  cd -
fi

chown -R "$SITE_USER:$SITE_USER" "$HTDOCS_DIR"

# 3. Setup Laravel Environment, Dependencies, and Assets
echo "[3/7] Running installer tasks as $SITE_USER..."

if [ ! -f "$HTDOCS_DIR/.env.example" ]; then
  echo "Error: .env.example not found in repo. Aborting before .env setup."
  exit 1
fi

sudo -u "$SITE_USER" bash -c "
  set -e
  cd '$HTDOCS_DIR'

  # Setup .env file
  if [ ! -f .env ]; then
    cp .env.example .env
  fi

  # Configure database and URLs in .env
  sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=mysql/' .env
  sed -i 's/^DB_HOST=.*/DB_HOST=localhost/' .env
  sed -i 's/^DB_PORT=.*/DB_PORT=3306/' .env
  sed -i 's/^DB_DATABASE=.*/DB_DATABASE=$DB_NAME/' .env
  sed -i 's/^DB_USERNAME=.*/DB_USERNAME=$DB_USER/' .env
  sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/' .env
  sed -i 's|^APP_URL=.*|APP_URL=https://$DOMAIN|' .env
  sed -i 's/^APP_ENV=.*/APP_ENV=production/' .env
  sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env

  # Install PHP dependencies
  composer install --no-dev --optimize-autoloader

  # Generate Application Key
  php artisan key:generate --force

  # Run migrations
  php artisan migrate --force || echo 'Migration may have failed, continuing...'

  # Clear and cache config
  php artisan config:clear
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache

  # Install and build frontend assets (only if this repo has a frontend)
  if [ -f package.json ]; then
    npm install
    npm run build || echo 'Build script not found, skipping...'
  else
    echo 'No package.json found, skipping npm install/build.'
  fi

  # Correct directory permissions
  chmod -R 775 storage bootstrap/cache
"

# 4. Configure the Cron Scheduler
echo "[4/7] Configuring cron scheduler for $SITE_USER..."
CRON_JOB="* * * * * cd $HTDOCS_DIR && php artisan schedule:run >> /dev/null 2>&1"
(crontab -u "$SITE_USER" -l 2>/dev/null; echo "$CRON_JOB") | sort -u | crontab -u "$SITE_USER" -

# 5. Install SSL Certificate
echo "[5/7] Requesting Let's Encrypt SSL certificate..."
echo "⚠️  IMPORTANT: Make sure DNS records for $DOMAIN point to $SERVER_IP"
echo "   Add an A record: $DOMAIN -> $SERVER_IP"

if clpctl lets-encrypt:install:certificate --domainName="$DOMAIN" 2>&1; then
  echo "✅ SSL certificate installed successfully!"
else
  echo "⚠️  SSL certificate installation failed. Please check DNS records."
  echo "   After DNS is configured, run:"
  echo "   clpctl lets-encrypt:install:certificate --domainName=\"$DOMAIN\""
  echo "   Or install a self-signed certificate:"
  echo "   clpctl site:install:certificate --domainName=\"$DOMAIN\" --selfSigned"
fi

# 6. Verification
echo "[6/7] Verifying site status..."
echo "=============================================="
echo "✅ Deployment completed successfully!"
echo "----------------------------------------------"
echo "Domain:   https://$DOMAIN"
echo "Database: $DB_NAME"
echo "DB User:  $DB_USER"
echo "Server IP: $SERVER_IP"
echo "----------------------------------------------"
echo "Next steps:"
echo "1. Add DNS A record: $DOMAIN -> $SERVER_IP"
echo "2. Install SSL certificate after DNS propagates"
echo "3. Access your site at: http://$SERVER_IP (temporary)"
echo "=============================================="
EOF