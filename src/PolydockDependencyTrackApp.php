<?php

declare(strict_types=1);

namespace Amazeeio\PolydockAppDependencyTrack;

use Amazeeio\PolydockAppDependencyTrack\Traits\Create\PostCreateAppInstanceTrait;
use Amazeeio\PolydockAppDependencyTrack\Traits\Create\PreCreateAppInstanceTrait;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use FreedomtechHosting\PolydockApp\Attributes\PolydockAppStoreFields;
use FreedomtechHosting\PolydockApp\Attributes\PolydockAppInstanceFields;
use FreedomtechHosting\PolydockApp\Attributes\PolydockAppTitle;
use FreedomtechHosting\PolydockApp\Contracts\HasAppInstanceFormFields;
use FreedomtechHosting\PolydockApp\Contracts\HasStoreAppFormFields;
use FreedomtechHosting\PolydockApp\Enums\PolydockAppInstanceStatus;
use FreedomtechHosting\PolydockApp\PolydockAppInstanceInterface;
use FreedomtechHosting\PolydockApp\PolydockAppInstanceStatusFlowException;
use FreedomtechHosting\PolydockAppAmazeeioGeneric\PolydockApp as GenericPolydockApp;

#[PolydockAppTitle('Dependency Track App')]
#[PolydockAppStoreFields]
#[PolydockAppInstanceFields]
class PolydockDependencyTrackApp extends GenericPolydockApp implements HasAppInstanceFormFields, HasStoreAppFormFields
{
    use PreCreateAppInstanceTrait;
    use PostCreateAppInstanceTrait;

    public static string $version = '0.1.0';

    /**
     * @return array<Component>
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
     * @return array<Component>
     */
    #[\Override]
    public static function getAppInstanceFormSchema(): array
    {
        return [
            TextInput::make('lagoon_organisation')
                ->label('Lagoon Organisation Name')
                ->placeholder('e.g. my-org')
                ->maxLength(255)
                ->helperText('The Lagoon organization that should receive the Dependency-Track API variables.'),
        ];
    }

    /**
     * @return array<\Filament\Infolists\Components\Component>
     */
    #[\Override]
    public static function getAppInstanceInfolistSchema(): array
    {
        return [
            TextEntry::make('lagoon_organisation')
                ->label('Lagoon Organisation Name')
                ->placeholder('Not configured'),
        ];
    }

    /**
     * @throws PolydockAppInstanceStatusFlowException
     */
    #[\Override]
    public function claimAppInstance(PolydockAppInstanceInterface $appInstance): PolydockAppInstanceInterface
    {
        $functionName = __FUNCTION__;
        $logContext = $this->getLogContext($functionName);

        $this->info("{$functionName}: starting Dependency-Track claim", $logContext);

        $this->validateAppInstanceStatusIsExpected($appInstance, PolydockAppInstanceStatus::PENDING_POLYDOCK_CLAIM);

        $this->setLagoonClientFromAppInstance($appInstance);

        $claimScript = $appInstance->getKeyValue('lagoon-claim-script');
        if (! empty($claimScript)) {
            $appInstance->storeKeyValue('lagoon-claim-script-service', 'apiserver');
            $appInstance->storeKeyValue('lagoon-claim-script-container', 'apiserver');
        }

        $appInstance = parent::claimAppInstance($appInstance);

        if ($appInstance->getStatus() !== PolydockAppInstanceStatus::POLYDOCK_CLAIM_COMPLETED) {
            return $appInstance;
        }

        $claimOutput = (string) $appInstance->getKeyValue('claim-command-output');
        if (preg_match('/API_KEY:([^\s]+)/', $claimOutput, $matches) === 1) {
            $appInstance->storeKeyValue('app-admin-api-key', trim($matches[1]));
        }

        $appUrl = $this->buildDependencyTrackUrlFromInstance($appInstance);
        if (! empty($appUrl)) {
            $appInstance->setAppUrl($appUrl, $appUrl, 24);
        }

        return $appInstance;
    }

    private function buildDependencyTrackUrlFromInstance(PolydockAppInstanceInterface $appInstance): ?string
    {
        $claimOutput = trim((string) $appInstance->getKeyValue('claim-command-output'));
        if (filter_var($claimOutput, FILTER_VALIDATE_URL)) {
            return $claimOutput;
        }

        $projectName = $appInstance->getKeyValue('lagoon-project-name');
        $projectId = $appInstance->getKeyValue('lagoon-project-id');
        $environmentName = $appInstance->getKeyValue('lagoon-deploy-branch');

        if (empty($projectName) || empty($projectId) || empty($environmentName)) {
            return null;
        }

        $environments = $this->lagoonClient->getProjectEnvironmentsByName($projectName);
        foreach ($environments as $environment) {
            if (($environment['name'] ?? null) !== $environmentName) {
                continue;
            }

            $routes = explode(',', (string) ($environment['routes'] ?? ''));
            foreach ($routes as $route) {
                $route = trim($route);
                if (empty($route)) {
                    continue;
                }

                if (str_starts_with($route, 'https://frontend.') || str_starts_with($route, 'http://frontend.')) {
                    return $route;
                }

                if (str_starts_with($route, 'frontend.')) {
                    return 'https://'.$route;
                }
            }
        }

        return null;
    }
}
