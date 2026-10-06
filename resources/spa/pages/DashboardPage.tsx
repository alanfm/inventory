import { useCallback } from "react";
import { useSearchParams, Link } from "react-router";
import {
  Activity,
  ArrowDownToLine,
  ArrowUpFromLine,
  Boxes,
  CircleAlert,
  RefreshCw,
  ShieldCheck,
} from "lucide-react";
import {
  Button,
  Card,
  ErrorState,
  Field,
  Input,
  PageHeader,
  Spinner,
  useSession,
} from "@starterkit/module-kit";
import { can, useAsync } from "../support";
import { situationLabels, unitLabels } from "../labels";
import { reportsService } from "../services/reportsService";

const number = new Intl.NumberFormat("pt-BR");
const date = (value: string) => value.split("-").reverse().join("/");
const situations = [
  ["regularItems", "Regular", "bg-emerald-500"],
  ["replenishmentAlerts", "Reposição", "bg-amber-500"],
  ["outOfStockItems", "Sem estoque", "bg-orange-500"],
  ["itemsWithoutMinimum", "Sem mínimo", "bg-slate-400"],
  ["inconsistentItems", "Inconsistente", "bg-red-500"],
] as const;
const series = [
  ["entries", "Entradas", "bg-emerald-500"],
  ["issues", "Saídas", "bg-orange-500"],
  ["adjustments", "Ajustes", "bg-blue-500"],
  ["reversals", "Estornos", "bg-violet-500"],
] as const;

