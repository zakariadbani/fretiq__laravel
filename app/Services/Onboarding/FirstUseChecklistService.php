<?php

namespace App\Services\Onboarding;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use Illuminate\Support\Facades\Route;

class FirstUseChecklistService
{
    /**
     * @return array{items: array<int, array{label: string, detail: string, complete: bool, url: string|null, action: string|null}>, complete: bool}
     */
    public function checklist(): array
    {
        $items = [
            $this->item(
                'Identité expéditeur',
                'Renseigner un nom, une adresse d’envoi et une réponse valide.',
                SenderIdentity::where('is_active', true)->exists(),
                'sender_identities',
                'sender_identities',
                'Configurer'
            ),
            $this->item(
                'Segment destinataire',
                'Définir l’audience exacte à prospecter avant tout envoi.',
                Segment::exists(),
                'segments',
                'segments',
                'Préparer'
            ),
            $this->item(
                'Modèle d’e-mail',
                'Créer un objet et un corps de message relus en français.',
                CampaignTemplate::exists(),
                'campaign_templates',
                'campaign_templates',
                'Rédiger'
            ),
            $this->item(
                'Première campagne',
                'Assembler segment, modèle, expéditeur et planning.',
                Campaign::exists(),
                'campaigns',
                'campaigns',
                'Créer'
            ),
        ];

        return [
            'items'    => $items,
            'complete' => collect($items)->every(fn (array $item) => $item['complete']),
        ];
    }

    /**
     * @return array{label: string, detail: string, complete: bool, url: string|null, action: string|null}
     */
    private function item(
        string $label,
        string $detail,
        bool $complete,
        string $routeName,
        string $permissionEntity,
        string $createAction
    ): array {
        $createPermission = "create {$permissionEntity}";
        $viewPermission = "view {$permissionEntity}";

        $url = null;
        $action = null;

        if (! $complete && $this->can($createPermission) && Route::has("admin.{$routeName}.create")) {
            $url = route("admin.{$routeName}.create");
            $action = $createAction;
        } elseif ($this->can($viewPermission) && Route::has("admin.{$routeName}.index")) {
            $url = route("admin.{$routeName}.index");
            $action = $complete ? 'Voir' : 'Ouvrir';
        }

        return compact('label', 'detail', 'complete', 'url', 'action');
    }

    private function can(string $permission): bool
    {
        return (bool) auth()->user()?->can($permission);
    }
}
