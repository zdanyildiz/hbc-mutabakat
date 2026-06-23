# Sunucu Bilgileri (Server Information)

Bu doküman pozitif-photo projesinin barındığı canlı sunucu donanım ve işletim sistemi bilgilerini içerir.

## Donanım ve Ağ Özellikleri

- **İşletim Sistemi:** Ubuntu 24.04 (Noble Numbat) - ATX
- **IP Adresi:** 179.61.147.124
- **İşlemci (CPU):** 4 Çekirdek (AMD Ryzen 9 9950X / 5950X / Intel Core i9-9900K Yüksek Performanslı İşlemci)
- **Bellek (RAM):** 16 GB DDR4/DDR5 4000MHz RAM
- **Depolama:** 80 GB NVMe SSD Disk
- **Ağ Genişliği:** 1 GBit İnternet Hattı

## Canlı Sunucu Proje ve Cron Bilgileri

- **Proje Dizin Yolu:** `/var/www/mutabakat`
- **Cron Temizlik Komutu (Günde 1 kez, gece 03:00'te çalışır):**
  ```cron
  0 3 * * * /usr/bin/php /var/www/mutabakat/cron/cleanup.php >/dev/null 2>&1
  ```

