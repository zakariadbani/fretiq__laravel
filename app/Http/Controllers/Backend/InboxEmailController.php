<?php

namespace App\Http\Controllers\Backend;

use App\Crud\ViewConfigs\InboxViewConfig;
use App\DataTables\Backend\InboxEmailsDataTable;
use App\Models\InboxEmail;
use App\Support\ConfigEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class InboxEmailController extends BackendController
{
    public function __construct(Request $request, InboxEmail $model, InboxEmailsDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view inbox')->only(['index', 'view']);
        $this->middleware('permission:edit inbox')->only(['resync', 'updateStatus']);
        $this->modelName = 'inbox';
        $this->listTitle = 'Boîte de réception';
    }

    public function index()
    {
        return $this->currentDataTable->render('backend.contents.inbox.crud.index', [
            'listTitle' => $this->listTitle,
            'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
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
        $email->update([
            'status' => $data['status'],
            'processed_at' => $data['status'] === InboxEmail::STATUS_NOUVEAU ? null : now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Statut mis à jour.']);
    }
}
