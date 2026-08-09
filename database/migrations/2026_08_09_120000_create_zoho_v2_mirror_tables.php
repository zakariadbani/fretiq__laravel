<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mirror = function (Blueprint $table): void {
            $table->id();
            $table->string('zoho_id', 100)->unique();
            $table->string('owner_zoho_id', 100)->nullable()->index();
            $table->string('parent_zoho_id', 100)->nullable()->index();
            $table->json('raw_payload');
            $table->char('payload_hash', 64);
            $table->char('field_schema_hash', 64)->nullable();
            $table->dateTime('zoho_created_at')->nullable()->index();
            $table->dateTime('zoho_modified_at')->nullable()->index();
            $table->dateTime('last_seen_at')->nullable()->index();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('zoho_deleted_at')->nullable()->index();
            $table->string('zoho_deletion_type', 32)->nullable();
            $table->unsignedBigInteger('sync_batch_id')->nullable()->index();
            $table->timestamps();
        };

        Schema::create('zoho_users', function (Blueprint $table) use ($mirror): void {
            $mirror($table);
            $table->string('email', 320)->nullable()->index();
            $table->string('normalized_email', 320)->nullable()->index();
            $table->string('full_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('status', 32)->nullable()->index();
        });

        Schema::create('zoho_accounts', function (Blueprint $table) use ($mirror): void {
            $mirror($table);
            $table->foreignId('fretiq_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('parent_account_zoho_id', 100)->nullable()->index();
            $table->string('name')->nullable()->index();
            $table->string('account_type', 100)->nullable()->index();
            $table->string('industry', 150)->nullable()->index();
            $table->string('country', 100)->nullable()->index();
            $table->string('website')->nullable();
            $table->string('phone')->nullable();
        });

        Schema::create('zoho_contacts', function (Blueprint $table) use ($mirror): void {
            $mirror($table);
            $table->foreignId('fretiq_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('account_zoho_id', 100)->nullable()->index();
            $table->string('email', 320)->nullable()->index();
            $table->string('normalized_email', 320)->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable()->index();
            $table->string('title')->nullable();
            $table->string('phone')->nullable();
            $table->string('country', 100)->nullable()->index();
        });

        Schema::create('zoho_leads', function (Blueprint $table) use ($mirror): void {
            $mirror($table);
            $table->foreignId('fretiq_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('fretiq_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('account_zoho_id', 100)->nullable()->index();
            $table->string('contact_zoho_id', 100)->nullable()->index();
            $table->string('email', 320)->nullable()->index();
            $table->string('normalized_email', 320)->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable()->index();
            $table->string('company_name')->nullable()->index();
            $table->string('status', 100)->nullable()->index();
            $table->boolean('is_converted')->nullable()->index();
            $table->string('lead_source', 100)->nullable()->index();
            $table->string('country', 100)->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('industry', 150)->nullable()->index();
        });

        Schema::create('zoho_deals', function (Blueprint $table) use ($mirror): void {
            $mirror($table);
            $table->string('account_zoho_id', 100)->nullable()->index();
            $table->string('contact_zoho_id', 100)->nullable()->index();
            $table->string('name')->nullable()->index();
            $table->string('stage', 100)->nullable()->index();
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('probability', 7, 4)->nullable();
            $table->decimal('weighted_amount', 18, 2)->nullable();
            $table->char('currency_code', 3)->nullable()->index();
            $table->date('closing_date')->nullable()->index();
            $table->string('lead_source', 100)->nullable()->index();
        });

        Schema::create('zoho_quotes', function (Blueprint $table) use ($mirror): void {
            $mirror($table);
            $table->string('deal_zoho_id', 100)->nullable()->index();
            $table->string('account_zoho_id', 100)->nullable()->index();
            $table->string('contact_zoho_id', 100)->nullable()->index();
            $table->string('subject')->nullable()->index();
            $table->string('quote_number')->nullable()->index();
            $table->string('status', 100)->nullable()->index();
            $table->decimal('grand_total', 18, 2)->nullable();
            $table->decimal('sub_total', 18, 2)->nullable();
            $table->decimal('discount', 18, 2)->nullable();
            $table->decimal('tax', 18, 2)->nullable();
            // This Zoho organization does not expose Grand_Total/Sub_Total. This is a
            // native-currency aggregate of an authoritative Quoted_Items payload.
            $table->decimal('line_items_total', 18, 2)->nullable();
            $table->boolean('line_items_total_complete')->default(false);
            $table->char('currency_code', 3)->nullable()->index();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->date('valid_till')->nullable()->index();
            $table->string('origin')->nullable()->index();
            $table->string('destination')->nullable()->index();
            $table->json('transport_type')->nullable();
            $table->date('quote_date')->nullable()->index();
            $table->string('follow_up_status', 100)->nullable()->index();
            $table->string('country', 100)->nullable()->index();
        });

        Schema::create('zoho_products', function (Blueprint $table) use ($mirror): void {
            $mirror($table);
            $table->string('name')->nullable()->index();
            $table->string('product_code')->nullable()->index();
            $table->decimal('unit_price', 18, 2)->nullable();
            $table->char('currency_code', 3)->nullable()->index();
            $table->string('product_category', 100)->nullable()->index();
            $table->string('vendor_name')->nullable()->index();
            $table->string('vendor_zoho_id', 100)->nullable()->index();
        });

        Schema::create('zoho_quote_items', function (Blueprint $table): void {
            $table->id();
            $table->string('zoho_quote_id', 100)->index();
            $table->string('zoho_line_item_id', 100);
            $table->string('identity_source', 16)->default('fallback');
            $table->string('product_zoho_id', 100)->nullable()->index();
            $table->unsignedInteger('sequence')->nullable();
            $table->string('product_name')->nullable();
            $table->text('description')->nullable();
            $table->string('unit_of_measure', 100)->nullable();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->decimal('list_price', 18, 2)->nullable();
            $table->decimal('unit_price', 18, 2)->nullable();
            $table->string('unit_price_raw', 255)->nullable();
            $table->decimal('discount', 18, 2)->nullable();
            $table->decimal('tax', 18, 2)->nullable();
            $table->decimal('total', 18, 2)->nullable();
            $table->string('total_raw', 255)->nullable();
            $table->char('currency_code', 3)->nullable()->index();
            $table->json('raw_payload');
            $table->char('payload_hash', 64);
            $table->char('field_schema_hash', 64)->nullable();
            $table->dateTime('zoho_created_at')->nullable()->index();
            $table->dateTime('zoho_modified_at')->nullable()->index();
            $table->dateTime('last_seen_at')->nullable()->index();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('zoho_deleted_at')->nullable()->index();
            $table->string('zoho_deletion_type', 32)->nullable();
            $table->unsignedBigInteger('sync_batch_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['zoho_quote_id', 'zoho_line_item_id']);
        });

        Schema::create('zoho_activities', function (Blueprint $table): void {
            $table->id();
            $table->string('activity_type', 32)->index();
            $table->string('zoho_id', 100);
            $table->string('owner_zoho_id', 100)->nullable()->index();
            $table->string('parent_zoho_id', 100)->nullable()->index();
            $table->string('contact_zoho_id', 100)->nullable()->index();
            $table->string('subject')->nullable()->index();
            $table->string('status', 100)->nullable()->index();
            $table->dateTime('activity_at')->nullable()->index();
            $table->dateTime('due_at')->nullable()->index();
            $table->dateTime('start_at')->nullable()->index();
            $table->dateTime('end_at')->nullable()->index();
            $table->json('raw_payload');
            $table->char('payload_hash', 64);
            $table->char('field_schema_hash', 64)->nullable();
            $table->dateTime('zoho_created_at')->nullable()->index();
            $table->dateTime('zoho_modified_at')->nullable()->index();
            $table->dateTime('last_seen_at')->nullable()->index();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('zoho_deleted_at')->nullable()->index();
            $table->string('zoho_deletion_type', 32)->nullable();
            $table->unsignedBigInteger('sync_batch_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['activity_type', 'zoho_id']);
        });

        Schema::create('zoho_deal_stage_history', function (Blueprint $table): void {
            $table->id();
            $table->string('zoho_id', 100)->unique();
            $table->string('deal_zoho_id', 100)->index();
            $table->string('parent_zoho_id', 100)->nullable()->index();
            $table->string('stage', 100)->nullable()->index();
            $table->string('previous_stage', 100)->nullable();
            $table->string('owner_zoho_id', 100)->nullable()->index();
            $table->dateTime('occurred_at')->nullable()->index();
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('probability', 7, 4)->nullable();
            $table->decimal('expected_revenue', 18, 2)->nullable();
            $table->char('currency_code', 3)->nullable()->index();
            $table->date('closing_date')->nullable()->index();
            $table->json('raw_payload');
            $table->char('payload_hash', 64);
            $table->char('field_schema_hash', 64)->nullable();
            $table->dateTime('zoho_created_at')->nullable();
            $table->dateTime('zoho_modified_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('zoho_deleted_at')->nullable()->index();
            $table->string('zoho_deletion_type', 32)->nullable();
            $table->unsignedBigInteger('sync_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('zoho_quote_status_history', function (Blueprint $table): void {
            $table->id();
            $table->string('zoho_id', 100)->unique();
            $table->string('quote_zoho_id', 100)->index();
            $table->string('parent_zoho_id', 100)->nullable()->index();
            $table->string('status', 100)->nullable()->index();
            $table->string('previous_status', 100)->nullable();
            $table->string('owner_zoho_id', 100)->nullable()->index();
            $table->dateTime('occurred_at')->nullable()->index();
            $table->json('raw_payload');
            $table->char('payload_hash', 64);
            $table->char('field_schema_hash', 64)->nullable();
            $table->dateTime('zoho_created_at')->nullable();
            $table->dateTime('zoho_modified_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('zoho_deleted_at')->nullable()->index();
            $table->string('zoho_deletion_type', 32)->nullable();
            $table->unsignedBigInteger('sync_batch_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('zoho_actions_commercials', function (Blueprint $table) use ($mirror): void {
            $mirror($table);
            $table->string('name')->nullable()->index();
            $table->text('comment')->nullable();
            $table->string('status', 100)->nullable()->index();
            $table->string('priority', 100)->nullable()->index();
            $table->dateTime('action_at')->nullable()->index();
            $table->dateTime('due_at')->nullable()->index();
            $table->string('contact_name')->nullable()->index();
            $table->string('account_name')->nullable()->index();
            $table->string('prospect_name')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
        });

        Schema::create('zoho_transport_international', function (Blueprint $table) use ($mirror): void {
            $mirror($table);
            $table->string('name')->nullable()->index();
            $table->string('status', 100)->nullable()->index();
            $table->string('email', 320)->nullable()->index();
            $table->string('secondary_email', 320)->nullable()->index();
            $table->char('currency_code', 3)->nullable()->index();
            $table->decimal('exchange_rate', 18, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_transport_international');
        Schema::dropIfExists('zoho_actions_commercials');
        Schema::dropIfExists('zoho_quote_status_history');
        Schema::dropIfExists('zoho_deal_stage_history');
        Schema::dropIfExists('zoho_activities');
        Schema::dropIfExists('zoho_quote_items');
        Schema::dropIfExists('zoho_products');
        Schema::dropIfExists('zoho_quotes');
        Schema::dropIfExists('zoho_deals');
        Schema::dropIfExists('zoho_leads');
        Schema::dropIfExists('zoho_contacts');
        Schema::dropIfExists('zoho_accounts');
        Schema::dropIfExists('zoho_users');
    }
};
