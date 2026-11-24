<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/**
 * Le prix rond à afficher pour recevoir au moins `receive`, quel que soit le réseau du client
 * (`$pay->displayPrice(...)`). Créez ensuite le paiement avec `'amount' => $price` et
 * `'fees' => 'partner'` : le client paie exactement ce prix, et le surplus vous reste. Jamais
 * `'customer'` : le client paierait le prix PLUS les frais.
 */
final class DisplayPrice
{
    /**
     * @param list<DisplayPriceNetwork> $netByNetwork
     * @param list<string> $countries
     * @param array<string, DisplayPriceForCountry> $byCountry
     */
    public function __construct(
        /** Le prix à afficher (le plus haut des pays demandés). */
        public readonly int|float $price,
        public readonly ?string $currency,
        /** Le réseau qui vous laisse le moins ; `null` s'il manque. */
        public readonly ?DisplayPriceNetwork $worstNetwork,
        /** Ce que vous recevez, réseau par réseau. */
        public readonly array $netByNetwork,
        /** Toujours `partner` : un prix affiché fixe se paie en « vous payez les frais ». */
        public readonly string $feesPaidBy,
        /** Ce que vous voulez recevoir au moins. */
        public readonly int|float $receive,
        /** Le pas d'arrondi. */
        public readonly int|float $roundTo,
        /** Les pays demandés. */
        public readonly array $countries,
        /** Un prix par pays (avec `country` ou `countries`). */
        public readonly array $byCountry,
        /** Un seul prix valable dans tous ces pays ; `null` si XOF et XAF sont mélangés. */
        public readonly ?DisplayPriceForCountry $everywhere,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        $byCountry = [];
        foreach (D::map($r, 'by_country') as $pays => $v) {
            if (is_array($v)) {
                $byCountry[$pays] = DisplayPriceForCountry::fromApi($v);
            }
        }
        $everywhere = D::nmap($r, 'everywhere');
        return new self(
            D::num($r, 'price'),
            D::nstr($r, 'currency'),
            DisplayPriceForCountry::worst($r),
            DisplayPriceForCountry::byNetwork($r),
            'partner',
            D::num($r, 'receive'),
            D::num($r, 'round_to'),
            D::strings($r, 'countries'),
            $byCountry,
            $everywhere === null ? null : DisplayPriceForCountry::fromApi($everywhere),
        );
    }
}
