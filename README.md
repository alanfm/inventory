# `acme/inventory`

Módulo de Almoxarifado de TI para o Starterkit: catálogo, variantes, saldos, livro, ajustes, estornos, relatórios e importação assistida. Autenticação, usuários, permissões, registro de módulos e shell são fornecidos pelo host.

## Compatibilidade

- PHP `~8.5.0`, Laravel `^13.0`, Core Starterkit `^1.1.0` e MariaDB/InnoDB.
- PhpSpreadsheet `^5.2`, instalado pelo Composer. O PHP precisa das extensões exigidas por esse pacote, incluindo GD e ZIP. O Composer deve validar esses requisitos sem `--ignore-platform-reqs`.
- Dependências frontend compartilhadas pelo host: React, React Router, Lucide, Radix Dialog/Tooltip, clsx, class-variance-authority e tailwind-merge. Não instalar outra cópia de React.

O frontend importa do core somente a superfície pública disponível em `@starterkit/module-kit`. Os componentes adicionais, tipos, controle visual de permissões, hook assíncrono e transporte HTTP de `/api/v1/inventory/*` pertencem ao pacote, em `resources/spa/support`. Não é necessário modificar os exports ou copiar arquivos internos do host. O transporte mantém sessão same-origin, CSRF, envelope de erros, FormData, downloads e retry único de escrita somente com `Idempotency-Key`.

## Distribuição para o instalador

O repositório GitHub informado ao painel precisa conter diretamente:

```text
composer.json
module.json
README.md
src/
routes/
database/
resources/spa/module.ts
resources/spa/support/
```

Este diretório é a raiz do pacote. O repositório da aplicação `estoque`, que contém o pacote em `modules/acme/inventory`, não deve ser informado ao instalador.

No checkout da aplicação, gere uma distribuição com:

```sh
./vendor/bin/sail node scripts/export-inventory.mjs
```

O resultado fica em `storage/app/inventory-distribution/inventory-1.0.1`, acompanhado de `inventory-1.0.1.tar.gz` com os manifestos na raiz do arquivo. O script não sobrescreve destinos existentes e rejeita links simbólicos. Publique o conteúdo dessa pasta na raiz de um repositório GitHub dedicado. Não publique `.env`, o host, `vendor`, `node_modules`, planilhas ou dados operacionais. A exportação não cria nem publica um repositório remoto.

## Instalação pelo painel

Em um Starterkit com gerenciamento habilitado, abra **Configurações → Módulos → Adicionar módulo** e informe o link HTTPS do repositório dedicado. O core valida os manifestos, instala o pacote em `modules/acme/inventory` e o deixa desabilitado. Clique **Habilitar** para validar providers, executar migrations, sincronizar permissões e recompilar a SPA. Recarregue a página para atualizar menus e atribua as permissões do inventário aos papéis desejados.

A migration de inicialização cria o local TI; não é necessário executar `inventory:install` nem importar uma planilha para começar. Nenhum saldo físico é inventado. Desabilitar ou remover o pacote preserva tabelas, dados e vínculos de permissão.

## Instalação manual no host

Copie a raiz deste pacote para `modules/acme/inventory` no host de destino, mantendo o repository Composer path `modules/*/*`. Execute no host:

```sh
./vendor/bin/sail composer require acme/inventory:^1.0
./vendor/bin/sail artisan core:modules:validate inventory
./vendor/bin/sail artisan core:modules:enable inventory
./vendor/bin/sail artisan migrate --force
./vendor/bin/sail artisan core:sync-permissions
./vendor/bin/sail npm run build
./vendor/bin/sail artisan core:modules:diagnose --json
```

O gerenciamento pelo painel requer Git, Composer, PHP, Node/npm e acesso aos registries no ambiente mutável. A imagem upstream de produção não oferece esse ambiente e pode precisar de GD para resolver PhpSpreadsheet: instale a extensão na etapa Composer e no runtime PHP antes de gerar a imagem. Mantenha o painel de mutações desabilitado em produção imutável e publique uma nova imagem com o pacote, estado e assets. Não altere um checkout usado como referência técnica somente leitura.

## Operação técnica

- API: `/api/v1/inventory`; interface: `/admin/inventory`.
- Reconciliação somente leitura: `./vendor/bin/sail artisan inventory:reconcile --json`.
- Benchmark sintético, somente em `local`/`testing`: `./vendor/bin/sail artisan inventory:benchmark --iterations=5 --lines=100 --json`.
- Testes PHP do pacote estendem `Tests\TestCase` do host; inclua `tests/` deste pacote na suíte do host e mapeie `Acme\Inventory\Tests\` no autoload-dev para executá-los. Testes frontend dependem do ambiente Vitest/jsdom do host.

As escritas operacionais protegidas exigem `Idempotency-Key`; PATCH de cadastros/rascunhos exige `version`. Valores monetários e IDs são strings na API.

## Limites de entrega

Compatibilidade de instalação não equivale a homologação operacional. Concorrência real, retroatividade, implantação e carga autorizada continuam sujeitos aos gates registrados em `docs/plans/inventory-status.md` na aplicação de origem. A implantação inicial não importará a planilha: categorias, códigos, unidades e variantes serão cadastrados manualmente. Não executar importação real com este README como autorização.
