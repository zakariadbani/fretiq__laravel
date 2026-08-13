<?php

namespace App\Http\Controllers\Backend;

use App\Crud\ViewConfigs\InboxViewConfig;
use App\DataTables\Backend\InboxEmailsDataTable;
use App\Models\InboxEmail;
use App\Models\SenderIdentity;
use App\Services\Inbox\InboxTriageService;
use App\Support\ConfigEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class InboxEmailController extends BackendController
{
    public function __construct(Request $request, InboxEmail $model, InboxEmailsDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view inbox')->only(['index', 'view']);
        $this->middleware('permission:edit inbox')->only(['resync', 'updateStatus', 'triage']);
        $this->modelName = 'inbox';
        $this->listTitle = 'Boîte de réception';
    }

    public function index()
    {
        $activeInboxes = SenderIdentity::query()
            ->where('is_active', true)
            ->where('imap_enabled', true)
            ->get(['last_polled_at']);

        return $this->currentDataTable->render('backend.contents.inbox.crud.index', [
            'listTitle' => $this->listTitle,
            'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            'activeInboxCount' => $activeInboxes->count(),
            'lastPolledAt' => $activeInboxes->max('last_polled_at'),
        ]);
    }

    public function view(int $id)
    {
        $email = InboxEmail::with([
            'senderIdentity',
            'contact.company',
            'campaignRecipient.run.campaign',
        ])->findOrFail($id);

        return view('backend.contents.inbox.crud.view', [
            'model' => $email,
            'viewConfig' => InboxViewConfig::make($email),
        ]);
    }

    public function resync(): JsonResponse
    {
        try {
            $exitCode = Artisan::call('inbox:poll');

            return response()->json([
                'success' => $exitCode === 0,
                'message' => $exitCode === 0
                    ? 'Relève IMAP ajoutée à la file.'
                    : 'La relève IMAP a retourné une erreur.',
            ], $exitCode === 0 ? 200 : 500);
        } catch (\Throwable $exception) {
            Log::error('Manual inbox poll dispatch failed.', ['exception_class' => $exception::class]);

            return response()->json([
                'success' => false,
                'message' => 'Impossible de lancer la relève IMAP.',
            ], 500);
        }
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', ConfigEnum::in('inbox_statuses')],
        ]);

        $email = InboxEmail::findOrFail($id);
        $changed = InboxEmail::query()
            ->whereKey($email->id)
            ->whereNull('processed_at')
            ->update([
                'status' => $data['status'],
                'processed_at' => $data['status'] === InboxEmail::STATUS_NOUVEAU ? null : now(),
            ]);

        $email->refresh();

        return response()->json([
            'success' => true,
            'changed' => $changed > 0,
            'status' => $email->status,
            'message' => $changed > 0
                ? 'Statut mis à jour.'
                : ($email->processed_at !== null
                    ? 'Ce message est déjà traité. Son statut reste inchangé.'
                    : 'Le statut est déjà à jour.'),
        ]);
    }

    public function triage(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', InboxTriageService::ACTIONS)],
        ]);
        $email = InboxEmail::findOrFail($id);

        try {
            $applied = app(InboxTriageService::class)
                ->triage($email, $data['action'], $request->user());
        } catch (\InvalidArgumentException $exception) {
            return redirect()->route('admin.inbox.view', $email->id)
                ->with('error', $exception->getMessage());
        }

        if (! $applied) {
            return redirect()->route('admin.inbox.view', $email->id)
                ->with('info', 'Ce message est deja traite. Aucune modification appliquee.');
        }

        $messages = [
            'interested' => html_entity_decode('Demande cr&eacute;&eacute;e et message trait&eacute;.'),
            'not_interested' => html_entity_decode('R&eacute;ponse enregistr&eacute;e et message class&eacute; non int&eacute;ress&eacute;.'),
            'automatic' => html_entity_decode('Message automatique class&eacute;.'),
        ];

        return redirect()->route('admin.inbox.view', $email->id)
            ->with('success', $messages[$data['action']]);
    }
}
