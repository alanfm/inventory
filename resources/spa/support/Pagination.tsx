import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@starterkit/module-kit";
import type { PaginationMeta } from "./api";

export interface PaginationProps {
  meta: PaginationMeta;
  onPageChange(page: number): void;
}

export function Pagination({ meta, onPageChange }: PaginationProps) {
  if (meta.lastPage <= 1) return null;

  return (
    <nav
      aria-label="Paginação"
      className="flex flex-wrap items-center justify-between gap-3"
    >
      <p className="text-body-sm text-ink-secondary">
        Página {meta.currentPage} de {meta.lastPage}
        {meta.total > 0 ? ` · ${meta.total} registros` : null}
      </p>
      <div className="flex items-center gap-2">
        <Button
          type="button"
          variant="secondary"
          size="compact"
          disabled={meta.currentPage <= 1}
          onClick={() => onPageChange(meta.currentPage - 1)}
        >
          <ChevronLeft className="size-4" aria-hidden="true" />
          Anterior
        </Button>
        <Button
          type="button"
          variant="secondary"
          size="compact"
          disabled={meta.currentPage >= meta.lastPage}
          onClick={() => onPageChange(meta.currentPage + 1)}
        >
          Próxima
          <ChevronRight className="size-4" aria-hidden="true" />
        </Button>
      </div>
    </nav>
  );
}
