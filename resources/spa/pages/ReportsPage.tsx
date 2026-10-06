import { FileText } from "lucide-react";
import { useCallback, useMemo, useState } from "react";
import { useSearchParams } from "react-router";
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
  Pagination,
  Select,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  TableWrapper,
  can,
  useAsync,
} from "../support";
import {
  labeled,
  movementTypeLabels,
  situationLabels,
  unitLabels,
} from "../labels";
import { reportsService, type ReportType } from "../services/reportsService";

const titles: Record<ReportType, string> = {
  stock: "Posição de estoque",
  replenishment: "Reposição",
  consumption: "Consumo",
  adjustments: "Ajustes e estornos",
};

const columnLabels: Record<string, string> = {
  itemId: "Item",
  code: "Código",
  item: "Item",
  category: "Categoria",
  unit: "Unidade",
  variantId: "Variante",
  brand: "Marca",
  model: "Modelo",
  variant: "Descrição",
  variantStock: "Saldo da variante",
  aggregateStock: "Saldo agregado",
  minimumStock: "Mínimo",
  situation: "Situação",
  knownEntryCostBRL: "Custo conhecido (R$)",
  unknownCostLines: "Linhas sem custo",
  unknownCostUnits: "Unidades sem custo",
  stock: "Saldo",
  periodFrom: "De",
  periodTo: "Até",
  consumption: "Consumo",
  purchaseLeadTimeDays: "Prazo (dias)",
  safetyStock: "Segurança",
  suggestedMinimumStock: "Mínimo sugerido",
  recommendationStatus: "Recomendação",
  groupBy: "Agrupamento",
  groupId: "Grupo",
  group: "Grupo",
  grossIssues: "Saídas brutas",
  reversedIssues: "Estornos",
  netConsumption: "Consumo líquido",
  date: "Data",
  movementId: "Movimento",
  type: "Tipo",
  originalMovementId: "Movimento original",
  delta: "Delta",
  reason: "Motivo",
  actorId: "Responsável",
};

const recommendationLabels: Record<string, string> = {
  CALCULABLE: "Calculável",
  NOT_CALCULABLE: "Não calculável",
  INSUFFICIENT_HISTORY: "Histórico insuficiente",
};

const groupLabels: Record<string, string> = {
  item: "Item",
  category: "Categoria",
  serviceOrderNumber: "Ordem de serviço",
  month: "Mês",
};

