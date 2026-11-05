<?php

declare(strict_types=1);

namespace Odden\Core\Screens;

use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\PropertyDefinition;

final class CoreScreens
{
    public static function register(ScreenRegistry $screens): void
    {
        $screens->defineGroup('Executive', 0);
        $screens->defineGroup('CRM', 10);
        $screens->defineGroup('Settings', 30);

        $screens->define(...[
            ...Screen::forModel('contacts', Contact::class, 'Contacts', 'CRM'),
            ...Screen::forModel('companies', Company::class, 'Companies', 'CRM'),
            ...Screen::forModel('lists', CrmList::class, 'Lists', 'CRM', 3),
            ...Screen::forModel('custom-properties', PropertyDefinition::class, 'Custom properties', 'Settings', pages: ['index', 'create', 'edit']),
            Screen::page('executive-overview', 'Executive overview', 'Executive', -10),
            Screen::page('data-quality', 'Data quality', 'CRM', 10),
        ]);

        $screens->requireViewAny('executive-overview', null, Contact::class, Company::class);
        $screens->requireViewAny('data-quality', null, Contact::class, Company::class);
    }
}
