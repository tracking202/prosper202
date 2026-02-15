<?php

declare(strict_types=1);

namespace P202Cli;

use Symfony\Component\Console\Application as ConsoleApplication;

class Application extends ConsoleApplication
{
    public function __construct()
    {
        parent::__construct('p202 - Prosper202 CLI', '1.0.0');
        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        // --- Config ---
        $this->add(new Commands\ConfigSetUrlCommand());
        $this->add(new Commands\ConfigSetKeyCommand());
        $this->add(new Commands\ConfigShowCommand());
        $this->add(new Commands\ConfigTestCommand());

        // --- CRUD entities (auto-generated) ---
        $this->registerCrudEntities();

        // --- Clicks ---
        $this->add(new Commands\ClickListCommand());
        $this->add(new Commands\ClickGetCommand());

        // --- Conversions ---
        $this->add(new Commands\ConversionListCommand());
        $this->add(new Commands\ConversionGetCommand());
        $this->add(new Commands\ConversionCreateCommand());
        $this->add(new Commands\ConversionDeleteCommand());

        // --- Reports ---
        $this->add(new Commands\ReportSummaryCommand());
        $this->add(new Commands\ReportBreakdownCommand());
        $this->add(new Commands\ReportTimeseriesCommand());
        $this->add(new Commands\ReportDaypartCommand());

        // --- Rotators ---
        $this->add(new Commands\RotatorListCommand());
        $this->add(new Commands\RotatorGetCommand());
        $this->add(new Commands\RotatorCreateCommand());
        $this->add(new Commands\RotatorUpdateCommand());
        $this->add(new Commands\RotatorDeleteCommand());
        $this->add(new Commands\RotatorRuleCreateCommand());
        $this->add(new Commands\RotatorRuleDeleteCommand());

        // --- Attribution ---
        $this->add(new Commands\AttributionModelListCommand());
        $this->add(new Commands\AttributionModelGetCommand());
        $this->add(new Commands\AttributionModelCreateCommand());
        $this->add(new Commands\AttributionModelUpdateCommand());
        $this->add(new Commands\AttributionModelDeleteCommand());
        $this->add(new Commands\AttributionSnapshotListCommand());
        $this->add(new Commands\AttributionExportListCommand());
        $this->add(new Commands\AttributionExportScheduleCommand());

        // --- Users ---
        $this->add(new Commands\UserListCommand());
        $this->add(new Commands\UserGetCommand());
        $this->add(new Commands\UserCreateCommand());
        $this->add(new Commands\UserUpdateCommand());
        $this->add(new Commands\UserDeleteCommand());
        $this->add(new Commands\UserRoleListCommand());
        $this->add(new Commands\UserRoleAssignCommand());
        $this->add(new Commands\UserRoleRemoveCommand());
        $this->add(new Commands\UserApiKeyListCommand());
        $this->add(new Commands\UserApiKeyCreateCommand());
        $this->add(new Commands\UserApiKeyDeleteCommand());
        $this->add(new Commands\UserPreferencesGetCommand());
        $this->add(new Commands\UserPreferencesUpdateCommand());

        // --- System ---
        $this->add(new Commands\SystemHealthCommand());
        $this->add(new Commands\SystemVersionCommand());
        $this->add(new Commands\SystemDbStatsCommand());
        $this->add(new Commands\SystemCronCommand());
        $this->add(new Commands\SystemErrorsCommand());
        $this->add(new Commands\SystemDataengineCommand());
    }

