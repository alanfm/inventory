import MovementsPage from "./MovementsPage";
import { Button, Field, Input } from "@starterkit/module-kit";
import { FormModal, Select, Textarea, useAsync } from "../support";
import { useCallback, useState } from "react";
import { Link, useNavigate } from "react-router";
import { catalogService } from "../services/catalogService";
import { movementService, type DraftInput } from "../services/movementService";
import { VariantSelect } from "../components/VariantSelect";

function MovementCreateForm({ type }: { type: "ENTRY" | "ISSUE" }) {
  const navigate = useNavigate();
  const loader = useCallback(
    (signal: AbortSignal) => catalogService.items("", 1, "", signal, 100),
    [],
  );
  const { data, loading, error, reload } = useAsync(loader);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const variants =
    data?.data.flatMap((item) =>
      item.variants
        .filter((variant) => variant.active)
        .map((variant) => ({
          ...variant,
          item,
        })),
    ) ?? [];

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const variant = variants.find(
      (candidate) => candidate.id === String(form.get("variantId")),
    );
    if (!variant) {
      setMessage("Selecione uma variante válida antes de continuar.");
      return;
    }
    const input: DraftInput = {
      type,
      occurredOn: String(form.get("occurredOn")) || null,
      lines: [
        {
          variantId: variant.id,
          quantity: Number(form.get("quantity")),
          ...(type === "ENTRY" && form.get("unitCost")
            ? { unitCost: String(form.get("unitCost")) }
            : {}),
        },
      ],
      ...(type === "ENTRY"
        ? {
            origin: String(form.get("origin")),
            documentNumber: String(form.get("documentNumber")) || null,
            observations: String(form.get("observations")) || null,
          }
        : {
            description: String(form.get("description")),
            serviceOrderNumber: String(form.get("serviceOrderNumber")) || null,
            observations: String(form.get("observations")) || null,
          }),
    };
    setSaving(true);
    setMessage("");
    try {
      const created = await movementService.createDraft(input);
      navigate(`/admin/inventory/movements/${created.id}`);
    } catch (caught) {
      setMessage(
        caught instanceof Error
          ? caught.message
          : "Não foi possível salvar o rascunho.",
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <FormModal
      title={type === "ENTRY" ? "Nova entrada" : "Nova saída"}
      returnTo="/admin/inventory/movements"
      background={<MovementsPage />}
      backgroundPermission="inventory.movements.viewAny"
    >
      {message && <p role="alert">{message}</p>}
      {loading && <p role="status">Carregando variantes…</p>}
      {error && (
        <p role="alert">
          Falha ao carregar catálogo.{" "}
          <button onClick={reload}>Tentar novamente</button>
        </p>
      )}
      {!loading && !error && variants.length === 0 && (
        <p>
          Nenhuma variante ativa disponível.{" "}
          <Link to="/admin/inventory">Abrir catálogo</Link>
        </p>
      )}
      {variants.length > 0 && (
        <form
          className="grid max-w-3xl gap-4 md:grid-cols-2"
          onSubmit={(event) => void submit(event)}
        >
          <Field id="variantId" label="Variante" required>
            <VariantSelect
              options={variants.map((variant) => ({
                value: variant.id,
                label: `${variant.item.code} — ${variant.item.name} / ${[
                  variant.brand,
                  variant.model,
                  variant.description,
                ]
                  .filter(Boolean)
                  .join(" ")}`,
              }))}
            />
          </Field>
          <Field id="quantity" label="Quantidade" required>
            <Input
              id="quantity"
              name="quantity"
              type="number"
              min="1"
              step="1"
              required
            />
          </Field>
          <Field id="occurredOn" label="Data do fato">
            <Input id="occurredOn" name="occurredOn" type="date" />
          </Field>
          {type === "ENTRY" ? (
            <>
              <Field id="origin" label="Origem" required>
                <Select id="origin" name="origin">
                  <option value="PURCHASE">Compra</option>
                  <option value="DONATION">Doação</option>
                  <option value="INITIAL_STOCK">Estoque inicial</option>
                  <option value="RETURN">Devolução</option>
                  <option value="TRANSFER_RECEIPT">Recebimento externo</option>
                  <option value="OTHER">Outra</option>
                </Select>
              </Field>
              <Field
                id="documentNumber"
                label="Documento (obrigatório para compra)"
              >
                <Input
                  id="documentNumber"
                  name="documentNumber"
                  maxLength={120}
                />
              </Field>
              <Field id="unitCost" label="Custo unitário (opcional)">
                <Input
                  id="unitCost"
                  name="unitCost"
                  type="number"
                  min="0"
                  step="0.01"
                />
              </Field>
            </>
          ) : (
            <>
              <Field id="description" label="Finalidade/atendimento">
                <Input id="description" name="description" maxLength={5000} />
              </Field>
              <Field
                id="serviceOrderNumber"
                label="Ordem de serviço (opcional)"
              >
                <Input
                  id="serviceOrderNumber"
                  name="serviceOrderNumber"
                  maxLength={120}
                />
              </Field>
            </>
          )}
          <Field
            id="observations"
            label={
              type === "ENTRY"
                ? "Observações (obrigatórias sem documento)"
                : "Observações (obrigatórias sem OS)"
            }
          >
            <Textarea id="observations" name="observations" maxLength={5000} />
          </Field>
          <p className="md:col-span-2 text-sm">
            O rascunho pode ficar incompleto e não altera nem reserva saldo. As
            regras serão revalidadas na confirmação.
          </p>
          <div>
            <Button type="submit" loading={saving}>
              Salvar rascunho
            </Button>
          </div>
        </form>
      )}
    </FormModal>
  );
}

export function EntryCreatePage() {
  return <MovementCreateForm type="ENTRY" />;
}
export function IssueCreatePage() {
  return <MovementCreateForm type="ISSUE" />;
}
