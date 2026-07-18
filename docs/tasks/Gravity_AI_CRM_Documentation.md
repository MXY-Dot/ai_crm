Gravity AI CRM / AI Omnichannel SaaS Platform

Улучшенная документация: архитектура, ТЗ, база данных, модули, API, MVP roadmap

Содержание документа

Цель продукта и принципы архитектуры

Общая схема системы

Роли и права доступа

Модули платформы

Multi-tenancy

Модели данных и таблицы

AI-архитектура

Chatwoot/Dify интеграция

User flows

API и webhooks

Безопасность

DevOps

Roadmap MVP → Beta → Production

1. Цель продукта

Gravity AI CRM — это SaaS-платформа для компаний, которая объединяет омниканальные сообщения, CRM, AI-ассистентов, базу знаний, аналитику и биллинг. Главная ценность продукта — сократить нагрузку на операторов, ускорить ответы клиентам и автоматически превращать переписки в лиды, заказы, бронирования и сделки.

1.1 Целевая аудитория

Салоны красоты: запись клиентов, расписание, напоминания.

Мебельные цеха: сбор размеров, бюджета, контактов, расчет стоимости.

Клиники: первичная консультация, запись, FAQ, напоминания.

Интернет-магазины: подбор товара, корзина, заказ, доставка.

Автосалоны: подбор авто, лиды, тест-драйвы, консультации.

1.2 Ключевая идея

Клиент пишет в Telegram / WhatsApp / Instagram / сайт
        ↓
Chatwoot принимает сообщение
        ↓
Laravel получает webhook и решает бизнес-логику
        ↓
Dify формирует AI-ответ
        ↓
Laravel сохраняет CRM-данные и отправляет ответ через Chatwoot
        ↓
Если AI не уверен — чат передается оператору

2. Общая архитектура системы

2.1 Архитектурная схема

┌──────────────┐   ┌──────────────┐   ┌──────────────┐
│ Telegram     │   │ WhatsApp     │   │ Website Chat │
└──────┬───────┘   └──────┬───────┘   └──────┬───────┘
       │                  │                  │
       └──────────────┬───┴──────────────┬───┘
                      ▼                  ▼
                ┌──────────────┐   ┌──────────────┐
                │ Chatwoot     │   │ Evolution API │
                └──────┬───────┘   └──────┬───────┘
                       ▼                  │
                ┌─────────────────────────┐
                │ Laravel API / Core SaaS │
                └──────┬─────────┬────────┘
                       │         │
          ┌────────────▼───┐ ┌───▼────────────┐
          │ PostgreSQL CRM │ │ Dify AI Agents │
          └────────────┬───┘ └───┬────────────┘
                       │         │
                  ┌────▼─────────▼────┐
                  │ Redis Queues/Jobs │
                  └───────────────────┘

3. Основные модули платформы

4. Роли и права доступа

5. Multi-tenant архитектура

Рекомендуемый вариант для MVP: одна общая база PostgreSQL с обязательным tenant_id во всех бизнес-таблицах. Это быстрее в разработке и проще для аналитики. Для крупных клиентов в будущем можно добавить dedicated database mode.

Правило разработки:
1. Любая CRM/AI/Chat таблица должна иметь tenant_id.
2. Все Eloquent queries должны фильтроваться по tenant_id через Global Scope.
3. Нельзя получать данные без проверки tenant_id.
4. Все API endpoints работают в контексте tenant.

6. Модели данных и таблицы

Ниже приведена базовая структура таблиц для MVP. Поля можно расширять, но tenant_id должен быть обязательным во всех бизнес-сущностях.

users

id, name, email, password, phone, status, last_login_at, two_factor_enabled, created_at, updated_at

tenants

id, name, slug, status, plan_id, trial_ends_at, settings_json, created_at, updated_at

companies

id, tenant_id, name, industry, phone, email, website, address, timezone, working_hours_json, brand_settings_json

roles

id, tenant_id, name, guard_name, permissions_json

channels

id, tenant_id, company_id, type, provider, external_id, status, credentials_encrypted, settings_json

conversations

id, tenant_id, company_id, channel_id, chatwoot_conversation_id, customer_id, status, assigned_user_id, ai_enabled, last_message_at

messages

id, tenant_id, conversation_id, external_message_id, sender_type, sender_id, content, attachments_json, direction, ai_generated, created_at

customers

id, tenant_id, company_id, name, phone, email, source, tags_json, meta_json, created_at

leads

id, tenant_id, company_id, customer_id, title, status, source, score, assigned_user_id, ai_summary, created_at

deals

