# Checklist manual único — rodada integrada local, do zero

Ambiente: local (Sail) — API `http://localhost:8080`, frontend
`http://localhost:5174`, Reverb `:8081`. **Lista única**: substitui os
checklists dispersos dos relatórios QR/aprovação/realtime/atribuição/
histórico/disponibilidade/dia operacional/onboarding (eles continuam valendo
como contexto técnico). Versão anterior (20 cenários, todos PENDENTE, nenhum
resultado registrado) reorganizada aqui por dependência real; nenhum cenário
válido foi removido.

Regras da rodada:

- "Do zero" = restaurante **novo** criado pelos fluxos existentes. Nada de
  limpar banco, rodar seeders ou criar dados por SQL/API.
- Tudo **PENDENTE**: o agente não executou nenhum cenário; testes
  automatizados não aprovam itens desta lista.
- Estados: `PENDENTE` / `PASSOU` / `FALHOU` / `BLOQUEADO` (com a causa).
- Evidência **sem segredos**: prints, status HTTP da aba Rede, contagens de
  banco. Nunca colar senhas, tokens, links de convite/recuperação, cookies
  nem payloads de `jobs`.
- Se faltar interface para um passo: marcar `BLOQUEADO` e anotar. Não
  substituir por SQL ou chamada direta à API.
- Menus citados com o rótulo real em es-ES (entre « »). Navegação lateral
  (rail/drawer): «Panel», «Cocina», «Servicio», «Carta», «Cierre diario»,
  «Mesas», «Equipo», «Feedback» — cada item só aparece com a permissão
  correspondente. «Configuración» fica no **menu do usuário** (avatar).

Papéis previstos (todas as contas novas nascem por convite nesta rodada):

| Papel | Conta | Origem |
|---|---|---|
| Super Admin | conta de plataforma existente | já existe (credencial sua) |
| Dono (owner) | e-mail novo seu | convite da plataforma (fase 2) |
| Manager | e-mail novo | convite do dono (fase 4) |
| Garçom A, Garçom B | e-mails novos | convite (fase 4) — **dois**, para comparar demanda |
| Cozinha | e-mail novo | convite (fase 4) |
| Caixa | e-mail novo | convite (fase 4) |
| Conta de outra organização | conta existente (ex.: um waiter atual) | já existe — só para isolamento/convite de conta existente |

Use e-mails que não recebam nada de verdade (o envio é local: tudo cai em
`storage/logs/mail-capture.log`). Recomendado: um perfil/janela anônima por
papel para não misturar sessões.

---

## Resultados registrados

Só o que o usuário confirmou explicitamente; nada inferido de testes
automatizados. Texto da confirmação (2026-10-25), sem edição:

> - Criação da conta de garçom por convite.
> - Início de turno e atribuição à mesa.
> - Abertura e fluxo QR do último teste orientado.

| Confirmação | Cenário(s) | Estado registrado |
|---|---|---|
| Conta de garçom por convite | 4.3 (só o papel garçom) | PASSOU (parcial) |
| Início de turno | 4.6 (iniciar) | PASSOU (parcial) |
| Atribuição à mesa | 6.1 **ou** 6.2 — a confirmar | anotado, cenário segue PENDENTE |
| Abertura e fluxo QR | 5.3 (abrir o dia) e/ou abertura de mesa por QR (6.2) — a confirmar | anotado, cenários seguem PENDENTE |

Pendente da sua parte: dizer a qual cenário cada uma das duas últimas
linhas corresponde (ou marcá-los direto aqui). Todos os demais cenários
continuam PENDENTE.

---

## Fase 1 — Preparação

### 1.1 Serviços no ar

| | |
|---|---|
| Papel | você (terminal) |
| Pré | Docker Desktop aberto com integração WSL ativa (em 2026-10-23 08:56 o `docker` não existia no WSL e nenhuma porta 8080/8081/5174 escutava). |
| Passos | Seguir "Como começar" no fim deste arquivo, passos 1–6. |
| Esperado | `artisan serve` (supervisor) responde em 8080; **uma** instância de cada: `reverb:start` (8081), `queue:work … --queue=realtime-local`, `queue:work … --queue=mail-local`; Vite em **5174** (não 5173). Nenhum worker na `default`. |
| Evidência | Saída do `ps` no container (só PID/usuário/comando); `ss -ltn` com 5174. |
| Estado | PENDENTE |

