<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zoho\V2\Identity\ZohoIdentityLinker;
use Illuminate\Console\Command;

final class ZohoCrmLinkIdentities extends Command
{
    protected $signature = 'zoho:crm:link-identities';

    protected $description = 'Link exact-email Fretiq contacts to mirrored Zoho records without attribution';

    public function handle(ZohoIdentityLinker $linker): int
    {
        $users = $linker->autoMapUsers();
        $contacts = $linker->linkMarketingContacts();
        $this->info(sprintf(
            'Zoho user mappings: %d mapped, %d ambiguous.',
            $users['mapped'],
            $users['ambiguous'],
        ));
        $this->info(sprintf(
            'Zoho contact links: %d created, %d ambiguous.',
            $contacts['created'],
            $contacts['ambiguous'],
        ));

        return self::SUCCESS;
    }
}