id, tenant_id, lead_id, pipeline_id, stage_id, amount, currency, status, probability, expected_close_at

tasks

id, tenant_id, company_id, lead_id, assigned_user_id, title, description, status, due_at, priority

knowledge_documents

id, tenant_id, company_id, title, file_path, file_type, status, dify_document_id, indexed_at

ai_agents

id, tenant_id, company_id, name, dify_app_id, prompt_template, tone, status, rules_json

ai_runs

id, tenant_id, conversation_id, message_id, ai_agent_id, input_tokens, output_tokens, confidence_score, status, latency_ms

bookings

id, tenant_id, company_id, customer_id, service_id, staff_id, starts_at, ends_at, status, notes

products

id, tenant_id, company_id, sku, name, description, price, currency, stock, status

orders

id, tenant_id, customer_id, status, total_amount, delivery_address, payment_status, created_at

subscriptions

id, tenant_id, plan_id, status, starts_at, ends_at, limits_json, usage_json

audit_logs

id, tenant_id, user_id, action, entity_type, entity_id, old_values_json, new_values_json, ip_address, user_agent, created_at

7. AI-архитектура

AI должен быть не просто генератором текста, а бизнес-агентом, который умеет отвечать, собирать данные, создавать лиды, предлагать товары/услуги, делать handoff и сохранять резюме диалога.

AI decision flow:
Message received
→ Detect language
→ Detect intent
→ Search knowledge base
→ Generate answer
→ Extract entities
→ Check confidence
→ Create/update CRM records
→ Send answer OR handoff to operator

8. Интеграция Chatwoot ↔ Laravel ↔ Dify

8.1 Правила Human Handoff

Confidence score ниже заданного порога.

Клиент просит оператора или живого человека.

Вопрос связан с оплатой, возвратом, жалобой, юридическими условиями.

AI не нашел ответ в базе знаний.

Клиент написал несколько раз подряд без успешного решения.

AI detected negative sentiment или конфликт.

9. User Flows

9.1 Онбординг компании

Регистрация владельца компании.

Создание tenant и company profile.

Выбор сферы бизнеса.

Выбор тарифа или trial.

Подключение Telegram/WhatsApp/виджета сайта.

Заполнение AI-брифа.

Загрузка документов базы знаний.

Тестовый диалог с AI.

Активация AI в реальных каналах.

9.2 Диалог клиента

Клиент пишет в канал.

AI приветствует клиента.

AI определяет намерение.

AI задает уточняющие вопросы.

Если есть контактные данные — создает customer/lead.

Если клиент готов купить/записаться — создает deal/booking/order.

Если нужно — передает оператору.

9.3 Сценарий мебельного цеха

Клиент спрашивает цену шкафа.

AI уточняет размеры, материал, цвет, бюджет и город.

AI сохраняет данные в lead.

AI рассчитывает примерный диапазон цены или передает менеджеру.

Менеджер получает задачу “Связаться с клиентом”.

9.4 Сценарий салона красоты

Клиент пишет “хочу записаться”.

AI уточняет услугу, мастера, дату и время.

Система проверяет расписание.

AI предлагает свободные слоты.

После подтверждения создается booking и отправляется напоминание.

10. API и Webhooks

10.1 Внутренние события

TenantCreated

CompanyOnboarded

ChannelConnected

ConversationStarted

MessageReceived

AIResponseGenerated

AIHandoffRequired

LeadCreated

DealCreated

BookingCreated

OrderCreated

SubscriptionLimitReached

11. Dashboard / Frontend

12. Безопасность

RBAC на уровне API и UI.

Global tenant scope для всех бизнес-моделей.

Audit logs для всех критичных действий.

2FA для Owner и Super Admin.

Rate limiting для API, webhooks и AI requests.

Шифрование access tokens и credentials.

Проверка подписи webhooks.

Логи ошибок без утечки персональных данных.

Backups PostgreSQL и storage.

13. DevOps и инфраструктура

14. Roadmap разработки

15. Критерии готовности MVP

Компания может зарегистрироваться и получить отдельный tenant.

Owner может подключить минимум один канал.

Сообщение из канала приходит в Chatwoot и Laravel.

AI отвечает через Dify и сохраняет переписку.

Lead создается автоматически из диалога.

Оператор может забрать чат у AI.

Все данные изолированы по tenant_id.

Есть базовый dashboard с лидами, чатами, AI и настройками.

Есть логирование действий и ошибок.

16. Что улучшено по сравнению с исходным ТЗ

Убрана повторяющаяся вода и копипаст требований.

