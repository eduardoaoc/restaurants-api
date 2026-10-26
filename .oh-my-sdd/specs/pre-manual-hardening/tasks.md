# Tasks: pre-manual-hardening

- [x] 1. Reconstruir estado (git, processos, filas, banco habitual em leitura).
- [x] 2. Corrigir emissão de token de convite (por envio, job criptografado, fila de e-mail).
- [x] 3. Recuperação de senha (backend + frontend + i18n).
- [x] 4. Caixa local de e-mail (`mail_capture`), runtime de testes reproduzível (`scripts/test-sail.sh`), guarda de banco no `TestCase`.
- [x] 5. OpenAPI (esquemas/endpoints de convites, recuperação, organização ativa) regenerado.
- [x] 6. Suíte backend completa + frontend (ver relatório).
- [x] 7. Backup + migrations + provisioning + `.env` + worker de e-mail no ambiente local.
- [x] 8. Checklist manual único (`docs/manual-checklist-local.md`).
- [x] 9. Relatório final.

## Continuação 2026-10-23 (preparação do roteiro do zero)

- [x] 10. Recuperar o ponto de parada da execução interrompida (2026-10-22 20:12–20:17, só frontend).
- [x] 11. Revisar/concluir turnos na interface (Equipo → Iniciar/Terminar turno) — dependência real da atribuição por QR.
- [x] 12. Revisar/concluir reconcile de segurança de 60 s com socket conectado (worker realtime parado).
- [x] 13. Conferir atribuição automática (sem divergência; nada alterado no backend).
- [x] 14. Documentar a regra da próxima data operacional.
- [x] 15. Reescrever `docs/manual-checklist-local.md` por dependência (8 fases).
- [ ] 16. Testes de navegador do frontend — **bloqueado** no ambiente (ver relatório §13).
