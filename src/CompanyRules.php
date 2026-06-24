<?php

declare(strict_types=1);

namespace App;

class CompanyRules
{
    /**
     * Firma ID'sine göre geçerli barkod HANE (uzunluk) aralığını döner.
     *
     * LCW (firma 1) için barkodlar 18 hanelidir.
     *
     * @param string|int|null $companyId
     * @return array{min: int, max: int}
     */
    public static function getLengthRule(string|int|null $companyId): array
    {
        if ($companyId !== null && (string)$companyId === '1') {
            return ['min' => 18, 'max' => 18];
        }
        return ['min' => 12, 'max' => 20];
    }

    /**
     * Firmaya ait geçerli barkod ön eklerini döner (boş ise ön ek kontrolü yapılmaz).
     *
     * ÖNEMLİ: Bu ön ekler YALNIZCA PDF tarafındaki "fazla koli" adaylarını temizlemek
     * için kullanılır (OCR gürültüsünden gelen, LCW barkodu olmayan 18 haneli dizileri
     * elemek için). Terminal/Excel barkodlarının geçerliliğini ETKİLEMEZ; aksi halde
     * "9..." ile başlayan sarf malzeme kodları "Hatalı" olur, oysa onların "Eksik"
     * listesinde görünmesi gerekir.
     *
     * @param string|int|null $companyId
     * @return array<string>
     */
    public static function getValidPrefixes(string|int|null $companyId): array
    {
        if ($companyId !== null && (string)$companyId === '1') {
            return ['15', '16'];
        }
        return [];
    }

    /**
     * Terminal (Excel) barkodunun firma kuralına uygunluğunu kontrol eder.
     *
     * Burada SADECE uzunluk kontrol edilir; ön ek kontrolü yapılmaz. Böylece "9..."
     * ile başlayan sarf malzeme kodları (18 haneli) geçerli sayılır, eşleşmeye girer
     * ve PDF'te karşılığı olmadığı için doğru şekilde "Eksik" listesinde görünür.
     *
     * @param string $barcode Sadece rakamlardan oluşan barkod.
     * @param string|int|null $companyId
     */
    public static function isValid(string $barcode, string|int|null $companyId): bool
    {
        $rule = self::getLengthRule($companyId);
        $len = strlen($barcode);

        return $len >= $rule['min'] && $len <= $rule['max'];
    }

    /**
     * Bir barkodun, firmanın geçerli ön eklerinden biriyle başlayıp başlamadığını kontrol eder.
     * Firma için tanımlı ön ek yoksa daima true döner.
     *
     * @param string $barcode Sadece rakamlardan oluşan barkod.
     * @param string|int|null $companyId
     */
    public static function hasValidPrefix(string $barcode, string|int|null $companyId): bool
    {
        $prefixes = self::getValidPrefixes($companyId);
        if ($prefixes === []) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($barcode, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
