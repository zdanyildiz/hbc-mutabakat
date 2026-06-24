<?php

declare(strict_types=1);

namespace App;

class CompanyRules
{
    /**
     * Belirtilen firma ID'sine göre geçerli barkod kurallarını döner.
     *
     * - min/max: izin verilen hane (rakam) sayısı aralığı.
     * - prefixes: barkodun başlaması gereken ön eklerden en az biri (boşsa ön ek kontrolü yapılmaz).
     *
     * LCW (firma 1) için barkodlar 18 haneli olmalı ve "15" veya "16" ile başlamalıdır.
     *
     * @param string|int|null $companyId
     * @return array{min: int, max: int, prefixes: array<string>}
     */
    public static function getLengthRule(string|int|null $companyId): array
    {
        if ($companyId !== null && (string)$companyId === '1') {
            return ['min' => 18, 'max' => 18, 'prefixes' => ['15', '16']];
        }
        return ['min' => 12, 'max' => 20, 'prefixes' => []];
    }

    /**
     * Bir barkodun, ilgili firmanın hane ve ön ek kurallarına uyup uymadığını kontrol eder.
     *
     * @param string $barcode Sadece rakamlardan oluşan barkod.
     * @param string|int|null $companyId
     */
    public static function isValid(string $barcode, string|int|null $companyId): bool
    {
        $rule = self::getLengthRule($companyId);
        $len = strlen($barcode);

        if ($len < $rule['min'] || $len > $rule['max']) {
            return false;
        }

        if ($rule['prefixes'] !== []) {
            foreach ($rule['prefixes'] as $prefix) {
                if (str_starts_with($barcode, $prefix)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }
}
