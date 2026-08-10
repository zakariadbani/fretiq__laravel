<?php

declare(strict_types=1);

namespace App\Services\Zoho\V2\Sync;

use App\Models\Zoho\ZohoSyncBatch;
use App\Services\Zoho\V2\Mappers\ZohoMapperResolver;
use App\Services\Zoho\V2\Registry\ModuleDefinition;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/** Local-only typed-field repair; it deliberately has no Zoho transport dependency. */
final class ZohoLocalMirrorRemapper
{
    public function __construct(
        private readonly ZohoModuleRegistry $registry,
        private readonly ZohoMapperResolver $mappers,
        private readonly ZohoMirrorMutationGuard $guard,
    ) {}

    /** @return array{scanned:int,changed:int,unchanged:int,failed:int,fields:array<string,int>,errors:list<string>} */
    public function remap(string $module, int $chunk, bool $apply): array
    {
        $definition = $this->registry->get($module);

        if ($apply && $this->hasActiveSyncWork()) {
            throw new RuntimeException('A Zoho sync or post-reconciliation batch is still active.');
        }

        $result = [
            'scanned' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'fields' => [],
            'errors' => [],
        ];
        $lease = $apply ? $this->guard->claim($module, 'local-remap') : null;

        if ($apply && $lease === null) {
            throw new RuntimeException("Module [{$module}] is currently owned by a Zoho sync.");
        }

        try {
            $this->records($definition)->chunkById($chunk, function ($records) use ($definition, $apply, $lease, &$result): void {
                if ($apply) {
                    $this->guard->assertOwned($lease);
                }

                foreach ($records as $record) {
                    $result['scanned']++;

                    try {
                        $context = [
                            'activity_type' => $definition->activityType,
                            'seen_at' => $record->last_seen_at ?? CarbonImmutable::now(),
                            'synced_at' => $record->last_synced_at ?? CarbonImmutable::now(),
                            'sync_batch_id' => $record->sync_batch_id,
                            'schema_hash' => $record->field_schema_hash,
                        ];
                        $mapped = $this->mappers->forModule($definition->key)->map((array) $record->raw_payload, $context);
                        $changes = $this->changes($definition, $record, $mapped);

                        if ($changes === []) {
                            $result['unchanged']++;

                            continue;
                        }

                        $result['changed']++;
                        foreach (array_keys($changes) as $field) {
                            $result['fields'][$field] = ($result['fields'][$field] ?? 0) + 1;
                        }

                        if ($apply) {
                            $this->guard->withOwnedLease($lease, static function () use ($record, $changes): void {
                                $record->timestamps = false;
                                $record->fill($changes);
                                $record->saveQuietly();
                            });
                        }
                    } catch (ZohoLeaseLostException $exception) {
                        throw $exception;
                    } catch (Throwable) {
                        $result['failed']++;
                        if (count($result['errors']) < 20) {
                            $result['errors'][] = sprintf('%s: local row %d could not be mapped.', $definition->key, $record->getKey());
                        }
                    }
                }
            });
        } finally {
            if ($lease !== null) {
                $this->guard->release($lease);
            }
        }

        ksort($result['fields']);

        return $result;
    }

    private function records(ModuleDefinition $definition): Builder
    {
        $model = $definition->modelClass;
        $query = $model::query()->orderBy('id');

        if ($definition->activityType !== null) {
            $query->where('activity_type', $definition->activityType);
        }

        return $query;
    }

    /** @param array<string,mixed> $mapped @return array<string,mixed> */
    private function changes(ModuleDefinition $definition, Model $record, array $mapped): array
    {
        $changes = [];

        foreach (array_keys($definition->promotedFieldSources) as $field) {
            if (array_key_exists($field, $mapped) && $this->valuesDiffer($record->getAttribute($field), $mapped[$field])) {
                $changes[$field] = $mapped[$field];
            }
        }

        return $changes;
    }

    private function valuesDiffer(mixed $current, mixed $mapped): bool
    {
        if ($current === null || $mapped === null) {
            return $current !== $mapped;
        }

        return $current != $mapped;
    }

    private function hasActiveSyncWork(): bool
    {
        return ZohoSyncBatch::query()
            ->where(function (Builder $query): void {
                $query->whereNull('completed_at')
                    ->orWhereIn('post_reconciliation_status', ['pending', 'running', 'retrying']);
            })
            ->exists();
    }
}
