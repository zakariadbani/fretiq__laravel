<?php

namespace Tests\Unit;

use App\Http\Controllers\Backend\CampaignController;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class CampaignRecipientScopeQueryTest extends TestCase
{
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

    private function invokeControllerMethod(string $name, array $arguments): mixed
    {
        $controller = (new ReflectionClass(CampaignController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CampaignController::class, $name);

        return $method->invoke($controller, ...$arguments);
    }
}
