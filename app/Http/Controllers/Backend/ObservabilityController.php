<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\Analytics\QueueObservabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ObservabilityController extends Controller
{
    public function __construct(private readonly QueueObservabilityService $observability)
    {
        $this->middleware('permission:manage roles');
    }

    public function index()
    {
        return view('backend.contents.observability.index', [
            'counts' => $this->observability->counts(),
            'scheduler' => $this->observability->schedulerHealth(),
            'tasks' => $this->observability->scheduledTasks(),
            'activeJobs' => $this->observability->activeJobs(),
            'failedJobs' => $this->observability->failedJobs(),
            'failedRuns' => $this->observability->failedRuns(),
            'refreshedAt' => now()->setTimezone('Europe/Paris'),
        ]);
    }

    public function cancelJob(Request $request, int $job): RedirectResponse
    {
        $cancelled = $this->observability->cancelJob($job);
        $this->audit($request, 'job.cancel', (string) $job, $cancelled);

        return back()->with($cancelled ? 'success' : 'error', $cancelled
            ? "Le job #{$job} a été annulé."
            : "Le job #{$job} est introuvable ou déjà réservé par un worker.");
    }

    public function retryFailedJob(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('send campaigns'), 403);

        $retried = $this->observability->retryFailedJob($uuid);
        $this->audit($request, 'failed_job.retry', $uuid, $retried);

        return back()->with($retried ? 'success' : 'error', $retried
            ? 'Le job échoué a été remis en file.'
            : 'Ce job échoué est introuvable.');
    }

    public function retryAllFailedJobs(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('send campaigns'), 403);

        $count = $this->observability->retryAllFailedJobs();
        $this->audit($request, 'failed_job.retry_all', 'all', true, ['count' => $count]);

        return back()->with('success', $count > 0
            ? "{$count} job(s) remis en file."
            : 'Aucun job échoué à relancer.');
    }

    public function forgetFailedJob(Request $request, string $uuid): RedirectResponse
    {
        $forgotten = $this->observability->forgetFailedJob($uuid);
        $this->audit($request, 'failed_job.forget', $uuid, $forgotten);

        return back()->with($forgotten ? 'success' : 'error', $forgotten
            ? 'Le job échoué a été oublié.'
            : 'Ce job échoué est introuvable.');
    }

    public function toggleCron(Request $request): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $this->observability->setCronEnabled($enabled);
        $this->audit($request, 'scheduler.toggle', 'global', true, ['enabled' => $enabled]);

        return back()->with('success', $enabled
            ? 'Les automatisations planifiées sont activées.'
            : 'Les automatisations planifiées sont suspendues.');
    }

    public function toggleTask(Request $request, string $task): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $updated = $this->observability->setTaskEnabled($task, $enabled);
        $this->audit($request, 'scheduler.task_toggle', $task, $updated, ['enabled' => $enabled]);

        return back()->with($updated ? 'success' : 'error', $updated
            ? 'La tâche planifiée a été mise à jour.'
            : 'Cette tâche planifiée est inconnue.');
    }

    public function runTask(Request $request, string $task): RedirectResponse
    {
        if ($this->observability->taskDispatchesMail($task)) {
            abort_unless($request->user()?->can('send campaigns'), 403);
        }

        try {
            $exitCode = $this->observability->runTask($task);
        } catch (\Throwable $exception) {
            $this->audit($request, 'scheduler.task_run', $task, false);
            Log::error('Observability task execution failed', [
                'user_id' => $request->user()?->id,
                'task' => $task,
                'exception_class' => $exception::class,
            ]);

            return back()->with('error', 'La commande a échoué. Consultez les journaux serveur.');
        }

        $success = $exitCode === 0;
        $this->audit($request, 'scheduler.task_run', $task, $success);

        return back()->with($success ? 'success' : 'error', $exitCode === null
            ? 'Cette tâche planifiée est inconnue.'
            : ($success ? 'La commande a été exécutée.' : 'La commande a retourné une erreur.'));
    }

    private function audit(Request $request, string $action, string $target, bool $success, array $context = []): void
    {
        Log::notice('Observability operation', [
            'user_id' => $request->user()?->id,
            'action' => $action,
            'target' => $target,
            'success' => $success,
            ...$context,
        ]);
    }
}
