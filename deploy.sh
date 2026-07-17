cat > deploy.sh << 'EOF'
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
DOMAIN="crogendentalapi.poslycloud.com"
SITE_USER="poslycloud-crogendentalapi"
DB_NAME="crogendentaldb"
DB_USER="crogendentalbsuser"
DB_PASS="crogend3KYkz"
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
    
    if clpctl site:add:php --domainName="$DOMAIN" --phpVersion="$LATEST_PHP_VERSION" --vhostTemplate="Generic" --siteUser="$SITE_USER" --siteUserPassword="$RANDOM_PASS"; then
        echo "✅ CloudPanel site '$DOMAIN' created successfully!"
    else
        echo "❌ Failed to create site '$DOMAIN' via CloudPanel CLI. Aborting."
        exit 1
    fi
fi

echo "=============================================="
echo "Starting deployment for $DOMAIN..."
echo "=============================================="

# 1. Get MySQL root password
echo "[1/6] Getting MySQL root password..."

MYSQL_ROOT_PASS=""

# Method 0: Try clpctl db:show:master-credentials (CloudPanel v2)
if [ -z "$MYSQL_ROOT_PASS" ]; then
    MYSQL_ROOT_PASS=$(clpctl db:show:master-credentials 2>/dev/null | grep -i "Password" | head -1 | awk '{print $4}')
fi

# Method 1: Try to get from CloudPanel config (alternative)
if [ -z "$MYSQL_ROOT_PASS" ]; then
    MYSQL_ROOT_PASS=$(clpctl system:info 2>/dev/null | grep -i "root password" | head -1 | awk '{print $NF}')
fi

# Method 2: Try common locations
if [ -z "$MYSQL_ROOT_PASS" ] && [ -f /root/.my.cnf ]; then
    MYSQL_ROOT_PASS=$(grep password /root/.my.cnf | cut -d= -f2)
fi

# Method 3: Check /etc/cloudpanel/ directory
if [ -z "$MYSQL_ROOT_PASS" ] && [ -f /etc/cloudpanel/mysql-root-password ]; then
    MYSQL_ROOT_PASS=$(cat /etc/cloudpanel/mysql-root-password)
fi

if [ -z "$MYSQL_ROOT_PASS" ]; then
    echo "⚠️  Could not find MySQL root password automatically."
    echo "Please enter the MySQL root password:"
    read -s MYSQL_ROOT_PASS
    echo ""
fi

# 2. Setup Database
echo "[2/6] Setting up database..."
mysql -h'127.0.0.1' -P'3306' -u root -p"$MYSQL_ROOT_PASS" << MYSQL_EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME;
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
MYSQL_EOF

if [ $? -eq 0 ]; then
    echo "✅ Database setup successful!"
else
    echo "❌ Database setup failed. Please check your MySQL root password."
    exit 1
fi

# 3. Clone the Codebase (from production branch)
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

    # Run migrations
    php artisan migrate --force

    # Clear and cache config
    php artisan config:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

    # Install and build frontend assets
    if [ -f package.json ]; then
        npm install
        npm run build || echo 'Build script not found, skipping...'
    fi

    # Correct directory permissions
    chmod -R 775 storage bootstrap/cache
"

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