### 1.2 Porta 5174 = AFORO_WEB_URL

| | |
|---|---|
| Passos | `grep -E '^AFORO_WEB_URL' .env` (API) e `grep VITE_PUBLIC_APP_URL .env` (web); abrir `http://localhost:5174/login`. |
| Esperado | Ambos `http://localhost:5174`; tela de login carrega; `SANCTUM_STATEFUL_DOMAINS` contém `localhost:5174`. Se o Vite subir em outra porta (5174 ocupada), parar e liberar a 5174 — links de convite/QR apontam para 5174. |
| Estado | PENDENTE |

### 1.3 Super Admin existente

| | |
|---|---|
| Papel | Super Admin |
| Passos | `/login` → credencial de plataforma (sua) → entra. |
| Esperado | Cai em `/platform/onboarding` («Alta de restaurante»), layout de plataforma, sem menus de restaurante. |
| Estado | PENDENTE |

### 1.4 Linha de base (sem payloads)

| | |
|---|---|
| Passos | No banco: `select queue, count(*) from jobs group by queue;` · `select count(*) from failed_jobs;` · `grep -c 'localhost:5174/invitation#\|reset-password#' storage/logs/laravel.log`. |
| Esperado | Referência anterior: `default` = 284, `realtime-local` = 0, `mail-local` = 0, `failed_jobs` = 1, `laravel.log` = 4. Anotar os valores de hoje; `default` deve ficar **igual** no fim. |
| Estado | PENDENTE |

---

## Fase 2 — Cadastro

### 2.1 Super Admin cria organização + restaurante novos

| | |
|---|---|
| Papel | Super Admin |
| Pré | 1.3. Ninguém logado como tenant nesta janela. |
| Caminho | `/platform/onboarding` «Alta de restaurante» |
| Passos | «Organización nueva» → «Nombre de la organización» (identificador gerado) → «Nombre del restaurante» (ex.: "PRUEBA LOCAL …") → «Zona horaria» Europe/Madrid → «Idioma principal» → «Correo del propietario» = seu e-mail novo → «Dar de alta» com **duplo clique**. |
| Esperado | 1 organização + 1 restaurante + 1 convite (duplo clique não duplica; reenvio idêntico mostra "Este alta ya se había registrado"). Resumo «Restaurante dado de alta», EUR, convite `Pendiente`, entrega passa a "Entregada al servidor de correo" depois que o worker `mail-local` processa. Nenhuma mesa, produto, equipe ou dia aberto criados. |
| Evidência | Print do resumo; `select count(*) from restaurants where slug='…'` = 1; fila `mail-local` volta a 0. |
| Estado | PENDENTE |

### 2.2 Link do convite pela captura local

| | |
|---|---|
| Papel | você (terminal) |
| Passos | `grep -o 'http://localhost:5174/[a-z-]*#[^" ]*' storage/logs/mail-capture.log \| tail -1` (na pasta da API). Copiar direto para o navegador — **não** colar em chat/relatório. |
| Esperado | Link `…/invitation#token=…`; `laravel.log` não ganhou ocorrência nova. |
| Estado | PENDENTE |

### 2.3 Dono define senha e entra

| | |
|---|---|
| Papel | Dono |
| Pré | 2.2. Janela **sem sessão**. |
| Passos | Abrir o link → barra de endereço fica só `/invitation` → prévia (organização, restaurante, função, e-mail, caducidade) → nome + senha + confirmação (testar divergente primeiro) → «Aceptar». Depois reabrir o mesmo link. |
| Esperado | Sessão iniciada no restaurante novo; Panel mostra «Configura tu restaurante» (checklist de configuração) e o dia **não** está aberto. Link reaberto: "ya se ha usado". |
| Evidência | Prints; `select status from invitations where id=…` = `accepted`. |
| Estado | PENDENTE |

---

## Fase 3 — Configuração inicial (Dono)

