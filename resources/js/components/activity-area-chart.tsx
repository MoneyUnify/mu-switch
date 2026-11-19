import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

export interface TrendCurrency {
    currency: string;
    count: number;
    volume: number;
}

export interface ActivityPoint {
    date?: string;
    label: string;
    count: number;
    volume: number;
    byCurrency?: Record<string, { count: number; volume: number }>;
    [key: string]: unknown;
}

export const CURRENCY_COLORS: Record<string, { stroke: string; fill: string }> =
    {
        USD: { stroke: '#2d6fbe', fill: '#2d6fbe' }, // MoneyUnify brand blue
        ZMW: { stroke: '#10b981', fill: '#10b981' }, // Emerald
        MWK: { stroke: '#f59e0b', fill: '#f59e0b' }, // Amber
        KES: { stroke: '#8b5cf6', fill: '#8b5cf6' }, // Violet
        EUR: { stroke: '#6366f1', fill: '#6366f1' }, // Indigo
        GBP: { stroke: '#06b6d4', fill: '#06b6d4' }, // Cyan
        UGX: { stroke: '#ec4899', fill: '#ec4899' }, // Pink
        TZS: { stroke: '#14b8a6', fill: '#14b8a6' }, // Teal
        RWF: { stroke: '#f97316', fill: '#f97316' }, // Orange
        NGN: { stroke: '#84cc16', fill: '#84cc16' }, // Lime
        ZAR: { stroke: '#0ea5e9', fill: '#0ea5e9' }, // Sky
    };

const PALETTE = [
    '#2d6fbe',
    '#10b981',
    '#f59e0b',
    '#8b5cf6',
    '#6366f1',
    '#ec4899',
    '#06b6d4',
    '#14b8a6',
];

export function getCurrencyColor(
    currency: string,
    index = 0,
): { stroke: string; fill: string } {
    if (CURRENCY_COLORS[currency]) {
        return CURRENCY_COLORS[currency];
    }

    const color = PALETTE[index % PALETTE.length];

    return { stroke: color, fill: color };
}

export interface ActivityAreaChartProps {
    points: ActivityPoint[];
    currencies?: TrendCurrency[];
    metric?: 'count' | 'volume';
    selectedCurrency?: string | null;
    formatVolume: (value: number, currency?: string) => string;
}

