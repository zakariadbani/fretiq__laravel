<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\ContactsDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Http\Request;

class ContactController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, Contact $model, ContactsDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view contacts')->only(['index', 'view']);
        $this->middleware('permission:create contacts')->only(['create', 'store']);
        $this->middleware('permission:edit contacts')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:delete contacts')->only(['delete']);

        $this->listTitle = 'Contacts';
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:       Contact::class,
            modelName:        'contacts',
            dataTableClass:   ContactsDataTable::class,
            permissionEntity: 'contacts',
            prefixName:       'admin',
            titleField:       'name',
        ));
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.contacts.crud.index',
            [
                'listTitle'       => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            ]
        );
    }

    /**
     * Provide select options to the create/edit form views.
     */
    protected function getViewVars(): array
    {
        return [
            'companies'     => Company::orderBy('name')->get(['id', 'name']),
            'statuses'      => config('global.data.contact_statuses', []),
            'legalBases'    => config('global.data.contact_legal_bases', []),
            'emailKinds'    => config('global.data.contact_email_kinds', []),
            'sources'       => config('global.data.contact_sources', []),
        ];
    }

    /**
     * After a contact is saved, honour an optional return_url from the request
     * (set by the inline company contact modal) so the user lands back on the
     * company Contacts tab instead of the generic contacts index.
     *
     * Returns a JsonResponse when return_url is present, which Crudable::store()
     * and Crudable::update() forward directly to the caller (the modal AJAX
     * fetch). Falls through to default Crudable behaviour when absent.
     *
     * @param array $attributes
     * @param \App\Models\Contact $model
     * @return \Illuminate\Http\JsonResponse|void
     */
    protected function afterSave(array $attributes, $model)
    {
        $returnUrl = $this->currentRequest->input('return_url');

        if ($returnUrl) {
            return response()->json([
                'message'  => 'success',
                'model'    => $model,
                'redirect' => $returnUrl,
            ], 200);
        }
    }
}