export default function DashboardPage() {
  const { state } = useSession();
  const [params, setParams] = useSearchParams();
  const from = params.get("from") ?? "";
  const to = params.get("to") ?? "";
  const invalid = Boolean(from && to && from > to);
  const loader = useCallback(
    (signal: AbortSignal) => reportsService.dashboard({ from, to }, signal),
    [from, to],
  );
  const { data: response, loading, error, reload } = useAsync(loader, !invalid);
  const data = response?.data;
  function change(key: "from" | "to", value: string) {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  }
  const maxMovements = Math.max(
    1,
    ...(data?.movementSeries.map(
      (row) => row.entries + row.issues + row.adjustments + row.reversals,
    ) ?? []),
  );
  return (
    <div className="space-y-6">
      <PageHeader
        title="Painel do almoxarifado"
        description="Acompanhe a saúde do estoque e as atividades da operação."
        breadcrumbs={[{ label: "Almoxarifado" }, { label: "Painel" }]}
        actions={
          <Button
            variant="secondary"
            onClick={reload}
            disabled={loading || invalid}
          >
            <RefreshCw className="size-4" aria-hidden="true" />
            Atualizar
          </Button>
        }
      />
      <Card className="flex flex-wrap items-end justify-between gap-4 p-5">
        <div>
          <h2 className="font-semibold">Período das movimentações</h2>
          <p className="text-sm text-ink-secondary">
            Os indicadores de estoque representam a posição atual.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <Field id="dashboard-from" label="De">
            <Input
              type="date"
              value={from}
              max={to || undefined}
              onChange={(event) => change("from", event.target.value)}
            />
          </Field>
          <Field id="dashboard-to" label="Até">
            <Input
              type="date"
              value={to}
              min={from || undefined}
              onChange={(event) => change("to", event.target.value)}
            />
          </Field>
          {(from || to) && (
            <Button
              variant="ghost"
              onClick={() => setParams({}, { replace: true })}
            >
              Mês atual
            </Button>
          )}
        </div>
      </Card>
      {invalid ? (
        <p role="alert" className="text-red-600">
          A data inicial deve ser anterior ou igual à data final.
        </p>
      ) : error ? (
        <ErrorState requestId={error.requestId} onRetry={reload} />
      ) : !data ? (
        <div className="flex justify-center p-12">
          <Spinner label="Carregando painel" />
        </div>
      ) : (
        <>
          <div className="flex flex-wrap justify-between gap-2 text-sm text-ink-secondary">
            <span>
              Movimentações: {date(data.period.from)} a {date(data.period.to)}
            </span>
            <span role="status">
              {loading
                ? "Atualizando…"
                : `Atualizado em ${new Date(data.asOf).toLocaleString("pt-BR")}`}
            </span>
          </div>
          <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {[
              {
                label: "Itens ativos",
                value: data.activeItems,
                detail: "Códigos ativos no catálogo",
                icon: Boxes,
                color: "text-blue-600 bg-blue-500/10",
              },
              {
                label: "Precisam de atenção",
                value: data.attentionItems,
                detail: `${number.format(data.outOfStockItems)} sem estoque · ${number.format(data.replenishmentAlerts)} para repor`,
                icon: CircleAlert,
                color: "text-amber-600 bg-amber-500/10",
              },
              {
                label: "Estoque regular",
                value: data.regularItems,
                detail: data.activeItems
                  ? `${Math.round((data.regularItems / data.activeItems) * 100)}% dos itens ativos`
                  : "Nenhum item ativo cadastrado",
                icon: ShieldCheck,
                color: "text-emerald-600 bg-emerald-500/10",
              },
              {
                label: "Movimentos no período",
                value: data.movementCount,
                detail: "Confirmados, incluindo os estornados",
                icon: Activity,
                color: "text-violet-600 bg-violet-500/10",
              },
            ].map((card) => (
              <Card key={card.label} className="p-5">
                <div className="flex items-center justify-between gap-3">
                  <dt className="text-sm text-ink-secondary">{card.label}</dt>
                  <span className={`rounded-lg p-2 ${card.color}`}>
                    <card.icon className="size-5" aria-hidden="true" />
                  </span>
                </div>
                <dd className="mt-3 text-3xl font-semibold tabular-nums">
                  {number.format(card.value)}
                </dd>
                <p className="mt-2 text-xs text-ink-secondary">{card.detail}</p>
              </Card>
            ))}
          </dl>
          <div className="grid items-start gap-6 xl:grid-cols-3">
            <Card className="p-6 xl:col-span-2">
              <h2 className="text-h3">Movimentações por dia</h2>
              <p className="mt-1 text-sm text-ink-secondary">
                Quantidade de movimentos por tipo. Rascunhos e descartados não
                entram no gráfico.
              </p>
              <div className="my-5 flex flex-wrap gap-4 text-xs">
                {series.map(([key, label, color]) => (
                  <span key={key} className="flex items-center gap-2">
                    <span className={`size-2.5 rounded-full ${color}`} />
                    {label}
                  </span>
                ))}
              </div>
              {data.movementSeries.length === 0 ? (
                <p className="rounded-lg bg-surface-subtle p-8 text-center text-sm text-ink-secondary">
                  Nenhuma movimentação confirmada no período.
                </p>
              ) : (
                <div
                  className="max-h-80 space-y-3 overflow-y-auto pr-2"
                  role="img"
                  aria-label="Gráfico de barras de movimentos por dia; valores detalhados em cada linha"
                >
                  {data.movementSeries.map((row) => (
                    <div
                      key={row.date}
                      className="grid grid-cols-[5rem_1fr] items-center gap-3"
                    >
                      <span className="text-xs tabular-nums">
                        {date(row.date)}
                      </span>
                      <div>
                        <div
                          className="flex h-5 overflow-hidden rounded"
                          aria-hidden="true"
                        >
                          {series.map(([key, , color]) => (
                            <div
                              key={key}
                              className={color}
                              style={{
                                width: `${(row[key] / maxMovements) * 100}%`,
                              }}
                            />
                          ))}
                        </div>
                        <p className="mt-1 text-xs text-ink-secondary">
                          {series
                            .map(
                              ([key, label]) =>
                                `${label}: ${number.format(row[key])}`,
                            )
                            .join(" · ")}
                        </p>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </Card>
            <Card className="p-6">
              <h2 className="text-h3">Situação do estoque</h2>
              <p className="mt-1 text-sm text-ink-secondary">
                Cada item aparece em uma única situação.
              </p>
              <div
                className="my-6 flex h-4 overflow-hidden rounded-full bg-slate-100"
                role="img"
                aria-label="Distribuição dos itens ativos por situação"
              >
                {situations.map(([key, , color]) => (
                  <span
                    key={key}
                    className={color}
                    style={{
                      width: `${data.activeItems ? (data[key] / data.activeItems) * 100 : 0}%`,
                    }}
                  />
                ))}
              </div>
              <dl className="space-y-4">
                {situations.map(([key, label, color]) => (
                  <div
                    key={key}
                    className="flex items-center justify-between text-sm"
                  >
                    <dt className="flex items-center gap-2">
                      <span className={`size-2.5 rounded-full ${color}`} />
                      {label}
                    </dt>
                    <dd className="font-semibold tabular-nums">
                      {number.format(data[key])}
                    </dd>
                  </div>
                ))}
              </dl>
            </Card>
          </div>
          <Card className="p-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="text-h3">Atenção ao estoque</h2>
                <p className="mt-1 text-sm text-ink-secondary">
                  Até 8 itens prioritários: inconsistências, falta de estoque,
                  reposição e mínimos ausentes.
                </p>
              </div>
              {can(state.user, "inventory.reports.view") && (
                <Button asChild variant="secondary">
                  <Link to="/admin/inventory/reports">Abrir relatórios</Link>
                </Button>
              )}
            </div>
            {data.alerts.length === 0 ? (
              <div className="mt-5 flex items-center gap-3 rounded-lg bg-emerald-500/10 p-4 text-sm">
                <ShieldCheck
                  className="size-5 text-emerald-600"
                  aria-hidden="true"
                />
                {data.activeItems
                  ? "Todos os itens ativos estão com estoque regular."
                  : "Cadastre itens e configure seus mínimos para acompanhar os alertas."}
              </div>
            ) : (
              <ul className="mt-5 divide-y divide-line">
                {data.alerts.map((item) => (
                  <li
                    key={item.id}
                    className="flex flex-wrap items-center justify-between gap-3 py-4"
                  >
                    <div>
                      <p className="font-medium">
                        {can(state.user, "inventory.items.view") ? (
                          <Link
                            className="hover:underline"
                            to={`/admin/inventory/${item.id}`}
                          >
                            {item.code} · {item.name}
                          </Link>
                        ) : (
                          `${item.code} · ${item.name}`
                        )}
                      </p>
                      <p className="mt-1 text-xs text-ink-secondary">
                        Saldo: {number.format(item.stock)}{" "}
                        {unitLabels[item.unit] ?? item.unit} · Mínimo:{" "}
                        {item.minimumStock === null
                          ? "Não configurado"
                          : number.format(item.minimumStock)}
                      </p>
                    </div>
                    <span
                      className={`rounded-full px-3 py-1 text-xs font-medium ${item.situation === "INCONSISTENT" ? "bg-red-500/10 text-red-700" : "bg-amber-500/10 text-amber-700"}`}
                    >
                      {situationLabels[item.situation]}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <div className="flex flex-wrap gap-3">
            {[
              [
                "inventory.entries.create",
                "/admin/inventory/movements/entry",
                "Nova entrada",
                ArrowDownToLine,
              ],
              [
                "inventory.issues.create",
                "/admin/inventory/movements/issue",
                "Nova saída",
                ArrowUpFromLine,
              ],
              [
                "inventory.items.viewAny",
                "/admin/inventory",
                "Consultar itens",
                Boxes,
              ],
              [
                "inventory.movements.viewAny",
                "/admin/inventory/movements",
                "Ver movimentações",
                Activity,
              ],
            ].map(
              ([permission, to, label, Icon]) =>
                can(state.user, permission as string) && (
                  <Button key={to as string} asChild variant="secondary">
                    <Link to={to as string}>
                      <Icon className="size-4" aria-hidden="true" />
                      {label as string}
                    </Link>
                  </Button>
                ),
            )}
          </div>
        </>
      )}
    </div>
  );
}
