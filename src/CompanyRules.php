<?php

declare(strict_types=1);

namespace App;

class CompanyRules
{
    /**
     * Belirtilen firma ID'sine göre geçerli barkod uzunluk kurallarını döner.
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
}
