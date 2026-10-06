import ItemsPage from "./ItemsPage";
import { Button, Field, Input } from "@starterkit/module-kit";
import { FormModal, Select, Textarea, useAsync } from "../support";
import { useCallback, useState } from "react";
import { useNavigate } from "react-router";
import { catalogService } from "../services/catalogService";

export default function ItemCreatePage() {
  const navigate = useNavigate();
  const loader = useCallback(
    (signal: AbortSignal) => catalogService.categories("", signal),
    [],
  );
  const { data } = useAsync(loader);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    const form = new FormData(event.currentTarget);
    try {
      const item = await catalogService.createItem({
        code: String(form.get("code")),
        categoryId: String(form.get("categoryId")),
        name: String(form.get("name")),
        description: String(form.get("description")) || null,
        unit: String(form.get("unit")),
      });
      navigate(`/admin/inventory/${item.id}`);
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "Não foi possível criar o item.",
      );
    } finally {
      setSaving(false);
    }
  }
  return (
    <FormModal
      title="Novo item"
      returnTo="/admin/inventory"
      background={<ItemsPage />}
      backgroundPermission="inventory.items.viewAny"
    >
      {error && <p role="alert">{error}</p>}
      <form
        className="max-w-2xl space-y-4"
        onSubmit={(event) => void submit(event)}
      >
        <Field id="item-code" label="Código" required>
          <Input
            id="item-code"
            name="code"
            maxLength={32}
            required
            pattern="[A-Za-z0-9_-]+"
          />
        </Field>
        <Field id="item-name" label="Nome" required>
          <Input id="item-name" name="name" maxLength={200} required />
        </Field>
        <Field id="item-category" label="Categoria" required>
          <Select id="item-category" name="categoryId" required defaultValue="">
            <option value="" disabled>
              Selecione
            </option>
            {data?.data
              .filter((category) => category.active)
              .map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
          </Select>
        </Field>
        <Field id="item-unit" label="Unidade" required>
          <Select id="item-unit" name="unit">
            <option value="UN">Unidade</option>
            <option value="PAR">Par</option>
            <option value="CX">Caixa</option>
          </Select>
        </Field>
        <Field id="item-description" label="Descrição">
          <Textarea id="item-description" name="description" maxLength={5000} />
        </Field>
        <Button type="submit" loading={saving}>
          Criar item
        </Button>
      </form>
    </FormModal>
  );
}
