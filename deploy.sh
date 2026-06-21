#!/bin/bash
set -e

PROJECT_DIR="/var/www/mutabakat"
cd "$PROJECT_DIR"

echo "HBC Mutabakat güncelleniyor..."
git fetch origin main
git reset --hard origin/main

# Klasör izinlerini Nginx (www-data) için güvenli hale getir
chown -R www-data:www-data "$PROJECT_DIR"
chmod -R 775 "$PROJECT_DIR"/var
chmod -R 775 "$PROJECT_DIR"/uploads

echo "Deploy tamamlandı."