Ordem real de dependência: configurações → carta (categorias → produtos com
preço e vínculo ao restaurante) → mesas/QR. Pedidos dependem de carta com
produto ativo **e** de dia aberto (fase 5).

### 3.1 Dados, idioma e fuso

| | |
|---|---|
| Caminho | Menu do usuário → «Configuración» (`/app/settings`) |
| Passos | «Información del restaurante» → nome → «Guardar cambios». «Configuración operativa» → «Idioma predeterminado», «Idiomas habilitados» (es-ES, ca-ES-valencia, en-GB), «Zona horaria del restaurante» = Europe/Madrid → «Guardar cambios». |
| Esperado | "Guardado"; recarregar mantém os valores. Moeda EUR. |
| Nota | Hora de corte do dia operacional = **06:00** (padrão; não há campo na interface). Ver 8.5. |
| Estado | PENDENTE |

### 3.2 Pedidos QR e aprovação obrigatória

| | |
|---|---|
| Caminho | «Configuración» → «Configuración operativa» → «Pedidos y atención» |
| Passos | Conferir «Pedidos desde el código QR» **ligado**; ligar «Los pedidos de clientes requieren aprobación» (vem **desligado** no restaurante novo); conferir «Llamar al camarero» e «Solicitar la cuenta» ligados → «Guardar cambios». |
| Esperado | Valores persistem após recarregar. Sem esta etapa o cenário 6.4 não testa aprovação. |
| Estado | PENDENTE |

### 3.3 Carta e categorias

| | |
|---|---|
| Caminho | «Carta» (`/app/menu`) |
| Passos | «Crear carta» (nome, ex.: "Carta principal") → aba «Categorías» → «Crear categoría» ×2 (ex.: Bebidas, Platos) → reordenar com «Mover categoría arriba/abajo». |
| Esperado | Carta `Activa`; categorias ativas na ordem escolhida. |
| Estado | PENDENTE |

### 3.4 Produtos, preços, disponibilidade e vínculo

| | |
|---|---|
| Caminho | «Carta» → aba «Productos» → «Añadir producto» → «Crear nuevo» |
| Passos | Criar ≥3 produtos com «Precio» (testar decimal, ex. `2,50`) e «Disponibilidad» `Disponible`. Na aba «Categorías», em cada categoria → «Añadir productos» → «Añadir a la categoría». Um produto extra fica para o teste de esgotado (6.7). Opcional: aba «Modificadores». Aba «Vista previa» para conferir. |
| Esperado | Produtos ligados **a este restaurante** com preço (o produto do catálogo da organização só aparece no restaurante depois de «Añadir a la carta»); preço exibido `2,50 €`. |
| Nota | «Usar uno existente» reaproveita produto de outro restaurante **da mesma organização**; a organização é nova, então o catálogo começa vazio ("Todavía no tienes productos en tu catálogo"). |
| Estado | PENDENTE |

### 3.5 Plantas, zonas, mesas e QR

| | |
|---|---|
| Caminho | «Mesas» (`/app/tables`) |
| Passos | «Añadir planta» → «Añadir zona» (ex.: Interior) → «Añadir mesa» ×4 (Mesa 1–4) → arrastar e «Guardar distribución» → selecionar uma mesa → «Configuración de la mesa» (capacidade, estado `Activa`) → painel «QR del cliente» → «Copiar enlace» / «Abrir carta» / «Imprimir». |
| Esperado | Cada mesa com QR próprio apontando para `http://localhost:5174/t/…`. Abrir o QR **antes** de abrir o dia: carta só leitura + "Todavía no estamos atendiendo". |
| Evidência | Print do painel QR (sem colar o link). |
| Estado | PENDENTE |

---

## Fase 4 — Equipe

### 4.1 Convites (manager, 2 garçons, cozinha, caixa)

| | |
|---|---|
| Papel | Dono |
| Caminho | «Equipo» (`/app/staff`) → «Invitar persona» |
| Passos | Para cada pessoa: e-mail novo, função (Encargado / Camarero / Cocina / Caja), restaurante de teste → «Enviar invitación». Seção «Invitaciones» lista os pendentes. |
| Esperado | "Invitación enviada a …"; função "Propietario" não é oferecida; um e-mail por convite na captura local. |
| Estado | PENDENTE |

