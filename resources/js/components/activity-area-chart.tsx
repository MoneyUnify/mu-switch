import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export interface ActivityPoint {
    label: string;
    count: number;
    volume: number;
}

export function ActivityAreaChart({ points, formatVolume }: { points: ActivityPoint[]; formatVolume: (value: number) => string }) {
    return (
        <ResponsiveContainer width="100%" height={176}>
            <AreaChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: -18 }}>
                <defs>
                    <linearGradient id="grad-activity" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="var(--primary)" stopOpacity={0.3} />
                        <stop offset="100%" stopColor="var(--primary)" stopOpacity={0.02} />
                    </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="currentColor" strokeOpacity={0.08} vertical={false} />
                <XAxis dataKey="label" tick={{ fontSize: 11, fill: 'currentColor', fillOpacity: 0.45 }} tickLine={false} axisLine={false} minTickGap={20} />
                <YAxis allowDecimals={false} width={28} tick={{ fontSize: 11, fill: 'currentColor', fillOpacity: 0.45 }} tickLine={false} axisLine={false} />
                <Tooltip
                    content={({ active, payload, label }) =>
                        active && payload && payload.length ? (
                            <div className="rounded-md border border-sidebar-border/70 bg-popover px-3 py-2 text-xs shadow-md dark:border-sidebar-border">
                                <div className="mb-1 font-medium">{label}</div>
                                <div className="tabular-nums">{payload[0].value} transactions</div>
                                <div className="text-muted-foreground tabular-nums">{formatVolume(Number(payload[0].payload.volume))}</div>
                            </div>
                        ) : null
                    }
                />
                <Area type="monotone" dataKey="count" name="Transactions" stroke="var(--primary)" strokeWidth={2} fill="url(#grad-activity)" dot={false} activeDot={{ r: 3 }} />
            </AreaChart>
        </ResponsiveContainer>
    );
}
