# PR-SEC1A.2 — DB runtime privilege plan for PR-3

Data: 2026-10-04. Inventário local em `a3fad32`, migrations/models/actions/controllers e drivers Laravel instalados. **Plano, não grants aplicados nem validação Supabase.** A auditoria foi interrompida em SEC-006; esse finding impede o veredito READY.

## Separação de identidades

- **Migration user:** CONNECT no banco provisionado; USAGE/CREATE somente no schema da aplicação; ownership dos objetos que precisa alterar. Executa CREATE/ALTER/DROP TABLE, INDEX, constraints, foreign keys, sequences, seeders e manutenção da tabela `migrations`. O DDL PostgreSQL explícito está nas migrations (CHECKs, índices parciais/únicos e remoção de constraints). Não precisa criar bancos ou roles. Provisionamento inicial/seeding de catálogos e plataforma deve usar uma identidade de operador, não ampliar os grants do HTTP worker.
- **Runtime user:** CONNECT, USAGE no schema; DML nas tabelas abaixo; USAGE nas sequences associadas a INSERT. Sem SUPERUSER, CREATEDB, CREATEROLE, REPLICATION, BYPASSRLS, ownership do banco/schema/tabelas ou CREATE no schema. Não deve ser membro da role de migration. GRANTs sem grant option. Transações, SELECT FOR UPDATE e advisory locks não justificam privilégios de DDL.
- **Queue worker:** pode compartilhar os grants runtime necessários ao job, ou usar role separada com o subconjunto deliveries/recipients/settings/day-closes + infraestrutura de queue/cache.

Schema alvo ainda deve ser decidido/validado na PR-3; referências não qualificadas atuais usam o search_path da conexão. Não executar `GRANT ... ON ALL TABLES` indiscriminadamente num schema compartilhado com outras aplicações/Supabase. Reconciliar privilégios PUBLIC herdados, ownership e exposição de schemas separadamente, sem alterar usuários agora.

## Inventário concreto de operações

S = SELECT, I = INSERT, U = UPDATE, D = DELETE. SELECT FOR UPDATE precisa de SELECT e UPDATE nas tabelas bloqueadas. Sequences são necessárias somente para tabelas com IDs gerados por sequence e INSERT runtime.

