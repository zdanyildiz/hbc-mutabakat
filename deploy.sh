#!/bin/bash
set -e

PROJECT_DIR="/var/www/mutabakat"
cd "$PROJECT_DIR"

echo "HBC Mutabakat güncelleniyor..."
git fetch origin main
git reset --hard origin/main

# Klasör izinlerini Nginx (www-data) için güvenli hale getir
mkdir -p "$PROJECT_DIR"/var
chown -R www-data:www-data "$PROJECT_DIR"
chmod -R 775 "$PROJECT_DIR"/var

echo "Deploy tamamlandı."
