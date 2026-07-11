<?php

namespace Tests\Feature\Backend;

use App\Models\Segment;
use Tests\TestCase;

class SegmentManualModeFormTest extends TestCase
{
    public function test_form_renders_the_french_manual_selected_contacts_control(): void
    {
        $segment = new Segment([
            'name' => 'Contacts choisis',
            'scope' => 'client',
            'is_manual' => true,
        ]);

        $html = view('backend.contents.segments.crud.form', [
            'model' => $segment,
            'modelName' => 'segments',
            'route' => '/admin/segments',
            'scopes' => config('global.data.segment_scopes'),
            'sectors' => [],
            'countries' => [],
            'contactStatuses' => config('global.data.contact_statuses'),
            'stats' => [],
            'title' => 'Modifier le segment',
            'page' => 'edit',
            'method' => 'post',
            'viewConfig' => [],
        ])->render();

        $this->assertStringContainsString('Contacts sélectionnés uniquement', $html);
        $this->assertStringContainsString('Audience dynamique', $html);
        $this->assertStringContainsString('name="is_manual"', $html);
        $this->assertStringContainsString('data-segment-dynamic-fields', $html);
    }
}
