# ⚡ HBC Mutabakat Performans ve Hızlandırma Yol Haritası

Bu doküman, OCR mutabakat sürecinin gelecekte 10 saniyelere indirilmesi veya sunucu kaynak tüketiminin daha da optimize edilmesi istendiğinde uygulanacak adımları içerir.

---

## 🚀 1. Çekirdek (vCPU) Takviyesi ve Paralelleştirme (Concurrency)
* **Mevcut Durum:** Sunucuda 4 çekirdek bulunmakta ve darboğazı önlemek amacıyla OCR worker limiti 2 (`max_workers = 2`) olarak rezerve edilmektedir.
* **Geliştirme Planı:** Sunucu işlemci çekirdek sayısı 8 veya 16 vCPU'ya yükseltildiğinde, Python `src/reconcile.py` içindeki parallel worker limiti artırılabilir:
  ```python
  # 8 çekirdekli sunucu için 6 worker (2 çekirdek pozitif-photo için rezerve)
  max_workers = min(6, len(page_images))
  ```
* **Etki:** 6 sayfanın aynı anda paralel işlenmesi, OCR süresini **doğrudan 3 kat** kısaltacaktır.

---

## 📷 2. Python Pillow (PIL) ile Bellek İçi Görsel Ön İşleme (Subprocess Kaldırma)
* **Mevcut Durum:** Her PDF sayfa görseli Tesseract'a verilmeden önce ImageMagick (`convert` aracı) ile filtrelenmektedir. Bu işlem her sayfa için ek bir subprocess (işlem) çağrısı başlatır ve CPU overhead oluşturur.
* **Geliştirme Planı:** `reconcile.py` içindeki ImageMagick subprocess çağrıları kaldırılacaktır. Bunun yerine Python'un yerel ve hızlı görsel kütüphanesi **Pillow (PIL)** entegre edilecektir:
  ```python
  from PIL import Image, ImageEnhance, ImageFilter

  def preprocess_image_pil(img_path: str, clean_img_path: str):
      with Image.open(img_path) as img:
          # Gri tonlama
          gray = img.convert('L')
          # Kontrast artırma
          contrast = ImageEnhance.Contrast(gray).enhance(1.5)
          # Keskinleştirme
          sharpened = contrast.filter(ImageFilter.SHARPEN)
          sharpened.save(clean_img_path)
  ```
* **Etki:** Görsel ön işleme doğrudan Python süreci içinde bellek üzerinde tamamlanacak, sayfa başına 1 subprocess çağrısı elenecek ve CPU yükü hafifleyecektir.

---

## 🧠 3. Tesseract Python C-API (tesserocr) Entegrasyonu
* **Mevcut Durum:** Her sayfa için işletim sisteminden `subprocess` ile `tesseract` komut satırı aracı çağrılmaktadır. Tesseract her çağrıda LSTM dil model dosyalarını diskten RAM'e yüklemek zorunda kalır (sayfa başına ~1-2 saniye I/O ve işlem maliyeti).
* **Geliştirme Planı:** Python'un Tesseract C++ API wrapper'ı olan **`tesserocr`** kütüphanesi kurulacaktır.
* **Uygulama:**
  ```python
  import tesserocr
  from PIL import Image

  # Dil modelini RAM'e bir kez yükle
  with tesserocr.PyTessBaseAPI(lang='tur+eng') as api:
      for img_path in page_images:
          api.SetImageFile(img_path)
          text = api.GetUTF8Text()
  ```
* **Etki:** Tesseract dil modeli RAM'e yalnızca 1 kez yüklenir, subprocess çağrıları tamamen sıfırlanır ve OCR hızı **%30 ila %50 arasında** artar.

---

## 📏 4. Seçici Metin Modu Checkbox Entegrasyonu (2 dk -> 1 sn)
* **Mevcut Durum:** Eşleşme başarısını garantilemek için sistem varsayılan olarak her zaman OCR (Tesseract) modunu kullanmaktadır.
* **Geliştirme Planı:** Eğer yüklenen PDF'lerin seçilebilir/dijital metin katmanı olduğu biliniyorsa, arayüze bir checkbox eklenerek OCR devre dışı bırakılabilir. Yeni eklenen bölünmüş barkod ve genişletilmiş CMap kurtarma algoritmaları sayesinde Text modu artık güvenilirdir.
* **Etki:** OCR devre dışı kaldığında mutabakat **1-2 saniye** içinde tamamlanır.