export default function ReportsPage() {
  const { state } = useSession();
  const canExport = can(state.user, "inventory.reports.export");
  const [params, setParams] = useSearchParams();
  const [type, setType] = useState<ReportType>("stock");
  const [exporting, setExporting] = useState(false);
  const [message, setMessage] = useState("");
  const from = params.get("from") ?? "";
  const to = params.get("to") ?? "";
  const groupBy = params.get("groupBy") ?? "item";
  const itemId = params.get("itemId") ?? "";
  const categoryId = params.get("categoryId") ?? "";
  const page = Math.max(1, Number(params.get("page") ?? 1) || 1);
  const filters = useMemo(
    () => ({
      from,
      to,
      ...(type === "consumption" ? { groupBy } : {}),
      itemId,
      categoryId,
      page,
      perPage: 20,
    }),
    [type, from, to, groupBy, itemId, categoryId, page],
  );
  const loader = useCallback(
    (signal: AbortSignal) => reportsService.report(type, filters, signal),
    [type, filters],
  );
  const { data, loading, error, reload } = useAsync(loader);
  const headers = data?.data[0] ? Object.keys(data.data[0]) : [];
  const filtered =
    from !== "" || to !== "" || itemId !== "" || categoryId !== "";

  function setFilter(key: string, value: string) {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    next.delete("page");
    setParams(next, { replace: true });
  }

  async function download(format: "csv" | "xlsx") {
    setExporting(true);
    setMessage("");
    try {
      await reportsService.export(type, filters, format);
    } catch (caught) {
      setMessage(
        caught instanceof Error
          ? caught.message
          : "Falha ao exportar relatório.",
      );
    } finally {
      setExporting(false);
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Relatórios do almoxarifado"
        description="Consulte e exporte relatórios com filtros aplicados."
        breadcrumbs={[
          { label: "Almoxarifado", to: "/admin/inventory" },
          { label: "Relatórios" },
        ]}
        actions={
          canExport ? (
            <div className="flex flex-wrap gap-2">
              <Button
                variant="secondary"
                loading={exporting}
                onClick={() => void download("csv")}
              >
                Exportar CSV
              </Button>
              <Button
                variant="secondary"
                loading={exporting}
                onClick={() => void download("xlsx")}
              >
                Exportar XLSX
              </Button>
            </div>
          ) : null
        }
      />
      <div className="flex flex-wrap items-end gap-4">
        <Field id="report-type" label="Relatório" className="w-full max-w-xs">
          <Select
            value={type}
            onChange={(event) => setType(event.target.value as ReportType)}
          >
            {Object.entries(titles).map(([value, label]) => (
              <option value={value} key={value}>
                {label}
              </option>
            ))}
          </Select>
        </Field>
        {type === "consumption" || type === "adjustments" ? (
          <>
            <Field id="report-from" label="De" className="w-44">
              <Input
                type="date"
                value={from}
                onChange={(event) => setFilter("from", event.target.value)}
              />
            </Field>
            <Field id="report-to" label="Até" className="w-44">
              <Input
                type="date"
                value={to}
                onChange={(event) => setFilter("to", event.target.value)}
              />
            </Field>
          </>
        ) : null}
        {type === "consumption" ? (
          <Field
            id="report-group"
            label="Agrupar por"
            className="w-full max-w-xs"
          >
            <Select
              value={groupBy}
              onChange={(event) => setFilter("groupBy", event.target.value)}
            >
              <option value="item">Item</option>
              <option value="category">Categoria</option>
              <option value="serviceOrderNumber">Ordem de serviço</option>
              <option value="month">Mês</option>
            </Select>
          </Field>
        ) : null}
        <Field id="report-item" label="ID do item" className="w-36">
          <Input
            type="number"
            min="1"
            value={itemId}
            onChange={(event) => setFilter("itemId", event.target.value)}
          />
        </Field>
        <Field id="report-category" label="ID da categoria" className="w-40">
          <Input
            type="number"
            min="1"
            value={categoryId}
            onChange={(event) => setFilter("categoryId", event.target.value)}
          />
        </Field>
      </div>
      {type === "stock" ? (
        <p className="text-body-sm text-ink-secondary">
          Custo histórico conhecido de entradas; não representa avaliação
          financeira do saldo. Quantidades permanecem separadas por unidade.
        </p>
      ) : null}
      {message ? <Alert variant="danger">{message}</Alert> : null}
      {loading && !data ? (
        <div className="flex justify-center p-10">
          <Spinner label="Carregando relatório" />
        </div>
      ) : error ? (
        <ErrorState requestId={error.requestId} onRetry={reload} />
      ) : data && data.data.length === 0 ? (
        <EmptyState
          icon={FileText}
          title="Nenhum resultado"
          description="Nenhum resultado para os filtros selecionados."
          action={
            filtered ? (
              <Button
                variant="secondary"
                onClick={() =>
                  setParams(new URLSearchParams(), { replace: true })
                }
              >
                Limpar filtros
              </Button>
            ) : null
          }
        />
      ) : data ? (
        <div className="space-y-4">
          <p className="text-body-sm text-ink-secondary">
            {data.meta.total} linhas · referência {data.asOf || "—"}
          </p>
          <TableWrapper>
            <Table>
              <caption className="sr-only">{titles[type]}</caption>
              <TableHeader>
                <TableRow>
                  {headers.map((header) => (
                    <TableHead key={header}>
                      {columnLabels[header] ?? header}
                    </TableHead>
                  ))}
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.data.map((row, index) => (
                  <TableRow key={index} className="h-auto">
                    {headers.map((header) => (
                      <TableCell key={header} className="py-3">
                        {formatCell(header, row[header])}
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableWrapper>
          <Pagination
            meta={data.meta}
            onPageChange={(nextPage) => {
              const next = new URLSearchParams(params);
              next.set("page", String(nextPage));
              setParams(next);
            }}
          />
        </div>
      ) : null}
    </div>
  );
}

function formatCell(key: string, value: unknown): string {
  if (value === null || value === undefined || value === "") return "—";
  const text =
    typeof value === "object" ? JSON.stringify(value) : String(value);
  if (key === "situation") return labeled(situationLabels, text);
  if (key === "unit") return labeled(unitLabels, text);
  if (key === "type") return labeled(movementTypeLabels, text);
  if (key === "recommendationStatus")
    return labeled(recommendationLabels, text);
  if (key === "groupBy") return labeled(groupLabels, text);
  return text;
}
