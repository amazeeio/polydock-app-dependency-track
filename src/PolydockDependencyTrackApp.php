<?php

declare(strict_types=1);

namespace Amazeeio\PolydockAppDependencyTrack;

use Amazeeio\PolydockAppDependencyTrack\Traits\Claim\ClaimAppInstanceTrait;
use Amazeeio\PolydockAppDependencyTrack\Traits\Create\PostCreateAppInstanceTrait;
use Amazeeio\PolydockAppDependencyTrack\Traits\Create\PreCreateAppInstanceTrait;
use Filament\Forms;
use Filament\Infolists;
use FreedomtechHosting\PolydockApp\Attributes\PolydockAppInstanceFields;
use FreedomtechHosting\PolydockApp\Attributes\PolydockAppStoreFields;
use FreedomtechHosting\PolydockApp\Attributes\PolydockAppTitle;
use FreedomtechHosting\PolydockApp\Contracts\HasAppInstanceFormFields;
use FreedomtechHosting\PolydockApp\Contracts\HasStoreAppFormFields;
use FreedomtechHosting\PolydockAppAmazeeioGeneric\PolydockApp as GenericPolydockApp;

#[PolydockAppTitle('Dependency Track App')]
#[PolydockAppStoreFields]
#[PolydockAppInstanceFields]
class PolydockDependencyTrackApp extends GenericPolydockApp implements HasAppInstanceFormFields, HasStoreAppFormFields
{
    use PreCreateAppInstanceTrait;
    use PostCreateAppInstanceTrait;
    use ClaimAppInstanceTrait;

    public static string $version = '0.1.0';

    /**
     * @return array<\Filament\Forms\Components\Component>
     */
    #[\Override]
    public static function getStoreAppFormSchema(): array
    {
        return [];
    }

    /**
     * @return array<\Filament\Infolists\Components\Component>
     */
    #[\Override]
    public static function getStoreAppInfolistSchema(): array
    {
        return [];
    }

    /**
     * @return array<\Filament\Forms\Components\Component>
     */
    #[\Override]
    public static function getAppInstanceFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('lagoon_organisation')
                ->label('Lagoon Organisation Name')
                ->placeholder('e.g. my-org')
                ->maxLength(255)
                ->helperText('The name of the Lagoon Organisation to inject the application variables into.'),
        ];
    }

    /**
     * @return array<\Filament\Infolists\Components\Component>
     */
    #[\Override]
    public static function getAppInstanceInfolistSchema(): array
    {
        return [
            Infolists\Components\TextEntry::make('lagoon_organisation')
                ->label('Lagoon Organisation Name')
                ->placeholder('Not configured'),
        ];
    }
}
