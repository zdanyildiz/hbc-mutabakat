# 📋 HBC Mutabakat Performans Geliştirme Görevleri

Bu doküman, firmanın gerçekleştireceği testlerden sonra mutabakat işlem süresini (ortalama 2 dakika) **10 saniyenin altına indirmek** amacıyla uygulanacak donanımsal ve yazılımsal geliştirme görevlerini (task listesi) içerir.

---

## 🛠️ Görev 1: Sunucu vCPU Takviyesi ve Paralel OCR Worker Artırımı
* **Tür:** Donanım + Yapılandırma
* **Açıklama:** Mevcut sunucudaki 4 çekirdekli CPU yapısı, diğer yoğun çalışan `pozitif-photo` uygulamasıyla çakışmaması için en fazla 2 paralel worker (`max_workers = 2`) ile sınırlandırılmıştır. Sunucu işlemcisi 8 veya 16 vCPU'ya yükseltildiğinde bu limit artırılacaktır.
* **Yapılacak İşler:**
  1. Sunucu işlemcisini 8 vCPU veya 16 vCPU değerine yükseltin.
  2. [reconcile.py](file:///c:/Users/zdany/Projects/hbc-mutabakat/src/reconcile.py) dosyasındaki `max_workers` değerini güncelleyin:
     ```python
     # 8 vCPU için 5 veya 6 worker tanımlanabilir
     max_workers = min(6, len(page_images))
     ```
* **Beklenen Hız Kazanımı:** OCR süresinde **~%150 - %250** arasında doğrusal hızlanma.

---

## 🖼️ Görev 2: Görsel Ön İşlemede ImageMagick Yerine Python Pillow (PIL) Kullanımı
* **Tür:** Yazılım / Optimizasyon
* **Açıklama:** PDF sayfaları görsele dönüştürüldükten sonra Tesseract'a verilmeden önce ImageMagick (`convert` aracı) CLI üzerinden çağrılarak grileştirme ve keskinleştirme yapılmaktadır. Bu, her sayfa için işletim sisteminde ek bir `subprocess` yaratır.
* **Yapılacak İşler:**
  1. Python ortamına `Pillow` kütüphanesini yükleyin (`pip install Pillow`).
  2. [reconcile.py](file:///c:/Users/zdany/Projects/hbc-mutabakat/src/reconcile.py) içerisindeki `subprocess.run(["convert", ...])` kod bloğunu kaldırın.
  3. Görsel işleme adımlarını doğrudan Python belleğinde Pillow ile gerçekleştirin:
     ```python
     from PIL import Image, ImageEnhance, ImageFilter
     
     def preprocess_image(input_path, output_path):
         with Image.open(input_path) as img:
             gray = img.convert('L')
             enhanced = ImageEnhance.Contrast(gray).enhance(1.5)
             enhanced.filter(ImageFilter.SHARPEN).save(output_path)
     ```
* **Beklenen Hız Kazanımı:** Sayfa başına ~1-2 saniye CLI/Disk I/O tasarrufu.

---

## 🧠 Görev 3: Tesseract CLI Yerine Python C-API wrapper (`tesserocr`) Entegrasyonu
* **Tür:** Yazılım / Altyapı
* **Açıklama:** Şu anda her PDF sayfası için `tesseract <dosya> <cikti>` şeklinde harici bir komut çalıştırılmaktadır. Tesseract, her çağrıldığında RAM'e LSTM dil modellerini (birkaç on megabayt) baştan yükler.
* **Yapılacak İşler:**
  1. Sunucuya `tesseract-ocr` geliştirici paketlerini kurun: `sudo apt-get install libleptonica-dev libtesseract-dev tesseract-ocr`.
  2. Python projesine `tesserocr` paketini yükleyin (`pip install tesserocr`).
  3. `reconcile.py` dosyasını, dil modelini RAM'de tek bir kez başlatıp tüm sayfaları bu oturum üzerinden okuyacak şekilde güncelleyin:
     ```python
     import tesserocr
     from PIL import Image
     
     with tesserocr.PyTessBaseAPI(lang='tur+eng') as api:
         for img_path in page_images:
             api.SetImageFile(img_path)
             text = api.GetUTF8Text()
             # Sonuçları işle...
     ```
* **Beklenen Hız Kazanımı:** Sayfa başına **~2 saniye** (Dil modelinin tekrar tekrar yüklenmesi engellenir).

---

## 🎯 Görev 4: OCR Karakter Whitelist Filtresi Uygulanması
* **Tür:** Yazılım / OCR Hassasiyet ve Hız
* **Açıklama:** Tesseract varsayılan olarak Türkçe ve İngilizce tüm karakter setini (harfler, noktalama işaretleri vb.) tanımaya çalışır. Bizim aradığımız barkodlar sadece rakamlardan (veya çok kısıtlı karakterlerden) oluşmaktadır.
* **Yapılacak İşler:**
  1. Tesseract API veya CLI çağrısına rakam kısıtlaması (whitelist) ekleyin.
  2. `tesserocr` veya CLI parametrelerine `-c tessedit_char_whitelist=0123456789` parametresini geçirin:
     ```python
     # tesserocr için:
     api.SetVariable("tessedit_char_whitelist", "0123456789")
     ```
* **Beklenen Hız Kazanımı:** OCR analiz süresinde **~%30** kısalma ve harflerin rakamlara yanlış dönüştürülmesinde (örneğin I -> 1, O -> 0 dışındaki karmaşık eşleşme hatalarında) tam doğruluk.

---

## ⚡ Görev 5: Arayüze "Hızlı Metin Taraması (OCR'sız)" Seçeneği Eklenmesi
* **Tür:** Yazılım / Kullanıcı Deneyimi (UX)
* **Açıklama:** Dijital olarak üretilmiş ve taranmamış (yani doğrudan sistem çıktısı olan temiz PDF'ler) için OCR yapmaya gerek yoktur. Metin tabanlı okuma milisaniyeler sürer.
* **Yapılacak İşler:**
  1. Mutabakat yükleme ekranına `[ ] Sadece dijital metin taraması yap (Hızlı Mod)` checkbox'ı ekleyin.
  2. PHP tarafında bu değer `true` seçildiyse Python OCR aşamalarını tamamen atlayıp doğrudan `PdfExtractor` ile metin katmanını okuyun.
* **Beklenen Hız Kazanımı:** Dijital PDF'lerde mutabakat süresi 2 dakikadan **1 saniyeye** düşer.
