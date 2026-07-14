<?php

namespace App\Http\Controllers;

use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Suppression;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class UnsubscribeController extends Controller
{
    public function show(Request $request, int $contact): View
    {
        if (! Contact::whereKey($contact)->exists()) {
            return view('public.unsubscribed', ['state' => 'error']);
        }

        return view('public.unsubscribed', [
            'state' => 'confirm',
            'action' => $request->fullUrl(),
        ]);
    }

    public function confirm(int $contact): View
    {
        $contactModel = Contact::find($contact);

        if ($contactModel === null) {
            return view('public.unsubscribed', ['state' => 'error']);
        }

        $this->processUnsubscribe($contactModel);

        return view('public.unsubscribed', ['state' => 'success']);
    }

    public function oneClick(Request $request, int $contact): Response|View
    {
        if ($request->input('List-Unsubscribe') !== 'One-Click') {
            return response()->view('public.unsubscribed', ['state' => 'error'], 400);
        }

        $contactModel = Contact::find($contact);

        if ($contactModel === null) {
            return view('public.unsubscribed', ['state' => 'error']);
        }

        $this->processUnsubscribe($contactModel);

        return response()->view('public.unsubscribed', ['state' => 'success']);
    }

    private function processUnsubscribe(Contact $contact): void
    {
        Suppression::firstOrCreate(
            ['email' => strtolower(trim($contact->email))],
            [
                'contact_id' => $contact->id,
                'reason'     => 'unsubscribe',
                'source'     => 'campaign',
            ],
        );

        CampaignRecipient::where('contact_id', $contact->id)
            ->whereNotIn('status', ['unsubscribed', 'bounced'])
            ->update(['status' => 'unsubscribed']);
    }
}
