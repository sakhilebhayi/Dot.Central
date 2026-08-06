<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\ControlRoomController;
use App\Http\Controllers\DispatchDecisionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OperatorSessionController;
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
        $totalAgents        = \App\Models\Agent::count();
        $activeAgents       = \App\Models\Agent::where('is_active', true)->count();
        $totalConversations = \App\Models\Conversation::count();
        $totalMessages      = \App\Models\Message::count();
        $totalTokens        = \App\Models\AgentUsageLog::selectRaw('COALESCE(SUM(tokens_input + tokens_output), 0) as total')
            ->value('total') ?? 0;
        $totalSkills        = \App\Models\AgentSkill::count();
        $agents             = \App\Models\Agent::withCount('conversations')->latest()->get();
        $skills             = \App\Models\AgentSkill::withCount('agents')->get();

        return view('dashboard', compact(
            'totalAgents', 'activeAgents', 'totalConversations', 'totalMessages',
            'totalTokens', 'totalSkills', 'agents', 'skills'
        ));
    })->name('dashboard');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

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
});
