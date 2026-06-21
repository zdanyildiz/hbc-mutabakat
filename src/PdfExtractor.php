<?php

declare(strict_types=1);

namespace App;

use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\TesseractOCR;

class PdfExtractor
{
    /** @var array<string, string> */
    private array $barcodeToOriginalMap = [];

    /**
     * @var array<array{line_number: int, line_text: string, detected_barcodes: array<string>}>
     */
    private array $mismatches = [];

    private bool $useOcr = false;

    public function setUseOcr(bool $useOcr): void
    {
        $this->useOcr = $useOcr;
    }

    public function isUseOcr(): bool
    {
        return $this->useOcr;
    }

    /**
     * Retrieves the map of extracted clean barcodes to their original raw words in PDF.
     *
     * @return array<string, string>
     */
    public function getBarcodeToOriginalMap(): array
    {
        return $this->barcodeToOriginalMap;
    }

    /**
     * Retrieves the list of detected barcode mismatches on a single PDF line.
     *
     * @return array<array{line_number: int, line_text: string, detected_barcodes: array<string>}>
     */
    public function getMismatches(): array
    {
        return $this->mismatches;
    }

    /**
     * Extracts barcode/tracking numbers from a PDF file.
     *
     * @param string $filePath
     * @return array<string>
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function extract(string $filePath): array
    {
        if ($this->useOcr) {
            return $this->extractOcr($filePath);
        }

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("PDF dosyası bulunamadı: {$filePath}");
        }

        $pdfStart = microtime(true);
        \App\Logger::log("[PdfExtractor-Text] PDF okuma başladı: " . basename($filePath));

        $text = '';
        $usedPdftotext = false;

        // 1. Yol: pdftotext CLI aracını dene (C tabanlı olduğu için Smalot'a göre 100 kat daha hızlıdır ve sıfır RAM tüketir)
        $checkCommand = PHP_OS_FAMILY === 'Windows' ? 'where pdftotext' : 'which pdftotext';
        $hasPdftotext = shell_exec($checkCommand);

        if ($hasPdftotext !== null && trim($hasPdftotext) !== '') {
            $pdftotextStart = microtime(true);
            // -layout parametresi satır yapısını korur, satır tutarsızlığı kontrolleri için gereklidir
            $output = shell_exec('pdftotext -layout ' . escapeshellarg($filePath) . ' -');
            if ($output !== null) {
                $text = $output;
                $usedPdftotext = true;
                $pdftotextElapsed = round(microtime(true) - $pdftotextStart, 4);
                \App\Logger::log("[PdfExtractor-Text] C++ pdftotext aracı kullanıldı - Süre: {$pdftotextElapsed} saniye");
            }
        }

        // 2. Yol (Fallback): pdftotext yoksa Smalot PdfParser kullan
        if (!$usedPdftotext) {
            \App\Logger::log("[PdfExtractor-Text] UYARI: pdftotext bulunamadı veya çalışmadı, Smalot PDF Parser fallback devreye giriyor.");
            $smalotStart = microtime(true);
            $parser = new Parser();
            try {
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText();
                $smalotElapsed = round(microtime(true) - $smalotStart, 4);
                \App\Logger::log("[PdfExtractor-Text] Smalot PDF Parser kullanıldı - Süre: {$smalotElapsed} saniye");
            } catch (\Exception $e) {
                \App\Logger::log("[PdfExtractor-Text] Smalot HATA: " . $e->getMessage());
                throw new \RuntimeException("PDF dosyası ayrıştırılamadı: " . $e->getMessage());
            }
        }

        $barcodes = [];
        $this->mismatches = [];
        $lines = explode("\n", $text);

        // Common OCR character mappings for scanned documents
        $ocrMap = [
            'l' => '1',
            'ı' => '1',
            'I' => '1',
            'i' => '1',
            '!' => '1',
            '[' => '1',
            ']' => '1',
            'B' => '8',
            'M' => '0',
            'O' => '0',
            'o' => '0',
            'E' => '8',
            'S' => '5',
            's' => '5',
            'ü' => '4',
        ];

        foreach ($lines as $lineIndex => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Extract candidate barcodes (allowing spaces/hyphens and OCR characters)
            preg_match_all('/\b(?:[A-Za-z0-9\x{0131}\x{0130}!\[\]][\s-]*){14,30}\b/u', $line, $matches);

            /** @var array<string> $lineBarcodes */
            $lineBarcodes = [];
            /** @var array<string> $lineWords */
            $lineWords = [];