### 4.2 Reenvio, cancelamento e uso único

| | |
|---|---|
| Papel | Dono |
| Passos | Reenviar um convite (contador "Reenviar en N s"; link antigo deixa de valer). Cancelar outro (confirmação; link → inválido). Criar de novo o cancelado se ainda for preciso para a equipe. |
| Esperado | Só o link mais recente aceita; cancelado não aceita; aceito não reaceita. |
| Evidência | Estados em `invitations` (sem token). |
| Estado | PENDENTE |

### 4.3 Aceite com senhas próprias

| | |
|---|---|
| Papel | cada convidado, cada um na sua janela sem sessão |
| Passos | Link da captura → nome + senha própria → «Aceptar». |
| Esperado | Cada um entra só com os menus do seu papel: Garçom → «Servicio» (+«Panel»); Cozinha → «Cocina»; Caixa → «Servicio» e «Cierre diario»; Manager → tudo menos plataforma. Roster do Dono mostra todos. |
| Estado | **PASSOU (parcial)** — confirmado pelo usuário em 2026-10-25 só para a **conta de garçom** criada por convite. Manager, cozinha e caixa: PENDENTE. |

### 4.4 Convite de conta existente (outra organização)

| | |
|---|---|
| Papel | Dono + conta existente |
| Passos | «Invitar persona» com o e-mail da conta existente, função Camarero. Abrir o link (a) na sessão do Dono → "otra cuenta"; (b) em janela sem sessão → senha errada, depois a correta. |
| Esperado | (a) sem formulário de aceite; (b) "La contraseña no es correcta", depois entra. Nome/senha da conta inalterados; continua na organização original com o papel original. |
| Estado | PENDENTE |

### 4.5 Permissões, escopo e troca de organização

| | |
|---|---|
| Passos | Conta de 4.4: menu do usuário → «Organización» → trocar → conferir menus/restaurante; «Equipo» de uma org não mostra pessoas da outra; voltar. Cozinha digita `/app/staff` e `/platform/onboarding` na URL. Garçom digita `/app/menu`, `/app/settings`. |
| Esperado | Permissões só do vínculo ativo; rotas bloqueadas redirecionam; backend responde 403/404 (aba Rede). |
| Estado | PENDENTE |

### 4.6 Turnos (necessários para atribuição por QR)

| | |
|---|---|
| Papel | Dono ou Manager (`manage_staff_shifts`) |
| Caminho | «Equipo» → selecionar a pessoa → cartão de turno → «Iniciar turno» / «Terminar turno» |
| Pré | Pessoa ativa e vinculada ao restaurante atual. |
| Passos | Iniciar turno de Garçom A e Garçom B (e, se quiser, cozinha/caixa). Tentar iniciar de novo para o mesmo garçom (abrir duas abas). |
| Esperado | Badge "En turno · …"; segunda tentativa → "Esta persona ya tiene un turno activo en este restaurante." Texto de ajuda: mesas abertas por QR só são atribuídas a garçons em turno. |
| Por quê | O QR escolhe só entre garçons com turno ativo nesse restaurante; a lista «Asignar / reasignar camarero» também mostra só pessoal em turno ("No hay personal en turno para asignar"). Garçom abrindo a mesa ele mesmo **não** precisa de turno. |
| Limite conhecido | O próprio garçom não tem botão para iniciar o seu turno (o backend permitiria); quem inicia é Dono/Manager pelo «Equipo». |
| Estado | **PASSOU (parcial)** — usuário confirmou em 2026-10-25 o **início de turno**. Segunda tentativa (409), «Terminar turno» e texto de ajuda: PENDENTE. |

### 4.7 Recuperação de senha

