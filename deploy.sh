cat >/deploy.sh <<'EOF'
#!/bin/bash

# CloudPanel Laravel Automated Deployment Script
# This script must be run as root.

# Exit immediately if any command fails
set -e

# Ensure the script is run as root
if [ "$EUID" -ne 0 ]; then
  echo "Error: Please run this script as root (sudo)."
  exit 1
fi

# ==========================================
# CONFIGURATION VARIABLES
# ==========================================
DOMAIN="shalomdentalcareapi.poslycloud.com"
SITE_USER="poslycloud-shalomdentalcare"
DB_NAME="shalomdentalcaredb"
DB_USER="shalomdentalcarebsuser"
DB_PASS="shalomdentalcare3KYkz"
GIT_REPO="git@github.com:ssekimuli/chart-backend.git"
GIT_BRANCH="production"  # Specify the production branch

# Check if the site user exists, and if not, create the site in CloudPanel first
if ! id "$SITE_USER" &>/dev/null; then
    echo "Site user '$SITE_USER' does not exist. Creating site '$DOMAIN' via CloudPanel CLI..."
    
    # If the user directory exists but the system user doesn't, clean it up to avoid conflicts
    if [ -d "/home/$SITE_USER" ]; then
        echo "Warning: Directory '/home/$SITE_USER' exists but user '$SITE_USER' does not. Cleaning up..."
        rm -rf "/home/$SITE_USER"
    fi

    # Find latest PHP version installed under /etc/php
    LATEST_PHP_VERSION=$(ls -d /etc/php/[0-9].[0-9] 2>/dev/null | sed 's|/etc/php/||' | sort -V | tail -n 1)
    if [ -z "$LATEST_PHP_VERSION" ]; then
        LATEST_PHP_VERSION="8.5"
    fi
    
    # Generate a random password for the site user
    RANDOM_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 16)
    
    if clpctl site:add:php --domainName="$DOMAIN" --phpVersion="$LATEST_PHP_VERSION" --vhostTemplate="Laravel 12" --siteUser="$SITE_USER" --siteUserPassword="$RANDOM_PASS"; then
        echo "✅ CloudPanel site '$DOMAIN' created successfully!"
    else
        echo "❌ Failed to create site '$DOMAIN' via CloudPanel CLI. Aborting."
        exit 1
    fi
fi

echo "=============================================="
echo "Starting deployment for $DOMAIN..."
echo "=============================================="

# 2. Clone the Codebase (from production branch)
echo "[3/6] Cloning repository (branch: $GIT_BRANCH)..."
HTDOCS_DIR="/home/$SITE_USER/htdocs/$DOMAIN"

if [ -d "$HTDOCS_DIR" ]; then
    # Clean the directory while keeping the directory itself
    find "$HTDOCS_DIR" -mindepth 1 -delete
else
    mkdir -p "$HTDOCS_DIR"
fi

# Clone the specific branch
git clone --branch "$GIT_BRANCH" --single-branch "$GIT_REPO" "$HTDOCS_DIR"
chown -R "$SITE_USER:$SITE_USER" "$HTDOCS_DIR"

# 4. Setup Laravel Environment, Dependencies, and Assets
echo "[4/6] Installing dependencies..."

if [ ! -f "$HTDOCS_DIR/.env.example" ]; then
    echo "Error: .env.example not found in repo. Aborting."
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
    sed -i 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' .env
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

   
    # Install and build frontend assets
    if [ -f package.json ]; then
        npm install
        npm run build || echo 'Build script not found, skipping...'
    fi

    # Correct directory permissions
    chmod -R 775 storage bootstrap/cache
"

# 4.5. Configure Nginx Document Root for Laravel (point to /public)
echo "[4.5/6] Updating Nginx configuration to point to public/ folder..."
NGINX_CONFIG="/etc/nginx/sites-enabled/$DOMAIN.conf"
if [ -f "$NGINX_CONFIG" ]; then
    if grep -q "root /home/$SITE_USER/htdocs/$DOMAIN;" "$NGINX_CONFIG"; then
        echo "Updating Nginx root directory in $NGINX_CONFIG..."
        sed -i "s|root /home/$SITE_USER/htdocs/$DOMAIN;|root /home/$SITE_USER/htdocs/$DOMAIN/public;|g" "$NGINX_CONFIG"
        nginx -t && systemctl reload nginx
        echo "✅ Nginx configuration updated and reloaded!"
    else
        echo "Nginx root is already configured correctly or custom."
    fi
else
    echo "⚠️  Nginx configuration file $NGINX_CONFIG not found. Skipping automatic root path update."
fi


# 5. Configure the Cron Scheduler
echo "[5/6] Setting up cron..."
CRON_JOB="* * * * * cd $HTDOCS_DIR && php artisan schedule:run >> /dev/null 2>&1"
(crontab -u "$SITE_USER" -l 2>/dev/null; echo "$CRON_JOB") | sort -u | crontab -u "$SITE_USER" -

# 6. Install SSL Certificate
echo "[6/6] Requesting SSL certificate..."
SERVER_IP=$(curl -s ifconfig.me)
echo "⚠️  Make sure DNS records for $DOMAIN point to $SERVER_IP"

if clpctl lets-encrypt:install:certificate --domainName="$DOMAIN" 2>&1; then
    echo "✅ SSL certificate installed successfully!"
else
    echo "⚠️  SSL certificate installation failed. Run manually after DNS propagates."
fi

echo "=============================================="
echo "✅ Deployment completed!"
echo "Domain: https://$DOMAIN"
echo "Branch: $GIT_BRANCH"
echo "=============================================="
EOF