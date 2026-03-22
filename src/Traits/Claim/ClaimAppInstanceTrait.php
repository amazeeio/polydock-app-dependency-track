<?php

declare(strict_types=1);

namespace Amazeeio\PolydockAppDependencyTrack\Traits\Claim;

use FreedomtechHosting\PolydockApp\Enums\PolydockAppInstanceStatus;
use FreedomtechHosting\PolydockApp\PolydockAppInstanceInterface;

trait ClaimAppInstanceTrait
{
    /**
     * Claim the app instance.
     *
     * This method is called when the app instance status is PENDING_POLYDOCK_CLAIM.
     * It executes the claim script inside the environment to set up the admin user and API key.
     */
    public function claimAppInstance(PolydockAppInstanceInterface $appInstance): PolydockAppInstanceInterface
    {
        $functionName = __FUNCTION__;
        $logContext = $this->getLogContext($functionName);

        $this->info("$functionName: starting", $logContext);

        $this->validateAppInstanceStatusIsExpectedAndConfigureLagoonClientAndVerifyLagoonValues(
            $appInstance,
            PolydockAppInstanceStatus::PENDING_POLYDOCK_CLAIM,
            $logContext
        );

        $projectName = $appInstance->getKeyValue('lagoon-project-name');
        $environmentName = $appInstance->getKeyValue('lagoon-deploy-branch');
        $claimScript = $this->getClaimScript($appInstance);

        $this->info("$functionName: starting for project: $projectName", $logContext);
        $appInstance->setStatus(
            PolydockAppInstanceStatus::POLYDOCK_CLAIM_RUNNING,
            PolydockAppInstanceStatus::POLYDOCK_CLAIM_RUNNING->getStatusMessage()
        )->save();

        try {
            // Prepare environment variables for the claim script
            $envVars = [
                'POLYDOCK_ADMIN_USERNAME' => $appInstance->getGeneratedAppAdminUsername(),
                'POLYDOCK_ADMIN_PASSWORD' => $appInstance->getGeneratedAppAdminPassword(),
            ];

            // Build the command with inline environment variables
            $command = $this->buildClaimScriptWithInlineEnvironmentVariables($claimScript, $envVars);

            $this->info("$functionName: executing claim script", array_merge($logContext, ['command' => $command]));

            $claimResult = $this->lagoonClient->executeCommandOnProjectEnvironment(
                $projectName,
                $environmentName,
                $command,
                'apiserver' // DT logic should run on the apiserver
            );

            if (isset($claimResult['error'])) {
                throw new \Exception("Claim command failed: " . ($claimResult['error'][0]['message'] ?? json_encode($claimResult['error'])));
            }

            $output = (string) ($claimResult['executeCommandOnProjectEnvironment'] ?? '');
            $this->info("$functionName: claim script execution completed", array_merge($logContext, ['output' => $output]));

            // Store the raw output for debugging
            $appInstance->storeKeyValue('claim-command-output', $output);

            // Extract API Key from output
            if (preg_match('/API_KEY:([a-zA-Z0-9]+)/', $output, $matches)) {
                $apiKey = $matches[1];
                $appInstance->storeKeyValue('app-admin-api-key', $apiKey);
                $this->info("$functionName: extracted API Key: $apiKey", $logContext);
            } else {
                $this->warning("$functionName: could not extract API Key from claim script output", $logContext);
            }

            // Also update the Lagoon project variables with the API key if needed
            // (Similar to what was in PostCreateAppInstanceTrait)
            $this->injectApiKeyIntoTarget($appInstance, $logContext);

        } catch (\Exception $e) {
            $this->error("$functionName: failed: " . $e->getMessage(), [
                'exception_class' => get_class($e),
                'exception_trace' => $e->getTraceAsString(),
            ]);

            $appInstance->setStatus(PolydockAppInstanceStatus::POLYDOCK_CLAIM_FAILED, 'Claim failed: ' . $e->getMessage())->save();

            return $appInstance;
        }

        $this->info("$functionName: completed", $logContext);
        $appInstance->setStatus(PolydockAppInstanceStatus::POLYDOCK_CLAIM_COMPLETED, 'Claim completed')->save();

        return $appInstance;
    }

    /**
     * Get the claim script path.
     */
    public function getClaimScript(PolydockAppInstanceInterface $appInstance): string
    {
        return (string) ($appInstance->getKeyValue('lagoon-claim-script') ?: '/lagoon/polydock_claim.sh');
    }

    /**
     * Inject the API Key into the target Lagoon Organisation or Project.
     */
    protected function injectApiKeyIntoTarget(PolydockAppInstanceInterface $appInstance, array $logContext): void
    {
        $apiKey = $appInstance->getKeyValue('app-admin-api-key');
        if (!$apiKey) {
            return;
        }

        $lagoonOrgName = $appInstance->getKeyValue('lagoon_organisation');
        if ($lagoonOrgName) {
            $this->info("Injecting DT API Key into Lagoon Organisation: $lagoonOrgName", $logContext);
            try {
                $this->lagoonClient->addOrUpdateGlobalVariableForOrganization(
                    $lagoonOrgName,
                    'LAGOON_FEATURE_FLAG_INSIGHTS_DEPENDENCY_TRACK_API_KEY',
                    $apiKey
                );
            } catch (\Exception $e) {
                $this->warning("Failed to inject API Key into Organisation: " . $e->getMessage(), $logContext);
            }
        }
    }

    /**
     * Build the claim script command with inline environment variables.
     */
    protected function buildClaimScriptWithInlineEnvironmentVariables(string $claimScript, array $environmentVariables): string
    {
        if ($claimScript === '' || count($environmentVariables) === 0) {
            return $claimScript;
        }

        $inlineVariables = [];
        foreach ($environmentVariables as $variableName => $variableValue) {
            if (!preg_match('/^[A-Z0-9_]+$/', $variableName)) {
                continue;
            }

            $inlineVariables[] = "$variableName=" . escapeshellarg((string) $variableValue);
        }

        if (count($inlineVariables) === 0) {
            return $claimScript;
        }

        return 'env ' . implode(' ', $inlineVariables) . ' ' . $claimScript;
    }
}
