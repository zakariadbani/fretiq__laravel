<?php

namespace Tests\Feature\Backend;

use App\Core\Bootstrap\BootstrapDefault;
use App\DataTables\Backend\CompaniesDataTable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class Scr67UiChromeTest extends TestCase
{
    public function test_master_uses_page_title_and_app_name(): void
    {
        config(['app.name' => 'fretiq']);

        $html = Blade::render(<<<'BLADE'
@extends('layout.master')
@section('title')
    {{ $name }}
@endsection
@section('content')
    <main>OK</main>
@endsection
BLADE, ['name' => 'Entreprise - Acme & Fils <Nord> "Express"']);

        $this->assertStringContainsString('<title>Entreprise - Acme &amp; Fils &lt;Nord&gt; &quot;Express&quot; | fretiq</title>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
    }

    public function test_default_bootstrap_does_not_load_demo_or_datatable_assets_globally(): void
    {
        \App\Core\Theme::$vendorFiles = [];
        \App\Core\Theme::$javascriptFiles = [];

        (new BootstrapDefault())->initAssets();

        $this->assertSame([], getVendors('js'));
        $this->assertSame([], getCustomJs());
    }
    public function test_datatable_page_registers_its_own_assets(): void
    {
        \App\Core\Theme::$vendorFiles = [];
        \App\Core\Theme::$javascriptFiles = [];

        app(CompaniesDataTable::class)->html();

        $this->assertContains('assets/plugins/custom/datatables/datatables.bundle.js', getVendors('js'));
        $this->assertContains('assets/js/custom/datatables-utils.js', getCustomJs());
    }


    public function test_permission_labels_are_human_readable(): void
    {
        $labels = [
            'backend.access' => 'Accès au back-office',
            'view companies' => 'Voir entreprises',
            'create campaign_templates' => 'Créer modèles de campagne',
            'view sequences' => 'Voir séquences',
            'send campaigns' => 'Envoyer campagnes',
            'manage roles' => 'Gérer rôles',
            'manage permissions' => 'Gérer permissions',
            'view zoho' => 'Voir Zoho',
            'sync zoho' => 'Synchroniser Zoho',
            'run discovery' => 'Lancer la découverte',
            'manage packages' => 'Gérer forfaits',
            'view settings' => 'Voir paramètres',
            'edit settings' => 'Modifier paramètres',
            'enrich companies' => 'Enrichir entreprises',
            'view consumption' => 'Voir consommation',
            'view provider quota' => 'Voir quotas fournisseurs',
        ];

        foreach ($labels as $permission => $label) {
            $this->assertSame($label, permission_label($permission), $permission);
        }
    }

    public function test_zoho_driver_badges_render_human_readable_labels(): void
    {
        view()->share('errors', new ViewErrorBag());

        $html = view('backend.contents.zoho.index', [
            'crmDriver' => 'local',
            'campaignsDriver' => 'zoho',
            'lastByModule' => [
                'Accounts' => null,
                'Contacts' => null,
            ],
            'tokenStatus' => 'absent',
            'tokenExpiry' => null,
            'tokenMinutes' => null,
            'history' => collect(),
        ])->render();

        $this->assertStringContainsString('Mode local', $html);
        $this->assertStringContainsString('Zoho actif', $html);
        $this->assertDoesNotMatchRegularExpression('/<span class="badge[^"]*"[^>]*>\s*local\s*<\/span>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<span class="badge[^"]*"[^>]*>\s*zoho\s*<\/span>/', $html);
    }

    public function test_shared_crud_partials_render_responsive_actions(): void
    {
        $toolbar = Blade::render(
            '@include("backend.elements.form-actions", ["variant" => "toolbar", "backRoute" => "admin.companies.index"])'
        );
        $sticky = Blade::render(
            '@include("backend.elements.form-actions", ["variant" => "sticky", "backRoute" => "admin.companies.index"])'
        );

        $this->assertStringContainsString('btn-sm fw-bold', $toolbar);
        $this->assertStringContainsString('Retour', $toolbar);
        $this->assertStringNotContainsString('data-crud-form-actions="sticky"', $toolbar);
        $this->assertStringNotContainsString('name="save"', $toolbar);

        $this->assertStringContainsString('data-crud-form-actions="sticky"', $sticky);
        $this->assertStringContainsString('flex-wrap gap-2 gap-md-3', $sticky);
        $this->assertStringContainsString('name="saveandcontinue"', $sticky);
        $this->assertStringContainsString('name="save"', $sticky);
        $this->assertStringContainsString('Enregistrer la fiche', $sticky);
    }

    public function test_shared_crud_tabbar_renders_native_tabs_and_cross_page_links(): void
    {
        $model = (object) ['id' => 7];
        $config = [
            'route_base' => 'admin.companies',
            'route_base_id' => 'company',
            'title' => 'Acme',
            'tabs' => [
                ['key' => 'apercu', 'label' => 'Aperçu', 'icon' => 'bi-grid', 'mode' => 'view'],
                ['key' => 'general', 'label' => 'Général', 'icon' => 'bi-building', 'mode' => 'edit'],
                ['key' => 'contacts', 'label' => 'Contacts', 'icon' => 'bi-people', 'mode' => 'both', 'count' => 2],
            ],
        ];

        $viewHtml = Blade::render(
            '@include("backend.partials.crud._tabbar", ["model" => $model, "currentPage" => "view", "config" => $config])',
            compact('model', 'config')
        );
        $editHtml = Blade::render(
            '@include("backend.partials.crud._tabbar", ["model" => $model, "currentPage" => "edit", "config" => $config])',
            compact('model', 'config')
        );

        $this->assertStringContainsString('flex-wrap gap-2 gap-md-0', $viewHtml);
        $this->assertStringContainsString('py-3 py-md-4', $viewHtml);
        $this->assertNativeTab($viewHtml, 'company_apercu');
        $this->assertStringContainsString('/admin/companies/7/edit#company_general', $viewHtml);
        $this->assertNativeTab($viewHtml, 'company_contacts');

        $this->assertStringContainsString('/admin/companies/7#company_apercu', $editHtml);
        $this->assertNativeTab($editHtml, 'company_general');
        $this->assertNativeTab($editHtml, 'company_contacts');
    }

    private function assertNativeTab(string $html, string $target): void
    {
        $this->assertMatchesRegularExpression(
            '/<a\b(?=[^>]*\bdata-bs-toggle="tab")(?=[^>]*\bhref="#' . preg_quote($target, '/') . '")[^>]*>/',
            $html
        );
    }
}
