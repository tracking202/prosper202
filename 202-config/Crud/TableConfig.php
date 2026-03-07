<?php

declare(strict_types=1);

namespace Prosper202\Crud;

final class TableConfig
{
    /**
     * @param string $table          Table name (e.g. '202_aff_networks')
     * @param string $primaryKey     Primary key column (e.g. 'aff_network_id')
     * @param string $userIdColumn   User ownership column (e.g. 'user_id')
     * @param string|null $deletedColumn Soft-delete column or null for hard delete
     * @param array<string, string> $fields Writable fields: column => bind type ('s','i','d')
     * @param list<string> $selectColumns Columns to return in SELECT queries
     */
    public function __construct(
        public readonly string $table,
        public readonly string $primaryKey,
        public readonly string $userIdColumn,
        public readonly ?string $deletedColumn,
        public readonly array $fields,
        public readonly array $selectColumns,
    ) {
    }

    // --- Pre-built configs for common entities ---

    public static function affNetworks(): self
    {
        return new self(
            table: '202_aff_networks',
            primaryKey: 'aff_network_id',
            userIdColumn: 'user_id',
            deletedColumn: 'aff_network_deleted',
            fields: ['aff_network_name' => 's', 'dni_network_id' => 'i'],
            selectColumns: ['aff_network_id', 'user_id', 'aff_network_name', 'dni_network_id', 'aff_network_deleted'],
        );
    }

    public static function ppcNetworks(): self
    {
        return new self(
            table: '202_ppc_networks',
            primaryKey: 'ppc_network_id',
            userIdColumn: 'user_id',
            deletedColumn: 'ppc_network_deleted',
            fields: ['ppc_network_name' => 's'],
            selectColumns: ['ppc_network_id', 'user_id', 'ppc_network_name', 'ppc_network_deleted'],
        );
    }

    public static function ppcAccounts(): self
    {
        return new self(
            table: '202_ppc_accounts',
            primaryKey: 'ppc_account_id',
            userIdColumn: 'user_id',
            deletedColumn: 'ppc_account_deleted',
            fields: ['ppc_account_name' => 's', 'ppc_network_id' => 'i', 'ppc_account_default' => 'i'],
            selectColumns: ['ppc_account_id', 'user_id', 'ppc_account_name', 'ppc_network_id', 'ppc_account_default', 'ppc_account_deleted'],
        );
    }

    public static function campaigns(): self
    {
        return new self(
            table: '202_aff_campaigns',
            primaryKey: 'aff_campaign_id',
            userIdColumn: 'user_id',
            deletedColumn: 'aff_campaign_deleted',
            fields: [
                'aff_campaign_name' => 's', 'aff_campaign_url' => 's',
                'aff_campaign_url_2' => 's', 'aff_campaign_url_3' => 's',
                'aff_campaign_url_4' => 's', 'aff_campaign_url_5' => 's',
                'aff_campaign_payout' => 'd', 'aff_campaign_currency' => 's',
                'aff_campaign_foreign_payout' => 'd', 'aff_network_id' => 'i',
                'aff_campaign_cloaking' => 'i', 'aff_campaign_rotate' => 'i',
                'aff_campaign_time' => 'i', 'aff_campaign_id_public' => 'i',
            ],
            selectColumns: [
                'aff_campaign_id', 'user_id', 'aff_campaign_name', 'aff_campaign_url',
                'aff_campaign_url_2', 'aff_campaign_url_3', 'aff_campaign_url_4', 'aff_campaign_url_5',
                'aff_campaign_payout', 'aff_campaign_currency', 'aff_campaign_foreign_payout',
                'aff_network_id', 'aff_campaign_cloaking', 'aff_campaign_rotate',
                'aff_campaign_time', 'aff_campaign_id_public', 'aff_campaign_deleted',
            ],
        );
    }

    public static function landingPages(): self
    {
        return new self(
            table: '202_landing_pages',
            primaryKey: 'landing_page_id',
            userIdColumn: 'user_id',
            deletedColumn: 'landing_page_deleted',
            fields: [
                'landing_page_url' => 's', 'aff_campaign_id' => 'i',
                'landing_page_nickname' => 's', 'leave_behind_page_url' => 's',
                'landing_page_type' => 'i',
            ],
            selectColumns: [
                'landing_page_id', 'user_id', 'landing_page_url', 'aff_campaign_id',
                'landing_page_nickname', 'leave_behind_page_url', 'landing_page_type', 'landing_page_deleted',
            ],
        );
    }

    public static function textAds(): self
    {
        return new self(
            table: '202_text_ads',
            primaryKey: 'text_ad_id',
            userIdColumn: 'user_id',
            deletedColumn: 'text_ad_deleted',
            fields: [
                'text_ad_name' => 's', 'text_ad_headline' => 's',
                'text_ad_description' => 's', 'text_ad_display_url' => 's',
                'aff_campaign_id' => 'i', 'landing_page_id' => 'i', 'text_ad_type' => 'i',
            ],
            selectColumns: [
                'text_ad_id', 'user_id', 'text_ad_name', 'text_ad_headline',
                'text_ad_description', 'text_ad_display_url', 'aff_campaign_id',
                'landing_page_id', 'text_ad_type', 'text_ad_deleted',
            ],
        );
    }
}