| | |
|---|---|
| Papel | uma conta criada nesta rodada (ex.: Caixa) |
| Passos | `/login` → «¿Olvidaste tu contraseña?» → e-mail existente → mensagem genérica; repetir com e-mail inexistente → **mesma** mensagem. Link da captura → barra sem `#token` → senha curta/divergente → válida → «Volver a iniciar sesión». Reabrir o link. Senha antiga recusada, nova aceita. Outra aba já logada com essa conta: próxima ação pede login. |
| Variante convite | Conta existente com convite pendente esquece a senha: recuperar → login **não** dá acesso à organização convidada até aceitar o convite. |
| Esperado | Sem enumeração; uso único; não entra automaticamente; só as sessões dessa conta caem. |
| Evidência | Prints das duas mensagens; `select count(*) from password_reset_tokens` = 0 após o uso. |
| Estado | PENDENTE |

---

## Fase 5 — Abertura

### 5.1 Bloqueios antes de abrir

| | |
|---|---|
| Papéis | Garçom, cliente (QR) |
| Passos | Garçom → «Servicio» → mesa → «Abrir mesa». Cliente abre o QR de uma mesa. |
| Esperado | Banner "El día no está abierto" / "No se pueden abrir mesas ni tomar pedidos hasta que un responsable abra el día"; abrir mesa e pedir recusados; QR mostra carta só leitura + "Comprobar de nuevo". |
| Estado | PENDENTE |

### 5.2 Conferência de disponibilidade

| | |
|---|---|
| Papel | Dono/Manager |
| Caminho | «Cierre diario» → «Día operativo» |
| Esperado | Aviso "Revisa los productos agotados antes de abrir. No se restablecen automáticamente." Esgotados de antes continuam esgotados (abrir não mexe). |
| Estado | PENDENTE |

### 5.3 Dono/Manager abre o dia com o fundo escolhido por você

| | |
|---|---|
| Papel | Dono ou Manager |
| Caminho | «Cierre diario» → «Día operativo» → «Abrir el día» |
| Passos | Conferir «Fecha operativa» (ver 8.5) → «Fondo inicial de caja» = **valor decidido por você** (testar formato `100,00`/`100.00`, negativo/3 decimais recusados) → «Abrir día» com duplo clique. Segunda aba tenta abrir. Garçom/cozinha/caixa: sem formulário de abertura. |
| Esperado | Um único dia aberto ("Día abierto · {data}", "Abierto por …"); segunda aba "El día ya estaba abierto"; fundo não editável depois e não somado a vendas. |
| Evidência | Print; `select business_date, status, opening_float from restaurant_daily_operations where restaurant_id=…`. |
| Estado | PENDENTE — ver "Resultados registrados": a confirmação "abertura" de 2026-10-25 ainda não foi mapeada com certeza para este cenário. |

### 5.4 Liberação da operação

| | |
|---|---|
| Esperado | Banner some em «Servicio»/«Panel» **sem recarregar** (evento realtime); QR aberto em 5.1 passa a permitir pedido após «Comprobar de nuevo». |
| Estado | PENDENTE |

---

## Fase 6 — Operação

Pré geral: dia aberto, Garçons A e B em turno (4.6), cozinha logada em
«Cocina» noutro aparelho/janela, Panel/Serviço abertos.

### 6.1 Garçom abre mesa e fica atribuído

| | |
|---|---|
| Papel | Garçom A |
| Caminho | «Servicio» → «Mesas» → Mesa 1 → drawer → «Abrir mesa» |
| Esperado | "Mesa abierta correctamente."; responsável = Garçom A (mesmo se ele estiver fora de turno). Consultas/abertura do QR depois **não** trocam o responsável. |
| Estado | PENDENTE — ver "Resultados registrados" (atribuição à mesa confirmada em 2026-10-25; falta saber se foi por abertura do garçom, 6.1, ou por QR, 6.2). |

### 6.2 QR abre mesa e atribui o garçom elegível com menos demanda

| | |
|---|---|
| Papéis | cliente (QR), Garçons A/B |
| Passos | Com A responsável por 1 mesa e B por 0: cliente abre o QR da Mesa 2 (fechada) → boas-vindas. Abrir o mesmo QR noutro aparelho. Depois QR da Mesa 3. |
| Esperado | Mesa 2 → **Garçom B** (menor demanda); uma única sessão para os dois aparelhos. Empate de demanda → quem nunca foi atribuído, depois o atribuído há mais tempo, depois menor id. O escolhido recebe «Notificaciones» (sino) "Nueva mesa asignada" sem refresh; clicar abre a mesa certa. |
| Estado | PENDENTE — ver "Resultados registrados" (fluxo QR do último teste orientado confirmado em 2026-10-25; falta confirmar demanda/empate, dois aparelhos e sino). |