export function ActivityAreaChart({
    points,
    currencies,
    metric = 'count',
    selectedCurrency = null,
    formatVolume,
}: ActivityAreaChartProps) {
    const hasCurrencies = Array.isArray(currencies) && currencies.length > 0;
    const seriesList = hasCurrencies
        ? selectedCurrency
            ? currencies.filter((c) => c.currency === selectedCurrency)
            : currencies
        : [{ currency: 'Transactions', count: 0, volume: 0 }];

    return (
        <ResponsiveContainer width="100%" height={176}>
            <AreaChart
                data={points}
                margin={{ top: 8, right: 8, bottom: 0, left: -18 }}
            >
                <defs>
                    <linearGradient
                        id="grad-activity"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stopColor="var(--primary)"
                            stopOpacity={0.3}
                        />
                        <stop
                            offset="100%"
                            stopColor="var(--primary)"
                            stopOpacity={0.02}
                        />
                    </linearGradient>
                    {hasCurrencies &&
                        currencies.map((c, idx) => {
                            const color = getCurrencyColor(c.currency, idx);

                            return (
                                <linearGradient
                                    key={c.currency}
                                    id={`grad-${c.currency}`}
                                    x1="0"
                                    y1="0"
                                    x2="0"
                                    y2="1"
                                >
                                    <stop
                                        offset="0%"
                                        stopColor={color.stroke}
                                        stopOpacity={0.25}
                                    />
                                    <stop
                                        offset="100%"
                                        stopColor={color.stroke}
                                        stopOpacity={0.01}
                                    />
                                </linearGradient>
                            );
                        })}
                </defs>
                <CartesianGrid
                    strokeDasharray="3 3"
                    stroke="currentColor"
                    strokeOpacity={0.08}
                    vertical={false}
                />
                <XAxis
                    dataKey="label"
                    tick={{
                        fontSize: 11,
                        fill: 'currentColor',
                        fillOpacity: 0.45,
                    }}
                    tickLine={false}
                    axisLine={false}
                    minTickGap={20}
                />
                <YAxis
                    allowDecimals={false}
                    width={metric === 'volume' ? 38 : 28}
                    tick={{
                        fontSize: 11,
                        fill: 'currentColor',
                        fillOpacity: 0.45,
                    }}
                    tickLine={false}
                    axisLine={false}
                    tickFormatter={(v: number) => {
                        if (metric === 'volume') {
                            if (v >= 1_000_000) {
                                return `${(v / 1_000_000).toFixed(1)}M`;
                            }

                            if (v >= 1_000) {
                                return `${(v / 1_000).toFixed(0)}k`;
                            }

                            return `${v}`;
                        }

                        return `${v}`;
                    }}
                />
                <Tooltip
                    content={({ active, payload, label }) => {
                        if (!active || !payload || !payload.length) {
                            return null;
                        }

                        const point = payload[0].payload as ActivityPoint;

                        return (
                            <div className="min-w-[190px] rounded-lg border border-sidebar-border/80 bg-popover/95 p-3 text-xs shadow-lg backdrop-blur-xs dark:border-sidebar-border">
                                <div className="mb-2 flex items-center justify-between border-b border-sidebar-border/50 pb-1.5 font-medium">
                                    <span className="text-foreground">
                                        {label}
                                    </span>
                                    <span className="font-mono text-[11px] text-muted-foreground">
                                        {point.count}{' '}
                                        {point.count === 1
                                            ? 'total txn'
                                            : 'total txns'}
                                    </span>
                                </div>

                                <div className="space-y-1.5">
                                    {payload.map((entry) => {
                                        const currCode = entry.name as string;
                                        const isSingleFallback =
                                            currCode === 'Transactions';
                                        const currData =
                                            point.byCurrency?.[currCode];
                                        const countVal = currData
                                            ? currData.count
                                            : metric === 'count'
                                              ? Number(entry.value ?? 0)
                                              : (point.count ?? 0);
                                        const volVal = currData
                                            ? currData.volume
                                            : metric === 'volume'
                                              ? Number(entry.value ?? 0)
                                              : (point.volume ?? 0);
                                        const formattedVol = formatVolume(
                                            volVal,
                                            isSingleFallback
                                                ? undefined
                                                : currCode,
                                        );

                                        return (
                                            <div
                                                key={currCode}
                                                className="flex items-center justify-between gap-3"
                                            >
                                                <div className="flex items-center gap-1.5">
                                                    <span
                                                        className="h-2 w-2 shrink-0 rounded-full"
                                                        style={{
                                                            backgroundColor:
                                                                entry.color,
                                                        }}
                                                    />
                                                    <span className="font-semibold text-foreground">
                                                        {currCode}
                                                    </span>
                                                </div>
                                                <div className="text-right tabular-nums">
                                                    <div className="font-medium text-foreground">
                                                        {metric === 'volume'
                                                            ? formattedVol
                                                            : `${countVal} ${countVal === 1 ? 'txn' : 'txns'}`}
                                                    </div>
                                                    {metric === 'count' &&
                                                        volVal > 0 && (
                                                            <div className="text-[10px] text-muted-foreground">
                                                                {formattedVol}
                                                            </div>
                                                        )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    }}
                />
                {!hasCurrencies ? (
                    <Area
                        type="monotone"
                        dataKey={metric === 'volume' ? 'volume' : 'count'}
                        name="Transactions"
                        stroke="var(--primary)"
                        strokeWidth={2}
                        fill="url(#grad-activity)"
                        dot={false}
                        activeDot={{ r: 3 }}
                    />
                ) : (
                    seriesList.map((c) => {
                        const originalIdx = currencies.findIndex(
                            (item) => item.currency === c.currency,
                        );
                        const color = getCurrencyColor(
                            c.currency,
                            originalIdx >= 0 ? originalIdx : 0,
                        );
                        const dataKey =
                            metric === 'volume'
                                ? `${c.currency}_volume`
                                : `${c.currency}_count`;

                        return (
                            <Area
                                key={c.currency}
                                type="monotone"
                                dataKey={dataKey}
                                name={c.currency}
                                stroke={color.stroke}
                                strokeWidth={2}
                                fill={`url(#grad-${c.currency})`}
                                dot={false}
                                activeDot={{
                                    r: 4,
                                    stroke: color.stroke,
                                    strokeWidth: 2,
                                    fill: 'var(--background)',
                                }}
                            />
                        );
                    })
                )}
            </AreaChart>
        </ResponsiveContainer>
    );
}
