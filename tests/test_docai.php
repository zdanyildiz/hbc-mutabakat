<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\GoogleDocumentAiClient;

$fullText = "Mağaza: T285-EDR SARACLAR CD\n" .
            "Belge Numarası: 10087\n" .
            "Sıra\tTema Takip No\tToplama ID / Koli ID\tİrs. Seri / No\tİrs. Tarih\tSevkId\tGönd. Depo\tKargo Firma\tKargoTakipNo / ATF No\n" .
            "1\t160046392000113692\t0-0\tIAC2026000003266\t4.08.2026\t116095\tT251\tTalha Nakliyat\t\n" .
            "2\t163417006017248296\t158188000-1518\tLLI202600107337 7\t6.08.2026\t17052150\tERM002\tÇelik Nakliyat\t163417006017248296\n";

/**
 * @return array{startIndex: string, endIndex: string}
 */
function getSeg(string $fullText, string $needle): array
{
    $start = mb_strpos($fullText, $needle, 0, 'UTF-8');
    if ($start === false) {
        throw new \RuntimeException("Needle not found: {$needle}");
    }
    $end = $start + mb_strlen($needle, 'UTF-8');
    return ['startIndex' => (string)$start, 'endIndex' => (string)$end];
}

$mockDocAiResponse = [
    'text' => $fullText,
    'pages' => [
        [
            'pageNumber' => 1,
            'formFields' => [
                [
                    'fieldName' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'Mağaza:')]]],
                    'fieldValue' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'T285-EDR SARACLAR CD')]]],
                ],
                [
                    'fieldName' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'Belge Numarası:')]]],
                    'fieldValue' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '10087')]]],
                ],
            ],
            'tables' => [
                [
                    'headerRows' => [
                        [
                            'cells' => [
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'Sıra')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'Tema Takip No')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'Toplama ID / Koli ID')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'İrs. Seri / No')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'İrs. Tarih')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'SevkId')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'Gönd. Depo')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'Kargo Firma')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'KargoTakipNo / ATF No')]]]],
                            ],
                        ],
                    ],
                    'bodyRows' => [
                        [
                            'cells' => [
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '1')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '160046392000113692')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '0-0')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'IAC2026000003266')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '4.08.2026')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '116095')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'T251')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'Talha Nakliyat')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => []]]],
                            ],
                        ],
                        [
                            'cells' => [
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '2')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '163417006017248296')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '158188000-1518')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'LLI202600107337 7')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '6.08.2026')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '17052150')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'ERM002')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, 'Çelik Nakliyat')]]]],
                                ['layout' => ['textAnchor' => ['textSegments' => [getSeg($fullText, '163417006017248296')]]]],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$client = new GoogleDocumentAiClient('test-proj', 'eu', 'proc-123', 'mock-key');
$parsed = $client->parseDocumentResponse($mockDocAiResponse);

echo "=== FORM ALANLARI ===\n";
print_r($parsed['form_fields']);

echo "\n=== YAPISAL MUTABAKAT SATIRLARI (ROWS) ===\n";
print_r($parsed['rows']);

echo "\n=== AYRIŞTIRILAN DÜZ METİN SATIRLARI ===\n";
print_r($parsed['lines']);

// Doğrulama kontrolü
assert(isset($parsed['form_fields']['Mağaza:']) && $parsed['form_fields']['Mağaza:'] === 'T285-EDR SARACLAR CD');
assert(count($parsed['rows']) === 2);
assert($parsed['rows'][0]['barcode'] === '160046392000113692');
assert($parsed['rows'][0]['store'] === 'T251');
assert($parsed['rows'][1]['barcode'] === '163417006017248296');
assert($parsed['rows'][1]['irsaliye_no'] === 'LLI202600107337 7');
assert($parsed['rows'][1]['store'] === 'ERM002');

echo "\n>>> TÜM DOĞRULAMA TESTLERİ %100 BAŞARIYLA GEÇTİ! <<<\n";
