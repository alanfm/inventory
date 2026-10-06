import { ArrowLeftRight, ClipboardCheck, Eye, Plus } from "lucide-react";
import { useCallback, useMemo, useState } from "react";
import { Link } from "react-router";
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  SimpleTooltip,
  Select,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  TableWrapper,
  Textarea,
  can,
  useAsync,
} from "../support";
import { labeled, movementStatusLabels, movementTypeLabels } from "../labels";
import { catalogService } from "../services/catalogService";
import { movementService } from "../services/movementService";

export default function MovementsPage() {
  const { state } = useSession();
  const canCreateEntry = can(state.user, "inventory.entries.create");
  const canCreateIssue = can(state.user, "inventory.issues.create");
  const canView = can(state.user, "inventory.movements.view");
  const canAdjust = can(state.user, "inventory.adjustments.create");
  const [countOpen, setCountOpen] = useState(false);
  const [variantId, setVariantId] = useState("");
  const [countedQuantity, setCountedQuantity] = useState("");
  const [reason, setReason] = useState("");
  const [countMessage, setCountMessage] = useState("");
  const [countBusy, setCountBusy] = useState(false);
  const loader = useCallback(
    (signal: AbortSignal) => movementService.list(signal),
    [],
  );
  const { data, loading, error, reload } = useAsync(loader);
  const catalogLoader = useCallback(
    (signal: AbortSignal) => catalogService.items("", 1, "", signal, 100),
    [],
  );
  const { data: catalog, loading: catalogLoading } = useAsync(catalogLoader);
  const variants = useMemo(
    () =>
      catalog?.data.flatMap((item) =>
        item.variants.map((variant) => ({
          item,
          variant,
          balance: variant.balances?.[0],
        })),
      ) ?? [],
    [catalog],
  );
  const selected =
    variants.find(({ variant }) => variant.id === variantId) ?? variants[0];
  const delta = selected
    ? Number(countedQuantity || 0) - (selected.balance?.quantity ?? 0)
    : 0;

  async function registerCount(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selected?.balance) {
      setCountMessage(
        "Saldo/local indisponível; recarregue os dados antes de contar.",
      );
      return;
    }
    setCountBusy(true);
    setCountMessage("");
    try {
      await movementService.adjust({
        locationId: selected.balance.locationId,
        variantId: selected.variant.id,
        countedQuantity: Number(countedQuantity),
        expectedBalanceVersion: selected.balance.version,
        reason: reason.trim(),
      });
      setCountOpen(false);
      setCountedQuantity("");
      setReason("");
      setCountMessage("");
      reload();
    } catch (caught) {
      setCountMessage(
        caught instanceof Error
          ? caught.message
          : "Não foi possível registrar a contagem.",
      );
    } finally {
      setCountBusy(false);
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Movimentações"
        description="Rascunhos, entradas e saídas do almoxarifado."
        breadcrumbs={[
          { label: "Almoxarifado", to: "/admin/inventory" },
          { label: "Movimentações" },
        ]}
        actions={
          canCreateEntry || canCreateIssue || canAdjust ? (
            <div className="flex flex-wrap gap-2">
              {canCreateIssue ? (
                <Button
                  asChild
                  className="border-orange-700 bg-transparent text-orange-700 hover:border-orange-800 hover:bg-transparent hover:text-orange-800"
                >
                  <Link to="/admin/inventory/movements/issue">
                    <Plus className="size-4" aria-hidden="true" />
                    Nova Saída
                  </Link>
                </Button>
              ) : null}
              {canCreateEntry ? (
                <Button
                  asChild
                  className="border-emerald-700 bg-transparent text-emerald-700 hover:border-emerald-800 hover:bg-transparent hover:text-emerald-800"
                >
                  <Link to="/admin/inventory/movements/entry">
                    <Plus className="size-4" aria-hidden="true" />
                    Nova Entrada
                  </Link>
                </Button>
              ) : null}
              {canAdjust ? (
                <Button
                  type="button"
                  className="border-blue-700 bg-transparent text-blue-700 hover:border-blue-800 hover:bg-transparent hover:text-blue-800"
                  onClick={() => {
                    setCountMessage("");
                    setCountOpen(true);
                  }}
                >
                  <ClipboardCheck className="size-4" aria-hidden="true" />
                  Contagem de Estoque
                </Button>
              ) : null}
            </div>
          ) : null
        }
      />
      {loading && !data ? (
        <div className="flex justify-center p-10">
          <Spinner label="Carregando movimentos" />
        </div>
      ) : error ? (
        <ErrorState requestId={error.requestId} onRetry={reload} />
      ) : data && data.data.length === 0 ? (
        <EmptyState
          icon={ArrowLeftRight}
          title="Nenhuma movimentação"
          description="Ainda não há movimentações registradas."
        />
      ) : data ? (
        <TableWrapper>
          <Table>
            <caption className="sr-only">
              Lista de movimentações do almoxarifado
            </caption>
            <TableHeader>
              <TableRow>
                <TableHead>Movimento</TableHead>
                <TableHead>Tipo</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead>Data</TableHead>
                <TableHead className="text-right">Ações</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.data.map((movement) => (
                <TableRow key={movement.id}>
                  <TableCell className="font-medium">#{movement.id}</TableCell>
                  <TableCell>
                    {labeled(movementTypeLabels, movement.type)}
                  </TableCell>
                  <TableCell>
                    <Badge variant="neutral">
                      {labeled(movementStatusLabels, movement.status)}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-ink-secondary">
                    {movement.occurred_on
                      ? movement.occurred_on
                          .slice(0, 10)
                          .split("-")
                          .reverse()
                          .join("/")
                      : "—"}
                  </TableCell>
                  <TableCell className="text-right">
                    {canView ? (
                      <SimpleTooltip label="Abrir">
                        <Button asChild variant="ghost" size="iconCompact">
                          <Link
                            to={`/admin/inventory/movements/${movement.id}`}
                            aria-label={`Abrir movimento ${movement.id}`}
                          >
                            <Eye className="size-4" aria-hidden="true" />
                          </Link>
                        </Button>
                      </SimpleTooltip>
                    ) : (
                      <span className="text-ink-muted">—</span>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableWrapper>
      ) : null}
      <Dialog
        open={countOpen}
        onOpenChange={(open) => {
          setCountOpen(open);
          if (!open) setCountMessage("");
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Contagem de estoque</DialogTitle>
            <DialogDescription>
              Informe a quantidade física encontrada. A diferença será
              registrada como ajuste auditável.
            </DialogDescription>
          </DialogHeader>
          <form
            className="space-y-4"
            onSubmit={(event) => void registerCount(event)}
          >
            <Field id="count-variant" label="Variante" required>
              <Select
                value={selected?.variant.id ?? ""}
                onChange={(event) => {
                  setVariantId(event.target.value);
                  setCountedQuantity("");
                }}
                required
                disabled={catalogLoading}
              >
                {variants.map(({ item, variant }) => (
                  <option key={variant.id} value={variant.id}>
                    {item.code} — {item.name} / {variant.description}
                  </option>
                ))}
              </Select>
            </Field>
            <p>
              Saldo atual:{" "}
              <strong>{selected?.balance?.quantity ?? "indisponível"}</strong>
            </p>
            <Field id="count-quantity" label="Quantidade contada" required>
              <Input
                name="countedQuantity"
                type="number"
                min="0"
                step="1"
                value={countedQuantity}
                onChange={(event) => setCountedQuantity(event.target.value)}
                required
              />
            </Field>
            <p>
              Prévia da diferença: {delta > 0 ? "+" : ""}
              {delta}
            </p>
            <Field id="count-reason" label="Motivo" required>
              <Textarea
                name="reason"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                maxLength={5000}
                required
              />
            </Field>
            {countMessage ? (
              <Alert variant="danger">{countMessage}</Alert>
            ) : null}
            <DialogFooter>
              <Button
                type="button"
                variant="secondary"
                onClick={() => setCountOpen(false)}
              >
                Cancelar
              </Button>
              <Button type="submit" loading={countBusy}>
                Registrar contagem
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
