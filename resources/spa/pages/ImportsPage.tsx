import { Eye, FileSpreadsheet } from "lucide-react";
import { useCallback, useState } from "react";
import {
  Alert,
  Button,
  EmptyState,
  ErrorState,
  Field,
  Input,
  PageHeader,
  Spinner,
  useSession,
} from "@starterkit/module-kit";
import {
  Badge,
  Pagination,
  Select,
  SimpleTooltip,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  TableWrapper,
  can,
  useAsync,
  type PaginationMeta,
} from "../support";
import {
  importService,
  type ImportBatch,
  type ImportRow,
} from "../services/importService";

const batchStatusLabels: Record<string, string> = {
  NEEDS_REVIEW: "Em revisão",
  READY: "Pronto",
  IMPORTED: "Importado",
};

type Mapping = {
  itemId: string;
  variantId: string;
  type: "ENTRY" | "ISSUE";
  action: "MAPPED" | "SKIPPED";
  reason: string;
};

export default function ImportsPage() {
  const { state } = useSession();
  const allowed = can(state.user, "inventory.imports.execute");
  const loader = useCallback(
    (signal: AbortSignal) => importService.list(signal),
    [],
  );
  const { data, loading, error, reload } = useAsync(loader);
  const [file, setFile] = useState<File | null>(null);
  const [batch, setBatch] = useState<ImportBatch | null>(null);
  const [rows, setRows] = useState<ImportRow[]>([]);
  const [page, setPage] = useState(1);
  const [rowsMeta, setRowsMeta] = useState<PaginationMeta | null>(null);
  const [mappings, setMappings] = useState<Record<number, Mapping>>({});
  const [message, setMessage] = useState("");
  const [messageVariant, setMessageVariant] = useState<"success" | "danger">(
    "danger",
  );
  const [busy, setBusy] = useState(false);

  function notify(text: string, variant: "success" | "danger" = "danger") {
    setMessage(text);
    setMessageVariant(variant);
  }

  async function analyze(event: React.FormEvent) {
    event.preventDefault();
    if (!file) return;
    setBusy(true);
    setMessage("");
    try {
      const result = await importService.analyze(file);
      setBatch(result);
      await loadBatch(result.id);
      reload();
    } catch (caught) {
      notify(
        caught instanceof Error
          ? caught.message
          : "Falha ao analisar a planilha.",
      );
    } finally {
      setBusy(false);
    }
  }

  async function loadBatch(id: number, selectedPage = 1) {
    const result = await importService.detail(id, selectedPage);
    setBatch(result.data);
    setRows(result.rows.data);
    setPage(result.rows.meta.currentPage);
    setRowsMeta(result.rows.meta);
    setMappings(
      Object.fromEntries(
        result.rows.data.map((row) => [
          row.id,
          {
            itemId: String(row.corrected_payload?.itemId ?? ""),
            variantId: String(row.corrected_payload?.variantId ?? ""),
            action: row.corrected_payload?._skipReason ? "SKIPPED" : "MAPPED",
            reason: String(row.corrected_payload?._skipReason ?? ""),
            type: (row.corrected_payload?.type === "ISSUE"
              ? "ISSUE"
              : "ENTRY") as "ENTRY" | "ISSUE",
          },
        ]),
      ),
    );
  }

  function updateMapping(row: ImportRow, patch: Partial<Mapping>) {
    setMappings((current) => ({
      ...current,
      [row.id]: {
        itemId: current[row.id]?.itemId ?? "",
        variantId: current[row.id]?.variantId ?? "",
        type: current[row.id]?.type ?? "ENTRY",
        action: current[row.id]?.action ?? "MAPPED",
        reason: current[row.id]?.reason ?? "",
        ...patch,
      },
    }));
  }

  async function saveResolutions() {
    if (!batch) return;
    setBusy(true);
    setMessage("");
    try {
      await importService.resolve(
        batch.id,
        batch.analysis_version,
        rows.map((row) => {
          const mapping = mappings[row.id];
          if (mapping?.action === "SKIPPED") {
            return {
              id: row.id,
              action: "SKIPPED" as const,
              reason: mapping.reason,
            };
          }

          return {
            id: row.id,
            action: "MAPPED" as const,
            itemId: Number(mapping?.itemId),
            variantId: Number(mapping?.variantId),
            type: mapping?.type ?? "ENTRY",
          };
        }),
      );
      await loadBatch(batch.id, page);
    } catch (caught) {
      notify(
        caught instanceof Error
          ? caught.message
          : "Não foi possível salvar o saneamento.",
      );
    } finally {
      setBusy(false);
    }
  }

  async function commit() {
    if (
      !batch ||
      !window.confirm(
        "Confirmar a importação? Isso cria movimentos e altera os saldos.",
      )
    ) {
      return;
    }
    setBusy(true);
    setMessage("");
    try {
      await importService.commit(batch);
      notify("Lote importado.", "success");
      await loadBatch(batch.id);
      reload();
    } catch (caught) {
      notify(
        caught instanceof Error ? caught.message : "Falha ao importar o lote.",
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Importação legada"
        description="Analise e revise uma cópia privada antes de qualquer alteração nos saldos."
        breadcrumbs={[
          { label: "Almoxarifado", to: "/admin/inventory" },
          { label: "Importação" },
        ]}
      />
      <p className="max-w-3xl text-body-sm text-ink-secondary">
        A pasta Controle de Estoque v1.2 é analisada pelas abas DADOS e
        LANÇAMENTOS. Fórmulas derivadas são ignoradas; para quantidade com
        fórmula, somente o valor armazenado é oferecido para revisão. Linha sem
        quantidade precisa ser descartada com justificativa explícita.
      </p>
      {message ? <Alert variant={messageVariant}>{message}</Alert> : null}
      {allowed ? (
        <form
          className="flex max-w-xl items-end gap-3"
          onSubmit={(event) => void analyze(event)}
        >
          <Field id="import-file" label="Arquivo XLSX" className="flex-1">
            <Input
              className="h-auto py-2 file:mr-3 file:rounded-sm file:border-0 file:bg-subtle file:px-2 file:py-1 file:text-label"
              type="file"
              accept=".xlsx"
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            />
          </Field>
          <Button type="submit" loading={busy} disabled={!file}>
            Enviar e analisar
          </Button>
        </form>
      ) : null}
      {loading && !data ? (
        <div className="flex justify-center p-10">
          <Spinner label="Carregando lotes" />
        </div>
      ) : error ? (
        <ErrorState requestId={error.requestId} onRetry={reload} />
      ) : data && data.data.data.length === 0 ? (
        <EmptyState
          icon={FileSpreadsheet}
          title="Nenhum lote"
          description="Ainda não há lotes de importação."
        />
      ) : data ? (
        <TableWrapper>
          <Table>
            <caption className="sr-only">Lotes de importação</caption>
            <TableHeader>
              <TableRow>
                <TableHead>Lote</TableHead>
                <TableHead>Arquivo</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="text-right">Ações</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.data.data.map((entry) => (
                <TableRow key={entry.id}>
                  <TableCell className="font-medium">#{entry.id}</TableCell>
                  <TableCell className="text-ink-secondary">
                    {entry.original_name}
                  </TableCell>
                  <TableCell>
                    <Badge variant="neutral">
                      {batchStatusLabels[entry.status] ?? entry.status}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    <SimpleTooltip label="Abrir prévia">
                      <Button
                        type="button"
                        variant="ghost"
                        size="iconCompact"
                        aria-label={`Abrir prévia do lote ${entry.id}`}
                        onClick={() => void loadBatch(entry.id)}
                      >
                        <Eye className="size-4" aria-hidden="true" />
                      </Button>
                    </SimpleTooltip>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableWrapper>
      ) : null}
      {batch ? (
        <section className="space-y-4">
          <h2 className="text-h3">
            Prévia do lote #{batch.id} ·{" "}
            {batchStatusLabels[batch.status] ?? batch.status}
          </h2>
          {rows.length === 0 ? (
            <EmptyState
              icon={FileSpreadsheet}
              title="Nenhuma linha"
              description="Não há linhas nesta prévia."
            />
          ) : (
            <TableWrapper>
              <Table>
                <caption className="sr-only">
                  Prévia das linhas do lote #{batch.id}
                </caption>
                <TableHeader>
                  <TableRow>
                    <TableHead>Aba / linha</TableHead>
                    <TableHead>Dados fonte</TableHead>
                    <TableHead>Item</TableHead>
                    <TableHead>Variante</TableHead>
                    <TableHead>Tipo</TableHead>
                    <TableHead>Decisão</TableHead>
                    <TableHead>Erros</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((row) => {
                    const mapping = mappings[row.id];
                    const skipped = mapping?.action === "SKIPPED";

                    return (
                      <TableRow key={row.id} className="h-auto">
                        <TableCell className="whitespace-nowrap py-3 font-medium">
                          {row.sheet_name} / {row.row_number}
                        </TableCell>
                        <TableCell className="py-3 text-ink-secondary">
                          <code className="block max-w-md min-w-64 font-mono text-caption break-words whitespace-pre-wrap">
                            {JSON.stringify(
                              row.corrected_payload ?? row.source_payload,
                            )}
                          </code>
                        </TableCell>
                        <TableCell className="py-3">
                          <Input
                            className="min-w-24"
                            aria-label={`Item da linha ${row.row_number}`}
                            inputMode="numeric"
                            value={mapping?.itemId ?? ""}
                            disabled={skipped}
                            onChange={(event) =>
                              updateMapping(row, { itemId: event.target.value })
                            }
                          />
                        </TableCell>
                        <TableCell className="py-3">
                          <Input
                            className="min-w-24"
                            aria-label={`Variante da linha ${row.row_number}`}
                            inputMode="numeric"
                            value={mapping?.variantId ?? ""}
                            disabled={skipped}
                            onChange={(event) =>
                              updateMapping(row, {
                                variantId: event.target.value,
                              })
                            }
                          />
                        </TableCell>
                        <TableCell className="py-3">
                          <Select
                            className="min-w-32"
                            aria-label={`Tipo da linha ${row.row_number}`}
                            value={mapping?.type ?? "ENTRY"}
                            disabled={skipped}
                            onChange={(event) =>
                              updateMapping(row, {
                                type: event.target.value as "ENTRY" | "ISSUE",
                              })
                            }
                          >
                            <option value="ENTRY">Entrada</option>
                            <option value="ISSUE">Saída</option>
                          </Select>
                        </TableCell>
                        <TableCell className="py-3">
                          <Select
                            className="min-w-36"
                            aria-label={`Decisão da linha ${row.row_number}`}
                            value={mapping?.action ?? "MAPPED"}
                            onChange={(event) =>
                              updateMapping(row, {
                                action: event.target.value as
                                  "MAPPED" | "SKIPPED",
                              })
                            }
                          >
                            <option value="MAPPED">Importar</option>
                            <option value="SKIPPED">Descartar</option>
                          </Select>
                          {skipped ? (
                            <Input
                              className="mt-2 min-w-48"
                              aria-label={`Justificativa para descartar linha ${row.row_number}`}
                              value={mapping?.reason ?? ""}
                              onChange={(event) =>
                                updateMapping(row, {
                                  action: "SKIPPED",
                                  reason: event.target.value,
                                })
                              }
                            />
                          ) : null}
                        </TableCell>
                        <TableCell className="py-3 text-ink-secondary">
                          {row.errors?.join("; ") || "—"}
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableWrapper>
          )}
          {rowsMeta ? (
            <Pagination
              meta={rowsMeta}
              onPageChange={(nextPage) => void loadBatch(batch.id, nextPage)}
            />
          ) : null}
          {allowed && batch.status !== "IMPORTED" ? (
            <Button
              variant="secondary"
              loading={busy}
              onClick={() => void saveResolutions()}
            >
              Salvar mapeamento desta página
            </Button>
          ) : null}
          {allowed && batch.status === "READY" ? (
            <Button loading={busy} onClick={() => void commit()}>
              Confirmar importação
            </Button>
          ) : null}
        </section>
      ) : null}
    </div>
  );
}