Добавлена понятная архитектура взаимодействия сервисов.

Добавлены реальные модули и ответственность каждого модуля.

Добавлены базовые таблицы и поля БД.

Добавлены User Flows для компании, клиента, салона и мебельного цеха.

Добавлены события, очереди, webhooks и API endpoints.

Добавлены правила Human Handoff.

Добавлены этапы разработки MVP/Beta/Production.

Добавлены критерии готовности MVP.

17. Рекомендация по реализации

Не разрабатывать собственный омниканальный inbox с нуля. Chatwoot должен отвечать за прием/отправку сообщений и интерфейс операторов, Dify — за AI/RAG, Laravel — за SaaS, CRM, биллинг, правила, аналитику и интеграции. Это снижает стоимость разработки и ускоряет запуск MVP.




| Параметр | Значение |

|---|---|

| Версия документа | v3.0 Improved |

| Дата | 05.07.2026 |

| Стек | Laravel 12, PHP 8.4, Vue 3, PostgreSQL, Redis, Chatwoot, Dify, Evolution API |

| Модель | Multi-tenant SaaS |

| Цель | Автоматизация продаж, поддержки и лидогенерации через AI-ассистентов |




| Слой | Назначение | Технологии |

|---|---|---|

| Frontend | Кабинет клиента, CRM, настройки AI, аналитика | Vue 3, Pinia, Vite, TailwindCSS |

| Backend/API | Бизнес-логика, SaaS, CRM, интеграции, биллинг | Laravel 12, PHP 8.4 |

| AI Layer | AI-агенты, RAG, база знаний, ответы | Dify |

| Omnichannel Layer | Прием и отправка сообщений по каналам | Chatwoot |

| WhatsApp Gateway | WhatsApp подключение | Evolution API |

| Database | Данные SaaS, CRM, логи, тарифы | PostgreSQL |

| Queue/Cache | Очереди, события, rate limiting | Redis |

| Storage | Документы базы знаний, вложения | S3-compatible или local storage |




| Модуль | Описание |

|---|---|

| Tenant Management | Создание и управление арендаторами SaaS. Отвечает за изоляцию данных, лимиты, настройки и брендирование. |

| Company Profile | Данные компании: название, сфера, филиалы, рабочее время, контакты, политика общения. |

| Users & RBAC | Пользователи, роли, права доступа, приглашения, 2FA, аудит действий. |

| Omnichannel Inbox | Подключение каналов и синхронизация сообщений через Chatwoot. |

| AI Orchestrator | Связка Laravel ↔ Dify, проверка confidence, применение правил, handoff. |

| Knowledge Base | Загрузка PDF/DOCX/XLSX/CSV, сайт компании, FAQ, индексация в AI. |

| CRM | Лиды, клиенты, сделки, задачи, комментарии, история контактов. |

| Booking | Услуги, мастера, расписание, запись, напоминания. |

| Catalog & Orders | Товары, категории, остатки, корзина, оформление заказов. |

| Analytics | Конверсия, источники, AI эффективность, нагрузка операторов, продажи. |

| Billing | Тарифы, подписки, лимиты сообщений, AI usage, счета. |

| Integrations | API, webhooks, Telegram, WhatsApp, Instagram, Facebook, website widget. |




| Роль | Права |

|---|---|

| Super Admin | Видит все tenants, управляет тарифами, системными настройками, интеграциями, мониторингом. |

| Company Owner | Управляет своей компанией, пользователями, каналами, AI, тарифом, CRM и отчетами. |

| Manager | Видит лиды, сделки, задачи, аналитику, назначает операторов, управляет pipeline. |

| Operator | Работает с чатами, отвечает клиентам, создает лиды/задачи, видит назначенные данные. |

| AI Agent | Системная роль для автоматических действий: ответы, создание лидов, заметок и задач. |




| Действие | Super Admin | Owner | Manager | Operator |

|---|---|---|---|---|

| Управление tenants | Да | Нет | Нет | Нет |

| Настройка компании | Да | Да | Частично | Нет |

| Подключение каналов | Да | Да | Нет | Нет |

| Настройка AI | Да | Да | Частично | Нет |

| Просмотр всех лидов | Да | Да | Да | Нет/назначенные |

| Ответ в чатах | Да | Да | Да | Да |

| Биллинг | Да | Да | Нет | Нет |

| Аналитика | Да | Да | Да | Ограничено |




| Вариант | Плюсы | Минусы | Рекомендация |

|---|---|---|---|

| Shared DB + tenant_id | Просто, быстро, дешево, удобно для MVP | Нужна строгая изоляция запросов | Использовать в MVP |

