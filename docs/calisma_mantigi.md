# ⚡ HBC Mutabakat Modülü Çalışma Mantığı Dokümantasyonu

Bu doküman, sistemin Excel ve PDF dosyalarını yüklediği andan itibaren son çıktının üretilmesine kadar geçen tüm süreci adım adım ve aktör bazlı (PHP, Python, JavaScript) açıklamaktadır.

---

## 🔄 Genel Akış Şeması (Adım Adım)

```mermaid
graph TD
    A[Kullanıcı Arayüzü] -->|1. Dosyaları Gönder| B[public/index.php]
    B -->|2. Excel Okuma| C[ExcelExtractor.php]
    B -->|3. PDF Okuma Talebi| D[PdfExtractor.php]
    D -->|4. OCR Çalıştır| E[reconcile.py (Python)]
    E -->|5. Sayfaları PNG'ye Çevir| F[pdftoppm]
    F -->|6. Karakter Okuma| G[Tesseract OCR]
    G -->|7. Ham Satırları Döndür| E
    E -->|8. JSON Ham Satırlar| D
    B -->|9. Eşleştirme Başlat| H[Reconciler.php]
    H -->|10. Karşılaştır ve Eşle| H
    B -->|11. JSON Rapor| A
```

---

## 👥 Aktörler ve Rolleri

### 1. Frontend: Tarayıcı ve Arayüz Katmanı (`public/assets/js/app.js`)
* **Dosya Yönetimi:** Sürükle-bırak veya dosya seçici alanları kontrol eder.
* **İstek Gönderimi:** Kullanıcı "Karşılaştırmayı Çalıştır" butonuna bastığında, yüklenen Excel ve PDF dosyalarını AJAX (fetch) kullanarak arka plana (`index.php?action=reconcile`) gönderir.
* **Sonuçları Çizme (Rendering):** PHP'den gelen mutabakat sonuç raporunu (JSON) alır; Yeşil (Eşleşti), Kırmızı (Eksik), Sarı (Fazla) ve Şüpheli kartlarını ve arama/filtreleme yapılabilen veri tablosunu ekrana dinamik olarak basar.
* **Manuel Onay:** Kullanıcının "Şüpheli" barkodları elle onaylayıp "Eşleşti" durumuna çekmesini tarayıcı tarafında yönetir.

---

### 2. Backend Controller: İstek Karşılayıcı (`public/index.php`)
* **Giriş Kontrolü:** Dosyaların yüklenip yüklenmediğini ve formatlarını kontrol eder.
* **İş Akışı Yönetimi:** `ExcelExtractor`, `PdfExtractor` ve `Reconciler` sınıflarını sırayla tetikler.
* **Veritabanı Kayıt:** Eğer MySQL aktif ise, mutabakat sonuçlarını geçmiş raporlara kaydeder.
* **Çıktı Üretim:** Tüm işlemler bittiğinde oluşan nihai verileri JSON formatında frontend'e (JavaScript) teslim eder.

---

### 3. Veri Okuyucu: Excel Çözücü (`src/ExcelExtractor.php`)
* Excel/CSV dosyasını açar.
* **Sabit Yapı:** Doğrudan **`A` sütunundaki barkodları** ve **`F` sütunundaki mağaza adlarını** okur.
* Sayı formatı bozulmalarını engellemek için saf metin olarak okur, sayı dışındaki tüm karakterleri temizleyip PHP'ye temiz bir barkod listesi sunar.

### 4. OCR Motoru: Python Metin Çıkarıcı (`src/reconcile.py`)
> [!IMPORTANT]
> **Python Eşleştirme Yapmaz!** Python scriptinin tek görevi, PDF dosyasını alıp içindeki yazıları OCR ile okuyarak satır satır ham metin halinde PHP'ye teslim etmektir. Excel barkodlarıyla hiçbir bağı kurmaz veya karşılaştırma yapmaz.

