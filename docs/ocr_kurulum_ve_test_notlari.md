# 🔧 OCR v2 Hattı — Sunucu Kurulum ve Test Notları

Bu doküman, Temmuz 2026'da yapılan OCR doğruluk geliştirmelerinin (TSV + güven skoru +
hedefli ikinci geçiş + RapidOCR hakem) canlı sunucuda devreye alınması için gereken
adımları içerir.

---

## 1. Zorunlu Bağımlılıklar (büyük ihtimalle zaten kurulu)

```bash
sudo apt-get install poppler-utils tesseract-ocr imagemagick
```

- `poppler-utils`: `pdfimages`, `pdftoppm`, `pdfinfo`, `pdftotext`
- `tesseract-ocr`: `eng` dil paketi varsayılan gelir; yeni hat **yalnızca `eng`**
  kullanır (rakam whitelist'i ile dil modelinin önemi yoktur, `eng` daha hızlı yüklenir
  ve her kurulumda mevcuttur — `tur` paketi artık gerekli değildir).
- `imagemagick`: `convert` aracı (deskew + filtre). Yoksa Pillow'a düşülür (deskew'suz).

## 2. Python Bağımlılıkları

```bash
# Zorunlu değil ama şiddetle önerilir: kırpma/ikinci geçiş bu kütüphaneyle çalışır
pip3 install Pillow

# Opsiyonel: uyuşmazlıklarda üçüncü okuma yapan yapay zekâ hakem motoru (~60 MB model,
# CPU'da çalışır, ilk yüklemede ~1-2 sn; sayfa başına değil süreç başına 1 kez yüklenir)
pip3 install rapidocr-onnxruntime
```

- **Pillow yoksa:** ana OCR çalışır, ancak düşük güvenli satırların kırpılıp psm 7 ile
  ikinci kez okunması atlanır (doğruluk kazancının önemli kısmı kaybolur).
- **RapidOCR yoksa:** sistem sorunsuz çalışır; ikinci geçiş alternatif okumaları yine
  üretilir, yalnızca hakem teyidi yapılmaz. Kurulumu ONNX Runtime'ı da getirir; başka
  hiçbir framework (PyTorch/Paddle) gerekmez.

## 3. Yeni Hattın Ne Yaptığı (özet)

1. **Kayıpsız görüntü:** Taranmış PDF'lerde sayfa artık `pdftoppm` ile yeniden render
   edilmez; gömülü orijinal tarama `pdfimages` ile çıkarılır (yeniden örnekleme
   bulanıklığı yok, daha hızlı). Dijital/dönük sayfalarda otomatik olarak eski yönteme
   düşülür.
2. **Akıllı ön işleme:** 1-bit (siyah/beyaz faks modu) taramalar tespit edilir ve
   kontrast/keskinlik yerine kenar yumuşatma uygulanır; tüm sayfalara deskew yapılır.
3. **TSV + güven skoru:** Tesseract'tan düz metin yerine kelime koordinatları ve güven
   skorları alınır; satırlar koordinatlarla kurulur.
4. **Hedefli ikinci geçiş:** Düşük güvenli barkod satırları kırpılıp 3x büyütülerek tek
   satır modunda (psm 7) yeniden okunur. Farklı okunan satırlar "alternatif okuma"
   olarak eşleştiriciye taşınır; RapidOCR varsa üçüncü bağımsız okuma eklenir.
5. **Güvene duyarlı eşleştirme (PHP):** Eşleştirici tüm alternatif okumaları da dener;
   yüksek güvenle (>=95) okunmuş satırlardan gelen adaylar, gerçek hane hatası içeren
   şüpheli eşleşme olarak önerilmez.
6. **OCR önbelleği:** Aynı PDF (içerik hash'i) tekrar işlenirse OCR atlanır
   (`var/cache/ocr_*.json`, cron ile 7 günde temizlenir). Tek istek içinde bile mağaza
   haritası çıkarımı ikinci okuma yaptığı için süre kazancı anında görülür.
7. **Düşük öncelik:** Python OCR süreci `nice -n 10` ile çalışır; pozitif-photo ve web
   istekleri CPU önceliğini korur.

## 4. Test Önerisi

Aynı Excel + PDF çiftini eski ve yeni sürümle karşılaştırın; `var/logs/app.log`
içinde şu yeni kayıtlar görünmelidir:

```
OCR_PROGRESS: Gömülü tarama görüntüleri kayıpsız çıkarıldı (N sayfa).
OCR_PROGRESS: Sayfa X: Y düşük güvenli satır ikinci geçişle okundu.
OCR_PROGRESS: RapidOCR hakem motoru yüklendi.          (rapidocr kuruluysa)
[PdfExtractor-Python] Önbellekten okundu - Mod: ocr    (ikinci çalıştırmada)
```

İlk çalıştırma süresi eskiyle benzer olmalı (ikinci geçiş kırpmaları küçüktür);
aynı PDF'in ikinci çalıştırması önbellek sayesinde saniyeler içinde bitmelidir.

## 5. Tarayıcı Ayarı Önerisi (mağazalara iletilecek)

Yeni örnek (`Image_002.pdf`) 300 DPI ancak **1-bit siyah/beyaz (faks modu)** taranmış.
Sistem bunu artık tespit edip telafi ediyor; yine de mümkünse tarayıcıda:

- **Renk modu: Gri tonlama (Grayscale)** — "Siyah-Beyaz/Black&White değil"
- Çözünürlük: 300 DPI (mevcut ayar doğru)
- Otomatik eğrilik düzeltme (deskew) açık olabilir

Gri tonlamalı tarama, rakam kenarlarındaki bilgiyi koruduğu için OCR hatasını en çok
azaltan tek ayardır.

## 6. Sonraki Adım (bu turda yapılmadı)

Eşzamanlı kullanıcı sayısı arttığında OCR'ın HTTP isteğinden çıkarılıp tek worker'lı
bir iş kuyruğuna (systemd servisi + `CPUQuota=200%`) taşınması planlanmalıdır; böylece
kaç kullanıcı yüklerse yüklesin sunucuda aynı anda en fazla bir OCR işi çalışır ve
pozitif-photo'ya 2 çekirdek garanti edilir. Test sonuçlarına göre değerlendirilecek.
