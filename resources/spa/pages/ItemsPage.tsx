import { Boxes, Eye, Plus } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router";
import {
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
} from "../support";
import { catalogService } from "../services/catalogService";
import { labeled, unitLabels } from "../labels";

export default function ItemsPage() {
  const { state } = useSession();
  const canCreate = can(state.user, "inventory.items.create");
  const canView = can(state.user, "inventory.items.view");
  const [params, setParams] = useSearchParams();
  const search = params.get("search") ?? "";
  const page = Math.max(1, Number(params.get("page") ?? 1) || 1);
  const categoryId = params.get("categoryId") ?? "";
  const [searchInput, setSearchInput] = useState(search);
  const loader = useCallback(
    (signal: AbortSignal) =>
      catalogService.items(search, page, categoryId, signal),
    [search, page, categoryId],
  );
  const { data, loading, error, reload } = useAsync(loader);
  const categoryLoader = useCallback(
    (signal: AbortSignal) => catalogService.categories("", signal),
    [],
  );
  const { data: categories } = useAsync(categoryLoader);
  const filtered = search !== "" || categoryId !== "";

  useEffect(() => {
    const handle = window.setTimeout(() => {
      if (searchInput === search) return;

      const next = new URLSearchParams(params);
      if (searchInput) {
        next.set("search", searchInput);
      } else {
        next.delete("search");
      }
      next.delete("page");
      setParams(next, { replace: true });
    }, 300);

    return () => window.clearTimeout(handle);
  }, [searchInput, search, params, setParams]);

  const changeCategory = (value: string) => {
    const next = new URLSearchParams(params);
    if (value) {
      next.set("categoryId", value);
    } else {
      next.delete("categoryId");
    }
    next.delete("page");
    setParams(next);
  };

  const clearFilters = () => {
    setSearchInput("");
    const next = new URLSearchParams(params);
    next.delete("search");
    next.delete("categoryId");
    next.delete("page");
    setParams(next, { replace: true });
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Itens do almoxarifado"
        description="Códigos agregados, categorias e variantes de produto."
        breadcrumbs={[{ label: "Painel", to: "/" }, { label: "Almoxarifado" }]}
        actions={
          canCreate ? (
            <Button asChild>
              <Link to="/admin/inventory/new">
                <Plus className="size-4" aria-hidden="true" />
                Novo item
              </Link>
            </Button>
          ) : null
        }
      />

      <div className="flex flex-wrap items-end gap-4">
        <div className="w-full max-w-sm">
          <label htmlFor="items-search" className="sr-only">
            Buscar itens
          </label>
          <Input
            id="items-search"
            type="search"
            placeholder="Buscar por código ou nome"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
          />
        </div>
        <Field
          id="items-category"
          label="Categoria"
          className="w-full max-w-xs"
        >
          <Select
            value={categoryId}
            onChange={(event) => changeCategory(event.target.value)}
          >
            <option value="">Todas</option>
            {categories?.data.map((category) => (
              <option key={category.id} value={category.id}>
                {category.name}
              </option>
            ))}
          </Select>
        </Field>
      </div>

      {loading && !data ? (
        <div className="flex justify-center p-10">
          <Spinner label="Carregando itens" />
        </div>
      ) : error ? (
        <ErrorState requestId={error.requestId} onRetry={reload} />
      ) : data && data.data.length === 0 ? (
        <EmptyState
          icon={Boxes}
          title={filtered ? "Nenhum resultado" : "Nenhum item"}
          description={
            filtered
              ? "Nenhum item corresponde aos filtros informados."
              : "Ainda não há itens cadastrados."
          }
          action={
            filtered ? (
              <Button variant="secondary" onClick={clearFilters}>
                Limpar filtros
              </Button>
            ) : null
          }
        />
      ) : data ? (
        <div className="space-y-4">
          <TableWrapper>
            <Table>
              <caption className="sr-only">
                Lista de itens do almoxarifado
              </caption>
              <TableHeader>
                <TableRow>
                  <TableHead>Código</TableHead>
                  <TableHead>Item</TableHead>
                  <TableHead>Categoria</TableHead>
                  <TableHead>Unidade</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead className="text-right">Ações</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.data.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell className="font-medium">{item.code}</TableCell>
                    <TableCell>{item.name}</TableCell>
                    <TableCell className="text-ink-secondary">
                      {item.category?.name ?? "—"}
                    </TableCell>
                    <TableCell>{labeled(unitLabels, item.unit)}</TableCell>
                    <TableCell>
                      <Badge variant="neutral">
                        {item.active ? "Ativo" : "Inativo"}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      {canView ? (
                        <SimpleTooltip label="Abrir">
                          <Button asChild variant="ghost" size="iconCompact">
                            <Link
                              to={`/admin/inventory/${item.id}`}
                              aria-label={`Abrir ${item.code}`}
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
