<?php

declare(strict_types=1);

namespace Amazeeio\PolydockAppDependencyTrack\Traits\Create;

use FreedomtechHosting\PolydockApp\Enums\PolydockAppInstanceStatus;
use FreedomtechHosting\PolydockApp\PolydockAppInstanceInterface;

trait PostCreateAppInstanceTrait
{
    public function postCreateAppInstance(PolydockAppInstanceInterface $appInstance): PolydockAppInstanceInterface
    {
        $functionName = __FUNCTION__;
        $logContext = $this->getLogContext($functionName);
        $testLagoonPing = true;
        $validateLagoonValues = true;
        // Do not validate lagoon project name and ID here either as we might only have an organisation
        $validateLagoonProjectName = false;
        $validateLagoonProjectId = false;

        $this->info("$functionName: starting", $logContext);

        $this->validateAppInstanceStatusIsExpectedAndConfigureLagoonClientAndVerifyLagoonValues(
            $appInstance,
            PolydockAppInstanceStatus::PENDING_POST_CREATE,
            $logContext,
            $testLagoonPing,
            $validateLagoonValues,
            $validateLagoonProjectName,
            $validateLagoonProjectId
        );

        $projectName = $appInstance->getKeyValue('lagoon-project-name');
        $environmentName = $appInstance->getKeyValue('lagoon-deploy-branch');

        $this->info("$functionName: starting for project: $projectName", $logContext);
        $appInstance->setStatus(
            PolydockAppInstanceStatus::POST_CREATE_RUNNING,
            PolydockAppInstanceStatus::POST_CREATE_RUNNING->getStatusMessage()
        )->save();

        try {
            // First we ensure the deploy group is attached if running a Project deployment
            if ($projectName && $this->lagoonClient->projectExistsByName($projectName)) {
                $addGroupToProjectResult = $this->lagoonClient->addGroupToProject(
                    $appInstance->getKeyValue('lagoon-deploy-group-name'),
                    $projectName
                );

                if (isset($addGroupToProjectResult['error'])) {
                    $errorMessage = \is_array($addGroupToProjectResult['error'])
                        ? ($addGroupToProjectResult['error'][0]['message'] ?? json_encode($addGroupToProjectResult['error']))
                        : $addGroupToProjectResult['error'];
                    $this->error($errorMessage);
                    throw new \Exception($errorMessage);
                }
            }

            // We need to fetch the environments of the deployed Dependency Track project to find the apiserver route
            $this->info("$functionName: checking environment routes for Dependency Track API endpoint", $logContext);
            $environments = $this->lagoonClient->getProjectEnvironmentsByName($projectName);

            $apiEndpoint = '';
            foreach ($environments as $env) {
                if ($env['name'] === $environmentName) {
                    $routes = explode(',', $env['routes'] ?? '');
                    foreach ($routes as $route) {
                        $route = trim($route);
                        // Filter the ones starting with 'apiserver.'
                        if (str_starts_with($route, 'https://apiserver.') || str_starts_with($route, 'http://apiserver.')) {
                            $apiEndpoint = $route;
                            break 2;
                        } elseif (str_starts_with($route, 'apiserver.')) {
                            $apiEndpoint = "https://$route";
                            break 2;
                        }
                    }
                }
            }

            if (!$apiEndpoint) {
                $this->error('Could not determine Dependency Track API endpoint (no route starting with apiserver.)');
                throw new \Exception('Missing API route for Dependency Track');
            }

            $this->info("$functionName: determined API Endpoint: $apiEndpoint", $logContext);

            // TODO: Execute CLI script inside the environment to get the API Key (TODO)
            $apiKey = 'odt_TODO_REPLACE_ME_VIA_CLI_SCRIPT';
            $this->info("$functionName: generated API Key using CLI Script (TODO implementation)", $logContext);

            // Now we inject into target Organisation or Project
            $lagoonOrgName = $appInstance->getKeyValue('lagoon_organisation');

            if (!empty($lagoonOrgId)) {
                $this->info("$functionName: Injecting LAGOON_FEATURE_FLAG_INSIGHTS_DEPENDENCY_TRACK_API variables into Lagoon Organisation", $logContext);
                $this->lagoonClient->addOrUpdateGlobalVariableForOrganization(
                    $lagoonOrgName,
                    'LAGOON_FEATURE_FLAG_INSIGHTS_DEPENDENCY_TRACK_API_ENDPOINT',
                    $apiEndpoint
                );
                $this->lagoonClient->addOrUpdateGlobalVariableForOrganization(
                    $lagoonOrgName,
                    'LAGOON_FEATURE_FLAG_INSIGHTS_DEPENDENCY_TRACK_API_KEY',
                    $apiKey
                );
            }

        } catch (\Exception $e) {
            $this->error('Post Create Failed: ' . $e->getMessage(), [
                'exception_class' => get_class($e),
                'exception_trace' => $e->getTraceAsString(),
            ]);

            $appInstance->setStatus(PolydockAppInstanceStatus::POST_CREATE_FAILED, 'An exception occured: ' . $e->getMessage())->save();

            return $appInstance;
        }

        $this->info("$functionName: completed", $logContext);
        $appInstance->setStatus(PolydockAppInstanceStatus::POST_CREATE_COMPLETED, 'Post-create completed')->save();

        return $appInstance;
    }
}
