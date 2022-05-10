<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read string|integer $account_reference
 * @property-read string $domain_name
 * @property-read string|integer $package_reference
 * @property-read integer $billing_cycle_months
 */
class ChangePackageParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'account_reference' => ['required'],
            'domain_name' => ['required', 'domain_name'],
            'package_reference' => ['required'],
            'billing_cycle_months' => ['required', 'integer'],
        ]);
    }
}
