# Changelog

## 1.0.1 — 2026-10-06

- Corrigido o ícone do submenu Movimentações: `Package` substitui `ArrowLeftRight`, ausente na lista pública do Starterkit.
- Instalações existentes precisam atualizar o módulo e recompilar a SPA.

## 1.0.0 — 2026-09-30

- Entrega inicial do módulo de Almoxarifado de TI.
- Catálogo de categorias, itens e variantes com permissões e versão otimista.
- Livro, saldos, idempotência, ajustes, estornos, reposição, relatórios e exportações.
- Importação assistida versionada, prévia, saneamento e commit idempotente.
- Benchmark sintético transacional e documentação de implantação/backup.

### Limitações conhecidas

- Homologação operacional da CTI ainda não executada.
- Dados reais da planilha não foram importados.
- Concorrência com processos/conexões reais e retroatividade permanecem pendentes.
