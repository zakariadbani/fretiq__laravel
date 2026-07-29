<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\SenderIdentitiesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\SenderIdentity;
use Illuminate\Http\Request;

class SenderIdentityController extends BackendController
{
    private const IMAP_CONNECTION_FIELDS = ['imap_host', 'imap_port', 'imap_username', 'imap_encryption', 'imap_validate_cert'];

    use Crudable {
        beforeSave as crudBeforeSave;
    }
    use Datatableable;

    /**
     * Whitelist of boolean fields that may be toggled via executeSwitch.
     *
     * @var array<string>
     */
    protected $toggleableFields = ['is_default', 'is_active'];

    public function __construct(Request $request, SenderIdentity $model, SenderIdentitiesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view sender_identities')->only(['index', 'view']);
        $this->middleware('permission:create sender_identities')->only(['create', 'store']);
        $this->middleware('permission:edit sender_identities')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:edit sender_identities')->only(['testImap']);
        $this->middleware('permission:delete sender_identities')->only(['delete']);

        $this->listTitle = "Identités d'expéditeur";
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:       SenderIdentity::class,
            modelName:        'sender_identities',
            dataTableClass:   SenderIdentitiesDataTable::class,
            permissionEntity: 'sender_identities',
            prefixName:       'admin',
            titleField:       'name',
        ));

        $this->viewConfigClass = \App\Crud\ViewConfigs\SenderIdentityViewConfig::class;
    }

    /**
     * Override executeSwitch to enforce single-default rule when toggling is_default.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function executeSwitch($id)
    {
        $request = $this->currentRequest->all();
        $model   = SenderIdentity::find((int) $id);

        if ($model === null) {
            return response()->json(['success' => false, 'msg' => trans('app.not_found')]);
        }

        $allowedFields = $this->toggleableFields;
        $field         = $request['field'] ?? '';

        if (!in_array($field, $allowedFields, true)) {
            return response()->json(['success' => false, 'msg' => trans('app.cannot_delete')], 403);
        }

        $state = (int) $request['state'];
        $model->update([$field => $state]);

        // Enforce single-default: if we just enabled is_default, clear all others.
        if ($field === 'is_default' && $state === 1) {
            SenderIdentity::where('id', '!=', $model->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.sender_identities.crud.index',
            [
                'listTitle'       => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            ]
        );
    }

    protected function beforeSave($id = null): array
    {
        $attributes = $this->crudBeforeSave($id);
        $identity = $id === null ? null : SenderIdentity::find((int) $id);
        if (array_key_exists('imap_enabled', $attributes)) {
            $attributes['imap_enabled'] = (bool) $attributes['imap_enabled'] ? 1 : 0;
        }

        if (blank($attributes['imap_password'] ?? null)) {
            if ($identity !== null && $this->imapConnectionChanged($identity, $attributes)) {
                $attributes['imap_password'] = null;
            } elseif ($identity !== null && (bool) ($attributes['imap_enabled'] ?? $identity->imap_enabled)) {
                $attributes['imap_password'] = $identity->imap_password;
            } else {
                unset($attributes['imap_password']);
            }
        }

        return $attributes;
    }

    public function testImap(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $identity = SenderIdentity::find((int) $id);
        if ($identity === null) {
            return response()->json(['success' => false, 'message' => 'Identité introuvable.'], 404);
        }

        $attributes = $request->validate([
            'imap_host' => 'sometimes|nullable|string|max:255',
            'imap_port' => 'sometimes|nullable|integer|min:1|max:65535',
            'imap_username' => 'sometimes|nullable|string|max:255',
            'imap_password' => 'sometimes|nullable|string|max:1000',
            'imap_encryption' => 'sometimes|nullable|in:ssl,tls,none',
            'imap_validate_cert' => 'sometimes|boolean',
        ]);
        $testing = clone $identity;
        $connectionChanged = $this->imapConnectionChanged($identity, $attributes);

        foreach (self::IMAP_CONNECTION_FIELDS as $field) {
            if (array_key_exists($field, $attributes)) {
                $testing->setAttribute($field, $attributes[$field]);
            }
        }

        if (blank($attributes['imap_password'] ?? null)) {
            if ($connectionChanged) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saisissez le mot de passe pour tester de nouveaux paramètres IMAP.',
                ], 422);
            }
        } else {
            $testing->imap_password = $attributes['imap_password'];
        }

        if (blank($testing->imap_host) || blank($testing->imap_username) || blank($testing->imap_password)) {
            return response()->json([
                'success' => false,
                'message' => "Renseignez l'hôte, l'utilisateur et le mot de passe IMAP avant de tester.",
            ], 422);
        }

        $imap = app(\App\Services\Inbox\InboxImapService::class);

        try {
            $imap->testConnection($testing);
            SenderIdentity::whereKey($identity->id)->update([
                'last_poll_error' => null,
                'consecutive_poll_failures' => 0,
            ]);

            return response()->json(['success' => true, 'message' => 'Connexion IMAP réussie.']);
        } catch (\Throwable $exception) {
            $detail = $imap->redact($testing, $exception->getMessage());

            \Illuminate\Support\Facades\Log::warning('IMAP connection test failed.', [
                'sender_identity_id' => $identity->id,
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') && $detail !== ''
                    ? 'Connexion IMAP impossible : ' . $detail
                    : 'Connexion IMAP impossible. Vérifiez les paramètres.',
            ], 422);
        }
    }

    private function imapConnectionChanged(SenderIdentity $identity, array $attributes): bool
    {
        foreach (self::IMAP_CONNECTION_FIELDS as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $current = $identity->{$field};
            $incoming = $attributes[$field];
            if ($field === 'imap_port') {
                $current = (int) $current;
                $incoming = (int) $incoming;
            } elseif ($field === 'imap_validate_cert') {
                $current = (bool) $current;
                $incoming = (bool) $incoming;
            } else {
                $current = (string) $current;
                $incoming = (string) $incoming;
            }

            if ($current !== $incoming) {
                return true;
            }
        }

        return false;
    }

    /**
     * After saving, ensure at most one identity is marked as default.
     * If the saved identity has is_default=true, reset all other identities to false.
     *
     * This handles two code paths:
     *   - Form submit: checkbox checked → is_default='1' in $attributes.
     *   - executeSwitch: state=1, field='is_default' → model already persisted with is_default=true.
     *
     * @param array $attributes
     * @param SenderIdentity $model
     * @return void
     */
    protected function afterSave(array $attributes, $model): void
    {
        // Re-read from DB so we always have the fresh persisted value.
        $fresh = SenderIdentity::find($model->id);

        if ($fresh && $fresh->is_default) {
            SenderIdentity::where('id', '!=', $fresh->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}