            if (!empty($matches[0])) {
                foreach ($matches[0] as $matchedWord) {
                    $matchedWord = trim($matchedWord);
                    if ($matchedWord === '') {
                        continue;
                    }

                    // 1) Apply OCR character mapping
                    $converted = strtr($matchedWord, $ocrMap);

                    // 2) Remove any non-digit characters
                    $cleaned = preg_replace('/\D/', '', $converted);

                    // 3) Validate if it fits tracking barcode lengths (16 to 20 digits)
                    if ($cleaned !== null && strlen($cleaned) >= 16 && strlen($cleaned) <= 20) {
                        $lineBarcodes[] = $cleaned;
                        $lineWords[] = $matchedWord;
                    }
                }
            }

            $uniqueBarcodes = array_values(array_unique($lineBarcodes));
            $countUnique = count($uniqueBarcodes);

            if ($countUnique === 1) {
                // Sadece tek bir barkod türü bulundu (tekrar etse bile aynı değer)
                $barcode = $uniqueBarcodes[0];
                $barcodes[] = $barcode;
                
                // Orijinal kelimeleri eşleştirelim
                foreach ($lineBarcodes as $idx => $cleanedB) {
                    if ($cleanedB === $barcode) {
                        $this->barcodeToOriginalMap[$barcode] = $lineWords[$idx];
                        break;
                    }
                }
            } elseif ($countUnique > 1) {
                // Bir satırda birden fazla farklı barkod bulundu! Bu bir tutarsızlıktır!
                $this->mismatches[] = [
                    'line_number' => $lineIndex + 1,
                    'line_text' => $line,
                    'detected_barcodes' => $uniqueBarcodes,
                ];
                
                // Tutarsızlık olsa bile, tedbir amaçlı her iki barkodu da geçerli kabul edip listeye ekleyelim
                foreach ($uniqueBarcodes as $barcode) {
                    $barcodes[] = $barcode;
                    
                    // Orijinal kelimeleri eşleştirelim
                    foreach ($lineBarcodes as $idx => $cleanedB) {
                        if ($cleanedB === $barcode) {
                            $this->barcodeToOriginalMap[$barcode] = $lineWords[$idx];
                            break;
                        }
                    }
                }
            }
        }

        $uniqueBarcodes = array_values(array_unique($barcodes));
        $elapsed = round(microtime(true) - $pdfStart, 4);
        \App\Logger::log("[PdfExtractor-Text] Tamamlandı - Süre: {$elapsed} saniye | Toplam Satır: " . count($lines) . " | Benzersiz Barkod: " . count($uniqueBarcodes));

        // Remove duplicates and re-index
        return $uniqueBarcodes;
    }

    /**
     * Extracts store name from PDF file.
     *
     * @param string $filePath
     * @return string
     */
    public function extractStoreName(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return 'Bilinmeyen Mağaza';
        }

        $parser = new Parser();
        try {
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();
        } catch (\Exception $e) {
            return 'Bilinmeyen Mağaza';
        }

        $lines = explode("\n", $text);
        
        $foundStoreLabel = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($foundStoreLabel) {
                if (preg_match('/(mutabakat|tarih|belge|numara|rapor)/iu', $line)) {
                    continue;
                }
                
                // Fix OCR glitches in common store names (e.g. T3o&lsT -> T308)
                $cleanedStore = strtr($line, [
                    'T3o&lsT' => 'T308',
                    'o' => '0',
                    'O' => '0',
                    'l' => '1',
                ]);
                return $cleanedStore;
            }

            if (mb_stripos($line, 'Mağaza') !== false) {
                $foundStoreLabel = true;
            }
        }

        $filename = basename($filePath, '.pdf');
        if (preg_match('/^T\d+$/i', $filename)) {
            return strtoupper($filename);
        }

        return 'Bilinmeyen Mağaza';
    }

    /**
     * Extracts barcode/tracking numbers from a PDF file using OCR (Imagick + Tesseract).
     *
     * @param string $filePath
     * @return array<string>
     * @throws \RuntimeException
     */
    private function extractOcr(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("PDF dosyası bulunamadı: {$filePath}");
        }

        if (!class_exists('\Imagick')) {
            throw new \RuntimeException("PDF görsel dönüşümü için 'Imagick' PHP eklentisi kurulu olmalıdır.");
        }

        $ocrStart = microtime(true);
        \App\Logger::log("[PdfExtractor-OCR] OCR işlemi başladı: " . basename($filePath));

        $images = [];
        $tempDir = dirname(__DIR__) . '/var/tmp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        try {
            // PDF'in sayfa sayısını hızlıca öğrenmek için pingImage kullanıyoruz
            $pingImagick = new \Imagick();
            $pingImagick->pingImage($filePath);
            $pageCount = $pingImagick->getNumberImages();
            $pingImagick->clear();
            $pingImagick->destroy();

            \App\Logger::log("[PdfExtractor-OCR] Toplam Sayfa Sayısı: {$pageCount}");

            // Her sayfayı tek tek bellek dostu şekilde işliyoruz
            for ($i = 0; $i < $pageCount; $i++) {
                $pageStart = microtime(true);
                // Nginx/Apache bağlantısının kopmasını engellemek için veri akışını canlı tut
                echo " ";
                if (function_exists('ob_flush') && ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();

                $pageImagick = new \Imagick();
                // Kullanıcının isteği üzerine kesin okuma kalitesi için 300 DPI yapıldı
                $pageImagick->setResolution(300, 300);
                $pageImagick->readImage($filePath . '[' . $i . ']');

                // Ön işleme: Grayscale + Binarization (OCR doğruluğunu artırır)
                $pageImagick->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
                $pageImagick->thresholdImage(0.5 * \Imagick::getQuantum());
                $pageImagick->setImageFormat('png');

                $pagePath = $tempDir . '/page_' . uniqid() . '_' . $i . '.png';
                $pageImagick->writeImage($pagePath);
                $images[] = $pagePath;

                $pageImagick->clear();
                $pageImagick->destroy();

                $pageElapsed = round(microtime(true) - $pageStart, 4);
                \App\Logger::log("[PdfExtractor-OCR] Sayfa {$i} görsele çevrildi - Süre: {$pageElapsed} saniye");
            }
        } catch (\Exception $e) {
            \App\Logger::log("[PdfExtractor-OCR] Imagick HATA: " . $e->getMessage());
            // Hata durumunda oluşturulmuş geçici resimleri temizle
            foreach ($images as $img) {
                if (file_exists($img)) {
                    unlink($img);
                }
            }
            throw new \RuntimeException("PDF görsele dönüştürülürken hata oluştu: " . $e->getMessage());
        }

        $barcodes = [];
        $this->mismatches = [];

        // Common OCR character mappings for scanned documents
        $ocrMap = [
            'l' => '1',
            'ı' => '1',
            'I' => '1',
            'i' => '1',
            '!' => '1',
            '[' => '1',
            ']' => '1',
            'B' => '8',
            'M' => '0',
            'O' => '0',
            'o' => '0',
            'E' => '8',
            'S' => '5',
            's' => '5',
            'ü' => '4',
        ];

        foreach ($images as $index => $pagePath) {
            // Nginx/Apache bağlantısının kopmasını engellemek için veri akışını canlı tut
            echo " ";
            if (function_exists('ob_flush') && ob_get_level() > 0) {
                @ob_flush();
            }
            flush();

            try {
                $tessStart = microtime(true);
                $ocr = new TesseractOCR($pagePath);
                // @phpstan-ignore-next-line
                $ocr->lang('eng');
                // @phpstan-ignore-next-line
                $ocr->psm(6);
                $text = $ocr->run();

                // Okuma bittikten sonra geçici resmi temizle
                if (file_exists($pagePath)) {
                    unlink($pagePath);
                }

                $tessElapsed = round(microtime(true) - $tessStart, 4);
                \App\Logger::log("[PdfExtractor-OCR] Sayfa {$index} Tesseract okuması bitti - Süre: {$tessElapsed} saniye");

                $lines = explode("\n", $text);
                foreach ($lines as $lineIndex => $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    // Extract candidate barcodes (allowing spaces/hyphens and OCR characters)
                    preg_match_all('/\b(?:[A-Za-z0-9\x{0131}\x{0130}!\[\]][\s-]*){14,30}\b/u', $line, $matches);

                    /** @var array<string> $lineBarcodes */
                    $lineBarcodes = [];
                    /** @var array<string> $lineWords */
                    $lineWords = [];

                    if (!empty($matches[0])) {
                        foreach ($matches[0] as $matchedWord) {
                            $matchedWord = trim($matchedWord);
                            if ($matchedWord === '') {
                                continue;
                            }

                            // 1) Apply OCR character mapping
                            $converted = strtr($matchedWord, $ocrMap);

                            // 2) Remove any non-digit characters
                            $cleaned = preg_replace('/\D/', '', $converted);

                            // 3) Validate if it fits tracking barcode lengths (16 to 20 digits)
                            if ($cleaned !== null && strlen($cleaned) >= 16 && strlen($cleaned) <= 20) {
                                $lineBarcodes[] = $cleaned;
                                $lineWords[] = $matchedWord;
                            }
                        }
                    }

                    $uniqueBarcodes = array_values(array_unique($lineBarcodes));
                    $countUnique = count($uniqueBarcodes);

                    if ($countUnique === 1) {
                        $barcode = $uniqueBarcodes[0];
                        $barcodes[] = $barcode;
                        foreach ($lineBarcodes as $idx => $cleanedB) {
                            if ($cleanedB === $barcode) {
                                $this->barcodeToOriginalMap[$barcode] = $lineWords[$idx];
                                break;
                            }
                        }
                    } elseif ($countUnique > 1) {
                        $this->mismatches[] = [
                            'line_number' => $lineIndex + 1,
                            'line_text' => $line,
                            'detected_barcodes' => $uniqueBarcodes,
                        ];
                        foreach ($uniqueBarcodes as $barcode) {
                            $barcodes[] = $barcode;
                            foreach ($lineBarcodes as $idx => $cleanedB) {
                                  if ($cleanedB === $barcode) {
                                      $this->barcodeToOriginalMap[$barcode] = $lineWords[$idx];
                                      break;
                                  }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                \App\Logger::log("[PdfExtractor-OCR] UYARI: Sayfa {$index} okunamadı (Boş sayfa veya okuma hatası) - Hata: " . $e->getMessage());
                if (file_exists($pagePath)) {
                    unlink($pagePath);
                }
                // Hata veren sayfayı atlayıp sonraki sayfalarla devam ediyoruz
                continue;
            }
        }

        $uniqueBarcodes = array_values(array_unique($barcodes));
        $elapsed = round(microtime(true) - $ocrStart, 4);
        \App\Logger::log("[PdfExtractor-OCR] Tamamlandı - Toplam Süre: {$elapsed} saniye | Benzersiz Barkod: " . count($uniqueBarcodes));

        return $uniqueBarcodes;
    }
}