* **Kayıpsız Görüntü Edinimi:** Taranmış PDF'lerde gömülü orijinal tarama görüntüsü `pdfimages` ile doğrudan çıkarılır (yeniden örnekleme bulanıklığı oluşmaz). Sayfa döndürülmüşse veya PDF dijital ise `pdftoppm` (300 DPI) ile render'a düşülür.
* **Akıllı Ön İşleme:** 1-bit (siyah/beyaz faks modu) taramalar tespit edilir ve kenar yumuşatma uygulanır; gri/renkli taramalarda kontrast + keskinleştirme yapılır. Tüm sayfalara eğrilik düzeltme (deskew) uygulanır.
* **TSV Okuması (Koordinat + Güven):** Tesseract **`--psm 6`** ve rakam whitelist'i ile **TSV modunda** çalıştırılır; her kelimenin konumu ve güven skoru alınır, satırlar koordinatlarla kurulur.
* **Hedefli İkinci Geçiş:** Düşük güvenli (conf < 88) barkod satırları kırpılıp 3x büyütülerek tek satır modunda (**`--psm 7`**) yeniden okunur. Farklı okunan satırların ikinci okuması **alternatif okuma** olarak eklenir; kuruluysa **RapidOCR** hakem motoru üçüncü bağımsız okumayı üretir.
* Satır listesi + satır başına meta (`conf`, `alts`) JSON formatında PHP'ye geri döndürülür.

### 5. Eşleştirme Motoru: Eşleştirici (`src/Reconciler.php`)
> [!NOTE]
> Eşleştirme ve karşılaştırma mantığının tamamı **PHP** tarafında çalışır.

* **1. Aşama (OCR Eşleme & Bölünmüş Satır Kurtarma):**
  * Excel'den okunan her barkodu alır.
  * Python'dan gelen OCR satır listesinde bu barkodun geçip geçmediğini kontrol eder. Her satır için hem birincil okuma hem de ikinci geçiş / RapidOCR'dan gelen **alternatif okumalar** denenir.
  * **Akıllı Kurtarma (OCR):** Eğer tam eşleşme bulunamazsa, barkodun bölünüp bölünmediği kontrol edilir. Barkodun ilk kısmı (prefix) bir satırda, son haneleri (suffix, 1-4 karakter) hemen ardındaki satırlarda yer alıyorsa bu iki parça birleştirilerek barkod kurtarılır ve satırlar havuzdan silinir.
  * Başarıyla eşleşen barkodlar **"Eşleşti (Yeşil)"** listesine eklenir. Bulunamazsa geçici olarak **"Eksik (Kırmızı)"** listesine yazılır.

* **2. Aşama (Metin Tabanlı Arama Fallback & Bölünmüş Arama):**
  * 1. aşama sonunda hala "Eksik" görünen barkodlar için ikinci bir arama başlatır.
  * Bu kez PDF'in C++ `pdftotext` (veya PHP Smalot) ile çıkarılmış saf metin katmanına odaklanır.
  * Buradaki satırlardaki bozuk karakterleri (`l => 1`, `E => 8`, `O => 0`, `B => 8`, `[` => 1 vb.) genişletilmiş karakter haritasıyla düzeltir ve sayı dışı karakterleri arındırır.
  * **Akıllı Kurtarma (Metin):** Metin katmanındaki CMap encoding ve tablo yapısı nedeniyle bölünmüş barkodlar bölünmüş arama mantığıyla kurtarılır.
  * Eğer bu düzeltilmiş ve kurtarılmış satır içinde eksik barkod bulunursa, onu eksik listesinden çıkarıp **"Eşleşti (Yeşil)"** listesine taşır.

* **3. Aşama (Glif-Ağırlıklı Fuzzy, Güvene Duyarlı):**
  * Hâlâ eksik görünen barkodlar, "fazla" adaylarıyla glif karışma tablosu üzerinden 1:1 eşlenir.
  * Kaynak satır yüksek güvenle (conf >= 95) okunmuşsa ve fark gerçek (glif-dışı) hane hatası içeriyorsa aday **şüpheli olarak önerilmez** — büyük ihtimalle gerçekten farklı bir kolidir.

* **Sonuç Belirleme:**
  * Excel'de olup PDF'te hiçbir şekilde bulunamayanlar: **Eksik (Kırmızı)**
  * PDF'te okunup Excel'de karşılığı olmayanlar: **Fazla (Sarı)**
  * Her iki tarafta da doğrulananlar: **Eşleşti (Yeşil)**

* **Önbellek:** Aynı PDF (içerik hash'i) tekrar yüklendiğinde OCR atlanır; sonuç `var/cache/ocr_*.json` üzerinden okunur (cron 7 günde temizler). Python OCR süreçleri `nice -n 10` ile düşük CPU önceliğinde çalışır.
