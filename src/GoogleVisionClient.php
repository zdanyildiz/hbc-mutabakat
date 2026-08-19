<?php

declare(strict_types=1);

namespace App;

class GoogleVisionClient
{
    private const VISION_API_URL = 'https://vision.googleapis.com/v1/images:annotate';

    public function __construct(
        private readonly string $apiKey
    ) {
        if (trim($this->apiKey) === '') {
            throw new \InvalidArgumentException("Google Cloud Vision API Anahtarı (.env GOOGLE_VISION_API_KEY) tanımlanmamış.");
        }
    }

    /**
     * Performs parallel asynchronous DOCUMENT_TEXT_DETECTION OCR on multiple image file paths.
     *
     * @param array<string> $imagePaths Array of absolute paths to page image files.
     * @return array<int, string> Array mapping page index (0-based) to full extracted text.
     * @throws \RuntimeException
     */
    public function annotateImages(array $imagePaths): array
    {
        $full = $this->annotateImagesFull($imagePaths);
        $results = [];
        foreach ($full as $idx => $page) {
            $results[$idx] = $page['text'];
        }
        return $results;
    }

    /**
     * Performs parallel asynchronous DOCUMENT_TEXT_DETECTION OCR on multiple image file paths
     * and returns, per page, both the flat reconstructed text AND the word-level bounding-box
     * data already present in the same API response (no extra cost/latency vs annotateImages()).
     *
     * @param array<string> $imagePaths Array of absolute paths to page image files.
     * @return array<int, array{text: string, words: array<array{text: string, cx: float, cy: float}>}>
     * @throws \RuntimeException
     */
    public function annotateImagesFull(array $imagePaths): array
    {
        if (empty($imagePaths)) {
            return [];
        }

        $results = [];
        $totalImages = count($imagePaths);
        \App\Logger::log("[GoogleVision] Toplam {$totalImages} sayfa için paralel Google Cloud Vision OCR başlatılıyor...");

        $endpoint = self::VISION_API_URL . '?key=' . urlencode($this->apiKey);

        // Process in batches of 16 to avoid exceeding Google request payload limits while keeping maximum speed
        $chunks = array_chunk($imagePaths, 16, true);

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $curlHandles = [];

            foreach ($chunk as $idx => $imgPath) {
                if (!file_exists($imgPath)) {
                    continue;
                }

                $imageData = file_get_contents($imgPath);
                if ($imageData === false) {
                    continue;
                }

                $base64Image = base64_encode($imageData);

                $payload = json_encode([
                    'requests' => [
                        [
                            'image' => [
                                'content' => $base64Image,
                            ],
                            'features' => [
                                [
                                    'type' => 'DOCUMENT_TEXT_DETECTION',
                                    'maxResults' => 1,
                                ],
                            ],
                            'imageContext' => [
                                'languageHints' => ['tr', 'en'],
                            ],
                        ],
                    ],
                ]);

                $ch = curl_init($endpoint);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json; charset=utf-8',
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                curl_multi_add_handle($mh, $ch);
                $curlHandles[$idx] = $ch;
            }

            // Execute concurrent requests
            $running = null;
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 0.1);
                }
            } while ($running > 0 && $status === CURLM_OK);

            // Collect responses
            foreach ($curlHandles as $idx => $ch) {
                $response = curl_multi_getcontent($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($err !== '') {
                    \App\Logger::log("[GoogleVision] Sayfa {$idx} cURL Hatası: {$err}");
                    $results[$idx] = ['text' => '', 'words' => []];
                    continue;
                }

                if ($httpCode !== 200) {
                    \App\Logger::log("[GoogleVision] Sayfa {$idx} HTTP Hata {$httpCode}: " . substr((string)$response, 0, 300));
                    $results[$idx] = ['text' => '', 'words' => []];
                    continue;
                }

                $data = json_decode((string)$response, true);
                if (is_array($data) && isset($data['responses'][0])) {
                    $pageResp = $data['responses'][0];
                    if (isset($pageResp['error'])) {
                        \App\Logger::log("[GoogleVision] Sayfa {$idx} API Hatası: " . $pageResp['error']['message']);
                        $results[$idx] = ['text' => '', 'words' => []];
                    } else {
                        $fta = $pageResp['fullTextAnnotation'] ?? [];
                        $extractedText = $fta['text'] ?? '';
                        $words = $this->extractWords($fta);
                        $results[$idx] = ['text' => $extractedText, 'words' => $words];
                        \App\Logger::log("[GoogleVision] Sayfa " . ($idx + 1) . " OCR tamamlandı (" . strlen($extractedText) . " karakter, " . count($words) . " kelime)");
                    }
                } else {
                    $results[$idx] = ['text' => '', 'words' => []];
                }
            }

            curl_multi_close($mh);
        }

        ksort($results);
        return $results;
    }

    /**
     * Walks the fullTextAnnotation's pages -> blocks -> paragraphs -> words hierarchy and
     * flattens it into a simple list of {text, cx, cy} entries (word text + bounding-box
     * center in absolute pixel coordinates of the rendered page image).
     *
     * @param array<mixed> $fullTextAnnotation
     * @return array<array{text: string, cx: float, cy: float}>
     */
    private function extractWords(array $fullTextAnnotation): array
    {
        $words = [];

        foreach (($fullTextAnnotation['pages'] ?? []) as $page) {
            foreach (($page['blocks'] ?? []) as $block) {
                foreach (($block['paragraphs'] ?? []) as $paragraph) {
                    foreach (($paragraph['words'] ?? []) as $word) {
                        $wordText = '';
                        foreach (($word['symbols'] ?? []) as $symbol) {
                            $wordText .= $symbol['text'] ?? '';
                        }
                        if ($wordText === '') {
                            continue;
                        }

                        $vertices = $word['boundingBox']['vertices'] ?? [];
                        if (empty($vertices)) {
                            continue;
                        }

                        $xs = [];
                        $ys = [];
                        foreach ($vertices as $vertex) {
                            $xs[] = (float)($vertex['x'] ?? 0);
                            $ys[] = (float)($vertex['y'] ?? 0);
                        }

                        $words[] = [
                            'text' => $wordText,
                            'cx' => array_sum($xs) / max(1, count($xs)),
                            'cy' => array_sum($ys) / max(1, count($ys)),
                        ];
                    }
                }
            }
        }

        return $words;
    }
}