| Tabelas | Runtime | Origem/justificativa |
| --- | --- | --- |
| `users` | S/I/U | Login, remember token/password rehash, criação de staff, identidade e suspensão de plataforma. Não há DELETE de usuários na API. |
| `organizations` | S/U | Tenant/context, nome/slug/status/plan da organização existente. Criação fica no provisioning de operador. |
| `restaurants` | S/I/U | CRUD exposto sem endpoint de DELETE. |
| `organization_users` | S/I/U | Staff/membership ativa/inativa; tenant/context. A API atual não remove membership da organização. |
| `restaurant_users`, `user_roles` | S/I/U/D | Reconciliação de assignments de staff remove relações antigas. |
| `roles`, `permissions`, `role_permissions` | S | Catálogo e helpers de autorização. Escrita em seed/provisioning, sem gestão de roles pela API. |
| `platform_roles`, `platform_permissions`, `platform_role_permissions`, `platform_role_assignments` | S | Gate/context de plataforma. Atribuição de plataforma pelo operador/provisioning. |
| `restaurant_settings` | S/I/U | Configuração operacional; criada junto ao restaurante. |
| `tables` | S/I/U | Estrutura/layout/status e geração do token no servidor. |
| `floors`, `zones` | S/I/U/D | Endpoints de criação/edição/remoção, guards de dependências. |
| `menus`, `categories`, `category_translations` | S/I/U | Criação/edição e upsert de traduções; sem endpoint de DELETE. |
| `category_products` | S/I/U/D | Attach/update/detach de produtos à categoria. |
| `products`, `product_translations`, `restaurant_products` | S/I/U | Catálogo, traduções, preço/disponibilidade e anexação ao restaurante. |
| `product_media` | S/I/U/D | Replace/updateOrCreate/delete; arquivos são escritos no disk, não pelo PostgreSQL. |
| `modifier_groups`, `modifier_group_translations`, `modifier_options`, `modifier_option_translations` | S/I/U | Catálogo e traduções. |
| `table_sessions` | S/I/U | Open, payment status, assigned waiter, close, transfer, void; row locks. |
| `orders` | S/I/U | Criação, lifecycle; unique idempotency e row locks. |
| `order_items`, `order_item_modifiers` | S/I | Snapshots criados nas actions de pedidos; transfer atualiza o pedido/sessão, sem writer direto nos items. |
| `payment_records` | S/I | Pagamentos append-only e agregações. |
| `table_requests`, `waiter_calls` | S/I/U | Criação e lifecycle/acknowledge. |
| `staff_shifts` | S/I/U | Start/end; row locks e índice parcial único. |
| `staff_reviews` | S/I | Reviews append-only. |
| `customer_feedbacks` | S/I | Feedback e relatórios; escrita pelo token público. |
| `print_records` | S/I | Registra impressão de receipt/ticket. |
| `audit_logs`, `restaurant_activity_events` | S/I | Trilha append-only; não dar U/D ao HTTP worker. |
| `restaurant_activity_reads` | S/I/U | Cursor monotônico por usuário/restaurante via upsert. |
| `restaurant_cash_movements` | S/I | Append-only, idempotency única. |
| `restaurant_day_closes` | S/I/U | Snapshot append-only; UPDATE permission requerida pelo SELECT FOR UPDATE no resend, mesmo sem update do conteúdo pelo endpoint. |
| `restaurant_day_close_annotations` | S/I | Notas posteriores append-only. |
| `restaurant_report_recipients` | S/I/U | Troca de destinatário desativa versões anteriores; dados sensíveis encrypted/hashed/masked. |
| `restaurant_day_close_deliveries` | S/I/U | Pending/send/webhook statuses; locks e unique constraints. |
| `sessions` | S/I/U/D | Driver database; logout/garbage collection e suspensão de usuário removem sessões. PK textual, sem sequence. |
| `cache`, `cache_locks` | S/I/U/D | Driver database do `.env.example`; rate limiting, TTL, locks, release/pruning. PK textual. |
| `jobs`, `job_batches`, `failed_jobs` | S/I/U/D | Driver database do `.env.example`; reservar/processar/remover jobs, retries/batches/falhas e pruning. Worker/operação de queue. |
| `personal_access_tokens` | Nenhum no fluxo atual | Model User não usa HasApiTokens; não há emissão/gestão de bearer tokens na API atual. Reavaliar se habilitada essa superfície. |
| `password_reset_tokens` | Nenhum no fluxo atual | Não há endpoint de password reset. Reavaliar ao adicionar o fluxo. |
| `migrations` | Nenhum | Tabela criada pelo migrator do framework, fora das 56 tabelas declaradas pelas migrations do projeto. Migration user somente. |

As 56 tabelas declaradas nas migrations estão representadas; `migrations` é adicional. Cascades/ações referenciais internas do PostgreSQL não justificam concessão de U/D sem um writer direto da aplicação.

## Locks, sequences e futuras migrations

`RestaurantOperationalLock` usa somente `SELECT pg_advisory_xact_lock_shared(?, ?)` e `SELECT pg_advisory_xact_lock(?, ?)` com namespace constante 9101 + restaurant_id inteiro. Prever EXECUTE dessas duas funções se a política da instalação retirar o EXECUTE herdado de PUBLIC; não conceder execução genérica de todas as funções. Os locks liberam em COMMIT/ROLLBACK.

Para cada tabela com INSERT e sequence de id, conceder **USAGE** na sequence concreta identificada com `pg_get_serial_sequence`; não presumir nomes. Não conceder UPDATE/setval ao runtime. Se a instalação usar identity columns com requisitos distintos, conferir com INSERT real usando a role limitada na PR-3. Grants de novos objetos/default privileges pertencem à role migration e devem seguir a mesma classificação; auditar explicitamente cada migration nova.

## Validação prevista, não executada

Na PR-3, rodar fluxo de login/session/cache/queue, gravações e replay/concurrency com runtime limitado; confirmar DDL/CREATE ROLE/CREATE DATABASE/setval recusados, grants PUBLIC e search_path, além das duas funções advisory. Não validar esses grants contra o superuser local como se fossem restritos. Não criamos roles, usuários, schemas, grants ou configuração Supabase nesta carta.