| Separate schema per tenant | Лучшая изоляция | Сложнее миграции и поддержка | Для Beta/Enterprise |

| Separate DB per tenant | Максимальная изоляция | Дорого и сложно | Только Enterprise |




| Компонент | Назначение |

|---|---|

| AI Agent Profile | Имя ассистента, тон, правила, сфера бизнеса, лимиты. |

| Prompt Template | Базовый системный промпт компании. |

| Knowledge Retrieval | Поиск по базе знаний компании через Dify. |

| Intent Detection | Определение намерения: вопрос, покупка, запись, жалоба, оператор. |

| Entity Extraction | Имя, телефон, бюджет, размер, услуга, дата, адрес. |

| Tool Actions | Создать лид, создать заказ, создать запись, создать задачу. |

| Confidence Gate | Если уверенность низкая — не отвечать, а передать оператору. |




| Шаг | Что происходит |

|---|---|

| 1 | Клиент пишет в Telegram/WhatsApp/виджет сайта. |

| 2 | Chatwoot создает conversation/message. |

| 3 | Chatwoot отправляет webhook в Laravel. |

| 4 | Laravel валидирует tenant/channel/conversation. |

| 5 | Laravel кладет задачу в Redis Queue. |

| 6 | Job отправляет контекст в Dify. |

| 7 | Dify возвращает ответ + metadata. |

| 8 | Laravel проверяет confidence и бизнес-правила. |

| 9 | Laravel создает/обновляет Lead/Customer/Task/Deal. |

| 10 | Laravel отправляет ответ в Chatwoot API или назначает оператора. |




| Endpoint | Метод | Назначение |

|---|---|---|

| /api/auth/login | POST | Авторизация пользователя |

| /api/tenants | POST/GET | Создание и просмотр tenants |

| /api/companies | POST/GET/PATCH | Компания |

| /api/channels | POST/GET/PATCH | Каналы коммуникации |

| /api/chatwoot/webhook | POST | Webhook от Chatwoot |

| /api/dify/callback | POST | Callback/response от Dify если используется async |

| /api/leads | GET/POST/PATCH | Лиды |

| /api/customers | GET/POST/PATCH | Клиенты |

| /api/deals | GET/POST/PATCH | Сделки |

| /api/bookings | GET/POST/PATCH | Бронирования |

| /api/orders | GET/POST/PATCH | Заказы |

| /api/knowledge | GET/POST/DELETE | База знаний |

| /api/billing/subscription | GET/PATCH | Подписка |

| /api/analytics/overview | GET | Общая аналитика |




| Экран | Что содержит |

|---|---|

| Главная | KPI, новые лиды, активные чаты, AI usage, конверсия. |

| Чаты | Список диалогов, фильтры, назначение оператора, AI summary. |

| Лиды | Kanban/list view, статусы, источник, ответственный, score. |

| CRM | Клиенты, сделки, задачи, комментарии, история. |

| AI | AI-агенты, промпты, база знаний, тест диалог, правила handoff. |

| Каналы | Telegram, WhatsApp, Instagram, Facebook, web widget. |

| Аналитика | Источники, конверсия, эффективность AI, продажи. |

| Настройки | Компания, пользователи, роли, интеграции, уведомления. |

| Биллинг | Тариф, лимиты, использование, счета. |




| Компонент | Рекомендация |

|---|---|

| Docker | Отдельные контейнеры: app, nginx, postgres, redis, queue, scheduler, chatwoot, dify. |

| CI/CD | GitHub Actions/GitLab CI: tests, build, deploy. |

| Queue workers | Supervisor или Horizon-compatible подход для Laravel jobs. |

| Monitoring | Uptime, errors, queues, database, AI latency. |

| Backups | Ежедневный backup БД, storage и конфигов. |

| Logs | Centralized logs: Laravel, Chatwoot, Dify, nginx. |




| Этап | Функции | Результат |

|---|---|---|

| MVP 1 | Auth, tenants, users, roles, company profile, CRM leads/customers | Базовая SaaS CRM |

| MVP 2 | Chatwoot webhook, Telegram, website widget, conversations/messages | Омниканальные чаты |

| MVP 3 | Dify integration, AI agent, knowledge base, handoff | AI отвечает клиентам |

| MVP 4 | Deals, tasks, analytics overview, billing basic | Коммерческий MVP |

| Beta | WhatsApp, bookings, catalog/orders, advanced reports | Готово для первых клиентов |

| Production | Marketplace, workflow builder, voice AI, enterprise tenants | Масштабируемый продукт |