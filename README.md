# ⚡ HBC Mutabakat Modülü

Bu proje, el terminali verileri (Excel/CSV) ile mağaza PDF çıktılarını deterministik bir şekilde karşılaştıran hızlı ve güvenli bir veri mutabakatı (data reconciliation) modülüdür. 

%100 doğruluk gerektiren bu eşleştirme işleminde, LLM veya Vision yapay zeka modelleri yerine klasik küme (set) teorisi ve Regex kullanılmıştır. Bu sayede işlem maliyeti sıfıra indirgenmiş, halüsinasyon riski ortadan kaldırılmış ve işlem süresi 1 saniyenin altına düşürülmüştür.

Proje, **PHP 8.3** ve **Linux** sunucu ortamında çalışacak şekilde tasarlanmış ve **PHPStan Level 9** (en katı statik analiz seviyesi) kurallarına %100 uyumlu olarak geliştirilmiştir.

---

## 🚀 Özellikler

- **Gelişmiş Arayüz (Premium Dark Theme):** Mor neon aksanlar, sürükle-bırak dosya yükleme alanları ve pürüzsüz animasyonlar.
- **Dosya Bağımsız Hücre Tarama:** Excel/CSV dosyasındaki barkod veya irsaliye numarasının hangi sütunda olduğunu seçmenize gerek yoktur. Sistem tüm hücreleri tarayarak 16-20 haneli takip numaralarını Regex ile otomatik tespit eder.
- **Smalot PDF Parser Entegrasyonu:** Mağaza PDF çıktısı içindeki metin tabanlı verileri saniyeler içinde okur ve Regex filtrelemesinden geçirir.
- **Deterministik Eşleştirme:** `array_diff` ve `array_intersect` fonksiyonları ile:
  - **Tam Eşleşenler (Yeşil):** Her iki dosyada da bulunan barkodlar.
  - **Eksikler (Kırmızı):** El terminalinde okutulmuş fakat PDF'te olmayan barkodlar.
  - **Fazlalar (Sarı):** PDF'te olan fakat el terminalinde olmayan barkodlar.
- **Gelişmiş Raporlama Tablosu:** Sonuçları anlık olarak arama kutusuyla filtreleyebilir, "Sadece Eksikler", "Sadece Fazlalar" veya "Tümü" olarak gruplayabilirsiniz.
- **Tek Tıkla CSV Export:** Oluşan mutabakat raporunu tarayıcı tarafında veya sunucu tarafında anında CSV formatında indirebilirsiniz.
- **MySQL Geçmiş Rapor Desteği:** İsteğe bağlı olarak geçmiş mutabakat raporlarını kaydedebilir, arayüzden tek tıkla eski rapor detaylarına geri dönebilirsiniz.

---

## 🛠️ Teknolojiler

- **Backend:** PHP 8.3 (Strict Types & OOP)
- **Kütüphaneler:**
  - `smalot/pdfparser` (PDF okuma)
  - `phpoffice/phpspreadsheet` (Excel/CSV okuma)
- **Statik Analiz:** PHPStan Level 9 Uyumlu (`phpstan/phpstan`)
- **Frontend:** HTML5, Vanilla CSS3 (Custom Glassmorphism), Vanilla JS (ES6+)
- **Veritabanı:** MySQL (PDO API)

---

## 💻 Kurulum

### 1. Bağımlılıkları Yükleme
Projeyi klonladıktan sonra terminalde composer bağımlılıklarını kurun:
```bash
composer install
```

### 2. Dizin İzinleri
Yüklenen geçici dosyalar ve raporlar için `var/` klasörünün Linux sunucuda yazılabilir olduğundan emin olun:
```bash
chmod -R 775 var/
```

### 3. Veritabanı Yapılandırması (Opsiyonel)
Geçmiş raporları veritabanında saklamak istiyorsanız, proje kök dizinindeki `config.php` dosyasını düzenleyin:
```php
return [
    'db' => [
        'host' => 'localhost',
        'dbname' => 'hbc_mutabakat',
        'username' => 'sizin_kullanici_adiniz',
        'password' => 'sizin_sifreniz',
        'enabled' => true, // MySQL'i aktif etmek için true yapın
    ],
    // ...
];
```
*Not: MySQL aktif edildiğinde, gerekli tablo şeması (`reconciliations`) ilk çalıştırmada sistem tarafından otomatik olarak oluşturulacaktır.*

### 4. Sunucu Yayını
Web sunucunuzun (Apache/Nginx) belge kök dizinini (Document Root) projenin `public/` klasörü olarak ayarlayın.

---

## ⚙️ Statik Analiz (PHPStan Seviye 9)

Projedeki tüm backend kodları, PHP'nin en yüksek tip güvenliği standartlarında yazılmıştır. Test etmek için PHPStan'i çalıştırabilirsiniz:

```bash
vendor/bin/phpstan analyse --memory-limit 512M
```

Çıktı sonucu:
```text
Note: Using configuration file phpstan.neon.
 6/6 [============================] 100%

 [OK] No errors
```

---

## 📖 Kullanım Senaryosu

1. Tarayıcınızda uygulamayı açın.
2. Mağaza veya sevkiyat adı girin (örn: `Kadıköy Merkez Mağaza`).
3. Sol taraftaki alana el terminalinden alınan **Excel/CSV** dosyasını sürükleyin.
4. Sağ taraftaki alana kargo/mağaza **PDF** çıktısını sürükleyin.
5. **"Karşılaştırmayı Çalıştır"** butonuna basın.
6. Sonuçlar anında ekranın altında belirecektir. Buradan eksik/fazla kolileri inceleyebilir ve CSV olarak indirebilirsiniz.
