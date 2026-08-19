<?php

declare(strict_types=1);

namespace App;

/**
 * "Mağaza Kargo Teslim Alım Mutabakat Raporu" sablonuna ozel, konum (x,y) tabanli
 * sutun ayristirici. Google Vision'in duz metin (fullTextAnnotation.text) yerine
 * her kelime icin dondurdugu bounding-box merkezini kullanarak, ayni fiziksel satirda
 * yan yana duran "Tema Takip No" (barkod) ve "KargoTakipNo" (barkod tekrari) sutunlarini
 * birbirine karistirmadan ayirir. Serbest metin taramasinin (satir kirilmasi yanlis
 * olursa) kacirabilecegi/karistirabilecegi durumlar icin ek, daha guvenilir bir
 * kurtarma kaynagi saglar.
 */
class ManifestColumnExtractor
{
    private const MIN_BARCODE_LEN = 14;
    private const MAX_BARCODE_LEN = 20;

    /** Ayni satir sayilmasi icin izin verilen dikey (y) tolerans, piksel (300 DPI render icin). */
    private const ROW_Y_TOLERANCE = 18.0;

    /** Iki barkod sutununu ayirt etmek icin gereken minimum yatay bosluk, piksel. */
    private const MIN_COLUMN_GAP = 30.0;

    /**
     * @param array<int, array<array{text: string, cx: float, cy: float}>> $pagesWords Sayfa indeksi => kelime listesi
     * @return array<array{barcode: ?string, barcode_fallback: ?string, store: ?string}>
     */
    public function extractRows(array $pagesWords): array
    {
        $allRows = [];
        foreach ($pagesWords as $words) {
            $allRows = array_merge($allRows, $this->extractRowsFromPage($words));
        }
        return $allRows;
    }

    /**
     * @param array<array{text: string, cx: float, cy: float}> $words
     * @return array<array{barcode: ?string, barcode_fallback: ?string, store: ?string}>
     */
    private function extractRowsFromPage(array $words): array
    {
        $barcodeCandidates = [];
        foreach ($words as $w) {
            $text = $w['text'];
            $len = strlen($text);
            if (ctype_digit($text) && $len >= self::MIN_BARCODE_LEN && $len <= self::MAX_BARCODE_LEN) {
                $barcodeCandidates[] = $w;
            }
        }

        if (empty($barcodeCandidates)) {
            return [];
        }

        usort($barcodeCandidates, fn (array $a, array $b) => $a['cx'] <=> $b['cx']);
        $n = count($barcodeCandidates);

        if ($n === 1) {
            $only = $barcodeCandidates[0]['text'];
            return [[
                'barcode' => $only,
                'barcode_fallback' => $only,
                'store' => $this->findNearestStore($words, $barcodeCandidates[0]['cy']),
            ]];
        }

        // En genis yatay bosluga gore iki sutuna (sol: Tema Takip No, sag: KargoTakipNo) ayir.
        $bestGapIdx = 0;
        $bestGap = -1.0;
        for ($i = 1; $i < $n; $i++) {
            $gap = $barcodeCandidates[$i]['cx'] - $barcodeCandidates[$i - 1]['cx'];
            if ($gap > $bestGap) {
                $bestGap = $gap;
                $bestGapIdx = $i;
            }
        }

        if ($bestGap < self::MIN_COLUMN_GAP) {
            // Belirgin iki sutun ayrimi yok (ornegin cok kisa/eksik sayfa) - guvenli
            // varsayim: tek sutun kabul edip ayni degeri hem birincil hem yedek yap.
            $rows = [];
            foreach ($barcodeCandidates as $c) {
                $rows[] = [
                    'barcode' => $c['text'],
                    'barcode_fallback' => $c['text'],
                    'store' => $this->findNearestStore($words, $c['cy']),
                ];
            }
            return $rows;
        }

        $leftCluster = array_slice($barcodeCandidates, 0, $bestGapIdx);
        $rightCluster = array_slice($barcodeCandidates, $bestGapIdx);
        $leftRefX = $this->median(array_column($leftCluster, 'cx'));
        $rightRefX = $this->median(array_column($rightCluster, 'cx'));

        $rowsByY = $this->groupByY($barcodeCandidates);

        $result = [];
        foreach ($rowsByY as $rowWords) {
            $barcode = null;
            $fallback = null;
            $bestLeftDist = INF;
            $bestRightDist = INF;
            $cySum = 0.0;
            $cyCount = 0;

            foreach ($rowWords as $w) {
                $cySum += $w['cy'];
                $cyCount++;

                $dLeft = abs($w['cx'] - $leftRefX);
                $dRight = abs($w['cx'] - $rightRefX);

                if ($dLeft <= $dRight && $dLeft < $bestLeftDist) {
                    $bestLeftDist = $dLeft;
                    $barcode = $w['text'];
                } elseif ($dRight < $dLeft && $dRight < $bestRightDist) {
                    $bestRightDist = $dRight;
                    $fallback = $w['text'];
                }
            }

            $rowCy = $cyCount > 0 ? $cySum / $cyCount : 0.0;
            $result[] = [
                'barcode' => $barcode,
                'barcode_fallback' => $fallback,
                'store' => $this->findNearestStore($words, $rowCy),
            ];
        }

        return $result;
    }

    /**
     * @param array<array{text: string, cx: float, cy: float}> $candidates
     * @return array<array<array{text: string, cx: float, cy: float}>>
     */
    private function groupByY(array $candidates): array
    {
        usort($candidates, fn (array $a, array $b) => $a['cy'] <=> $b['cy']);

        $groups = [];
        $current = [];
        $lastCy = null;

        foreach ($candidates as $c) {
            if ($lastCy !== null && abs($c['cy'] - $lastCy) > self::ROW_Y_TOLERANCE) {
                $groups[] = $current;
                $current = [];
            }
            $current[] = $c;
            $lastCy = $c['cy'];
        }

        if (!empty($current)) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Verilen satira (y konumuna) en yakin, mağaza/depo kodu formatina uyan
     * (buyuk harf + opsiyonel rakam, 3-7 karakter, orn. "ERM002", "T285") kelimeyi bulur.
     * Sutun pozisyonuna bagimli degildir; sadece format + en yakin satir eslesmesi kullanir.
     *
     * @param array<array{text: string, cx: float, cy: float}> $words
     */
    private function findNearestStore(array $words, float $rowCy): ?string
    {
        $best = null;
        $bestDist = INF;

        foreach ($words as $w) {
            if (preg_match('/^[A-ZÇĞİÖŞÜ]{1,6}\d{0,4}$/u', $w['text']) !== 1) {
                continue;
            }

            $len = mb_strlen($w['text']);
            if ($len < 3 || $len > 7) {
                continue;
            }

            $dist = abs($w['cy'] - $rowCy);
            if ($dist <= self::ROW_Y_TOLERANCE * 1.5 && $dist < $bestDist) {
                $bestDist = $dist;
                $best = $w['text'];
            }
        }

        return $best;
    }

    /**
     * @param array<float> $values
     */
    private function median(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2.0;
        }

        return $values[$mid];
    }
}
