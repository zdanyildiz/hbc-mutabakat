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

        $parser = new Parser();
        try {
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();
        } catch (\Exception $e) {
            throw new \RuntimeException("PDF dosyası ayrıştırılamadı: " . $e->getMessage());
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

            // Split line by whitespace/tabs to process word by word
            $words = preg_split('/[\s\t]+/', $line);
            if ($words === false) {
                continue;
            }

            /** @var array<string> $lineBarcodes */
            $lineBarcodes = [];
            /** @var array<string> $lineWords */
            $lineWords = [];

            foreach ($words as $word) {
                $word = trim($word);
                if ($word === '') {
                    continue;
                }

                // 1) Apply OCR character mapping
                $converted = strtr($word, $ocrMap);

                // 2) Remove any non-digit characters
                $cleaned = preg_replace('/\D/', '', $converted);

                // 3) Validate if it fits tracking barcode lengths (16 to 20 digits)
                if ($cleaned !== null && strlen($cleaned) >= 16 && strlen($cleaned) <= 20) {
                    $lineBarcodes[] = $cleaned;
                    $lineWords[] = $word;
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

        // Remove duplicates and re-index
        return array_values(array_unique($barcodes));
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

        $images = [];
        try {
            $imagick = new \Imagick();
            $imagick->setResolution(300, 300);
            $imagick->readImage($filePath);

            $tempDir = dirname(__DIR__) . '/var/tmp';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            foreach ($imagick as $index => $page) {
                // Görüntü ön işleme: Grayscale + Binarization (OCR doğruluğunu artırır)
                $page->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
                $page->thresholdImage(0.5 * \Imagick::getQuantum());
                $page->setImageFormat('png');
                $pagePath = $tempDir . '/page_' . uniqid() . '_' . $index . '.png';
                $page->writeImage($pagePath);
                $images[] = $pagePath;
            }
            $imagick->clear();
        } catch (\Exception $e) {
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

        foreach ($images as $pagePath) {
            try {
                $ocr = new TesseractOCR($pagePath);
                // @phpstan-ignore-next-line
                $ocr->lang('tur', 'eng');
                // @phpstan-ignore-next-line
                $ocr->psm(6);
                // @phpstan-ignore-next-line
                $ocr->configFile('digits');
                $text = $ocr->run();

                // Okuma bittikten sonra geçici resmi temizle
                if (file_exists($pagePath)) {
                    unlink($pagePath);
                }

                $lines = explode("\n", $text);
                foreach ($lines as $lineIndex => $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $words = preg_split('/[\s\t]+/', $line);
                    if ($words === false) {
                        continue;
                    }

                    /** @var array<string> $lineBarcodes */
                    $lineBarcodes = [];
                    /** @var array<string> $lineWords */
                    $lineWords = [];

                    foreach ($words as $word) {
                        $word = trim($word);
                        if ($word === '') {
                            continue;
                        }

                        $converted = strtr($word, $ocrMap);
                        $cleaned = preg_replace('/\D/', '', $converted);

                        if ($cleaned !== null && strlen($cleaned) >= 16 && strlen($cleaned) <= 20) {
                            $lineBarcodes[] = $cleaned;
                            $lineWords[] = $word;
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
                if (file_exists($pagePath)) {
                    unlink($pagePath);
                }
                throw new \RuntimeException("OCR okuma hatası: " . $e->getMessage());
            }
        }

        return array_values(array_unique($barcodes));
    }
}
