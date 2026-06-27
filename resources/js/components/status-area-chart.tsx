import { Area, AreaChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export interface StatusPoint {
    label: string;
    s2xx: number;
    s3xx: number;
    s4xx: number;
    s5xx: number;
}

const SERIES = [
    { key: 's2xx', name: '2xx', color: '#10b981' },
    { key: 's3xx', name: '3xx', color: '#3b82f6' },
    { key: 's4xx', name: '4xx', color: '#f59e0b' },
    { key: 's5xx', name: '5xx', color: '#ef4444' },
] as const;

export function StatusAreaChart({ points }: { points: StatusPoint[] }) {
    return (
        <ResponsiveContainer width="100%" height={240}>
            <AreaChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: -16 }}>
                <defs>
                    {SERIES.map((s) => (
                        <linearGradient key={s.key} id={`grad-${s.key}`} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={s.color} stopOpacity={0.25} />
                            <stop offset="100%" stopColor={s.color} stopOpacity={0.02} />
                        </linearGradient>
                    ))}
                </defs>

                <CartesianGrid strokeDasharray="3 3" stroke="currentColor" strokeOpacity={0.08} vertical={false} />
                <XAxis dataKey="label" tick={{ fontSize: 11, fill: 'currentColor', fillOpacity: 0.45 }} tickLine={false} axisLine={false} minTickGap={24} />
                <YAxis allowDecimals={false} width={36} tick={{ fontSize: 11, fill: 'currentColor', fillOpacity: 0.45 }} tickLine={false} axisLine={false} />
                <Tooltip
                    contentStyle={{
                        fontSize: 12,
                        borderRadius: 8,
                        border: '1px solid var(--border)',
                        background: 'var(--popover)',
                        color: 'var(--popover-foreground)',
                        boxShadow: '0 4px 12px rgb(0 0 0 / 0.08)',
                    }}
                    labelStyle={{ fontWeight: 600, marginBottom: 4 }}
                    itemStyle={{ padding: 0 }}
                />
                <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />

                {SERIES.map((s) => (
                    <Area
                        key={s.key}
                        type="monotone"
                        dataKey={s.key}
                        name={s.name}
                        stroke={s.color}
                        strokeWidth={2}
                        fill={`url(#grad-${s.key})`}
                        dot={false}
                        activeDot={{ r: 3 }}
                    />
                ))}
            </AreaChart>
        </ResponsiveContainer>
    );
}