    private function registerCrudEntities(): void
    {
        $entities = [
            [
                'name' => 'campaign',
                'endpoint' => 'campaigns',
                'fields' => [
                    'aff_campaign_name' => 'Campaign name',
                    'aff_campaign_url' => 'Primary offer URL',
                    'aff_campaign_url_2' => 'Offer URL #2 (rotation)',
                    'aff_campaign_url_3' => 'Offer URL #3 (rotation)',
                    'aff_campaign_url_4' => 'Offer URL #4 (rotation)',
                    'aff_campaign_url_5' => 'Offer URL #5 (rotation)',
                    'aff_campaign_payout' => 'Payout per conversion',
                    'aff_campaign_currency' => 'Currency code (USD, EUR, etc)',
                    'aff_campaign_foreign_payout' => 'Foreign currency payout',
                    'aff_network_id' => 'Affiliate network ID',
                    'aff_campaign_cloaking' => 'Enable cloaking (0|1)',
                    'aff_campaign_rotate' => 'Enable URL rotation (0|1)',
                ],
                'required' => ['aff_campaign_name', 'aff_campaign_url'],
                'listParams' => ['filter[aff_network_id]' => 'Filter by affiliate network'],
            ],
            [
                'name' => 'aff-network',
                'endpoint' => 'aff-networks',
                'fields' => [
                    'aff_network_name' => 'Network name',
                    'dni_network_id' => 'DNI network ID',
                ],
                'required' => ['aff_network_name'],
            ],
            [
                'name' => 'ppc-network',
                'endpoint' => 'ppc-networks',
                'fields' => [
                    'ppc_network_name' => 'Traffic source network name',
                ],
                'required' => ['ppc_network_name'],
            ],
            [
                'name' => 'ppc-account',
                'endpoint' => 'ppc-accounts',
                'fields' => [
                    'ppc_account_name' => 'Account name',
                    'ppc_network_id' => 'PPC network ID',
                    'ppc_account_default' => 'Default account (0|1)',
                ],
                'required' => ['ppc_account_name', 'ppc_network_id'],
                'listParams' => ['filter[ppc_network_id]' => 'Filter by PPC network'],
            ],
            [
                'name' => 'tracker',
                'endpoint' => 'trackers',
                'fields' => [
                    'aff_campaign_id' => 'Campaign ID',
                    'ppc_account_id' => 'PPC account ID',
                    'text_ad_id' => 'Text ad ID',
                    'landing_page_id' => 'Landing page ID',
                    'rotator_id' => 'Rotator ID',
                    'click_cpc' => 'Cost per click',
                    'click_cpa' => 'Cost per action',
                    'click_cloaking' => 'Enable cloaking (0|1)',
                ],
                'required' => ['aff_campaign_id'],
                'listParams' => [
                    'filter[aff_campaign_id]' => 'Filter by campaign',
                    'filter[ppc_account_id]' => 'Filter by PPC account',
                ],
            ],
            [
                'name' => 'landing-page',
                'endpoint' => 'landing-pages',
                'fields' => [
                    'landing_page_url' => 'Landing page URL',
                    'aff_campaign_id' => 'Campaign ID',
                    'landing_page_nickname' => 'Nickname / label',
                    'leave_behind_page_url' => 'Leave-behind page URL',
                    'landing_page_type' => 'Page type (0|1)',
                ],
                'required' => ['landing_page_url', 'aff_campaign_id'],
                'listParams' => ['filter[aff_campaign_id]' => 'Filter by campaign'],
            ],
            [
                'name' => 'text-ad',
                'endpoint' => 'text-ads',
                'fields' => [
                    'text_ad_name' => 'Ad name',
                    'text_ad_headline' => 'Headline',
                    'text_ad_description' => 'Description text',
                    'text_ad_display_url' => 'Display URL',
                    'aff_campaign_id' => 'Campaign ID',
                    'landing_page_id' => 'Landing page ID',
                    'text_ad_type' => 'Ad type (0|1)',
                ],
                'required' => ['text_ad_name'],
                'listParams' => ['filter[aff_campaign_id]' => 'Filter by campaign'],
            ],
        ];

        foreach ($entities as $entity) {
            $commands = Commands\CrudCommands::generate(
                $entity['name'],
                $entity['endpoint'],
                $entity['fields'],
                $entity['required'] ?? [],
                $entity['listParams'] ?? []
            );
            foreach ($commands as $cmd) {
                $this->add($cmd);
            }
        }
    }
}
