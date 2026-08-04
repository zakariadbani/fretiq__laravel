<?php

declare(strict_types=1);

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\InboxEmail;
use App\Models\SenderIdentity;
use Illuminate\Http\Request;

class InboxEmailsDataTable extends BackendDataTable
{
    protected $skipDefaultAction = true;

    protected $columns = [
        'from_email' => ['title' => 'Expéditeur', 'orderable' => true, 'searchable' => true, 'raw' => true],
        'subject' => ['title' => 'Objet', 'orderable' => true, 'searchable' => true, 'raw' => true],
        'sender_identity' => ['title' => 'Identité', 'orderable' => false, 'searchable' => false, 'raw' => true],
        'contact' => ['title' => 'Contact', 'orderable' => false, 'searchable' => false, 'raw' => true],
        'status' => ['title' => 'Statut', 'orderable' => true, 'searchable' => false, 'raw' => true],
        'received_at' => ['title' => 'Reçu le', 'orderable' => true, 'searchable' => false],
    ];

    protected $table_filters = [
        'status' => [
            'type' => 'select_enum',
            'filterKey' => 'status',
            'configKey' => 'inbox_statuses',
            'title' => 'Statut',
        ],
        'sender_identity_id' => [
            'type' => 'select',
            'filterKey' => 'sender_identity_id',
            'title' => 'Identité',
            'options' => [],
        ],
    ];

    public function __construct(InboxEmail $model, Request $request)
    {
        parent::__construct($model, $request);

        $this->table_filters['sender_identity_id']['options'] = SenderIdentity::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (SenderIdentity $identity) => ['value' => $identity->id, 'text' => $identity->name])
            ->all();
    }

    public function query()
    {
        return $this->currentModel->newQuery()->with([
            'senderIdentity',
            'contact.company',
            'campaignRecipient.run.campaign',
        ]);
    }
    public function html()
    {
        return parent::html()->orderBy(6, 'desc');
    }

    protected function createEditColumns(): void
    {
        $statuses = config('global.data.inbox_statuses', []);

        $this->datatables->editColumn('action', fn (InboxEmail $row) =>
            '<a href="' . e(route('admin.inbox.view', $row->id)) . '" class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm" title="Voir"><i class="bi bi-eye"></i></a>'
        );

        $this->datatables->editColumn('from_email', function (InboxEmail $row) {
            $name = $row->from_name ? '<span class="fw-bold d-block">' . e($row->from_name) . '</span>' : '';
            return $name . '<span class="text-muted fs-7">' . e($row->from_email) . '</span>';
        });

        $this->datatables->editColumn('subject', fn (InboxEmail $row) =>
            '<a href="' . e(route('admin.inbox.view', $row->id)) . '" class="text-gray-800 text-hover-primary fw-semibold">' . e($row->subject ?: '(sans objet)') . '</a>'
        );

        $this->datatables->addColumn('sender_identity', fn (InboxEmail $row) =>
            $row->senderIdentity ? e($row->senderIdentity->name) : '<span class="text-muted">—</span>'
        );

        $this->datatables->addColumn('contact', function (InboxEmail $row) {
            if (! $row->contact) {
                return '<span class="text-muted">—</span>';
            }
            return '<a href="' . e(route('admin.contacts.view', $row->contact->id)) . '" class="text-primary fw-semibold">' . e($row->contact->email) . '</a>';
        });

        $this->datatables->editColumn('status', function (InboxEmail $row) use ($statuses) {
            $config = $statuses[$row->status] ?? [];
            return '<span class="badge badge-light-' . e($config['color'] ?? 'secondary') . '">' . e($config['label'] ?? $row->status) . '</span>';
        });

        $this->datatables->editColumn('received_at', fn (InboxEmail $row) =>
            $row->received_at?->format('d/m/Y H:i') ?? '—'
        );
    }

    protected function dataTableLanguage(): array
    {
        $emptyTable = match (true) {
            auth()->user()?->can('view sender_identities') => 'Aucun message relevé. <a href="' . e(route('admin.sender_identities.index')) . '">Configurer les boîtes de réception</a>',
            auth()->user()?->can('edit inbox') => 'Aucun message relevé. Lancez une relève avec « Relever maintenant ».',
            default => 'Aucun message relevé. Demandez à un administrateur de configurer la relève.',
        };

        return array_replace(parent::dataTableLanguage(), [
            'emptyTable' => $emptyTable,
            'zeroRecords' => 'Aucun message ne correspond à ces filtres. Modifiez ou réinitialisez les filtres.',
        ]);
    }

    protected function getEntityName(): string
    {
        return 'email';
    }
}
