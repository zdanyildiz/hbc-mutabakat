# ⚡ HBC Mutabakat Modülü Çalışma Mantığı Dokümantasyonu

Bu doküman, HBC Mutabakat Modülü'nün geliştirme süreci öncesindeki çalışma mantığı ile yapılan iyileştirmeler sonrasındaki güncel çalışma mantığını açıklamaktadır.

---

## 1. Önceki Çalışma Mantığı

### Excel / CSV Okuma ve Eşleme
* **Dinamik Sütun Keşfi:** Excel dosyasının ilk 10 satırı taranarak başlığında `"barkod"` ve `"mağaza"` kelimeleri geçen sütunlar dinamik olarak tespit edilmeye çalışılıyordu.
* **Sütun Ezilme Hatası:** Excel şablonunda birden fazla barkod içeren sütun bulunduğunda (Örn: A sütununda `"Barkod"`, B sütununda `"Müşteri Barkod"`), dinamik döngü son eşleşen sütunu (B sütununu) hafızaya alıyor ve A sütunundaki verilerin haritalanamamasına neden oluyordu. Bu durum, arayüzde birçok barkod için jenerik `"Bilinmeyen Mağaza"` (fallback) değerinin gösterilmesine yol açıyordu.
* **Gevşek Hata Yönetimi:** Mağaza hücresi boş olduğunda sistem sessizce hata yerine `"Bilinmeyen Mağaza"` atayarak hatanın kaynağının tespit edilmesini zorlaştırıyordu.

### PDF Okuma ve Eşleştirme
* **Kelime Bazlı Regex Tarama:** PDF metni basitçe kelimelere ayrıştırılıyor ve 16-20 hane arasındaki sayılar Regex ile çekiliyordu.
* **Font Encoding / OCR Hataları:** Fatura/İrsaliye üreten yazılımların PDF oluştururken yaptığı font gömme hataları (Örn: ekranda `4` görünen sayının kod katmanında `ü` harfine, `1` sayısının `l` harfine dönüşmesi) sebebiyle barkodlar PDF içinde bulunamıyor ve aslen mevcut olan koliler "Eksik" (Kırmızı) olarak listeleniyordu.
* **Çapraz Doğrulama Yoktu:** PDF içerisindeki satırlarda aynı barkodun birden fazla sütunda (`TemaTakipNo` ve `Kargo Takip No`) yer alması durumu kontrol edilmiyor, satır doğruluğu teyit edilmiyordu.

---

## 2. Güncel Çalışma Mantığı (Yeni Sistem)

### Excel / CSV Okuma ve Eşleme (Sabit & Güvenli)
* **Sabit Sütun Mimarisi:** Excel şablonu standart olduğu için gereksiz dinamik sütun aramaları kaldırılmıştır. Sistem doğrudan ve deterministik olarak **`A` sütununu "Barkod"**, **`F` sütununu ise "Mağaza Adı"** olarak kabul eder.
* **Biçimlendirme ve Veri Temizliği:** PHP'nin büyük sayıları float/scientific notation formatına (Örn: `1.63E+17`) dönüştürmesini engellemek için `getFormattedValue()` kullanılarak hücredeki saf metin çekilir ve sayı dışı tüm karakterler (`\D` regexi ile) arındırılır.
* **Açık Satır Hataları:** Eğer Excel'de barkodun karşısındaki mağaza alanı boş bırakılmışsa, mutabakatın durmaması için ilgili barkoda doğrudan **`[Mağaza Adı Belirtilmemiş (Satır X)]`** değeri atanır. Böylece veri hatasının Excel'deki tam satır numarası arayüzde listelenir.

### PDF Okuma ve Eşleştirme (Çift Modlu & Çapraz Doğrulamalı)

Modül, sunucu yeteneklerine ve kullanıcının seçimine göre çalışabilen iki farklı okuma moduna sahiptir:

#### A) Standart Hızlı Mod (Metin Tabanlı)
* **Satır Bazlı Ayrıştırma:** PDF metni satır satır analiz edilir. Her satırdaki `TemaTakipNo` ve `Kargo Takip No` alanlarında yer alan barkodlar bağımsız olarak taranır.
* **Satır Çapraz Doğrulaması (Cross-Validation):** Aynı satırda yer alan bu iki barkodun (dönüştürme ve temizlik sonrasında) birbirine eşit olup olmadığı kontrol edilir.
  * Eğer iki barkod birbirinden farklıysa (Örn: satırdaki kaymalar veya ağır bir kodlama hatası yüzünden), bu durum bir **"PDF Satır Tutarsızlığı"** olarak işaretlenir.
  * Arayüzde neon kırmızı renkli bir uyarı kartı belirerek uyuşmazlığın yaşandığı **PDF Satır Numarasını, Orijinal Satır İçeriğini ve Okunan İki Farklı Barkodu** listeler.
* **Kopyalanabilir PDF Arama Kodu:** Karakter kayması yaşayan barkodlar (Örn: `l60056792000ü4202`) için arayüzde kopyalanabilir alan oluşturulur. Kullanıcı bu koda tıklayıp panoya kopyalayarak PDF okuyucusunda arattığında doğrudan ilgili hatalı satıra ulaşabilir.

#### B) Görsel OCR Modu (Hassas & Kesin Sonuç)
* Arayüzden **"Görsel OCR Modu"** aktif edildiğinde tetiklenir.
* **Görsel Dönüşümü:** PDF sayfaları sunucuda kurulu olan `Imagick` kütüphanesi yardımıyla yüksek çözünürlüklü (150 DPI) PNG resimlerine dönüştürülür.
* **Tesseract OCR Okuması:** Resim dosyaları sunucu tarafında çalışan yerel `Tesseract OCR` motoruna gönderilerek Türkçe ve İngilizce dil paketleriyle analiz edilir.
* **Neden %100 Doğru?** Tesseract OCR, PDF'in arka planındaki bozuk font kodlama (encoding) katmanına değil, doğrudan **görsel piksel yapısına** baktığı için ekrandaki `4` sayısını tam olarak `4` olarak okur. Font hatalarından kaynaklı sapmalar (`ü` veya `l` harfleri) tamamen bypass edilmiş olur.
* Okuma işlemi tamamlandığında sunucudaki geçici PNG resimleri otomatik olarak silinir.