### 6.3 Mesa já atribuída preserva o responsável

| | |
|---|---|
| Passos | QR da Mesa 1 (aberta pelo Garçom A em 6.1); recarregar várias vezes. Terminar o turno de A (4.6) e abrir de novo o QR da Mesa 1. |
| Esperado | Responsável continua A nas duas situações (atribuição válida é preservada mesmo fora de turno); nenhuma notificação duplicada. |
| Estado | PENDENTE |

### 6.4 Sem garçom elegível → aviso persistente

| | |
|---|---|
| Passos | Terminar os turnos de A e B → cliente abre QR da Mesa 4 → Dono/Manager **desconectado** nesse momento → depois entra. |
| Esperado | Mesa abre "Pendiente de asignación" (cliente não é bloqueado); Dono/Manager encontra no sino o aviso «Pendiente de asignación» mesmo tendo entrado depois (persistente); um aviso por ocorrência. Reiniciar turnos de A e B ao final. |
| Estado | PENDENTE |

### 6.5 Reatribuição manual

| | |
|---|---|
| Papel | Dono/Manager (`assign_waiters`) |
| Caminho | drawer da mesa → «Asignar / reasignar camarero» → escolher; «Quitar asignación»; «Llamar al responsable» |
| Esperado | Lista só com pessoal em turno; novo responsável recebe aviso sem refresh; auditoria registrada. Garçom sem `assign_waiters` não vê o seletor. |
| Estado | PENDENTE |

### 6.6 Pedido QR, aprovação por clique normal e rejeição

| | |
|---|---|
| Pré | 3.2 (aprovação ligada). |
| Passos | Cliente pede "PRUEBA LOCAL — no preparar" pelo QR → Garçom vê em «Pedidos pendientes de aprobación» → «Ver pedido» → **clique normal** em «Aprobar» (uma vez). Outro pedido → «Rechazar» → «Sí, rechazar pedido». |
| Esperado | Exatamente um `POST /orders/{id}/approve → 200`; sai da fila e vai ao histórico como confirmado; rejeitado fora da conta e com status traduzido no QR. |
| Evidência | Aba Rede (status), print. |
| Estado | PENDENTE |

### 6.7 Cozinha: aceitar, preparar, pronto, servido

| | |
|---|---|
| Papéis | Cozinha, Garçom |
| Caminho | «Cocina» colunas «Nuevos» → «Aceptados» → «En preparación» → «Listos»; garçom: drawer → pedidos → «Marcar como servido» |
| Esperado | Pedido aprovado aparece em «Nuevos» **sem recarregar**; «Aceptar pedido» → «Iniciar preparación» → «Marcar listo»; garçom recebe "listo para servir"; servido refletido nos dois lados e no status público do QR. |
| Estado | PENDENTE |

### 6.8 Histórico, status público e conta

| | |
|---|---|
| Passos | QR → histórico da visita e status; staff → drawer → histórico paginado, «Ver cuenta». |
| Esperado | Estados traduzidos; valores em formato `es-ES` (`2,50 €`); conta = soma dos itens não rejeitados. |
| Estado | PENDENTE |

### 6.9 Produto esgotado e carrinho antigo

| | |
|---|---|
| Papéis | Garçom, cliente |
| Caminho | «Servicio» → «Disponibilidad de productos» → «Marcar agotado» / «Volver a disponer» |
| Passos | Cliente com o produto extra (3.4) no carrinho; garçom marca agotado; cliente tenta enviar o carrinho antigo; restaurar. Cozinha/Caixa: painel ausente. |
| Esperado | QR atualiza e/ou recusa com "Algún producto ya no está disponible. Revisa tu pedido."; carrinho intacto para editar; outro restaurante não afetado. |
| Estado | PENDENTE |

### 6.10 Transferência e isolamento entre visitas

