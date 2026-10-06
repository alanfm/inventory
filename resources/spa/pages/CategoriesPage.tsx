import { Pencil, Plus, Power, Tags } from "lucide-react";
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  SimpleTooltip,
  Textarea,
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
import { catalogService, type Category } from "../services/catalogService";

export default function CategoriesPage() {
  const { state } = useSession();
  const canCreate = can(state.user, "inventory.categories.create");
  const canUpdate = can(state.user, "inventory.categories.update");
  const loader = useCallback(
    (signal: AbortSignal) => catalogService.categories("", signal),
    [],
  );
  const { data, loading, error, reload } = useAsync(loader);
  const [name, setName] = useState("");
  const [observations, setObservations] = useState("");
  const [editing, setEditing] = useState<Category | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setSaving(true);
    setMessage("");
    try {
      if (editing) {
        await catalogService.updateCategory(
          editing,
          name,
          observations,
          editing.active,
        );
      } else {
        await catalogService.createCategory(name, observations);
      }
      closeForm();
      reload();
    } catch (caught) {
      setMessage(
        caught instanceof Error
          ? caught.message
          : "Não foi possível salvar a categoria.",
      );
    } finally {
      setSaving(false);
    }
  }

  function closeForm() {
    setFormOpen(false);
    setEditing(null);
    setName("");
    setObservations("");
    setMessage("");
  }

  function openCreateForm() {
    setEditing(null);
    setName("");
    setObservations("");
    setMessage("");
    setFormOpen(true);
  }

  function openEditForm(category: Category) {
    setEditing(category);
    setName(category.name);
    setObservations(category.observations ?? "");
    setMessage("");
    setFormOpen(true);
  }

  async function toggle(category: Category) {
    setMessage("");
    try {
      await catalogService.updateCategory(
        category,
        category.name,
        category.observations ?? "",
        !category.active,
      );
      reload();
    } catch (caught) {
      setMessage(
        caught instanceof Error
          ? caught.message
          : "Não foi possível atualizar a categoria.",
      );
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Categorias"
        description="Classificações dos itens do almoxarifado."
        breadcrumbs={[
          { label: "Almoxarifado", to: "/admin/inventory" },
          { label: "Categorias" },
        ]}
        actions={
          canCreate ? (
            <Button onClick={openCreateForm}>
              <Plus className="size-4" aria-hidden="true" />
              Nova categoria
            </Button>
          ) : null
        }
      />
      {message ? <Alert variant="danger">{message}</Alert> : null}
      <Dialog
        open={formOpen}
        onOpenChange={(open) => {
          if (open) setFormOpen(true);
          else closeForm();
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editing ? "Editar categoria" : "Nova categoria"}
            </DialogTitle>
            <DialogDescription>
              Informe o nome e, se necessário, observações para esta categoria.
            </DialogDescription>
          </DialogHeader>
          <form className="space-y-4" onSubmit={(event) => void submit(event)}>
            <Field id="category-name" label="Nome da categoria" required>
              <Input
                id="category-name"
                maxLength={120}
                value={name}
                onChange={(event) => setName(event.target.value)}
                required
              />
            </Field>
            <Field id="category-observations" label="Observações">
              <Textarea
                id="category-observations"
                maxLength={5000}
                value={observations}
                onChange={(event) => setObservations(event.target.value)}
              />
            </Field>
            {message ? <Alert variant="danger">{message}</Alert> : null}
            <DialogFooter>
              <Button type="button" variant="secondary" onClick={closeForm}>
                Cancelar
              </Button>
              <Button type="submit" loading={saving}>
                {editing ? "Salvar alterações" : "Cadastrar categoria"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
      {loading && !data ? (
        <div className="flex justify-center p-10">
          <Spinner label="Carregando categorias" />
        </div>
      ) : error ? (
        <ErrorState requestId={error.requestId} onRetry={reload} />
      ) : data && data.data.length === 0 ? (
        <EmptyState
          icon={Tags}
          title="Nenhuma categoria"
          description="Ainda não há categorias cadastradas."
        />
      ) : data ? (
        <TableWrapper>
          <Table>
            <caption className="sr-only">
              Lista de categorias do almoxarifado
            </caption>
            <TableHeader>
              <TableRow>
                <TableHead>Categoria</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="text-right">Ações</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.data.map((category) => (
                <TableRow key={category.id}>
                  <TableCell className="font-medium">{category.name}</TableCell>
                  <TableCell>
                    <Badge variant="neutral">
                      {category.active ? "Ativa" : "Inativa"}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    {canUpdate ? (
                      <div className="inline-flex items-center gap-1">
                        <SimpleTooltip label="Editar">
                          <Button
                            type="button"
                            variant="ghost"
                            size="iconCompact"
                            aria-label={`Editar ${category.name}`}
                            onClick={() => {
                              openEditForm(category);
                            }}
                          >
                            <Pencil className="size-4" aria-hidden="true" />
                          </Button>
                        </SimpleTooltip>
                        <SimpleTooltip
                          label={category.active ? "Inativar" : "Reativar"}
                        >
                          <Button
                            type="button"
                            variant="ghost"
                            size="iconCompact"
                            aria-label={`${category.active ? "Inativar" : "Reativar"} ${category.name}`}
                            onClick={() => void toggle(category)}
                          >
                            <Power className="size-4" aria-hidden="true" />
                          </Button>
                        </SimpleTooltip>
                      </div>
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
    </div>
  );
}
