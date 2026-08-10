<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\ControlRoomController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DispatchDecisionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OperatorSessionController;
use App\Http\Controllers\StaleSessionProposalController;
use App\Models\Agent;
use App\Models\AgentSkill;
use App\Models\AgentUsageLog;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->name('ecosystem.auth');
Route::get('/', function () {
    return view('welcome');
});

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively. There's no Jetstream equivalent for a Cookie Policy, so this one is wired by hand,
// following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        // Agent/AgentSkill are the ecosystem-wide agent catalog (no owner
        // column at all) and are intentionally never scoped. Conversation/
        // Message/AgentUsageLog carry HasUserScope/HasConversationUserScope,
        // so they no longer need the ad-hoc where('user_id', ...) filters
        // that used to live directly in this closure.
        $totalAgents = Agent::count();
        $activeAgents = Agent::where('is_active', true)->count();
        $totalConversations = Conversation::count();
        $totalMessages = Message::count();
        $totalTokens = AgentUsageLog::selectRaw('COALESCE(SUM(tokens_input + tokens_output), 0) as total')
            ->value('total') ?? 0;
        $totalSkills = AgentSkill::count();
        $agents = Agent::withCount('conversations')->latest()->get();
        $skills = AgentSkill::withCount('agents')->get();

        return view('dashboard', compact(
            'totalAgents', 'activeAgents', 'totalConversations', 'totalMessages',
            'totalTokens', 'totalSkills', 'agents', 'skills'
        ));
    })->name('dashboard');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

    Route::resource('agents', AgentController::class)->except(['destroy']);

    // Mining-dispatch domain (MVP scaffold) — control rooms are the tenant
    // root, dispatch decisions/alerts/operator sessions nest underneath.
    Route::resource('control-rooms', ControlRoomController::class);

    Route::post('control-rooms/{controlRoom}/dispatch-decisions', [DispatchDecisionController::class, 'store'])
        ->name('control-rooms.dispatch-decisions.store');
    Route::delete('dispatch-decisions/{dispatchDecision}', [DispatchDecisionController::class, 'destroy'])
        ->name('dispatch-decisions.destroy');

    Route::post('control-rooms/{controlRoom}/alerts', [AlertController::class, 'store'])
        ->name('control-rooms.alerts.store');
    Route::patch('alerts/{alert}', [AlertController::class, 'update'])
        ->name('alerts.update');
    Route::delete('alerts/{alert}', [AlertController::class, 'destroy'])
        ->name('alerts.destroy');

    Route::post('control-rooms/{controlRoom}/operator-sessions', [OperatorSessionController::class, 'store'])
        ->name('control-rooms.operator-sessions.store');
    Route::patch('operator-sessions/{operatorSession}', [OperatorSessionController::class, 'update'])
        ->name('operator-sessions.update');
    Route::delete('operator-sessions/{operatorSession}', [OperatorSessionController::class, 'destroy'])
        ->name('operator-sessions.destroy');

    Route::patch('stale-session-proposals/{staleSessionProposal}/end', [StaleSessionProposalController::class, 'end'])
        ->name('stale-session-proposals.end');
    Route::patch('stale-session-proposals/{staleSessionProposal}/dismiss', [StaleSessionProposalController::class, 'dismiss'])
        ->name('stale-session-proposals.dismiss');

    Route::post('agents/{agent}/conversations', [ConversationController::class, 'store'])
        ->name('agents.conversations.store');
    Route::get('agents/{agent}/chat/{conversation}', [ConversationController::class, 'show'])
        ->name('agents.chat');
});
