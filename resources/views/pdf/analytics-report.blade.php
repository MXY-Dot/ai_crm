<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1c2330; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    .period { color: #5b6478; font-size: 11px; margin-bottom: 18px; }
    h2 { font-size: 13px; margin: 20px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #dbdfe8; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #eceff3; }
    th { color: #5b6478; font-weight: 600; font-size: 10px; text-transform: uppercase; }
    .kpi-grid { width: 100%; }
    .kpi-grid td { width: 50%; padding: 3px 6px; }
    .kpi-label { color: #5b6478; }
    .kpi-value { font-weight: 600; text-align: right; }
    .funnel-bar-bg { background: #eceff3; height: 8px; border-radius: 4px; }
    .funnel-bar { background: #3a56a0; height: 8px; border-radius: 4px; }
</style>
</head>
<body>
    <h1>Отчёт по аналитике WERO</h1>
    <p class="period">Период: {{ $start }} — {{ $end }}</p>

    <h2>Основные показатели</h2>
    <table class="kpi-grid">
        @foreach ($kpiLabels as $key => $label)
            <tr>
                <td class="kpi-label">{{ $label }}</td>
                <td class="kpi-value">{{ $kpis[$key] ?? '' }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Воронка обращений</h2>
    <table>
        <tr><th>Стадия</th><th>Количество</th><th>%</th><th></th></tr>
        @foreach ($funnel as $stage)
            <tr>
                <td>{{ $stage['label'] }}</td>
                <td>{{ $stage['count'] }}</td>
                <td>{{ $stage['percent_of_total'] }}%</td>
                <td style="width:120px;"><div class="funnel-bar-bg"><div class="funnel-bar" style="width: {{ $stage['percent_of_total'] }}%"></div></div></td>
            </tr>
        @endforeach
    </table>

    <h2>Темы обращений</h2>
    <table>
        <tr><th>Тема</th><th>Количество</th><th>%</th><th>Изменение</th></tr>
        @forelse ($topics as $topic)
            <tr>
                <td>{{ $topic['topic'] }}{{ $topic['is_new'] ? ' (новая)' : '' }}</td>
                <td>{{ $topic['count'] }}</td>
                <td>{{ $topic['percent'] }}%</td>
                <td>{{ $topic['change_percent'] }}%</td>
            </tr>
        @empty
            <tr><td colspan="4">Нет данных за этот период.</td></tr>
        @endforelse
    </table>

    <h2>Результаты диалогов</h2>
    <table>
        <tr><th>Результат</th><th>Количество</th><th>%</th></tr>
        @forelse ($outcomes as $outcome)
            <tr><td>{{ $outcome['outcome'] }}</td><td>{{ $outcome['count'] }}</td><td>{{ $outcome['percent'] }}%</td></tr>
        @empty
            <tr><td colspan="3">Нет данных за этот период.</td></tr>
        @endforelse
    </table>

    <h2>Настроение клиентов</h2>
    <table>
        <tr><th>Настроение</th><th>Количество</th><th>%</th></tr>
        @forelse ($sentiment as $row)
            <tr><td>{{ $row['sentiment'] }}</td><td>{{ $row['count'] }}</td><td>{{ $row['percent'] }}%</td></tr>
        @empty
            <tr><td colspan="3">Нет данных за этот период.</td></tr>
        @endforelse
    </table>

    <h2>Операторы</h2>
    <table>
        <tr><th>Оператор</th><th>Диалогов</th><th>Закрыто</th><th>Оценка</th><th>Недовольных</th><th>Без результата</th><th>Ответ, мин</th><th>Заявок</th><th>Конверсия, %</th></tr>
        @forelse ($operators as $op)
            <tr>
                <td>{{ $op['name'] }}</td>
                <td>{{ $op['conversations'] }}</td>
                <td>{{ $op['closed'] }}</td>
                <td>{{ $op['avg_quality_score'] ?? '—' }}</td>
                <td>{{ $op['unhappy_count'] }}</td>
                <td>{{ $op['no_result_count'] }}</td>
                <td>{{ $op['avg_response_minutes'] ?? '—' }}</td>
                <td>{{ $op['leads_count'] }}</td>
                <td>{{ $op['conversion_rate'] }}</td>
            </tr>
        @empty
            <tr><td colspan="9">Нет назначенных диалогов за этот период.</td></tr>
        @endforelse
    </table>
</body>
</html>
