<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\ContactsDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\Suppression;
use Illuminate\Http\Request;

class ContactController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, Contact $model, ContactsDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        // Assigned at runtime — avoids trait+class property default conflict (FATAL if done as class property).
        $this->viewConfigClass = \App\Crud\ViewConfigs\ContactViewConfig::class;

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
     * Override the trait's view() to eager-load company and inject $stats.
     */
    public function view($id)
    {
        $model = $this->currentModel->with('company')->find($id);

        if ($model === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.contacts.index'));
        }

        return $this->getView('backend.contents.contacts.crud.view')
            ->with('title', __('overview'))
            ->with('model', $model)
            ->with('stats', $this->contactStats($model));
    }

    /**
     * Build analytics for one contact.
     *
     * Queries:
     *   Q1 — CampaignRecipient rows for this contact (single fetch, filtered in PHP)
     *   Q2 — Demande count by contact_id
     *   Q3 — Suppression existence check by email
     */
    private function contactStats(Contact $contact): array
    {
        // Q1 — All recipient rows for this contact
        $recipients = CampaignRecipient::where('contact_id', $contact->id)->get();

        $emailsSent    = $recipients->filter(fn ($r) => !is_null($r->sent_at))->count();
        $emailsOpened  = $recipients->filter(fn ($r) => !is_null($r->opened_at))->count();
        $emailsClicked = $recipients->filter(fn ($r) => !is_null($r->clicked_at))->count();
        $emailsReplied = $recipients->filter(fn ($r) => !is_null($r->replied_at))->count();

        // Délivrés — status-based fallback (no delivered_at column)
        $emailsDelivered = $recipients->whereIn('status', ['delivered', 'opened', 'clicked', 'replied'])->count();

        // Q2 — Demande count
        $demandesTotal = Demande::where('contact_id', $contact->id)->count();

        // Q3 — Suppression check by email
        $suppressed = $contact->email
            ? Suppression::isSuppressed($contact->email)
            : false;

        // Funnel series (mirrors CompanyController funnel shape)
        $funnelSeries = [
            $emailsSent,
            $emailsDelivered,
            $emailsOpened,
            $emailsClicked,
            $emailsReplied,
        ];

        return [
            'emails_sent'    => $emailsSent,
            'emails_opened'  => $emailsOpened,
            'emails_clicked' => $emailsClicked,
            'emails_replied' => $emailsReplied,
            'demandes_total' => $demandesTotal,
            'suppressed'     => $suppressed,
            'funnel'         => [
                'labels' => ['Envoyés', 'Délivrés', 'Ouverts', 'Cliqués', 'Répondus'],
                'series' => $funnelSeries,
            ],
        ];
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
            'companies'  => Company::orderBy('name')->get(['id', 'name']),
            'statuses'   => config('global.data.contact_statuses', []),
            'legalBases' => config('global.data.contact_legal_bases', []),
            'emailKinds' => config('global.data.contact_email_kinds', []),
            'sources'    => config('global.data.contact_sources', []),
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
