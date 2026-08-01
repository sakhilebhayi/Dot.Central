<x-app-layout>
    <div style="padding:2rem 2.5rem;">

        {{-- Page header --}}
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
            <div>
                <h1 style="font-family:'Syne',sans-serif;font-size:1.625rem;font-weight:800;color:var(--text-primary);margin:0 0 0.25rem;">Agent Hub Dashboard</h1>
                <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Manage AI agents, monitor conversations and track usage across the Dot ecosystem.</p>
            </div>
            <a href="#" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.65rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;text-decoration:none;box-shadow:0 8px 20px rgba(225,29,72,0.25);white-space:nowrap;">
                <span class="material-symbols-rounded" style="font-size:18px;">add_circle</span>
                New Agent
            </a>
        </div>

        {{-- KPI row --}}
        <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:1rem;margin-bottom:2rem;">

            @php
            $kpis = [
                ['label'=>'Total Agents',     'value'=>$totalAgents,       'icon'=>'smart_toy',   'accent'=>'#6366f1'],
                ['label'=>'Active Agents',    'value'=>$activeAgents,      'icon'=>'check_circle', 'accent'=>'#22c55e'],
                ['label'=>'Conversations',    'value'=>$totalConversations,'icon'=>'chat',         'accent'=>'#e11d48'],
                ['label'=>'Messages',         'value'=>$totalMessages,     'icon'=>'forum',        'accent'=>'#f59e0b'],
                ['label'=>'Tokens Used',      'value'=>number_format($totalTokens), 'icon'=>'token','accent'=>'#a855f7'],
                ['label'=>'Total Skills',     'value'=>$totalSkills,       'icon'=>'psychology',   'accent'=>'#06b6d4'],
            ];
            @endphp

            @foreach($kpis as $kpi)
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;padding:1.25rem 1rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.12em;color:var(--text-muted);">{{ $kpi['label'] }}</span>
                    <div style="width:30px;height:30px;border-radius:8px;background:{{ $kpi['accent'] }}1a;display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:16px;color:{{ $kpi['accent'] }};">{{ $kpi['icon'] }}</span>
                    </div>
                </div>
                <div style="font-family:'Syne',sans-serif;font-size:1.75rem;font-weight:800;color:var(--text-primary);line-height:1;">{{ $kpi['value'] }}</div>
            </div>
            @endforeach
        </div>

        {{-- Agents section --}}
        <div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start;">

            {{-- Agents table --}}
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;overflow:hidden;">
                <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--divider);display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:0.625rem;">
                        <span class="material-symbols-rounded" style="font-size:20px;color:#e11d48;">smart_toy</span>
                        <span style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);">Agents</span>
                        <span style="font-size:0.65rem;padding:0.15rem 0.5rem;border-radius:9999px;background:rgba(225,29,72,0.15);color:#fda4af;font-weight:700;">{{ $agents->count() }}</span>
                    </div>
                    <a href="#" style="font-size:0.72rem;color:#e11d48;text-decoration:none;font-weight:600;">View all</a>
                </div>

                @if($agents->isEmpty())
                <div style="padding:3.5rem 1.5rem;text-align:center;">
                    <div style="width:64px;height:64px;border-radius:16px;background:rgba(225,29,72,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
                        <span class="material-symbols-rounded" style="font-size:32px;color:#e11d48;">smart_toy</span>
                    </div>
                    <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;">No agents yet</div>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 1.5rem;">Create your first AI agent to get started with the Dot ecosystem.</p>
                    <a href="#" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#fff;text-decoration:none;">
                        <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
                        Create your first agent
                    </a>
                </div>
                @else
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--divider);">
                                <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Agent</th>
                                <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Model</th>
                                <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Status</th>
                                <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Conversations</th>
                                <th style="padding:0.75rem 1.5rem;text-align:right;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($agents as $agent)
                            @php
                                $model = strtolower($agent->model ?? '');
                                if (str_contains($model, 'claude')) {
                                    $modelColor = '#a855f7'; $modelBg = 'rgba(168,85,247,0.12)'; $modelLabel = 'Claude';
                                } elseif (str_contains($model, 'gpt')) {
                                    $modelColor = '#22c55e'; $modelBg = 'rgba(34,197,94,0.12)'; $modelLabel = 'GPT';
                                } elseif (str_contains($model, 'gemini')) {
                                    $modelColor = '#3b82f6'; $modelBg = 'rgba(59,130,246,0.12)'; $modelLabel = 'Gemini';
                                } else {
                                    $modelColor = '#8d90a2'; $modelBg = 'rgba(141,144,162,0.12)'; $modelLabel = ucfirst($agent->model ?? 'Unknown');
                                }
                                $isActive = (bool) $agent->is_active;
                            @endphp
                            <tr style="border-bottom:1px solid var(--divider);transition:background 0.15s;" onmouseover="this.style.background='rgba(26,36,56,0.6)'" onmouseout="this.style.background='transparent'">
                                <td style="padding:1rem 1.5rem;">
                                    <div style="display:flex;align-items:center;gap:0.75rem;">
                                        <div style="width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,#e11d48,#9f1239);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                            <span class="material-symbols-rounded" style="font-size:17px;color:#fff;">smart_toy</span>
                                        </div>
                                        <div>
                                            <div style="font-family:'Syne',sans-serif;font-size:0.82rem;font-weight:700;color:var(--text-primary);">{{ $agent->name }}</div>
                                            @if($agent->description)
                                            <div style="font-size:0.7rem;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px;">{{ $agent->description }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td style="padding:1rem;">
                                    <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.6rem;border-radius:9999px;background:{{ $modelBg }};color:{{ $modelColor }};font-size:0.65rem;font-weight:700;font-family:'Syne',sans-serif;">
                                        <span style="width:5px;height:5px;border-radius:9999px;background:{{ $modelColor }};display:inline-block;"></span>
                                        {{ $modelLabel }}
                                    </span>
                                </td>
                                <td style="padding:1rem;">
                                    @if($isActive)
                                    <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.6rem;border-radius:9999px;background:rgba(34,197,94,0.12);color:#22c55e;font-size:0.65rem;font-weight:700;font-family:'Syne',sans-serif;">
                                        <span style="width:5px;height:5px;border-radius:9999px;background:#22c55e;display:inline-block;animation:pulse 2s infinite;"></span>
                                        Active
                                    </span>
                                    @else
                                    <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.6rem;border-radius:9999px;background:rgba(141,144,162,0.12);color:var(--text-muted);font-size:0.65rem;font-weight:700;font-family:'Syne',sans-serif;">
                                        <span style="width:5px;height:5px;border-radius:9999px;background:#8d90a2;display:inline-block;"></span>
                                        Inactive
                                    </span>
                                    @endif
                                </td>
                                <td style="padding:1rem;">
                                    <span style="font-size:0.8rem;font-weight:600;color:var(--text-secondary);">{{ number_format($agent->conversations_count) }}</span>
                                </td>
                                <td style="padding:1rem 1.5rem;text-align:right;">
                                    <a href="#" style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.35rem 0.9rem;border-radius:9999px;border:1px solid rgba(225,29,72,0.35);color:#fda4af;font-size:0.7rem;font-weight:700;text-decoration:none;font-family:'Syne',sans-serif;transition:all 0.15s;" onmouseover="this.style.background='rgba(225,29,72,0.1)'" onmouseout="this.style.background='transparent'">
                                        Open
                                        <span class="material-symbols-rounded" style="font-size:14px;">arrow_forward</span>
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>

            {{-- Skills panel --}}
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;overflow:hidden;">
                <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--divider);display:flex;align-items:center;gap:0.625rem;">
                    <span class="material-symbols-rounded" style="font-size:20px;color:#a855f7;">psychology</span>
                    <span style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);">Agent Skills</span>
                    <span style="font-size:0.65rem;padding:0.15rem 0.5rem;border-radius:9999px;background:rgba(168,85,247,0.15);color:#d8b4fe;font-weight:700;">{{ $skills->count() }}</span>
                </div>

                <div style="padding:1.25rem 1.5rem;">
                    @if($skills->isEmpty())
                    <div style="text-align:center;padding:2rem 0;">
                        <span class="material-symbols-rounded" style="font-size:36px;color:#4a4f6a;display:block;margin-bottom:0.75rem;">psychology</span>
                        <p style="font-size:0.78rem;color:var(--text-muted);margin:0;">No skills defined yet.</p>
                    </div>
                    @else
                    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                        @foreach($skills as $skill)
                        @php
                            $count = $skill->agents_count;
                            $alpha = $count > 0 ? min(1, 0.5 + ($count / max($skills->max('agents_count'), 1)) * 0.5) : 0.4;
                        @endphp
                        <div style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.35rem 0.75rem;border-radius:9999px;background:rgba(168,85,247,0.12);border:1px solid rgba(168,85,247,0.2);cursor:default;" title="{{ $skill->description ?? $skill->name }}">
                            @if($skill->icon)
                            <span class="material-symbols-rounded" style="font-size:13px;color:#a855f7;">{{ $skill->icon }}</span>
                            @endif
                            <span style="font-size:0.7rem;font-weight:700;color:#d8b4fe;font-family:'Syne',sans-serif;">{{ $skill->name }}</span>
                            <span style="font-size:0.6rem;padding:0.05rem 0.35rem;border-radius:9999px;background:rgba(168,85,247,0.25);color:#c084fc;font-weight:700;">{{ $count }}</span>
                        </div>
                        @endforeach
                    </div>

                    <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--divider);">
                        <a href="#" style="display:flex;align-items:center;justify-content:center;gap:0.4rem;padding:0.6rem;border-radius:0.5rem;border:1px dashed rgba(168,85,247,0.25);color:var(--text-muted);font-size:0.72rem;font-weight:600;text-decoration:none;transition:all 0.15s;font-family:'Syne',sans-serif;" onmouseover="this.style.borderColor='rgba(168,85,247,0.5)';this.style.color='#d8b4fe'" onmouseout="this.style.borderColor='rgba(168,85,247,0.25)';this.style.color='#8d90a2'">
                            <span class="material-symbols-rounded" style="font-size:15px;">add</span>
                            Add Skill
                        </a>
                    </div>
                    @endif
                </div>
            </div>

        </div>

        {{-- Quick-start CTA (only when no agents) --}}
        @if($agents->isEmpty())
        <div style="margin-top:1.5rem;padding:2rem;background:linear-gradient(135deg,rgba(225,29,72,0.08),rgba(159,18,57,0.05));border:1px solid rgba(225,29,72,0.18);border-radius:0.875rem;display:flex;align-items:center;justify-content:space-between;gap:1.5rem;">
            <div>
                <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:var(--text-primary);margin-bottom:0.35rem;">Get started with Dot.Central</div>
                <p style="font-size:0.8rem;color:var(--text-muted);margin:0;max-width:480px;">Create AI agents, assign skills, and deploy them across the Dot ecosystem. Your agents can be wired into Dot.Tasks, Dot.Finance, and every other platform in the hub.</p>
            </div>
            <a href="#" style="flex-shrink:0;display:inline-flex;align-items:center;gap:0.5rem;padding:0.75rem 1.5rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.82rem;font-weight:700;color:#fff;text-decoration:none;box-shadow:0 8px 24px rgba(225,29,72,0.3);white-space:nowrap;">
                <span class="material-symbols-rounded" style="font-size:18px;">rocket_launch</span>
                Create Your First Agent
            </a>
        </div>
        @endif

    </div>
</x-app-layout>
