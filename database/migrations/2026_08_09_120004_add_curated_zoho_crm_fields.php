<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_leads', function (Blueprint $table): void {
            foreach ([
                'converted_deal_zoho_id', 'title', 'client_type', 'transport_type', 'language', 'city',
                'website', 'incoterm', 'destination', 'volume', 'competitor', 'origin_destination',
                'mobile', 'secondary_phone', 'unsubscribed_mode',
            ] as $column) {
                $table->string($column)->nullable();
            }
            $table->dateTime('converted_at')->nullable();
            $table->text('address')->nullable();
            $table->text('observation')->nullable();
            $table->boolean('email_opt_out')->nullable();
            $table->dateTime('unsubscribed_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->json('tags')->nullable();
        });

        Schema::table('zoho_accounts', function (Blueprint $table): void {
            foreach ([
                'active_status', 'account_status', 'city', 'language', 'commercial_name', 'assignment_type',
                'accounting_number', 'ice', 'tax_id', 'trade_register', 'cnss', 'business_tax_number',
                'trade_register_center', 'payment_mode', 'transport_type', 'volume', 'competitor',
                'origin_destination', 'logistics_manager_zoho_id',
            ] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('address')->nullable();
            $table->text('observation')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->json('tags')->nullable();
        });

        Schema::table('zoho_contacts', function (Blueprint $table): void {
            foreach (['company_name', 'city', 'postal_code', 'language', 'lead_source', 'unsubscribed_mode', 'mobile', 'salutation'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('address')->nullable();
            $table->longText('description')->nullable();
            foreach (['email_opt_out', 'email_opened', 'link_clicked'] as $column) {
                $table->boolean($column)->nullable();
            }
            $table->dateTime('unsubscribed_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->json('tags')->nullable();
        });

        Schema::table('zoho_deals', function (Blueprint $table): void {
            foreach ([
                'pipeline', 'stackability', 'origin', 'destination', 'incoterm', 'cargo_description',
                'gross_weight', 'volume', 'dimensions', 'departure_frequency', 'package_type', 'quote_type',
                'transport_type', 'transit_time', 'expires_on',
            ] as $column) {
                $table->string($column)->nullable();
            }
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->integer('quantity')->nullable();
            $table->longText('description')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->dateTime('stage_modified_at')->nullable();
            $table->json('tags')->nullable();
        });

        Schema::table('zoho_quotes', function (Blueprint $table): void {
            foreach ([
                'gross_weight', 'volume', 'quantity_text', 'package_type', 'equipment_type', 'free_time',
                'dangerous_goods_status', 'un_number', 'dangerous_goods_class', 'dimensions', 'loading_meters',
            ] as $column) {
                $table->string($column)->nullable();
            }
            $table->json('incoterms')->nullable();
            $table->integer('transit_time_days')->nullable();
            $table->json('stackability')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->json('tags')->nullable();
        });

        Schema::table('zoho_deal_stage_history', function (Blueprint $table): void {
            $table->string('moved_to_stage')->nullable();
            $table->integer('stage_duration_days')->nullable();
        });

        Schema::table('zoho_field_manifests', function (Blueprint $table): void {
            $table->json('mapping_gaps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('zoho_field_manifests', function (Blueprint $table): void {
            $table->dropColumn('mapping_gaps');
        });
        Schema::table('zoho_deal_stage_history', function (Blueprint $table): void {
            $table->dropColumn(['moved_to_stage', 'stage_duration_days']);
        });
        Schema::table('zoho_quotes', function (Blueprint $table): void {
            $table->dropColumn([
                'gross_weight', 'volume', 'quantity_text', 'package_type', 'transit_time_days', 'equipment_type',
                'free_time', 'incoterms', 'stackability', 'dangerous_goods_status', 'un_number',
                'dangerous_goods_class', 'dimensions', 'loading_meters', 'last_activity_at', 'tags',
            ]);
        });
        Schema::table('zoho_deals', function (Blueprint $table): void {
            $table->dropColumn([
                'exchange_rate', 'pipeline', 'stackability', 'last_activity_at', 'stage_modified_at', 'tags',
                'origin', 'destination', 'incoterm', 'cargo_description', 'gross_weight', 'volume', 'quantity',
                'dimensions', 'departure_frequency', 'package_type', 'quote_type', 'transport_type',
                'transit_time', 'expires_on', 'description',
            ]);
        });
        Schema::table('zoho_contacts', function (Blueprint $table): void {
            $table->dropColumn([
                'company_name', 'address', 'city', 'postal_code', 'language', 'lead_source', 'email_opt_out',
                'email_opened', 'link_clicked', 'unsubscribed_mode', 'unsubscribed_at', 'last_activity_at',
                'description', 'tags', 'mobile', 'salutation',
            ]);
        });
        Schema::table('zoho_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'active_status', 'account_status', 'address', 'city', 'language', 'commercial_name',
                'assignment_type', 'accounting_number', 'ice', 'tax_id', 'trade_register', 'cnss',
                'business_tax_number', 'trade_register_center', 'payment_mode', 'transport_type', 'volume',
                'competitor', 'origin_destination', 'observation', 'logistics_manager_zoho_id',
                'last_activity_at', 'tags',
            ]);
        });
        Schema::table('zoho_leads', function (Blueprint $table): void {
            $table->dropColumn([
                'converted_deal_zoho_id', 'converted_at', 'title', 'client_type', 'transport_type', 'language',
                'address', 'city', 'website', 'incoterm', 'destination', 'volume', 'competitor',
                'origin_destination', 'observation', 'email_opt_out', 'unsubscribed_mode', 'unsubscribed_at',
                'last_activity_at', 'tags', 'mobile', 'secondary_phone',
            ]);
        });
    }
};