| | |
|---|---|
| Passos | Drawer → «Transferir mesa» → mesa livre de destino. Reabrir o QR **original** e o da mesa de destino. |
| Esperado | Histórico e responsável seguem a sessão transferida; QR original não enxerga a visita transferida; nova visita na mesa original não herda pedidos nem responsável anteriores. |
| Estado | PENDENTE |

---

## Fase 7 — Resiliência e segurança

Parar/religar **por PID** (`docker exec restaurants-api-laravel.test-1 ps -eo pid,user,args`, depois `kill <PID>`). Nunca `pkill` genérico nem `queue:restart`.

### 7.1 Reverb parado

| | |
|---|---|
| Passos | Com «Servicio» e «Cocina» abertos: parar o `reverb:start` → aguardar ~10 s → criar pedido/abrir mesa → religar o Reverb (comando em "Como começar"). |
| Esperado | Indicador de conexão perdida/indisponível em até ~10 s; dados chegam por **polling a cada ~15 s**; ao religar, reconecta sozinho e reconcilia (uma leitura), sem itens duplicados. |
| Estado | PENDENTE |

### 7.2 Worker `realtime-local` parado (Reverb no ar)

| | |
|---|---|
| Passos | Parar só o worker `queue=realtime-local` → criar pedido / aprovar → observar 60–70 s → `select count(*) from jobs where queue='realtime-local';` → religar o worker. |
| Esperado | WebSocket **continua conectado** (não exigir indicador "desconectado"); eventos ficam pendentes na fila (contagem > 0); a tela se atualiza pelo reconcile de segurança em **até ~60 s**; ao religar, a fila volta a 0 e os eventos atrasados chegam sem duplicar itens. `default` intocada. |
| Estado | PENDENTE |

### 7.3 Isolamento entre organizações e permissões

| | |
|---|---|
| Passos | Usuário tenant chama `/api/v1/platform/restaurants` (console/Rede) → 403; staff de outra org em `/staff/invitations/{id}/revoke` → 404; convite de outro e-mail com sessão diferente → "otra cuenta"; token de reset de outra conta → inválido; organização suspensa pela plataforma → aceite recusado (se houver interface para suspender; senão `BLOQUEADO`). Garçom de outro restaurante não aparece como candidato nem recebe avisos daqui. |
| Esperado | Backend recusa; UI não oferece ações proibidas. |
| Estado | PENDENTE |

---

## Fase 8 — Encerramento

### 8.1 Pagamentos parciais e totais

| | |
|---|---|
| Papel | Garçom ou Caixa |
| Caminho | drawer → «Ver cuenta» → «Registrar pago» («Efectivo»/«Tarjeta»/«Otro») → «Confirmar pago» |
| Esperado | Parcial reduz o saldo; total zera; «Pagos registrados» lista os dois. |
| Estado | PENDENTE |

### 8.2 Encerrar mesa

| | |
|---|---|
| Esperado | «Cerrar mesa» bloqueado com aviso enquanto houver pedido não servido/saldo; depois "Mesa cerrada correctamente."; sessão `closed`. |
| Estado | PENDENTE |

### 8.3 Pós-visita

| | |
|---|---|
| Esperado | QR antigo mostra pós-visita (feedback opcional; link de Google se configurado); não opera a sessão encerrada; novo scan abre visita nova. |
| Estado | PENDENTE |

### 8.4 Fechamento diário e métricas

| | |
|---|---|
| Papel | Dono/Manager (ou Garçom/Caixa: só hoje) |
| Caminho | «Cierre diario» |
| Passos | Tentar fechar com mesa aberta → bloqueado com a mesa listada → resolver → efetivo contado → fechar. «Panel» depois. |
| Esperado | Cierre gravado; «Cobrado · último día cerrado» com data/valor da operação; fundo inicial não somado às vendas; QR/staff recusam pedidos novos. |
| Estado | PENDENTE |

### 8.5 Próxima operação com recebido zerado (depende da data)

