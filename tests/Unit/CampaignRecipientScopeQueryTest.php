<?php

namespace Tests\Unit;

use App\Http\Controllers\Backend\CampaignController;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class CampaignRecipientScopeQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollup_scope_uses_mysql_57_compatible_grouped_latest_recipient_query(): void
    {
        $query = $this->invokeControllerMethod('recipientScopeQuery', [
            collect([11, 12]),
            ['run' => null, 'q' => ''],
        ]);

        $sql = strtolower($query->toSql());
        $unquotedSql = str_replace('`', '', $sql);

        $this->assertStringNotContainsString(' over ', $sql);
        $this->assertStringNotContainsString('row_number', $sql);
        $this->assertStringContainsString('max(id) as latest_id', $unquotedSql);
        $this->assertStringContainsString('group by contact_id', $unquotedSql);
        $this->assertStringContainsString(
            'campaign_recipients.id = recipient_rollup.latest_id',
            $unquotedSql
        );
        $this->assertStringContainsString('campaign_recipients.*', $unquotedSql);

        foreach (['envois', 'max_sent_at', 'max_opened_at', 'max_clicked_at', 'has_replied'] as $alias) {
            $this->assertStringContainsString("recipient_rollup.{$alias}", $unquotedSql);
        }
    }

    public function test_chip_count_aggregate_replaces_rollup_selection(): void
    {
        $scopeQuery = $this->invokeControllerMethod('recipientScopeQuery', [
            collect([11, 12]),
            ['run' => null, 'q' => ''],
        ]);

        $queries = DB::connection()->pretend(function () use ($scopeQuery): void {
            $this->invokeControllerMethod('recipientChipCounts', [$scopeQuery, false]);
        });

        $sql = strtolower($queries[0]['query']);
        $outerSelect = str_replace('`', '', substr($sql, 0, strpos($sql, ' from ')));

        $this->assertStringContainsString('count(*) as total', $outerSelect);
        $this->assertStringNotContainsString('campaign_recipients.*', $outerSelect);
        $this->assertStringNotContainsString('recipient_rollup.envois', $outerSelect);
    }

    /**
     * The 'clicked' chip (count + filter) must count a Zoho-style row (status
     * flipped to 'clicked' by the sync but clicked_at left null before the
     * CampaignFeedbackService fix lands / for any pre-existing row) exactly
     * like a local pixel-style row (clicked_at set, status untouched).
     */
    public function test_clicked_chip_counts_zoho_style_rows_without_a_timestamp(): void
    {
        $company = Company::create([
            'name' => 'Chip Co', 'relationship' => 'client', 'source' => 'manual', 'qualification_status' => 'pending',
        ]);
        $contactA = Contact::create([
            'company_id' => $company->id, 'email' => 'chip-a@example.test', 'name' => 'A',
            'status' => 'new', 'source' => 'manual', 'legal_basis' => 'relationship', 'email_kind' => 'role',
        ]);
        $contactB = Contact::create([
            'company_id' => $company->id, 'email' => 'chip-b@example.test', 'name' => 'B',
            'status' => 'new', 'source' => 'manual', 'legal_basis' => 'relationship', 'email_kind' => 'role',
        ]);
        $segment = Segment::create(['name' => 'Chip segment', 'scope' => 'client']);
        $template = CampaignTemplate::create(['name' => 'Chip template', 'subject' => 'S', 'html_content' => '<p>Chip</p>']);
        $sender = SenderIdentity::create(['name' => 'TCL', 'email' => 'chip-sender@example.test']);
        $campaign = Campaign::create([
            'name' => 'Chip campaign', 'segment_id' => $segment->id, 'template_id' => $template->id,
            'sender_identity_id' => $sender->id, 'schedule_type' => 'one_shot', 'scheduled_at' => now(), 'timezone' => 'Europe/Paris',
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id, 'occurrence_key' => 'chip-run-'.uniqid(), 'run_at' => now(), 'status' => 'sent',
        ]);

        // Zoho-style: status flipped to 'clicked' by the sync, timestamp-less.
        $zohoStyle = CampaignRecipient::create([
            'campaign_run_id' => $run->id, 'contact_id' => $contactA->id, 'status' => 'clicked', 'sent_at' => now(),
        ]);
        // Local/pixel-style: clicked_at set directly, status left at 'sent'.
        $localStyle = CampaignRecipient::create([
            'campaign_run_id' => $run->id, 'contact_id' => $contactB->id, 'status' => 'sent',
            'sent_at' => now(), 'clicked_at' => now(),
        ]);

        $countsScopeQuery = $this->invokeControllerMethod('recipientScopeQuery', [
            collect([$run->id]),
            ['run' => $run, 'q' => ''],
        ]);
        $counts = $this->invokeControllerMethod('recipientChipCounts', [$countsScopeQuery, true]);

        $this->assertSame(
            2,
            $counts['clicked'],
            'Both the Zoho-style (status only) and local-style (timestamp only) rows must count as clicked.',
        );

        $filterQuery = $this->invokeControllerMethod('recipientScopeQuery', [
            collect([$run->id]),
            ['run' => $run, 'q' => ''],
        ]);
        $this->invokeControllerMethod('applyRecipientChipFilter', [$filterQuery, 'clicked', true]);

        $this->assertEqualsCanonicalizing(
            [$zohoStyle->id, $localStyle->id],
            $filterQuery->pluck('id')->all(),
        );
    }

    private function invokeControllerMethod(string $name, array $arguments): mixed
    {
        $controller = (new ReflectionClass(CampaignController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CampaignController::class, $name);

        return $method->invoke($controller, ...$arguments);
    }
}
