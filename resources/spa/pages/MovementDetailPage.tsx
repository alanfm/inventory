import { List, Pencil } from "lucide-react";
import { useCallback, useState } from "react";
import { Link, useParams } from "react-router";
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
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
import { labeled, unitLabels } from "../labels";
import { catalogService } from "../services/catalogService";
import { movementService } from "../services/movementService";

export default function MovementDetailPage() {
  const { state } = useSession();
  const canReverse = can(state.user, "inventory.movements.reverse");
  const { id = "" } = useParams();
  const loader = useCallback(
    (signal: AbortSignal) => movementService.get(id, signal),
    [id],
  );
  const { data: movement, loading, error, reload } = useAsync(loader);
  const catalogLoader = useCallback(
    (signal: AbortSignal) => catalogService.items("", 1, "", signal, 100),
    [],
  );
  const { data: catalog } = useAsync(catalogLoader);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [reason, setReason] = useState("");
  const [editOpen, setEditOpen] = useState(false);
  const [reverseOpen, setReverseOpen] = useState(false);
  async function cancel() {
    if (!movement) return;
    setBusy(true);
    setMessage("");
    try {
      await movementService.cancel(movement.id);
      reload();
    } catch (caught) {
      setMessage(
        caught instanceof Error
          ? caught.message
          : "Não foi possível descartar o rascunho.",
      );
    } finally {
      setBusy(false);
    }
  }
  async function post() {
    if (!movement) return;
    setBusy(true);
    setMessage("");
    try {
      await movementService.post(movement.id, movement.version);
      reload();
    } catch (caught) {
      setMessage(
        caught instanceof Error
          ? caught.message
          : "Não foi possível confirmar o movimento.",
      );
    } finally {
      setBusy(false);
    }
  }
  async function updateDraft(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!movement) return;
    const form = new FormData(event.currentTarget);
    setBusy(true);
    setMessage("");
    try {
      await movementService.updateDraft(movement.id, movement.version, {
        occurredOn: String(form.get("occurredOn")) || null,
        origin:
          movement.type === "ENTRY" ? String(form.get("origin")) : undefined,
        documentNumber:
          movement.type === "ENTRY"
            ? String(form.get("documentNumber")) || null
            : undefined,
        description:
          movement.type === "ISSUE"
            ? String(form.get("description")) || null
            : undefined,
        serviceOrderNumber:
          movement.type === "ISSUE"
            ? String(form.get("serviceOrderNumber")) || null
            : undefined,
        observations: String(form.get("observations")) || null,
        lines: movement.lines.map((line, index) =>
          index === 0
            ? {
                variantId: String(form.get("variantId")),
                quantity: Number(form.get("quantity")),
                ...(movement.type === "ENTRY" && form.get("unitCost")
                  ? { unitCost: String(form.get("unitCost")) }
                  : {}),
              }
            : {
                variantId: String(line.variant_id),
                quantity: Number(line.quantity ?? 0),
                ...(movement.type === "ENTRY" && line.unit_cost
                  ? { unitCost: line.unit_cost }
                  : {}),
              },
        ),
      });
      setEditOpen(false);
      reload();
    } catch (caught) {
      setMessage(
        caught instanceof Error
          ? caught.message
          : "Não foi possível editar o rascunho.",
      );
    } finally {
      setBusy(false);
    }
  }
  async function reverse(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!movement) return;
    setBusy(true);
    setMessage("");
    try {
      await movementService.reverse(String(movement.id), reason);
      setReason("");
      setReverseOpen(false);
      reload();
    } catch (caught) {
      setMessage(
        caught instanceof Error
          ? caught.message
          : "Não foi possível estornar o movimento.",
      );
    } finally {
      setBusy(false);
    }
  }
  if (loading && !movement) {
    return (
      <div className="flex justify-center p-10">
        <Spinner label="Carregando movimentação" />
      </div>
    );
  }
  if (error || !movement) {
    return <ErrorState requestId={error?.requestId} onRetry={reload} />;
  }
  return (
    <div className="space-y-6">
      <PageHeader
        title={`${movement.type === "ENTRY" ? "Entrada" : movement.type === "ISSUE" ? "Saída" : movement.type === "ADJUSTMENT" ? "Ajuste" : "Estorno"} #${movement.id}`}
        breadcrumbs={[
          { label: "Movimentos", to: "/admin/inventory/movements" },
          { label: `#${movement.id}` },
        ]}
      />
      {message ? <Alert variant="danger">{message}</Alert> : null}
      <dl className="grid max-w-2xl grid-cols-2 gap-3">
        <dt>Estado</dt>
        <dd>{movement.status}</dd>
        <dt>Data do fato</dt>
        <dd>{movement.occurred_on ?? "Não informada"}</dd>
        <dt>Finalidade</dt>
        <dd>{movement.description || "Não informada"}</dd>
        <dt>OS</dt>
        <dd>{movement.service_order_number || "Não informada"}</dd>
        <dt>Documento</dt>
        <dd>{movement.document_number || "Não informado"}</dd>
        <dt>Observações</dt>
        <dd>{movement.observations || "—"}</dd>
      </dl>
      <section className="space-y-4">
        <h2 className="text-h3">Linhas</h2>
        {movement.lines.length === 0 ? (
          <EmptyState
            icon={List}
            title="Rascunho sem linhas"
            description="Este movimento ainda não tem itens."
          />
        ) : (
          <TableWrapper>
            <Table>
              <caption className="sr-only">
                Linhas do movimento #{movement.id}
              </caption>
              <TableHeader>
                <TableRow>
                  <TableHead>Código</TableHead>
                  <TableHead>Item</TableHead>
                  <TableHead>Variante</TableHead>
                  <TableHead>Quantidade</TableHead>
                  <TableHead>Unidade</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {movement.lines.map((line) => (
                  <TableRow key={line.id}>
                    <TableCell className="font-medium">
                      {line.snapshot.code || "—"}
                    </TableCell>
                    <TableCell>{line.snapshot.itemName || "—"}</TableCell>
                    <TableCell className="text-ink-secondary">
                      {[
                        line.snapshot.brand,
                        line.snapshot.model,
                        line.snapshot.description,
                      ]
                        .filter(Boolean)
                        .join(" ") || "—"}
                    </TableCell>
                    <TableCell>
                      {line.quantity ?? line.counted_quantity ?? 0}
                    </TableCell>
                    <TableCell>
                      {labeled(unitLabels, line.snapshot.unit ?? "")}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableWrapper>
        )}
      </section>
      {movement.status === "DRAFT" && (
        <div className="flex gap-3">
          <Button variant="secondary" onClick={() => setEditOpen(true)}>
            <Pencil className="size-4" aria-hidden="true" />
            Editar rascunho
          </Button>
          <Button loading={busy} onClick={() => void post()}>
            Confirmar {movement.type === "ENTRY" ? "entrada" : "saída"}
          </Button>
          <Button variant="danger" loading={busy} onClick={() => void cancel()}>
            Descartar rascunho
          </Button>
        </div>
      )}
      {movement.status === "DRAFT" ? (
        <Dialog
          open={editOpen}
          onOpenChange={(open) => {
            setEditOpen(open);
            if (!open) setMessage("");
          }}
        >
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>Editar rascunho</DialogTitle>
              <DialogDescription>
                Atualize os dados antes de confirmar a movimentação.
              </DialogDescription>
            </DialogHeader>
            <form
              className="grid gap-4 sm:grid-cols-2"
              onSubmit={(event) => void updateDraft(event)}
            >
              <Field id="edit-variant" label="Variante" required>
                <Select
                  name="variantId"
                  defaultValue={String(movement.lines[0]?.variant_id ?? "")}
                  required
                >
                  {catalog?.data.flatMap((item) =>
                    item.variants
                      .filter((variant) => variant.active)
                      .map((variant) => (
                        <option key={variant.id} value={variant.id}>
                          {item.code} — {item.name} /{" "}
                          {[variant.brand, variant.model, variant.description]
                            .filter(Boolean)
                            .join(" ")}
                        </option>
                      )),
                  )}
                </Select>
              </Field>
              <Field id="edit-quantity" label="Quantidade" required>
                <Input
                  name="quantity"
                  type="number"
                  min="1"
                  step="1"
                  defaultValue={movement.lines[0]?.quantity ?? ""}
                  required
                />
              </Field>
              <Field id="edit-occurred-on" label="Data do fato">
                <Input
                  name="occurredOn"
                  type="date"
                  defaultValue={movement.occurred_on ?? ""}
                />
              </Field>
              {movement.type === "ENTRY" ? (
                <>
                  <Field id="edit-origin" label="Origem" required>
                    <Select
                      name="origin"
                      defaultValue={movement.origin ?? "PURCHASE"}
                    >
                      <option value="PURCHASE">Compra</option>
                      <option value="DONATION">Doação</option>
                      <option value="INITIAL_STOCK">Estoque inicial</option>
                      <option value="RETURN">Devolução</option>
                      <option value="TRANSFER_RECEIPT">
                        Recebimento externo
                      </option>
                      <option value="OTHER">Outra</option>
                    </Select>
                  </Field>
                  <Field id="edit-document" label="Documento">
                    <Input
                      name="documentNumber"
                      defaultValue={movement.document_number ?? ""}
                      maxLength={120}
                    />
                  </Field>
                  <Field id="edit-unit-cost" label="Custo unitário">
                    <Input
                      name="unitCost"
                      type="number"
                      min="0"
                      step="0.01"
                      defaultValue={movement.lines[0]?.unit_cost ?? ""}
                    />
                  </Field>
                </>
              ) : (
                <>
                  <Field id="edit-description" label="Finalidade" required>
                    <Input
                      name="description"
                      defaultValue={movement.description ?? ""}
                      maxLength={5000}
                      required
                    />
                  </Field>
                  <Field id="edit-service-order" label="Ordem de serviço">
                    <Input
                      name="serviceOrderNumber"
                      defaultValue={movement.service_order_number ?? ""}
                      maxLength={120}
                    />
                  </Field>
                </>
              )}
              <Field
                id="edit-observations"
                className="sm:col-span-2"
                label="Observações"
              >
                <Textarea
                  name="observations"
                  defaultValue={movement.observations ?? ""}
                  maxLength={5000}
                />
              </Field>
              {message ? (
                <div className="sm:col-span-2">
                  <Alert variant="danger">{message}</Alert>
                </div>
              ) : null}
              <DialogFooter className="sm:col-span-2">
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => setEditOpen(false)}
                >
                  Cancelar
                </Button>
                <Button type="submit" loading={busy}>
                  Salvar rascunho
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      ) : null}
      {canReverse &&
        movement.status === "POSTED" &&
        movement.type !== "REVERSAL" && (
          <>
            <Button
              variant="danger"
              onClick={() => {
                setMessage("");
                setReason("");
                setReverseOpen(true);
              }}
            >
              Estornar movimento
            </Button>
            <Dialog open={reverseOpen} onOpenChange={setReverseOpen}>
              <DialogContent>
                <DialogHeader>
                  <DialogTitle>Estorno integral</DialogTitle>
                  <DialogDescription>
                    Informe o motivo para estornar este movimento.
                  </DialogDescription>
                </DialogHeader>
                <form
                  className="grid max-w-2xl gap-3"
                  onSubmit={(event) => void reverse(event)}
                >
                  <p>
                    O sistema lançará a compensação de todas as linhas. A
                    operação será recusada se deixar alguma variante com saldo
                    negativo.
                  </p>
                  <label htmlFor="reverse-reason">Motivo obrigatório</label>
                  <Textarea
                    id="reverse-reason"
                    required
                    maxLength={5000}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                  />
                  {message ? <Alert variant="danger">{message}</Alert> : null}
                  <DialogFooter>
                    <Button
                      type="button"
                      variant="secondary"
                      onClick={() => setReverseOpen(false)}
                    >
                      Cancelar
                    </Button>
                    <Button type="submit" variant="danger" loading={busy}>
                      Estornar movimento
                    </Button>
                  </DialogFooter>
                </form>
              </DialogContent>
            </Dialog>
          </>
        )}
      <Link className="underline" to="/admin/inventory/movements">
        Voltar à lista
      </Link>
    </div>
  );
}