| | |
|---|---|
| Regra (código) | `OpenDailyOperationAction`: data operativa = data local do restaurante (Europe/Madrid), recuada um dia antes da hora de corte **06:00**. Só abre se essa data for **posterior** ao último cierre e ainda não tiver operação. Senão: "Esta fecha operativa ya está cerrada. Podrás abrir el siguiente día después de la hora de corte." |
| Na prática | Abrindo hoje (2026-10-23) depois das 06:00, a data é 2026-10-23; após o cierre, a próxima abertura só é possível a partir de **2026-10-24 06:00 (Madrid)**. Abrindo entre 00:00 e 05:59, a data operativa é a do dia anterior. |
| Passos | Hoje, após o cierre: tentar abrir → mensagem acima (resultado esperado). No dia seguinte após 06:00: abrir com fundo decidido por você → «Panel». |
| Esperado | Recebido começa em 0; Cierre anterior intacto no historial. |
| Não fazer | Alterar relógio, datas no banco ou a hora de corte para simular. Simulação só existe nos testes isolados. |
| Estado | PENDENTE (parte "dia seguinte" só executável a partir de 2026-10-24 06:00) |

---

## Transversal — idiomas, temas e dispositivos

| | |
|---|---|
| Passos | Repetir telas tocadas (aceite, recuperação, «Equipo» com turno, Plataforma, «Configuración», «Carta», «Mesas», «Servicio», «Cocina», «Cierre diario», QR) em 375 / 768 / 1440, claro/escuro, es-ES / ca-ES-valencia / en-GB. QR no celular exige `SAIL_BIND_ADDRESS` e host acessível na LAN — só se você quiser. |
| Esperado | Sem rolagem horizontal, alvos ≥ 44 px, foco visível, textos traduzidos (incluindo erros de turno). |
| Estado | PENDENTE |

## Fechamento da rodada

- Backlog da `default` igual ao de 1.4; `failed_jobs` sem novas falhas (ou anotadas).
- `grep -c 'localhost:5174/invitation#\|reset-password#' storage/logs/laravel.log` não aumentou.
- Decisão sobre a sessão legada **#490** (AFORO Malvarrosa, Mesa 11, `occupied/unpaid`, 5 pedidos, 0 pagamentos) registrada por você — nada nesta lista a altera.
- Restaurantes AFORO Malvarrosa/Ruzafa não usados para pagar/encerrar.

---

## Como começar (passos exatos)

Na pasta `restaurants-api`, salvo indicação.

1. **Docker**: abrir o Docker Desktop (integração WSL ativa) e conferir `docker ps`. Depois `docker compose up -d` — `artisan serve` sobe pelo supervisor do container.
2. **Conferir antes de iniciar** (não duplicar):
   `docker exec restaurants-api-laravel.test-1 ps -eo pid,user,args | grep -E 'reverb:start|queue:work' | grep -v grep`
3. **Reverb** (se ausente):
   `docker exec -d -u sail restaurants-api-laravel.test-1 php artisan reverb:start`
4. **Worker realtime** (se ausente):
   `docker exec -d -u sail restaurants-api-laravel.test-1 php artisan queue:work database --queue=realtime-local --name=aforo-realtime-local --sleep=1 --tries=3 --timeout=60`
5. **Worker e-mail** (se ausente):
   `docker exec -d -u sail restaurants-api-laravel.test-1 php artisan queue:work database --queue=mail-local --name=aforo-mail-local --tries=3 --backoff=30 --timeout=30 --sleep=3`
   Se um worker `sail` falhar por permissão em `storage/` (pastas antigas `root:root`), anotar e me avisar — não usar `chmod -R` às cegas.
6. **Frontend**: em `restaurants-web`, `npm run dev -- --port 5174 --strictPort` e abrir `http://localhost:5174/login`. Registrar a linha de base (1.4).
7. **Super Admin**: entrar com a sua credencial de plataforma → «Alta de restaurante» (2.1).
8. **Criar o restaurante de teste** com o seu e-mail de dono novo.
9. **Aceitar o convite como dono**: link pela captura local (2.2) numa janela sem sessão (2.3).
10. **Continuar pela interface**: fase 3 (Configuración → Carta → Mesas), fase 4 (Equipo, convites, turnos), e só então a fase 5 (abrir o dia com o fundo que você escolher).
