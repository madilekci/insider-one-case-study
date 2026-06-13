<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dev Dashboard — {{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        .log-scroll { scrollbar-width: thin; scrollbar-color: #374151 #111827; }
        .log-scroll::-webkit-scrollbar { width: 6px; }
        .log-scroll::-webkit-scrollbar-track { background: #111827; }
        .log-scroll::-webkit-scrollbar-thumb { background-color: #374151; border-radius: 3px; }
        @keyframes flash { 0%,100% { opacity:1 } 50% { opacity:0.3 } }
        .flash { animation: flash 0.6s ease; }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen font-mono text-sm"
      x-data="dashboard()"
      x-init="init()"
      x-cloak>

{{-- ===== TOP STATUS BAR ===== --}}
<div class="sticky top-0 z-50 bg-gray-900 border-b border-gray-800 px-6 py-2 flex items-center justify-between">
    <div class="flex items-center gap-4">
        <span class="text-lg font-bold text-indigo-400 tracking-tight">⚡ Dev Dashboard</span>
        <span class="text-xs text-gray-500">{{ config('app.env') }}</span>
        <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full"
              :class="health.status === 'ok' ? 'bg-green-900 text-green-300' : 'bg-red-900 text-red-300'">
            <span class="w-1.5 h-1.5 rounded-full inline-block"
                  :class="health.status === 'ok' ? 'bg-green-400' : 'bg-red-400'"></span>
            <span x-text="health.status ?? 'loading'"></span>
        </span>
    </div>
    <div class="flex items-center gap-6 text-xs text-gray-400">
        <span>Queue: <span class="text-white font-semibold"
            x-text="(metrics.queues?.high ?? 0) + (metrics.queues?.normal ?? 0) + (metrics.queues?.low ?? 0)"></span></span>
        <span>Worker: <span :class="health.checks?.worker?.ok ? 'text-green-400' : 'text-red-400'"
            x-text="health.checks?.worker?.ok ? 'running' : 'stopped'"></span></span>
        <span>Refresh: <span class="text-gray-300" x-text="lastRefresh"></span></span>
        <button @click="refreshAll()" class="text-indigo-400 hover:text-indigo-300 transition">↻ Refresh</button>
    </div>
</div>

<div class="max-w-screen-2xl mx-auto px-6 py-6 space-y-8">

{{-- ===== DEMO TRAFFIC ===== --}}
<section>
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-xs uppercase tracking-widest text-gray-500">Demo Traffic</h2>
        <span class="text-xs"
              :class="demo.is_running ? 'text-yellow-300' : 'text-gray-500'"
              x-text="demo.is_running ? 'seeding live traffic...' : 'idle'"></span>
    </div>
    <div class="bg-gray-900 border border-gray-800 rounded-lg p-4 grid grid-cols-1 xl:grid-cols-[1.2fr_0.8fr] gap-4">
        <div class="space-y-3">
            <p class="text-xs text-gray-400 leading-5">
                Generates 60 seconds of mixed fake notification traffic with queued, scheduled, cancelled, failed, and pre-sent records.
                Clear removes all demo notifications and resets demo-only observability artifacts.
            </p>
            <div class="flex flex-wrap gap-2">
                <button @click="startDemoTraffic()"
                        :disabled="demo.is_running || demoBusy"
                        class="px-4 py-1.5 rounded bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm transition">
                    <span x-show="!demoBusy">▶ Generate 1-Min Demo Data</span>
                    <span x-show="demoBusy">⟳ Working…</span>
                </button>
                <button @click="clearDemoTraffic()"
                        :disabled="demoBusy"
                        class="px-4 py-1.5 rounded bg-red-700 hover:bg-red-600 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm transition">
                    Clear Demo Data
                </button>
            </div>
            <div class="text-xs text-gray-500">
                <span class="text-gray-300">Status:</span>
                <span x-text="demo.message ?? 'No demo run yet.'"></span>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3 text-xs">
            <div class="bg-gray-950 border border-gray-800 rounded px-3 py-2">
                <div class="text-gray-500">Created</div>
                <div class="text-lg text-white font-semibold" x-text="demo.created ?? 0"></div>
            </div>
            <div class="bg-gray-950 border border-gray-800 rounded px-3 py-2">
                <div class="text-gray-500">Queued</div>
                <div class="text-lg text-yellow-300 font-semibold" x-text="demo.queued ?? 0"></div>
            </div>
            <div class="bg-gray-950 border border-gray-800 rounded px-3 py-2">
                <div class="text-gray-500">Scheduled</div>
                <div class="text-lg text-blue-300 font-semibold" x-text="demo.scheduled ?? 0"></div>
            </div>
            <div class="bg-gray-950 border border-gray-800 rounded px-3 py-2">
                <div class="text-gray-500">Cancelled</div>
                <div class="text-lg text-gray-300 font-semibold" x-text="demo.cancelled ?? 0"></div>
            </div>
            <div class="bg-gray-950 border border-gray-800 rounded px-3 py-2">
                <div class="text-gray-500">Failed Seeded</div>
                <div class="text-lg text-red-300 font-semibold" x-text="demo.failed_seeded ?? 0"></div>
            </div>
            <div class="bg-gray-950 border border-gray-800 rounded px-3 py-2">
                <div class="text-gray-500">Sent Seeded</div>
                <div class="text-lg text-emerald-300 font-semibold" x-text="demo.sent_seeded ?? 0"></div>
            </div>
        </div>
    </div>
</section>

{{-- ===== METRIC CARDS ===== --}}
<section>
    <h2 class="text-xs uppercase tracking-widest text-gray-500 mb-3">Metrics</h2>
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 mb-3">
        <template x-for="card in flowCards" :key="card.label">
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4 flex flex-col gap-1 min-h-[116px]">
                <div class="text-xs text-gray-500 truncate" x-text="card.label"></div>
                <div class="text-2xl font-bold" :class="card.color ?? 'text-white'" x-text="card.value"></div>
                <div class="text-xs text-gray-400 mt-1" x-text="card.subLabel"></div>
                <div class="text-xs text-gray-300" x-text="card.subValue"></div>
            </div>
        </template>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-3">
        <template x-for="card in metricCards" :key="card.label">
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-3 flex flex-col gap-1">
                <div class="text-xs text-gray-500 truncate" x-text="card.label"></div>
                <div class="text-xl font-bold" :class="card.color ?? 'text-white'" x-text="card.value"></div>
            </div>
        </template>
    </div>
</section>

{{-- ===== QUEUE STATUS ===== --}}
<section>
    <h2 class="text-xs uppercase tracking-widest text-gray-500 mb-3">Queue Depths</h2>
    <div class="grid grid-cols-3 gap-4">
        <template x-for="q in ['high','normal','low']" :key="q">
            <div class="bg-gray-900 border rounded-lg p-5 flex flex-col gap-2"
                 :class="queueColor(q)">
                <div class="flex items-center justify-between">
                    <span class="text-xs uppercase tracking-widest text-gray-400" x-text="q"></span>
                    <span class="text-xs px-1.5 py-0.5 rounded"
                          :class="queueBadge(q)"
                          x-text="queueLabel(q)"></span>
                </div>
                <div class="text-4xl font-bold text-white" x-text="metrics.queues?.[q] ?? 0"></div>
                <div class="text-xs text-gray-500">jobs pending</div>
            </div>
        </template>
    </div>
</section>

{{-- ===== MAIN CONTENT: LOGS + TESTS side by side ===== --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

{{-- ===== LIVE LOG STREAM ===== --}}
<section>
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-xs uppercase tracking-widest text-gray-500">Live Event Log</h2>
        <div class="flex gap-2 text-xs">
            <span class="text-gray-500">last <span class="text-gray-300" x-text="logs.length"></span> events</span>
            <button @click="logs = []" class="text-red-500 hover:text-red-400">clear</button>
        </div>
    </div>

    {{-- filters --}}
    <div class="grid grid-cols-2 gap-2 mb-2">
        <select x-model="logFilters.level"
                @change="fetchLogs()"
                class="bg-gray-800 border border-gray-700 rounded px-2 py-1 text-xs text-gray-200 focus:outline-none focus:border-indigo-500">
            <option value="">level (all)</option>
            <option value="info">info</option>
            <option value="warning">warning</option>
            <option value="error">error</option>
        </select>
        <input x-model="logFilters.event"
               @input="fetchLogs()"
               class="bg-gray-800 border border-gray-700 rounded px-2 py-1 text-xs text-gray-200 focus:outline-none focus:border-indigo-500"
               placeholder="event name filter">
        <input x-model="logFilters.notification_id"
               @input="fetchLogs()"
               class="bg-gray-800 border border-gray-700 rounded px-2 py-1 text-xs text-gray-200 focus:outline-none focus:border-indigo-500"
               placeholder="notification_id (full or partial)">
        <input x-model="logFilters.correlation_id"
               @input="fetchLogs()"
               class="bg-gray-800 border border-gray-700 rounded px-2 py-1 text-xs text-gray-200 focus:outline-none focus:border-indigo-500"
               placeholder="correlation_id">
    </div>

    <div class="bg-gray-950 border border-gray-800 rounded-lg h-80 overflow-y-auto log-scroll p-3 space-y-1"
         id="log-container">
        <template x-if="logs.length === 0">
            <div class="text-gray-600 text-xs text-center mt-8">No events yet. Waiting for activity...</div>
        </template>
        <template x-for="(entry, idx) in logs" :key="idx">
            <div class="flex gap-2 text-xs leading-5 font-mono hover:bg-gray-900 px-1 rounded"
                 :class="logRowClass(entry)">
                <span class="text-gray-600 shrink-0 w-20" x-text="entry.timestamp?.slice(11,19)"></span>
                <span class="w-14 shrink-0 font-semibold" :class="logLevelClass(entry.level)" x-text="entry.level?.toUpperCase()"></span>
                <span class="text-indigo-300 shrink-0 w-44 truncate" x-text="entry.event"></span>
                <span class="text-cyan-300 shrink-0 w-24 truncate"
                      :title="entry.notification_id ?? ''"
                      x-text="entry.notification_id ? (entry.notification_id.slice(0,8) + '…') : '-'">
                </span>
                <span class="text-gray-500 shrink-0 w-12" x-text="entry.attempt != null ? ('#' + entry.attempt) : '-'"></span>
                <span class="text-gray-400 truncate" x-text="entry.message"></span>
            </div>
        </template>
    </div>
</section>

{{-- ===== TEST RUNNER ===== --}}
<section>
    <h2 class="text-xs uppercase tracking-widest text-gray-500 mb-3">Test Runner</h2>
    <div class="bg-gray-900 border border-gray-800 rounded-lg p-4 space-y-3">

        {{-- group selector + run button --}}
        <div class="flex gap-3 items-end">
            <div class="flex-1">
                <label class="text-xs text-gray-500 mb-1 block">Test Group</label>
                <select x-model="testGroup"
                        class="w-full bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-gray-200 focus:outline-none focus:border-indigo-500">
                    <option value="smoke">Smoke (health + metrics)</option>
                    <option value="notifications">Notification API + Processing</option>
                    <option value="queue">Queue Integration</option>
                    <option value="templates">Scheduled + Templates</option>
                    <option value="load">⚡ Load Test (slow)</option>
                </select>
            </div>
            <button @click="runTests()"
                    :disabled="testRunning"
                    class="px-4 py-1.5 rounded bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm transition">
                <span x-show="!testRunning">▶ Run</span>
                <span x-show="testRunning">⟳ Running…</span>
            </button>
        </div>

        <template x-if="testGroup === 'load'">
            <div class="text-xs text-yellow-400 bg-yellow-900/30 border border-yellow-700 rounded px-3 py-2">
                ⚠ Load tests are resource-intensive and may take several minutes.
            </div>
        </template>

        {{-- test history --}}
        <template x-if="testHistory.length > 0">
            <div class="flex gap-2 flex-wrap">
                <template x-for="(run, i) in testHistory.slice(0,5)" :key="run.run_id">
                    <button @click="loadTestRun(run.run_id)"
                            class="text-xs px-2 py-0.5 rounded border transition"
                            :class="run.run_id === currentRunId
                                ? 'bg-indigo-800 border-indigo-500 text-indigo-100'
                                : 'bg-gray-800 border-gray-700 text-gray-400 hover:border-gray-500'">
                        <span x-text="run.group"></span>
                        <span class="ml-1" x-text="run.is_running ? '⟳' : (run.success === true ? '✓' : (run.success === false ? '✗' : '?'))"></span>
                    </button>
                </template>
            </div>
        </template>

        {{-- output area --}}
        <div class="bg-gray-950 border border-gray-800 rounded h-60 overflow-y-auto log-scroll p-3 text-xs leading-5"
             id="test-output">
            <template x-if="!testOutput && !testRunning">
                <div class="text-gray-600 text-center mt-8">No test output. Run a test group above.</div>
            </template>
            <pre class="whitespace-pre-wrap text-gray-300" x-text="testOutput"></pre>
            <template x-if="testRunning">
                <div class="text-indigo-400 mt-1">● running...</div>
            </template>
        </div>

        <div class="flex items-center justify-between text-xs text-gray-500">
            <span x-show="testStartedAt" x-text="'Started: ' + testStartedAt"></span>
            <span x-show="!testRunning && testOutput">
                <span :class="testSuccess ? 'text-green-400' : 'text-red-400'"
                      x-text="testSuccess ? '✓ PASSED' : '✗ FAILED'"></span>
            </span>
            <button x-show="testOutput" @click="testOutput = ''; testSuccess = null"
                    class="text-gray-600 hover:text-gray-400">clear</button>
        </div>
    </div>
</section>

</div>{{-- end grid --}}

{{-- ===== NOTIFICATION ACTIVITY ===== --}}
<section>
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-xs uppercase tracking-widest text-gray-500">Recent Notification Activity</h2>
        <div class="flex gap-2">
            <select x-model="notifFilters.status" @change="fetchNotifications()"
                    class="bg-gray-800 border border-gray-700 rounded px-2 py-1 text-xs text-gray-300 focus:outline-none">
                <option value="">All statuses</option>
                <option value="pending">pending</option>
                <option value="queued">queued</option>
                <option value="processing">processing</option>
                <option value="sent">sent</option>
                <option value="failed">failed</option>
                <option value="cancelled">cancelled</option>
            </select>
            <select x-model="notifFilters.channel" @change="fetchNotifications()"
                    class="bg-gray-800 border border-gray-700 rounded px-2 py-1 text-xs text-gray-300 focus:outline-none">
                <option value="">All channels</option>
                <option value="email">email</option>
                <option value="sms">sms</option>
                <option value="push">push</option>
            </select>
        </div>
    </div>
    <div class="overflow-x-auto rounded-lg border border-gray-800">
        <table class="w-full text-xs">
            <thead class="bg-gray-900 text-gray-500 uppercase tracking-wider">
                <tr>
                    <th class="px-3 py-2 text-left">ID</th>
                    <th class="px-3 py-2 text-left">Channel</th>
                    <th class="px-3 py-2 text-left">Priority</th>
                    <th class="px-3 py-2 text-left">Status</th>
                    <th class="px-3 py-2 text-left">Attempts</th>
                    <th class="px-3 py-2 text-left">Scheduled</th>
                    <th class="px-3 py-2 text-left">Created</th>
                    <th class="px-3 py-2 text-left">Last Error</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <template x-if="notifications.length === 0">
                    <tr><td colspan="8" class="px-3 py-6 text-center text-gray-600">No notifications found.</td></tr>
                </template>
                <template x-for="n in notifications" :key="n.id">
                    <tr class="hover:bg-gray-900 transition">
                        <td class="px-3 py-2 font-mono text-gray-400 cursor-pointer hover:text-indigo-300"
                            @click="logFilters.notification_id = n.id; fetchLogs()"
                            :title="n.id"
                            x-text="n.id.slice(0,8) + '…'"></td>
                        <td class="px-3 py-2">
                            <span class="px-1.5 py-0.5 rounded text-xs font-medium"
                                  :class="channelBadge(n.channel)"
                                  x-text="n.channel"></span>
                        </td>
                        <td class="px-3 py-2">
                            <span class="px-1.5 py-0.5 rounded text-xs"
                                  :class="priorityBadge(n.priority)"
                                  x-text="n.priority"></span>
                        </td>
                        <td class="px-3 py-2">
                            <span class="px-1.5 py-0.5 rounded text-xs font-medium"
                                  :class="statusBadge(n.status)"
                                  x-text="n.status"></span>
                        </td>
                        <td class="px-3 py-2 text-center text-gray-300" x-text="n.attempt_count"></td>
                        <td class="px-3 py-2 text-gray-500" x-text="n.scheduled_at ? n.scheduled_at.slice(0,16) : '—'"></td>
                        <td class="px-3 py-2 text-gray-500" x-text="n.created_at?.slice(0,16)"></td>
                        <td class="px-3 py-2 text-red-400 truncate max-w-xs" :title="n.last_error" x-text="n.last_error ?? '—'"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</section>

{{-- ===== SYSTEM HEALTH ===== --}}
<section>
    <h2 class="text-xs uppercase tracking-widest text-gray-500 mb-3">System Health</h2>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <template x-for="(check, key) in health.checks" :key="key">
            <div class="bg-gray-900 border rounded-lg px-4 py-3 flex items-center gap-3"
                 :class="check.ok ? 'border-green-800' : 'border-red-800'">
                <span class="text-lg" x-text="check.ok ? '✅' : '❌'"></span>
                <span class="text-sm text-gray-300" x-text="check.label"></span>
            </div>
        </template>
        <template x-if="Object.keys(health.checks ?? {}).length === 0">
            <div class="col-span-4 text-gray-600 text-xs text-center py-4">Loading health checks…</div>
        </template>
    </div>
</section>

</div>{{-- end container --}}

<script>
function dashboard() {
    return {
        metrics: {},
        logs: [],
        notifications: [],
        health: { status: null, checks: {} },
        demo: { is_running: false, message: 'No demo run yet.' },
        lastRefresh: '—',

        logFilters: { level: '', event: '', notification_id: '', correlation_id: '' },
        notifFilters: { status: '', channel: '' },

        testGroup: 'smoke',
        testRunning: false,
        testOutput: '',
        testSuccess: null,
        testStartedAt: null,
        currentRunId: null,
        testHistory: [],
        testPollTimer: null,
        demoBusy: false,
        pollTimer: null,
        lastPollAt: {
            metrics: 0,
            logs: 0,
            notifications: 0,
            health: 0,
            demo: 0,
        },
        pollInFlight: {
            metrics: false,
            logs: false,
            notifications: false,
            health: false,
            demo: false,
        },

        flowCards: [],
        metricCards: [],

        init() {
            this.refreshAll();
            this.startPolling();

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    this.refreshAll();
                }
            });
        },

        startPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
            }

            this.pollTimer = setInterval(() => {
                this.pollTick();
            }, 1000);
        },

        pollTick() {
            const hidden = document.hidden;
            const hasLogTraceFilters = !!(this.logFilters.level || this.logFilters.event || this.logFilters.notification_id || this.logFilters.correlation_id);
            const metricsInterval = hidden ? 20000 : 5000;
            const logsInterval = hidden ? 30000 : (hasLogTraceFilters ? 4500 : 9000);
            const notificationsInterval = hidden ? 30000 : 12000;
            const healthInterval = hidden ? 45000 : 15000;
            const demoInterval = hidden ? 30000 : (this.demo.is_running ? 2500 : 12000);

            this.poll('metrics', metricsInterval, () => this.fetchMetrics());
            this.poll('logs', logsInterval, () => this.fetchLogs());
            this.poll('notifications', notificationsInterval, () => this.fetchNotifications());
            this.poll('health', healthInterval, () => this.fetchHealth());
            this.poll('demo', demoInterval, () => this.fetchDemoStatus());
        },

        async poll(name, minIntervalMs, fn) {
            const now = Date.now();

            if (this.pollInFlight[name]) {
                return;
            }

            if ((now - this.lastPollAt[name]) < minIntervalMs) {
                return;
            }

            this.lastPollAt[name] = now;
            this.pollInFlight[name] = true;

            try {
                await fn();
            } finally {
                this.pollInFlight[name] = false;
            }
        },

        async refreshAll() {
            await Promise.all([
                this.fetchMetrics(),
                this.fetchLogs(),
                this.fetchNotifications(),
                this.fetchHealth(),
                this.fetchDemoStatus(),
            ]);
            this.lastRefresh = new Date().toLocaleTimeString();
        },

        async fetchDemoStatus() {
            try {
                const res = await fetch('/dashboard/demo/status');
                this.demo = await res.json();
            } catch (e) { /* silent */ }
        },

        async startDemoTraffic() {
            if (this.demo.is_running || this.demoBusy) return;
            this.demoBusy = true;

            try {
                const res = await fetch('/dashboard/demo/start', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ duration_seconds: 60 }),
                });

                const data = await res.json();
                this.demo = data.status ?? data;
                await this.refreshAll();
            } catch (e) {
                this.demo.message = 'Failed to start demo traffic: ' + e.message;
            } finally {
                this.demoBusy = false;
            }
        },

        async clearDemoTraffic() {
            if (this.demoBusy) return;
            this.demoBusy = true;

            try {
                const res = await fetch('/dashboard/demo/clear', {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const data = await res.json();
                this.demo = data.status ?? { is_running: false, message: 'Demo data cleared.' };
                await this.refreshAll();
            } catch (e) {
                this.demo.message = 'Failed to clear demo traffic: ' + e.message;
            } finally {
                this.demoBusy = false;
            }
        },

        async fetchMetrics() {
            try {
                const res = await fetch('/dashboard/metrics');
                this.metrics = await res.json();
                this.buildMetricCards();
            } catch (e) { /* silent */ }
        },

        buildMetricCards() {
            const n = this.metrics.notifications ?? {};
            const o = n.overview ?? {};
            const p = this.metrics.provider ?? {};
            const successPct = n.success_rate != null ? (n.success_rate * 100).toFixed(1) + '%' : '—';
            const failurePct = n.failure_rate != null ? (n.failure_rate * 100).toFixed(1) + '%' : '—';

            this.flowCards = [
                {
                    label: 'Total',
                    value: o.total ?? 0,
                    color: 'text-white',
                    subLabel: 'Processed + Waiting',
                    subValue: `${o.processed_total ?? 0} + ${o.waiting_total ?? 0}`,
                },
                {
                    label: 'Processed',
                    value: o.processed_total ?? 0,
                    color: 'text-emerald-300',
                    subLabel: 'Sent / Failed',
                    subValue: `${o.processed_sent ?? 0} / ${o.processed_failed ?? 0}`,
                },
                {
                    label: 'Waiting',
                    value: o.waiting_total ?? 0,
                    color: (o.waiting_total ?? 0) > 0 ? 'text-yellow-300' : 'text-white',
                    subLabel: 'Retry / New',
                    subValue: `${o.waiting_retry ?? 0} / ${o.waiting_new ?? 0}`,
                },
                {
                    label: 'In Queue',
                    value: o.in_queue_total ?? 0,
                    color: (o.in_queue_total ?? 0) > 0 ? 'text-cyan-300' : 'text-white',
                    subLabel: 'Retry / New',
                    subValue: `${o.in_queue_retry ?? 0} / ${o.in_queue_new ?? 0}`,
                },
            ];

            this.metricCards = [
                { label: 'Retries',        value: n.retry_total ?? 0,         color: 'text-yellow-400' },
                { label: 'Rate Limited',   value: n.rate_limited_total ?? 0,  color: 'text-orange-400' },
                { label: 'Scheduled',      value: o.scheduled_total ?? 0,     color: 'text-blue-300' },
                { label: 'Success Rate',   value: successPct,                 color: 'text-green-300' },
                { label: 'Avg Latency',    value: (p.avg_latency_ms ?? 0) + 'ms', color: 'text-indigo-300' },
            ];
        },

        async fetchLogs() {
            try {
                const params = new URLSearchParams();
                Object.entries(this.logFilters).forEach(([k, v]) => { if (v) params.set(k, v); });
                const res = await fetch('/dashboard/logs?' + params);
                this.logs = await res.json();
            } catch (e) { /* silent */ }
        },

        async fetchNotifications() {
            try {
                const params = new URLSearchParams();
                Object.entries(this.notifFilters).forEach(([k, v]) => { if (v) params.set(k, v); });
                const res = await fetch('/dashboard/notifications?' + params);
                this.notifications = await res.json();
            } catch (e) { /* silent */ }
        },

        async fetchHealth() {
            try {
                const res = await fetch('/dashboard/health');
                this.health = await res.json();
            } catch (e) { /* silent */ }
        },

        async runTests() {
            if (this.testRunning) return;
            this.testRunning = true;
            this.testOutput = '';
            this.testSuccess = null;
            this.testStartedAt = new Date().toLocaleTimeString();
            this.currentRunId = null;

            try {
                const res = await fetch('/dashboard/tests/run', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ group: this.testGroup }),
                });
                const data = await res.json();
                if (data.run_id) {
                    this.currentRunId = data.run_id;
                    this.testHistory.unshift({ run_id: data.run_id, group: this.testGroup, is_running: true, success: null });
                    this.pollTestStatus(data.run_id);
                } else {
                    this.testOutput = JSON.stringify(data, null, 2);
                    this.testRunning = false;
                }
            } catch (e) {
                this.testOutput = 'Error starting tests: ' + e.message;
                this.testRunning = false;
            }
        },

        pollTestStatus(runId) {
            if (this.testPollTimer) clearInterval(this.testPollTimer);
            this.testPollTimer = setInterval(async () => {
                try {
                    const res = await fetch('/dashboard/tests/' + runId);
                    const data = await res.json();
                    this.testOutput = data.output ?? '';

                    // scroll test output to bottom
                    this.$nextTick(() => {
                        const el = document.getElementById('test-output');
                        if (el) el.scrollTop = el.scrollHeight;
                    });

                    if (!data.is_running) {
                        clearInterval(this.testPollTimer);
                        this.testRunning = false;
                        this.testSuccess = data.exit_code === 0;
                        const hist = this.testHistory.find(h => h.run_id === runId);
                        if (hist) { hist.is_running = false; hist.success = this.testSuccess; }
                    }
                } catch (e) {
                    clearInterval(this.testPollTimer);
                    this.testRunning = false;
                }
            }, 1500);
        },

        async loadTestRun(runId) {
            this.currentRunId = runId;
            try {
                const res = await fetch('/dashboard/tests/' + runId);
                const data = await res.json();
                this.testOutput = data.output ?? '';
                this.testRunning = data.is_running;
                this.testStartedAt = data.started_at;
                if (data.is_running) this.pollTestStatus(runId);
            } catch (e) { /* silent */ }
        },

        queueColor(q) {
            const depth = this.metrics.queues?.[q] ?? 0;
            if (depth === 0) return 'border-gray-800';
            if (depth < 10) return 'border-yellow-800';
            return 'border-red-800';
        },

        queueBadge(q) {
            const depth = this.metrics.queues?.[q] ?? 0;
            if (depth === 0) return 'bg-gray-800 text-gray-500';
            if (depth < 10) return 'bg-yellow-900 text-yellow-300';
            return 'bg-red-900 text-red-300';
        },

        queueLabel(q) {
            const depth = this.metrics.queues?.[q] ?? 0;
            if (depth === 0) return 'empty';
            if (depth < 10) return 'low';
            return 'busy';
        },

        logRowClass(entry) {
            if (entry.level === 'error') return 'bg-red-950/30';
            if (entry.level === 'warning') return 'bg-yellow-950/30';
            return '';
        },

        logLevelClass(level) {
            if (level === 'error') return 'text-red-400';
            if (level === 'warning') return 'text-yellow-400';
            return 'text-green-400';
        },

        statusBadge(status) {
            const map = {
                sent:       'bg-green-900 text-green-300',
                failed:     'bg-red-900 text-red-300',
                processing: 'bg-blue-900 text-blue-300',
                queued:     'bg-yellow-900 text-yellow-300',
                pending:    'bg-gray-700 text-gray-300',
                cancelled:  'bg-gray-800 text-gray-500',
            };
            return map[status] ?? 'bg-gray-700 text-gray-300';
        },

        channelBadge(channel) {
            const map = {
                email: 'bg-purple-900 text-purple-300',
                sms:   'bg-cyan-900 text-cyan-300',
                push:  'bg-orange-900 text-orange-300',
            };
            return map[channel] ?? 'bg-gray-700 text-gray-300';
        },

        priorityBadge(priority) {
            const map = {
                high:   'bg-red-900 text-red-300',
                normal: 'bg-gray-700 text-gray-300',
                low:    'bg-gray-800 text-gray-500',
            };
            return map[priority] ?? 'bg-gray-700 text-gray-300';
        },
    };
}
</script>
</body>
</html>
